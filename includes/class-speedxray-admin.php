<?php
/**
 * Admin screen.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * The step-by-step screen: 1 Diagnose → 2 Unused files → 3 Safe fixes → 4 Database → 5 Before/After.
 */
class SpeedXRay_Admin {

	const SLUG = 'speed-x-ray';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'scan', 'asset_rule', 'save_opts', 'db_clean', 'pause', 'reset_baseline' ) as $action ) {
			add_action( 'admin_post_speedxray_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( SPEEDXRAY_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Diagnose', 'speed-x-ray' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page(
			__( 'Speed X-Ray', 'speed-x-ray' ),
			__( 'Speed X-Ray', 'speed-x-ray' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-performance',
			81
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sxr-admin', SPEEDXRAY_URL . 'assets/admin.css', array(), SPEEDXRAY_VERSION );
		wp_enqueue_script( 'sxr-admin', SPEEDXRAY_URL . 'assets/admin.js', array(), SPEEDXRAY_VERSION, true );
		wp_localize_script(
			'sxr-admin',
			'sxrAdmin',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'psiNonce' => wp_create_nonce( 'speedxray_psi' ),
				'i18n'     => array(
					'testing' => __( 'Testing with Google… this takes 20–60 seconds.', 'speed-x-ray' ),
					'failed'  => __( 'The test failed:', 'speed-x-ray' ),
				),
			)
		);
	}

	private static function tabs() {
		/**
		 * Filters the tabs of the Speed X-Ray screen. Extensions render their own tabs
		 * on the "speedxray_render_tab_{$key}" action.
		 *
		 * @param array $tabs Tab key => label.
		 */
		return apply_filters(
			'speedxray_admin_tabs',
			array(
				'diagnose' => __( '1. Diagnose', 'speed-x-ray' ),
				'assets'   => __( '2. Unused files', 'speed-x-ray' ),
				'optimize' => __( '3. Safe fixes', 'speed-x-ray' ),
				'database' => __( '4. Database', 'speed-x-ray' ),
				'verify'   => __( '5. Before / After', 'speed-x-ray' ),
				'images'   => __( '6. Images', 'speed-x-ray' ),
			)
		);
	}

	/** Admin URL of a Speed X-Ray tab, for extensions. */
	public static function tab_url( $tab, $args = array() ) {
		return self::url( array_merge( array( 'tab' => $tab ), $args ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function form_open( $action, $extra_attrs = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra_attrs is a literal from this class.
		wp_nonce_field( 'speedxray_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'speedxray_' . $action ) . '">';
	}

	private static function hidden( $name, $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}

	/* ================================================================ handlers */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speed-x-ray' ), 403 );
		}
		check_admin_referer( 'speedxray_' . $action );
	}

	private static function done( $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/** Reads a POST field. Only called after guard() has verified the nonce. */
	private static function posted( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	public static function handle_scan() {
		self::guard( 'scan' );
		$url = trim( self::posted( 'custom_url' ) );
		if ( '' === $url ) {
			$url = self::posted( 'target' );
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( ! $url || ! SpeedXRay_Helpers::is_own_url( $url ) ) {
			self::done( array( 'sxr_msg' => 'bad_url' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// A scan makes three loopback requests; give slow sites room to finish.
			set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$report = SpeedXRay_Scanner::run( $url );
		$tab    = self::posted( 'return_tab' );
		self::done(
			array(
				'tab'     => array_key_exists( $tab, self::tabs() ) ? $tab : 'diagnose',
				'report'  => $report['path'],
				'sxr_msg' => $report['error'] ? 'scan_error' : 'scanned',
			)
		);
	}

	public static function handle_asset_rule() {
		self::guard( 'asset_rule' );
		$path = SpeedXRay_Helpers::normalize_path( self::posted( 'path' ) );
		if ( 'remove' === self::posted( 'op' ) ) {
			$key   = self::posted( 'key' );
			$rules = SpeedXRay_Asset_Manager::rules();
			SpeedXRay_Asset_Manager::remove_rule( $key );
			if ( isset( $rules[ $key ] ) ) {
				/* translators: 1: file handle, 2: page path or "everywhere". */
				SpeedXRay_Helpers::log_change( sprintf( __( 'Enabled %1$s again (%2$s)', 'speed-x-ray' ), $rules[ $key ]['handle'], 'everywhere' === $rules[ $key ]['scope'] ? __( 'everywhere', 'speed-x-ray' ) : $rules[ $key ]['path'] ), 'undo' );
			}
			$msg = 'rule_removed';
		} else {
			$type   = 'script' === self::posted( 'type' ) ? 'script' : 'style';
			$scope  = 'everywhere' === self::posted( 'scope' ) ? 'everywhere' : 'page';
			$handle = self::posted( 'handle' );
			if ( '' !== $handle ) {
				SpeedXRay_Asset_Manager::add_rule( $type, $handle, $scope, $path );
				/* translators: 1: file handle, 2: CSS or JS, 3: page path or "everywhere". */
				SpeedXRay_Helpers::log_change( sprintf( __( 'Stopped %1$s (%2$s) on %3$s', 'speed-x-ray' ), $handle, 'script' === $type ? 'JS' : 'CSS', 'everywhere' === $scope ? __( 'every page', 'speed-x-ray' ) : $path ) );
			}
			$msg = 'rule_added';
		}
		self::done(
			array(
				'tab'     => 'assets',
				'report'  => $path,
				'sxr_msg' => $msg,
			)
		);
	}

	public static function handle_save_opts() {
		self::guard( 'save_opts' );
		$on   = isset( $_POST['opts'] ) && is_array( $_POST['opts'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['opts'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$opts = array();
		$defs = SpeedXRay_Optimizations::definitions();
		$old  = SpeedXRay_Optimizations::all();
		foreach ( array_keys( $defs ) as $key ) {
			$opts[ $key ] = in_array( $key, $on, true );
			if ( $opts[ $key ] !== ! empty( $old[ $key ] ) ) {
				/* translators: %s: name of a fix. */
				SpeedXRay_Helpers::log_change( sprintf( $opts[ $key ] ? __( 'Turned on: %s', 'speed-x-ray' ) : __( 'Turned off: %s', 'speed-x-ray' ), $defs[ $key ]['label'] ), $opts[ $key ] ? 'fix' : 'undo' );
			}
		}
		update_option( SpeedXRay_Optimizations::OPTION, $opts, true );
		update_option( SpeedXRay_Optimizations::EXCLUDE_OPTION, self::posted( 'defer_exclude' ), true );
		update_option( SpeedXRay_Minify::EXCLUDE_OPTION, self::posted( 'minify_exclude' ), true );
		if ( ( ! empty( $old['minify_css'] ) && ! $opts['minify_css'] ) || ( ! empty( $old['minify_js'] ) && ! $opts['minify_js'] ) ) {
			SpeedXRay_Minify::clear_cache();
		}
		self::done(
			array(
				'tab'     => 'optimize',
				'sxr_msg' => 'opts_saved',
			)
		);
	}

	public static function handle_db_clean() {
		self::guard( 'db_clean' );
		$item = sanitize_key( self::posted( 'item' ) );
		$items = SpeedXRay_DB_Cleaner::items();
		$n     = array_key_exists( $item, $items ) ? SpeedXRay_DB_Cleaner::clean( $item ) : 0;
		if ( $n ) {
			/* translators: 1: number of rows, 2: what was cleaned. */
			SpeedXRay_Helpers::log_change( sprintf( __( 'Cleaned %1$s rows: %2$s', 'speed-x-ray' ), number_format_i18n( $n ), $items[ $item ][0] ) );
		}
		self::done(
			array(
				'tab'     => 'database',
				'sxr_msg' => 'cleaned',
				'n'       => $n,
			)
		);
	}

	public static function handle_pause() {
		self::guard( 'pause' );
		$paused = ! get_option( 'speedxray_paused' );
		update_option( 'speedxray_paused', $paused ? 1 : 0, true );
		SpeedXRay_Helpers::log_change( $paused ? __( 'Paused all fixes', 'speed-x-ray' ) : __( 'Resumed all fixes', 'speed-x-ray' ), $paused ? 'undo' : 'fix' );
		self::done( array( 'sxr_msg' => $paused ? 'paused' : 'resumed' ) );
	}

	public static function handle_reset_baseline() {
		self::guard( 'reset_baseline' );
		$path = SpeedXRay_Helpers::normalize_path( self::posted( 'path' ) );
		SpeedXRay_Scanner::reset_baseline( $path );
		self::done(
			array(
				'tab'     => 'verify',
				'report'  => $path,
				'sxr_msg' => 'baseline',
			)
		);
	}

	/* ================================================================== screen */

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'diagnose';
		$path    = isset( $_GET['report'] ) ? SpeedXRay_Helpers::normalize_path( sanitize_text_field( wp_unslash( $_GET['report'] ) ) ) : (string) get_option( 'speedxray_last_path', '' );
		$msg     = isset( $_GET['sxr_msg'] ) ? sanitize_key( wp_unslash( $_GET['sxr_msg'] ) ) : '';
		$cleaned = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable

		$tab     = array_key_exists( $tab, self::tabs() ) ? $tab : 'diagnose';
		$reports = SpeedXRay_Scanner::reports();
		$entry   = isset( $reports[ $path ] ) ? $reports[ $path ] : null;

		echo '<div class="wrap sxr">';
		echo '<h1>' . esc_html__( 'Speed X-Ray', 'speed-x-ray' ) . ' <span class="sxr-version">' . esc_html( SPEEDXRAY_VERSION ) . '</span></h1>';
		echo '<p class="sxr-lead">' . esc_html__( 'Diagnose first, then fix only what is actually wrong — and see exactly what a plugin can and cannot fix.', 'speed-x-ray' ) . '</p>';

		self::notice( $msg, $cleaned );
		self::pause_banner();

		echo '<nav class="nav-tab-wrapper sxr-tabs">';
		foreach ( self::tabs() as $key => $label ) {
			$args = array( 'tab' => $key );
			if ( $entry ) {
				$args['report'] = $path;
			}
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $args ) ), esc_attr( $tab === $key ? ' nav-tab-active' : '' ), esc_html( $label ) );
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'assets':
				self::tab_assets( $entry, $path, $reports );
				break;
			case 'optimize':
				self::tab_optimize( $entry );
				break;
			case 'database':
				self::tab_database( $entry );
				break;
			case 'verify':
				self::tab_verify( $entry, $path, $reports );
				break;
			case 'diagnose':
				self::tab_diagnose( $entry, $path, $reports );
				break;
			default:
				/**
				 * Renders an extension's tab.
				 *
				 * @param array|null $entry   Baseline/latest reports of the selected page.
				 * @param string     $path    Selected page path.
				 * @param array      $reports All stored reports.
				 */
				do_action( 'speedxray_render_tab_' . $tab, $entry, $path, $reports );
		}

		echo '</div>';
	}

	private static function notice( $msg, $cleaned ) {
		$messages = array(
			'scanned'      => array( 'success', __( 'Scan complete. Work through the issues below, then continue to the next step.', 'speed-x-ray' ) ),
			'scan_error'   => array( 'error', __( 'The scan finished with a problem — see the message below.', 'speed-x-ray' ) ),
			'bad_url'      => array( 'error', __( 'Please choose a page on this site.', 'speed-x-ray' ) ),
			'rule_added'   => array( 'success', __( 'File stopped. Open the page in a private window and check it still works, then re-scan to confirm.', 'speed-x-ray' ) ),
			'rule_removed' => array( 'success', __( 'File enabled again.', 'speed-x-ray' ) ),
			'opts_saved'   => array( 'success', __( 'Fixes saved. Check your key pages in a private window, then re-scan in step 5.', 'speed-x-ray' ) ),
			/* translators: %s: number of database rows removed. */
			'cleaned'      => array( 'success', sprintf( __( '%s rows removed. Large tables are cleaned in batches — click again if some remain.', 'speed-x-ray' ), number_format_i18n( $cleaned ) ) ),
			'paused'       => array( 'warning', __( 'All Speed X-Ray fixes are paused. Your site now runs exactly as without the plugin.', 'speed-x-ray' ) ),
			'resumed'      => array( 'success', __( 'Fixes are active again.', 'speed-x-ray' ) ),
			'baseline'     => array( 'success', __( 'The latest scan is now the baseline for future comparisons.', 'speed-x-ray' ) ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
		}
	}

	private static function pause_banner() {
		$paused = (bool) get_option( 'speedxray_paused' );
		echo '<div class="sxr-safety' . ( $paused ? ' is-paused' : '' ) . '">';
		self::form_open( 'pause' );
		if ( $paused ) {
			echo '<strong>' . esc_html__( 'All fixes are paused.', 'speed-x-ray' ) . '</strong> ';
			submit_button( __( 'Resume fixes', 'speed-x-ray' ), 'primary small', 'submit', false );
		} else {
			echo '<span>' . wp_kses(
				sprintf(
					/* translators: %s: the URL parameter, shown as code. */
					__( 'Something broke? Add %s to any page URL (while logged in as an administrator) to see it without Speed X-Ray, or', 'speed-x-ray' ),
					'<code>?speedxray_off=1</code>'
				),
				array( 'code' => array() )
			) . '</span> ';
			submit_button( __( 'Pause all fixes', 'speed-x-ray' ), 'secondary small', 'submit', false );
		}
		echo '</form></div>';
	}

	/* ---------------------------------------------------------- 1. Diagnose */

	private static function tab_diagnose( $entry, $path, $reports ) {
		echo '<div class="sxr-card">';
		echo '<h2>' . esc_html__( 'Step 1 — Diagnose a page', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'Speed X-Ray loads the page as a logged-out visitor, measures PHP time and every database query (attributed to the plugin or theme that made it), then reads the HTML and every CSS/JS file. Scan your most important pages one by one: home, a product or service page, a blog post.', 'speed-x-ray' ) . '</p>';
		self::scan_form( $entry ? $entry['latest']['url'] : home_url( '/' ), 'diagnose', __( 'Run diagnosis', 'speed-x-ray' ) );
		echo '</div>';

		if ( ! $entry ) {
			return;
		}
		$report = $entry['latest'];
		self::report_switcher( $reports, $path, 'diagnose' );

		if ( $report['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $report['error'] ) . '</p></div>';
		}

		self::metric_cards( $report['metrics'] );

		$groups = array(
			'auto'    => array( __( 'Speed X-Ray can fix these', 'speed-x-ray' ), __( 'Use the linked step. Every fix can be undone.', 'speed-x-ray' ) ),
			'manual'  => array( __( 'You need to change these on the site', 'speed-x-ray' ), __( 'Theme, builder, content or plugin choices. Speed X-Ray tells you exactly what and where, but changing them automatically would risk breaking your design.', 'speed-x-ray' ) ),
			'hosting' => array( __( 'Server / hosting', 'speed-x-ray' ), __( 'No plugin can fix these. Send this list to your host.', 'speed-x-ray' ) ),
		);
		$good   = array();
		$rank   = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
		);
		foreach ( $groups as $fix => $meta ) {
			$items = array();
			foreach ( $report['issues'] as $issue ) {
				if ( 'good' === $issue['severity'] ) {
					$good[ $issue['id'] ] = $issue;
				} elseif ( $issue['fix'] === $fix ) {
					$items[] = $issue;
				}
			}
			if ( ! $items ) {
				continue;
			}
			usort(
				$items,
				function ( $a, $b ) use ( $rank ) {
					return $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ];
				}
			);
			echo '<div class="sxr-card sxr-group sxr-group-' . esc_attr( $fix ) . '">';
			echo '<h2>' . esc_html( $meta[0] ) . ' <span class="sxr-count">' . esc_html( number_format_i18n( count( $items ) ) ) . '</span></h2>';
			echo '<p class="description">' . esc_html( $meta[1] ) . '</p>';
			foreach ( $items as $issue ) {
				self::issue_row( $issue, $path );
			}
			echo '</div>';
		}

		if ( $good ) {
			/* translators: %s: number of passed checks. */
			echo '<details class="sxr-card"><summary><strong>' . esc_html( sprintf( __( 'Passed checks (%s)', 'speed-x-ray' ), number_format_i18n( count( $good ) ) ) ) . '</strong></summary><ul class="sxr-good">';
			foreach ( $good as $issue ) {
				echo '<li>✓ ' . esc_html( $issue['title'] ) . '</li>';
			}
			echo '</ul></details>';
		}

		self::components_table( $report );

		/**
		 * Runs after the diagnosis of a page is shown.
		 *
		 * @param array $report The page's latest report.
		 */
		do_action( 'speedxray_diagnose_after', $report );
	}

	private static function scan_form( $current, $return_tab, $button ) {
		$targets    = array( home_url( '/' ) => __( 'Homepage', 'speed-x-ray' ) );
		$post_types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'page', 'attachment' ) ) );
		foreach ( array( array( 'page' ), $post_types ) as $types ) {
			if ( ! $types ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'   => $types,
					'post_status' => 'publish',
					'numberposts' => 15,
					'orderby'     => 'modified',
				)
			);
			foreach ( $posts as $post ) {
				$title = get_the_title( $post );
				/* translators: 1: post type name, e.g. Page, 2: post title. */
				$targets[ get_permalink( $post ) ] = sprintf( __( '%1$s: %2$s', 'speed-x-ray' ), get_post_type_object( $post->post_type )->labels->singular_name, '' !== $title ? $title : __( '(no title)', 'speed-x-ray' ) );
			}
		}
		if ( ! isset( $targets[ $current ] ) ) {
			$targets = array( $current => $current ) + $targets;
		}

		self::form_open( 'scan', ' class="sxr-scan-form" data-sxr-scan' );
		self::hidden( 'return_tab', $return_tab );
		echo '<label class="screen-reader-text" for="sxr-target">' . esc_html__( 'Page to scan', 'speed-x-ray' ) . '</label>';
		echo '<select name="target" id="sxr-target">';
		foreach ( $targets as $url => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $url ), selected( $url, $current, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="sxr-custom-url">' . esc_html__( 'Or any URL on this site', 'speed-x-ray' ) . '</label>';
		echo '<input type="text" name="custom_url" id="sxr-custom-url" class="regular-text" placeholder="' . esc_attr__( '…or paste any URL or path on this site', 'speed-x-ray' ) . '"> ';
		submit_button( $button, 'primary', 'submit', false );
		echo '<span class="sxr-scanning" hidden>' . esc_html__( 'Scanning — this can take up to a minute…', 'speed-x-ray' ) . '</span>';
		echo '</form>';
	}

	private static function report_switcher( $reports, $path, $tab ) {
		if ( count( $reports ) < 2 ) {
			echo '<p class="sxr-meta">' . esc_html(
				sprintf(
					/* translators: 1: page path, 2: human-readable time difference, e.g. "5 mins". */
					__( 'Showing: %1$s — scanned %2$s ago', 'speed-x-ray' ),
					$path,
					human_time_diff( $reports[ $path ]['latest']['time'] )
				)
			) . '</p>';
			return;
		}
		echo '<form method="get" class="sxr-meta">';
		self::hidden( 'page', self::SLUG );
		self::hidden( 'tab', $tab );
		echo '<label for="sxr-report">' . esc_html__( 'Showing:', 'speed-x-ray' ) . '</label> <select name="report" id="sxr-report" data-sxr-autosubmit>';
		foreach ( $reports as $p => $e ) {
			/* translators: 1: page path, 2: human-readable time difference, e.g. "5 mins". */
			printf( '<option value="%s"%s>%s</option>', esc_attr( $p ), selected( $p, $path, false ), esc_html( sprintf( __( '%1$s (%2$s ago)', 'speed-x-ray' ), $p, human_time_diff( $e['latest']['time'] ) ) ) );
		}
		echo '</select> <noscript>';
		submit_button( __( 'Show', 'speed-x-ray' ), 'small', 'submit', false );
		echo '</noscript></form>';
	}

	private static function ms( $value ) {
		/* translators: %s: number of milliseconds. */
		return null === $value ? '—' : sprintf( __( '%s ms', 'speed-x-ray' ), number_format_i18n( $value ) );
	}

	private static function metric_cards( $m ) {
		$files = function ( $count, $bytes ) {
			/* translators: 1: number of files, 2: total size. */
			return sprintf( _n( '%1$s file · %2$s', '%1$s files · %2$s', $count, 'speed-x-ray' ), number_format_i18n( $count ), SpeedXRay_Helpers::bytes( $bytes ) );
		};
		$num   = function ( $v ) {
			return null === $v ? '—' : number_format_i18n( $v );
		};

		$queries = $num( $m['queries'] );
		if ( null !== $m['queries'] && null !== $m['query_ms'] ) {
			$queries .= ' / ' . self::ms( $m['query_ms'] );
		}

		$cards = array(
			array( __( 'Server response', 'speed-x-ray' ), self::ms( $m['response_ms'] ), __( 'What visitors wait for before anything shows', 'speed-x-ray' ) ),
			array( __( 'PHP build time', 'speed-x-ray' ), self::ms( $m['server_ms'] ), __( 'Uncached cost of this page', 'speed-x-ray' ) ),
			array( __( 'DB queries', 'speed-x-ray' ), $queries, __( 'Count / time', 'speed-x-ray' ) ),
			array( __( 'JavaScript', 'speed-x-ray' ), $files( $m['js_files'], $m['js_bytes'] ), __( 'Local files, uncompressed', 'speed-x-ray' ) ),
			array( __( 'CSS', 'speed-x-ray' ), $files( $m['css_files'], $m['css_bytes'] ), __( 'Local files, uncompressed', 'speed-x-ray' ) ),
			array( __( 'HTML elements', 'speed-x-ray' ), $num( $m['dom_nodes'] ), __( 'Under 1,500 is healthy', 'speed-x-ray' ) ),
			array( __( 'Render-blocking JS', 'speed-x-ray' ), $num( $m['blocking_scripts'] ), __( 'Scripts in <head> without defer', 'speed-x-ray' ) ),
			array( __( 'External domains', 'speed-x-ray' ), $num( $m['third_party'] ), __( 'Tracking, fonts, chat, ads…', 'speed-x-ray' ) ),
		);
		echo '<div class="sxr-metrics">';
		foreach ( $cards as $c ) {
			echo '<div class="sxr-metric"><span class="sxr-metric-label">' . esc_html( $c[0] ) . '</span><span class="sxr-metric-value">' . esc_html( $c[1] ) . '</span><span class="sxr-metric-hint">' . esc_html( $c[2] ) . '</span></div>';
		}
		echo '</div>';
	}

	private static function severity_label( $severity ) {
		$labels = array(
			'critical' => __( 'Critical', 'speed-x-ray' ),
			'warning'  => __( 'Warning', 'speed-x-ray' ),
			'info'     => __( 'Info', 'speed-x-ray' ),
		);
		return isset( $labels[ $severity ] ) ? $labels[ $severity ] : $severity;
	}

	private static function issue_row( $issue, $path ) {
		echo '<div class="sxr-issue sxr-sev-' . esc_attr( $issue['severity'] ) . '">';
		echo '<div class="sxr-issue-head"><span class="sxr-badge">' . esc_html( self::severity_label( $issue['severity'] ) ) . '</span> <strong>' . esc_html( $issue['title'] ) . '</strong>';
		if ( $issue['tab'] ) {
			$tabs = self::tabs();
			printf(
				' <a class="button button-small" href="%s">%s</a>',
				esc_url(
					self::url(
						array(
							'tab'    => $issue['tab'],
							'report' => $path,
						)
					)
				),
				/* translators: %s: name of a step, e.g. "3. Safe fixes". */
				esc_html( sprintf( __( 'Fix in %s →', 'speed-x-ray' ), $tabs[ $issue['tab'] ] ) )
			);
		}
		echo '</div>';
		if ( $issue['detail'] ) {
			echo '<p>' . nl2br( esc_html( $issue['detail'] ) ) . '</p>';
		}
		if ( $issue['list'] ) {
			echo '<ul class="sxr-list">';
			foreach ( $issue['list'] as $line ) {
				echo '<li><code>' . esc_html( $line ) . '</code></li>';
			}
			echo '</ul>';
		}

		/**
		 * Runs at the end of one issue in the diagnosis.
		 *
		 * @param array $issue The issue.
		 */
		do_action( 'speedxray_issue_after', $issue );
		echo '</div>';
	}

	private static function components_table( $report ) {
		if ( ! $report['components'] ) {
			return;
		}
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Database time by plugin / theme', 'speed-x-ray' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every query on this page, attributed to the plugin or theme whose code triggered it.', 'speed-x-ray' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Queries', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Time', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
		foreach ( $report['components'] as $key => $c ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( SpeedXRay_Helpers::component_label( $key ) ),
				esc_html( number_format_i18n( $c['queries'] ) ),
				esc_html( self::ms( round( $c['time'] * 1000, 1 ) ) )
			);
		}
		echo '</tbody></table>';

		if ( $report['slow'] ) {
			echo '<h3>' . esc_html__( 'Slowest queries', 'speed-x-ray' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Source', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Query', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
			foreach ( $report['slow'] as $q ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td><code class="sxr-sql">%s</code></td></tr>',
					esc_html( self::ms( round( $q['time'] * 1000 ) ) ),
					esc_html( SpeedXRay_Helpers::component_label( $q['component'] ) ),
					esc_html( $q['sql'] )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------ 2. Unused files */

	private static function status_label( $status ) {
		$labels = array(
			'unused'        => __( 'unused', 'speed-x-ray' ),
			'likely_unused' => __( 'likely unused', 'speed-x-ray' ),
			'mostly_unused' => __( 'mostly unused', 'speed-x-ray' ),
			'review'        => __( 'review', 'speed-x-ray' ),
			'used'          => __( 'used', 'speed-x-ray' ),
			'third-party'   => __( 'external', 'speed-x-ray' ),
			'core'          => __( 'core', 'speed-x-ray' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function tab_assets( $entry, $path, $reports ) {
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Step 2 — Stop files this page does not need', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every CSS and JavaScript file WordPress printed on the scanned page, who ships it, and whether the page actually uses it. For each stylesheet, Speed X-Ray checks how many of its class/id selectors match an element in the page HTML.', 'speed-x-ray' ) . '</p>';
		$legend = array(
			'unused'        => __( 'no selector in this file matches anything on the page', 'speed-x-ray' ),
			'likely_unused' => __( 'JavaScript from a plugin whose CSS is entirely unused here', 'speed-x-ray' ),
			'mostly_unused' => __( 'under 5% of selectors match', 'speed-x-ray' ),
			'review'        => __( 'JavaScript cannot be checked from HTML — decide by whether the plugin\'s feature is on this page', 'speed-x-ray' ),
		);
		echo '<ul class="sxr-legend">';
		foreach ( $legend as $status => $text ) {
			echo '<li><span class="sxr-status sxr-st-' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span> ' . esc_html( $text ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="sxr-warn"><strong>' . esc_html__( 'Always verify.', 'speed-x-ray' ) . '</strong> ' . esc_html__( 'Popups, mobile menus, cart drawers and sliders add their elements with JavaScript, so their CSS can look unused. After stopping a file, open the page in a private window and click through it. "Enable again" undoes it instantly.', 'speed-x-ray' ) . '</p>';
		echo '</div>';

		self::rules_table( $path );

		if ( ! $entry ) {
			echo '<div class="sxr-card"><p>' . esc_html__( 'Run a diagnosis in step 1 first.', 'speed-x-ray' ) . '</p></div>';
			return;
		}
		self::report_switcher( $reports, $path, 'assets' );
		$assets = $entry['latest']['assets'];
		if ( ! $assets ) {
			echo '<div class="sxr-card"><p>' . esc_html__( 'No file data for this scan (see the scan message in step 1).', 'speed-x-ray' ) . '</p></div>';
			return;
		}

		echo '<div class="sxr-card"><table class="widefat sxr-assets"><thead><tr>';
		foreach ( array( __( 'File', 'speed-x-ray' ), __( 'From', 'speed-x-ray' ), __( 'Size', 'speed-x-ray' ), __( 'Selectors used', 'speed-x-ray' ), __( 'Status', 'speed-x-ray' ), __( 'Action', 'speed-x-ray' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $assets as $a ) {
			$meta = 'script' === $a['type'] ? 'JS · ' . ( 'footer' === $a['position'] ? __( 'footer', 'speed-x-ray' ) : __( 'head', 'speed-x-ray' ) ) . ( $a['strategy'] ? ' · ' . $a['strategy'] : '' ) : 'CSS';
			echo '<tr>';
			printf(
				'<td><strong>%s</strong> <span class="sxr-type">%s</span><br><a href="%s" target="_blank" rel="noopener noreferrer" class="sxr-src">%s</a></td>',
				esc_html( $a['handle'] ),
				esc_html( $meta ),
				esc_url( $a['src'] ),
				esc_html( basename( (string) wp_parse_url( $a['src'], PHP_URL_PATH ) ) )
			);
			echo '<td>' . esc_html( $a['owner']['label'] ) . '</td>';
			echo '<td>' . esc_html( SpeedXRay_Helpers::bytes( $a['size'] ) ) . '</td>';
			echo '<td>' . esc_html( $a['usage'] ? number_format_i18n( $a['usage']['used'] ) . ' / ' . number_format_i18n( $a['usage']['total'] ) : '—' ) . '</td>';
			echo '<td><span class="sxr-status sxr-st-' . esc_attr( $a['status'] ) . '">' . esc_html( self::status_label( $a['status'] ) ) . '</span></td>';
			echo '<td>';
			self::asset_actions( $a, $path );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function asset_actions( $a, $path ) {
		if ( $a['rule'] ) {
			echo '<span class="sxr-note">' . esc_html__( 'A rule stops this file, but it still loads because another file needs it.', 'speed-x-ray' ) . '</span>';
			return;
		}
		if ( $a['required_by'] ) {
			/* translators: %s: comma-separated list of file handles. */
			echo '<span class="sxr-note">' . esc_html( sprintf( __( 'Needed by: %s', 'speed-x-ray' ), implode( ', ', array_slice( $a['required_by'], 0, 4 ) ) ) ) . '</span>';
			return;
		}
		if ( 'core' === $a['owner']['type'] ) {
			echo '<span class="sxr-note">' . esc_html__( 'WordPress core — use step 3', 'speed-x-ray' ) . '</span>';
			return;
		}
		self::form_open( 'asset_rule', ' class="sxr-inline"' );
		self::hidden( 'op', 'add' );
		self::hidden( 'type', $a['type'] );
		self::hidden( 'handle', $a['handle'] );
		self::hidden( 'path', $path );
		echo '<button class="button button-small" name="scope" value="page">' . esc_html__( 'Stop on this page', 'speed-x-ray' ) . '</button> ';
		echo '<button class="button button-small" name="scope" value="everywhere" data-confirm="' . esc_attr__( 'Stop this file on EVERY page of the site? Only do this if you are sure the feature is not used anywhere.', 'speed-x-ray' ) . '">' . esc_html__( 'Everywhere', 'speed-x-ray' ) . '</button>';
		echo '</form>';
	}

	private static function rules_table( $path ) {
		$rules = SpeedXRay_Asset_Manager::rules();
		if ( ! $rules ) {
			return;
		}
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Files you stopped', 'speed-x-ray' ) . ' <span class="sxr-count">' . esc_html( number_format_i18n( count( $rules ) ) ) . '</span></h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Where', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Since', 'speed-x-ray' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'speed-x-ray' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rules as $key => $rule ) {
			echo '<tr><td><strong>' . esc_html( $rule['handle'] ) . '</strong> <span class="sxr-type">' . esc_html( 'script' === $rule['type'] ? 'JS' : 'CSS' ) . '</span></td>';
			echo '<td>' . ( 'everywhere' === $rule['scope'] ? '<strong>' . esc_html__( 'Whole site', 'speed-x-ray' ) . '</strong>' : esc_html( $rule['path'] ) ) . '</td>';
			/* translators: %s: human-readable time difference, e.g. "2 days". */
			echo '<td>' . esc_html( sprintf( __( '%s ago', 'speed-x-ray' ), human_time_diff( $rule['created'] ) ) ) . '</td><td>';
			self::form_open( 'asset_rule' );
			self::hidden( 'op', 'remove' );
			self::hidden( 'key', $key );
			self::hidden( 'path', $path );
			submit_button( __( 'Enable again', 'speed-x-ray' ), 'small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------- 3. Safe fixes */

	private static function tab_optimize( $entry ) {
		$found = array();
		if ( $entry ) {
			foreach ( $entry['latest']['issues'] as $issue ) {
				$found[ $issue['id'] ] = true;
			}
		}
		$opts  = SpeedXRay_Optimizations::all();
		$risks = array(
			'safe'   => __( 'Safe', 'speed-x-ray' ),
			'low'    => __( 'Low risk', 'speed-x-ray' ),
			'medium' => __( 'Medium risk', 'speed-x-ray' ),
		);

		echo '<div class="sxr-card"><h2>' . esc_html__( 'Step 3 — Safe fixes', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'Turn on only what the diagnosis found (marked "found on your page"). Turn fixes on one at a time and check your site after each, so if something breaks you know which one did it.', 'speed-x-ray' ) . '</p>';
		self::form_open( 'save_opts' );
		echo '<table class="form-table sxr-opts" role="presentation"><tbody>';
		foreach ( SpeedXRay_Optimizations::definitions() as $key => $def ) {
			$disabled = 'defer_js' === $key && ! SpeedXRay_Optimizations::defer_supported();
			$is_found = isset( $found[ $key ] ) || ( 'defer_js' === $key && isset( $found['blocking_js'] ) ) || ( 'limit_revisions' === $key && isset( $found['db_clutter'] ) ) || ( 'minify_css' === $key && isset( $found['minify_css_files'] ) ) || ( 'minify_js' === $key && isset( $found['minify_js_files'] ) );
			echo '<tr><th scope="row"><label>';
			printf( '<input type="checkbox" name="opts[]" value="%s"%s%s> ', esc_attr( $key ), checked( ! empty( $opts[ $key ] ), true, false ), disabled( $disabled, true, false ) );
			echo esc_html( $def['label'] ) . '</label></th><td>';
			echo '<span class="sxr-risk sxr-risk-' . esc_attr( $def['risk'] ) . '">' . esc_html( $risks[ $def['risk'] ] ) . '</span> ';
			if ( $is_found ) {
				echo '<span class="sxr-found">' . esc_html__( 'found on your page', 'speed-x-ray' ) . '</span> ';
			}
			echo '<p class="description">' . esc_html( $def['desc'] ) . '</p>';
			if ( $disabled ) {
				echo '<p class="description">' . esc_html__( 'Requires WordPress 6.3 or newer.', 'speed-x-ray' ) . '</p>';
			}
			if ( 'defer_js' === $key ) {
				echo '<p><label for="sxr-defer-exclude">' . esc_html__( 'Never defer these script handles (comma separated; jQuery is always excluded):', 'speed-x-ray' ) . '</label><br><input type="text" class="large-text" id="sxr-defer-exclude" name="defer_exclude" value="' . esc_attr( (string) get_option( SpeedXRay_Optimizations::EXCLUDE_OPTION, '' ) ) . '"></p>';
			}
			if ( 'minify_js' === $key ) {
				echo '<p><label for="sxr-minify-exclude">' . esc_html__( 'Never minify these CSS/JS handles (comma separated):', 'speed-x-ray' ) . '</label><br><input type="text" class="large-text" id="sxr-minify-exclude" name="minify_exclude" value="' . esc_attr( (string) get_option( SpeedXRay_Minify::EXCLUDE_OPTION, '' ) ) . '"></p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save fixes', 'speed-x-ray' ) );
		echo '</form></div>';

		/**
		 * Runs after the safe fixes list (extensions add their own fixes here).
		 *
		 * @param array|null $entry Baseline/latest reports of the selected page.
		 */
		do_action( 'speedxray_optimize_after', $entry );

		$not_done = array(
			array( __( 'Page caching', 'speed-x-ray' ), __( 'your host or a dedicated cache plugin does this better and more safely.', 'speed-x-ray' ) ),
			array( __( 'Combining files', 'speed-x-ray' ), __( 'with HTTP/2 the gain is small and it is the most common cause of broken sites. Files are minified one by one instead.', 'speed-x-ray' ) ),
			array( __( 'Minifying HTML', 'speed-x-ray' ), __( 'with gzip on, it saves 1–3% and can break inline scripts and builders.', 'speed-x-ray' ) ),
			array( __( 'Rewriting your builder layout', 'speed-x-ray' ), __( 'reported in step 1 with exact instructions instead.', 'speed-x-ray' ) ),
		);
		echo '<div class="sxr-card"><h2>' . esc_html__( 'What Speed X-Ray deliberately does not do', 'speed-x-ray' ) . '</h2><ul class="sxr-list-plain">';
		foreach ( $not_done as $row ) {
			echo '<li><strong>' . esc_html( $row[0] ) . '</strong> — ' . esc_html( $row[1] ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* --------------------------------------------------------- 4. Database */

	private static function tab_database( $entry ) {
		$counts = SpeedXRay_DB_Cleaner::counts();
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Step 4 — Database cleanup', 'speed-x-ray' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Take a backup first.', 'speed-x-ray' ) . '</strong> ' . esc_html__( 'Everything here is permanently deleted. Items are removed with WordPress\'s own functions, in batches.', 'speed-x-ray' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Rows', 'speed-x-ray' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'speed-x-ray' ) . '</span></th></tr></thead><tbody>';
		foreach ( SpeedXRay_DB_Cleaner::items() as $item => $meta ) {
			echo '<tr><td><strong>' . esc_html( $meta[0] ) . '</strong><br><span class="description">' . esc_html( $meta[1] ) . '</span></td>';
			echo '<td>' . esc_html( number_format_i18n( $counts[ $item ] ) ) . '</td><td>';
			if ( $counts[ $item ] > 0 ) {
				self::form_open( 'db_clean' );
				self::hidden( 'item', $item );
				/* translators: %s: name of the item being deleted, e.g. "Post revisions". */
				echo '<button class="button" data-confirm="' . esc_attr( sprintf( __( 'Permanently delete: %s? Make sure you have a backup.', 'speed-x-ray' ), $meta[0] ) ) . '">' . esc_html__( 'Clean', 'speed-x-ray' ) . '</button>';
				echo '</form>';
			} else {
				echo '<span class="sxr-ok">' . esc_html__( 'Clean', 'speed-x-ray' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		$top = $entry && isset( $entry['latest']['server']['autoload_top'] ) ? $entry['latest']['server']['autoload_top'] : array();
		if ( $top ) {
			echo '<div class="sxr-card"><h2>' . esc_html__( 'Largest autoloaded options', 'speed-x-ray' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: total size, e.g. 900 KB. */
					__( 'Loaded into memory on every request (total %s). The name usually tells you which plugin created it. Speed X-Ray only shows these — deleting the wrong one can break a plugin, so remove leftovers of uninstalled plugins manually or ask your developer.', 'speed-x-ray' ),
					SpeedXRay_Helpers::bytes( $entry['latest']['server']['autoload_bytes'] )
				)
			) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Option', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Size', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
			foreach ( $top as $row ) {
				echo '<tr><td><code>' . esc_html( $row['option_name'] ) . '</code></td><td>' . esc_html( SpeedXRay_Helpers::bytes( (int) $row['size'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	/* ----------------------------------------------------- 5. Before/After */

	/** What the user changed since this page's baseline scan, so a before/after has its explanation. */
	private static function changes_card( $entry ) {
		$since   = $entry && $entry['baseline'] ? (int) $entry['baseline']['time'] : 0;
		$changes = array_slice( SpeedXRay_Helpers::changes( $since ), 0, 15 );
		echo '<div class="sxr-card"><h2>' . esc_html( $since ? __( 'What you changed since the baseline', 'speed-x-ray' ) : __( 'Recent changes', 'speed-x-ray' ) ) . '</h2>';
		if ( ! $changes ) {
			echo '<p class="description">' . esc_html__( 'Nothing changed yet. Every fix you turn on, file you stop and cleanup you run is listed here with its date, so you can see what caused a difference.', 'speed-x-ray' ) . '</p></div>';
			return;
		}
		echo '<ul class="sxr-changes">';
		foreach ( $changes as $change ) {
			printf(
				'<li class="sxr-change-%1$s"><time>%2$s</time> %3$s</li>',
				esc_attr( $change['type'] ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $change['t'] ) ),
				esc_html( $change['label'] )
			);
		}
		echo '</ul></div>';
	}

	private static function tab_verify( $entry, $path, $reports ) {
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Step 5 — Before / After', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'The first scan of each page is its baseline. Re-scan after applying fixes to see what actually changed — measured, not guessed.', 'speed-x-ray' ) . '</p>';
		self::scan_form( $entry ? $entry['latest']['url'] : home_url( '/' ), 'verify', __( 'Re-scan now', 'speed-x-ray' ) );

		/**
		 * Runs inside the Before / After intro card.
		 *
		 * @param array|null $entry Baseline/latest reports of the selected page.
		 */
		do_action( 'speedxray_verify_intro', $entry );
		echo '</div>';

		/**
		 * Extra before/after cards (Google PageSpeed, real visitors).
		 *
		 * @param array|null $entry Baseline/latest reports of the selected page.
		 * @param string     $path  Selected page key.
		 */
		do_action( 'speedxray_verify_cards', $entry, $path );
		self::changes_card( $entry );

		if ( ! $entry ) {
			return;
		}
		self::report_switcher( $reports, $path, 'verify' );

		$before = $entry['baseline'];
		$after  = $entry['latest'];
		if ( ! $before || $before['time'] === $after['time'] ) {
			echo '<div class="sxr-card"><p>' . esc_html__( 'Only one scan of this page so far. Apply fixes in steps 2–4, then click "Re-scan now".', 'speed-x-ray' ) . '</p></div>';
			return;
		}

		$rows = array(
			'response_ms'      => array( __( 'Server response', 'speed-x-ray' ), 'ms' ),
			'server_ms'        => array( __( 'PHP build time', 'speed-x-ray' ), 'ms' ),
			'queries'          => array( __( 'Database queries', 'speed-x-ray' ), 'n' ),
			'query_ms'         => array( __( 'Database time', 'speed-x-ray' ), 'ms' ),
			'js_files'         => array( __( 'JavaScript files', 'speed-x-ray' ), 'n' ),
			'js_bytes'         => array( __( 'JavaScript size', 'speed-x-ray' ), 'b' ),
			'css_files'        => array( __( 'CSS files', 'speed-x-ray' ), 'n' ),
			'css_bytes'        => array( __( 'CSS size', 'speed-x-ray' ), 'b' ),
			'blocking_scripts' => array( __( 'Render-blocking scripts', 'speed-x-ray' ), 'n' ),
			'dom_nodes'        => array( __( 'HTML elements', 'speed-x-ray' ), 'n' ),
			'html_bytes'       => array( __( 'HTML size', 'speed-x-ray' ), 'b' ),
			'third_party'      => array( __( 'External domains', 'speed-x-ray' ), 'n' ),
		);
		$fmt  = function ( $v, $unit ) {
			if ( null === $v ) {
				return '—';
			}
			if ( 'b' === $unit ) {
				return SpeedXRay_Helpers::bytes( $v );
			}
			return 'ms' === $unit ? self::ms( $v ) : number_format_i18n( $v );
		};
		$open = function ( $r ) {
			return count(
				array_filter(
					$r['issues'],
					function ( $i ) {
						return in_array( $i['severity'], array( 'critical', 'warning' ), true );
					}
				)
			);
		};

		echo '<div class="sxr-card"><p class="sxr-meta">' . esc_html(
			sprintf(
				/* translators: 1: date/time of the baseline scan, 2: date/time of the latest scan. */
				__( 'Baseline: %1$s → Latest: %2$s', 'speed-x-ray' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $before['time'] ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $after['time'] )
			)
		) . '</p>';
		echo '<table class="widefat striped sxr-compare"><thead><tr><th>' . esc_html__( 'Metric', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Before', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'After', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Change', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $row ) {
			$b      = isset( $before['metrics'][ $key ] ) ? $before['metrics'][ $key ] : null;
			$a      = isset( $after['metrics'][ $key ] ) ? $after['metrics'][ $key ] : null;
			$change = '—';
			$class  = '';
			if ( null !== $b && null !== $a && $b > 0 ) {
				$pct    = (int) round( ( $a - $b ) / $b * 100 );
				$change = ( $pct > 0 ? '+' : '' ) . $pct . '%';
				$class  = $pct < 0 ? 'sxr-better' : ( $pct > 0 ? 'sxr-worse' : '' );
			}
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td></tr>', esc_html( $row[0] ), esc_html( $fmt( $b, $row[1] ) ), esc_html( $fmt( $a, $row[1] ) ), esc_attr( $class ), esc_html( $change ) );
		}
		printf( '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td></td></tr>', esc_html__( 'Open problems', 'speed-x-ray' ), esc_html( number_format_i18n( $open( $before ) ) ), esc_html( number_format_i18n( $open( $after ) ) ) );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Timings vary a little between runs (server load, other visitors). Re-scan two or three times before drawing conclusions from small differences.', 'speed-x-ray' ) . '</p>';

		self::form_open( 'reset_baseline' );
		self::hidden( 'path', $path );
		submit_button( __( 'Make latest scan the new baseline', 'speed-x-ray' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
