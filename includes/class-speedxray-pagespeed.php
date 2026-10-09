<?php
/**
 * Lab Core Web Vitals from Google PageSpeed Insights, before and after your changes.
 * External service: only contacted when the user clicks "Test with Google PageSpeed".
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_PageSpeed {

	const OPTION     = 'speedxray_psi';
	const KEY_OPTION = 'speedxray_psi_key';
	const ENDPOINT   = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const KEEP       = 5; // Runs kept per group.

	/** Google's "good" / "poor" limits for the lab metrics shown. */
	const LIMITS = array(
		'lcp' => array( 2500, 4000 ),
		'cls' => array( 0.1, 0.25 ),
		'tbt' => array( 200, 600 ),
		'fcp' => array( 1800, 3000 ),
	);

	public static function init() {
		add_action( 'wp_ajax_speedxray_psi', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_speedxray_psi_reset', array( __CLASS__, 'reset' ) );
		add_action( 'admin_post_speedxray_psi_key', array( __CLASS__, 'save_key' ) );
		add_action( 'speedxray_verify_cards', array( __CLASS__, 'render' ), 10, 2 );
	}

	/** Google can only test pages it can reach over the internet. */
	public static function is_public( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|test|invalid|example|lan|internal)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	/**
	 * Runs one PageSpeed test.
	 *
	 * @return array|WP_Error
	 */
	public static function run( $url, $strategy ) {
		$args = array(
			'url'      => $url,
			'strategy' => 'desktop' === $strategy ? 'desktop' : 'mobile',
			'category' => 'performance',
		);
		$key  = (string) get_option( self::KEY_OPTION, '' );
		if ( '' !== $key ) {
			$args['key'] = $key;
		}
		$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT ), array( 'timeout' => 90 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'psi_http', __( 'Could not reach Google PageSpeed: ', 'speed-x-ray' ) . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 429 === $code ) {
			return new WP_Error( 'psi_quota', '' === $key ? __( 'Google limits tests made without an API key and the shared limit is used up right now. Add your own free API key below (takes two minutes) and try again.', 'speed-x-ray' ) : __( 'Your API key has reached its daily limit. Try again tomorrow.', 'speed-x-ray' ) );
		}
		if ( 200 !== $code || empty( $json['lighthouseResult'] ) ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : sprintf( /* translators: %d: HTTP status. */ __( 'Google returned HTTP %d.', 'speed-x-ray' ), $code );
			return new WP_Error( 'psi_error', $message );
		}
		return self::parse( $json );
	}

	/** Keeps only what we show from a PageSpeed v5 response. */
	public static function parse( $json ) {
		$lh     = $json['lighthouseResult'];
		$audit  = function ( $id ) use ( $lh ) {
			return isset( $lh['audits'][ $id ]['numericValue'] ) ? (float) $lh['audits'][ $id ]['numericValue'] : null;
		};
		$result = array(
			't'     => time(),
			'score' => isset( $lh['categories']['performance']['score'] ) ? (int) round( $lh['categories']['performance']['score'] * 100 ) : null,
			'lcp'   => $audit( 'largest-contentful-paint' ),
			'cls'   => $audit( 'cumulative-layout-shift' ),
			'tbt'   => $audit( 'total-blocking-time' ),
			'fcp'   => $audit( 'first-contentful-paint' ),
			'si'    => $audit( 'speed-index' ),
			'field' => null,
		);
		$exp = isset( $json['loadingExperience']['metrics'] ) ? $json['loadingExperience']['metrics'] : array();
		if ( $exp && empty( $json['loadingExperience']['origin_fallback'] ) ) {
			$p               = function ( $id ) use ( $exp ) {
				return isset( $exp[ $id ]['percentile'] ) ? (float) $exp[ $id ]['percentile'] : null;
			};
			$cls             = $p( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' );
			$result['field'] = array(
				'lcp'      => $p( 'LARGEST_CONTENTFUL_PAINT_MS' ),
				'inp'      => $p( 'INTERACTION_TO_NEXT_PAINT' ),
				'cls'      => null === $cls ? null : $cls / 100,
				'category' => isset( $json['loadingExperience']['overall_category'] ) ? $json['loadingExperience']['overall_category'] : '',
			);
		}
		return $result;
	}

	/* ---------------------------------------------------------------- data */

	public static function data() {
		$d = get_option( self::OPTION, array() );
		return is_array( $d ) ? $d : array();
	}

	/** First runs of a page go to "before"; once there are before runs, new runs go to "after". */
	public static function add_run( $path, $strategy, $run ) {
		$all   = self::data();
		$group = empty( $all[ $path ][ $strategy ]['before'] ) ? 'before' : 'after';
		$all[ $path ][ $strategy ][ $group ][] = $run;
		$all[ $path ][ $strategy ][ $group ]   = array_slice( $all[ $path ][ $strategy ][ $group ], -self::KEEP );
		update_option( self::OPTION, $all, false );
		return $group;
	}

	public static function median( $runs, $metric ) {
		$values = array_values( array_filter( wp_list_pluck( $runs, $metric ), 'is_numeric' ) );
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$n = count( $values );
		return $n % 2 ? $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2;
	}

	public static function rating( $metric, $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( 'score' === $metric ) {
			return $value >= 90 ? 'good' : ( $value >= 50 ? 'mid' : 'bad' );
		}
		if ( ! isset( self::LIMITS[ $metric ] ) ) {
			return '';
		}
		return $value <= self::LIMITS[ $metric ][0] ? 'good' : ( $value < self::LIMITS[ $metric ][1] ? 'mid' : 'bad' );
	}

	public static function format( $metric, $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( 'score' === $metric ) {
			return (string) (int) round( $value );
		}
		if ( 'cls' === $metric ) {
			return number_format_i18n( $value, 3 );
		}
		return $value >= 1000 ? number_format_i18n( $value / 1000, 1 ) . ' s' : number_format_i18n( $value ) . ' ms';
	}

	/* ------------------------------------------------------------ handlers */

	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'speedxray_psi', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'speed-x-ray' ) ), 403 );
		}
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$strategy = isset( $_POST['strategy'] ) && 'desktop' === $_POST['strategy'] ? 'desktop' : 'mobile';
		if ( ! $url || ! SpeedXRay_Helpers::is_own_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a page on this site.', 'speed-x-ray' ) ) );
		}
		if ( ! self::is_public( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Google can only test pages it can reach over the internet. This works on your live site, not on localhost or a password-protected staging site.', 'speed-x-ray' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$run = self::run( $url, $strategy );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}
		$group = self::add_run( SpeedXRay_Helpers::page_key( $url ), $strategy, $run );
		wp_send_json_success( array( 'group' => $group ) );
	}

	/** "After" becomes the new "before", to measure the next round of changes. */
	public static function reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speed-x-ray' ), 403 );
		}
		check_admin_referer( 'speedxray_psi_reset' );
		$path     = isset( $_POST['path'] ) ? SpeedXRay_Helpers::normalize_path( sanitize_text_field( wp_unslash( $_POST['path'] ) ) ) : '';
		$strategy = isset( $_POST['strategy'] ) && 'desktop' === $_POST['strategy'] ? 'desktop' : 'mobile';
		$all      = self::data();
		if ( ! empty( $all[ $path ][ $strategy ]['after'] ) ) {
			$all[ $path ][ $strategy ] = array(
				'before' => $all[ $path ][ $strategy ]['after'],
				'after'  => array(),
			);
			update_option( self::OPTION, $all, false );
		}
		wp_safe_redirect( SpeedXRay_Admin::tab_url( 'verify', array( 'report' => $path, 'psi' => $strategy ) ) );
		exit;
	}

	public static function save_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speed-x-ray' ), 403 );
		}
		check_admin_referer( 'speedxray_psi_key' );
		update_option( self::KEY_OPTION, isset( $_POST['psi_key'] ) ? sanitize_text_field( wp_unslash( $_POST['psi_key'] ) ) : '', false );
		$path = isset( $_POST['path'] ) ? SpeedXRay_Helpers::normalize_path( sanitize_text_field( wp_unslash( $_POST['path'] ) ) ) : '';
		wp_safe_redirect( SpeedXRay_Admin::tab_url( 'verify', array( 'report' => $path ) ) );
		exit;
	}

	/* -------------------------------------------------------------- screen */

	public static function render( $entry, $path ) {
		$url      = $entry ? $entry['latest']['url'] : home_url( '/' );
		$path     = $entry ? $path : SpeedXRay_Helpers::page_key( $url );
		$strategy = isset( $_GET['psi'] ) && 'desktop' === $_GET['psi'] ? 'desktop' : 'mobile'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch.
		$all      = self::data();
		$runs     = isset( $all[ $path ][ $strategy ] ) ? $all[ $path ][ $strategy ] : array();
		$before   = isset( $runs['before'] ) ? $runs['before'] : array();
		$after    = isset( $runs['after'] ) ? $runs['after'] : array();
		$public   = self::is_public( $url );

		echo '<div class="sxr-card" id="sxr-psi"><h2>' . esc_html__( 'Core Web Vitals — Google PageSpeed (lab)', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'Google loads the page on a simulated phone or desktop and measures LCP, CLS and blocking time. Your first tests become “before”; tests after your changes become “after”. Lab results vary from run to run, so run each two or three times — the median is compared.', 'speed-x-ray' ) . '</p>';

		echo '<p class="sxr-psi-switch">';
		foreach ( array( 'mobile' => __( 'Mobile', 'speed-x-ray' ), 'desktop' => __( 'Desktop', 'speed-x-ray' ) ) as $key => $label ) {
			printf( '<a class="button%s" href="%s">%s</a> ', esc_attr( $key === $strategy ? ' button-primary' : '' ), esc_url( SpeedXRay_Admin::tab_url( 'verify', array( 'report' => $path, 'psi' => $key ) ) . '#sxr-psi' ), esc_html( $label ) );
		}
		echo '</p>';

		if ( ! $public ) {
			echo '<p class="sxr-warn">' . esc_html__( 'Google can only test pages it can reach over the internet, so this works on your live site — not on localhost or a password-protected staging site.', 'speed-x-ray' ) . '</p>';
		}
		/* translators: 1: Mobile/Desktop, 2: page path. */
		echo '<p><button type="button" class="button button-primary" data-sxr-psi data-url="' . esc_attr( $url ) . '" data-strategy="' . esc_attr( $strategy ) . '"' . disabled( $public, false, false ) . '>' . esc_html( sprintf( __( 'Test %1$s with Google PageSpeed (%2$s)', 'speed-x-ray' ), 'desktop' === $strategy ? __( 'desktop', 'speed-x-ray' ) : __( 'mobile', 'speed-x-ray' ), $path ) ) . '</button> <span class="sxr-psi-msg" aria-live="polite"></span></p>';

		if ( $before ) {
			$rows = array(
				'score' => __( 'Performance score', 'speed-x-ray' ),
				'lcp'   => __( 'LCP — largest content shown', 'speed-x-ray' ),
				'cls'   => __( 'CLS — layout shift', 'speed-x-ray' ),
				'tbt'   => __( 'TBT — main thread blocked', 'speed-x-ray' ),
				'fcp'   => __( 'FCP — first content shown', 'speed-x-ray' ),
				'si'    => __( 'Speed Index', 'speed-x-ray' ),
			);
			echo '<table class="widefat striped sxr-compare"><thead><tr><th>' . esc_html__( 'Metric', 'speed-x-ray' ) . '</th>';
			/* translators: %d: number of test runs. */
			echo '<th>' . esc_html( sprintf( _n( 'Before (%d run)', 'Before (median of %d runs)', count( $before ), 'speed-x-ray' ), count( $before ) ) ) . '</th>';
			/* translators: %d: number of test runs. */
			echo '<th>' . esc_html( $after ? sprintf( _n( 'After (%d run)', 'After (median of %d runs)', count( $after ), 'speed-x-ray' ), count( $after ) ) : __( 'After', 'speed-x-ray' ) ) . '</th><th>' . esc_html__( 'Change', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $metric => $label ) {
				$b      = self::median( $before, $metric );
				$a      = $after ? self::median( $after, $metric ) : null;
				$change = '—';
				$class  = '';
				if ( null !== $a && null !== $b && $b > 0 ) {
					$pct    = (int) round( ( $a - $b ) / $b * 100 );
					$better = 'score' === $metric ? $pct > 0 : $pct < 0;
					$change = ( $pct > 0 ? '+' : '' ) . $pct . '%';
					$class  = 0 === $pct ? '' : ( $better ? 'sxr-better' : 'sxr-worse' );
				}
				printf(
					'<tr><td>%1$s</td><td><span class="sxr-rate sxr-rate-%2$s">%3$s</span></td><td><span class="sxr-rate sxr-rate-%4$s">%5$s</span></td><td class="%6$s">%7$s</td></tr>',
					esc_html( $label ),
					esc_attr( self::rating( $metric, $b ) ),
					esc_html( self::format( $metric, $b ) ),
					esc_attr( self::rating( $metric, $a ) ),
					esc_html( null === $a ? __( 'test again after your changes', 'speed-x-ray' ) : self::format( $metric, $a ) ),
					esc_attr( $class ),
					esc_html( $change )
				);
			}
			echo '</tbody></table>';

			$latest = $after ? end( $after ) : end( $before );
			if ( ! empty( $latest['field'] ) ) {
				$f = $latest['field'];
				echo '<p class="sxr-psi-field"><strong>' . esc_html__( 'Real Chrome users, last 28 days (Google):', 'speed-x-ray' ) . '</strong> ';
				echo esc_html( 'LCP ' . self::format( 'lcp', $f['lcp'] ) . ' · INP ' . ( null === $f['inp'] ? '—' : number_format_i18n( $f['inp'] ) . ' ms' ) . ' · CLS ' . ( null === $f['cls'] ? '—' : number_format_i18n( $f['cls'], 2 ) ) );
				echo ' <span class="description">' . esc_html__( 'Google updates this slowly: changes show up over about four weeks.', 'speed-x-ray' ) . '</span></p>';
			}

			if ( $after ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'speedxray_psi_reset' );
				echo '<input type="hidden" name="action" value="speedxray_psi_reset"><input type="hidden" name="path" value="' . esc_attr( $path ) . '"><input type="hidden" name="strategy" value="' . esc_attr( $strategy ) . '">';
				submit_button( __( 'Make “after” the new “before”', 'speed-x-ray' ), 'secondary small', 'submit', false );
				echo '</form>';
			}
		}

		$key = (string) get_option( self::KEY_OPTION, '' );
		echo '<details class="sxr-psi-key"' . ( '' === $key ? '' : '' ) . '><summary>' . esc_html( '' === $key ? __( 'Google API key (recommended)', 'speed-x-ray' ) : __( 'Google API key: saved', 'speed-x-ray' ) ) . '</summary>';
		echo '<p>' . esc_html__( 'Without a key, tests share a small limit with every other site and often fail. A free key gives your site its own daily limit.', 'speed-x-ray' ) . ' <a href="https://developers.google.com/speed/docs/insights/v5/get-started#APIKey" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get a free key', 'speed-x-ray' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'speedxray_psi_key' );
		echo '<input type="hidden" name="action" value="speedxray_psi_key"><input type="hidden" name="path" value="' . esc_attr( $path ) . '">';
		echo '<input type="password" class="regular-text" name="psi_key" value="' . esc_attr( $key ) . '" autocomplete="off" placeholder="AIza…"> ';
		submit_button( __( 'Save key', 'speed-x-ray' ), 'secondary', 'submit', false );
		echo '</form></details>';
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: link to Google's privacy policy. */
				__( 'Testing sends this page’s address (and your key, if set) to Google PageSpeed Insights — only when you click the button. See Google’s %s.', 'speed-x-ray' ),
				'<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy policy', 'speed-x-ray' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
		echo '</div>';
	}
}
