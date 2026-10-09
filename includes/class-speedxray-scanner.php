<?php
/**
 * Step 1: diagnosis.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads a page the way an anonymous visitor would, reads the profiler data, the HTML and
 * every local CSS file, and turns it into a list of issues. Every issue says who can fix it:
 *   auto    - Speed X-Ray can fix it (links to the step that does)
 *   manual  - something on the site must change (theme, builder, content, plugin choice)
 *   hosting - the server/host must change; no plugin can fix it
 */
class SpeedXRay_Scanner {

	const REPORTS_OPTION = 'speedxray_reports';
	const MAX_REPORTS    = 10;

	/**
	 * Scans one page.
	 *
	 * @param string $url  Page on this site.
	 * @param bool   $save Store it as the page's latest report (false for background/bulk scans).
	 * @return array Report.
	 */
	public static function run( $url, $save = true ) {
		$path   = SpeedXRay_Helpers::page_key( $url );
		$report = array(
			'url'        => $url,
			'path'       => $path,
			'time'       => time(),
			'error'      => '',
			'issues'     => array(),
			'metrics'    => array(),
			'assets'     => array(),
			'components' => array(),
			'slow'       => array(),
			'server'     => array(),
			'context'    => array(),
		);

		// 1. Profiled request: the real, uncached server cost of this exact page.
		$token = strtolower( wp_generate_password( 32, false, false ) );
		update_option(
			'speedxray_profile_token',
			array(
				'token'   => $token,
				'expires' => time() + 300,
			),
			false
		);
		$profiled = self::fetch(
			add_query_arg(
				array(
					'speedxray_profile' => $token,
					'speedxray_nc'      => wp_rand(),
				),
				$url
			),
			array( 'headers' => array( 'Cache-Control' => 'no-cache' ) )
		);
		delete_option( 'speedxray_profile_token' );
		$profile = get_transient( 'speedxray_profile_' . $token );
		delete_transient( 'speedxray_profile_' . $token );

		// 2. Plain requests: what a visitor actually gets. The first one warms any page cache.
		$compress = array(
			'headers'    => array( 'Accept-Encoding' => 'gzip, deflate, br' ),
			'decompress' => false,
		);
		self::fetch( $url, $compress );
		$plain = self::fetch( $url, $compress );

		if ( is_wp_error( $profiled['response'] ) ) {
			$report['error'] = sprintf(
				/* translators: 1: page URL, 2: error message from the HTTP request. */
				__( 'Your server could not load %1$s from itself (loopback request failed): %2$s. Page-level checks were skipped; server checks still ran.', 'speed-x-ray' ),
				$url,
				$profiled['response']->get_error_message()
			);
		} elseif ( wp_remote_retrieve_response_code( $profiled['response'] ) >= 400 ) {
			$report['error'] = sprintf(
				/* translators: %d: HTTP status code, e.g. 404. */
				__( 'The page returned HTTP %d. If the site is behind a login, maintenance mode or password protection, scan a public page instead.', 'speed-x-ray' ),
				wp_remote_retrieve_response_code( $profiled['response'] )
			);
		}

		$issues = self::server_checks( $report );

		if ( ! is_wp_error( $plain['response'] ) ) {
			$issues = array_merge( $issues, self::delivery_checks( $plain, $report ) );
		}

		$html = $report['error'] ? '' : wp_remote_retrieve_body( $profiled['response'] );
		$page = $html ? self::analyze_html( $html ) : null;

		if ( ! $report['error'] ) {
			$issues = array_merge( $issues, self::profile_checks( $profile, $report ) );
		}
		if ( $page ) {
			$assets           = is_array( $profile ) ? self::analyze_assets( $profile['assets'], $page, $path ) : array();
			$report['assets']      = $assets;
			$report['third_party'] = $page['third_party'];
			$issues                = array_merge( $issues, self::page_checks( $page, $assets ) );
		}

		$report['issues']  = $issues;
		$report['metrics'] = self::metrics( $profile, $plain, $page, $report['assets'] );

		/**
		 * Filters a finished scan report. Extensions add their own sections and issues here.
		 *
		 * @param array      $report  The report.
		 * @param array|null $profile Raw profile data from the scan request (null if it could not be collected).
		 */
		$report = apply_filters( 'speedxray_scan_report', $report, is_array( $profile ) ? $profile : null );

		if ( $save ) {
			self::save( $report );
		}
		return $report;
	}

	/* ------------------------------------------------------------------ requests */

	private static function fetch( $url, $args = array() ) {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array_replace_recursive(
				array(
					'timeout'     => 45,
					'redirection' => 3,
					// Same filter WordPress core uses for its own loopback requests (Site Health, cron).
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
					'user-agent'  => 'Mozilla/5.0 (compatible; SpeedXRay/' . SPEEDXRAY_VERSION . '; +https://wordpress.org/plugins/speed-x-ray/)',
				),
				$args
			)
		);
		return array(
			'response' => $response,
			'ms'       => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	private static function issue( $id, $severity, $title, $detail, $fix, $tab = '', $items = array() ) {
		return array(
			'id'       => $id,
			'severity' => $severity, // critical | warning | info | good.
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
			'tab'      => $tab,
			'list'     => $items,
		);
	}

	/* ------------------------------------------------------------ server checks */

	private static function server_checks( &$report ) {
		global $wpdb;
		$issues = array();

		// PHP version.
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$issues[] = self::issue(
				'php_version',
				version_compare( PHP_VERSION, '7.4', '<' ) ? 'critical' : 'warning',
				/* translators: %s: PHP version number. */
				sprintf( __( 'Old PHP version: %s', 'speed-x-ray' ), PHP_VERSION ),
				__( 'Newer PHP versions run WordPress noticeably faster and still receive security fixes. Ask your host (or use the hosting panel) to switch to PHP 8.1 or newer — test on staging first.', 'speed-x-ray' ),
				'hosting'
			);
		} else {
			/* translators: %s: PHP version number. */
			$issues[] = self::issue( 'php_version', 'good', sprintf( __( 'PHP %s', 'speed-x-ray' ), PHP_VERSION ), '', 'hosting' );
		}

		// OPcache. Some hosts restrict opcache_get_status() and it then raises a warning.
		$opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ) ) {
			$issues[] = self::issue( 'opcache', 'good', __( 'PHP OPcache is on', 'speed-x-ray' ), '', 'hosting' );
		} else {
			$issues[] = self::issue(
				'opcache',
				'warning',
				__( 'PHP OPcache is off or could not be confirmed', 'speed-x-ray' ),
				__( 'Without OPcache PHP recompiles every WordPress file on every request. Ask your host to enable it. (Some hosts hide its status; if they confirm it is on, ignore this.)', 'speed-x-ray' ),
				'hosting'
			);
		}

		// Persistent object cache.
		if ( wp_using_ext_object_cache() ) {
			$issues[] = self::issue( 'object_cache', 'good', __( 'Persistent object cache is active', 'speed-x-ray' ), '', 'hosting' );
		} else {
			$issues[] = self::issue(
				'object_cache',
				'warning',
				__( 'No persistent object cache (Redis / Memcached)', 'speed-x-ray' ),
				__( 'Every uncached page — logged-in users, cart, checkout, search — repeats the same database lookups. If your host offers Redis, enable it and install a Redis object cache plugin.', 'speed-x-ray' ),
				'hosting'
			);
		}

		// Memory limit.
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $memory > 0 && $memory < 128 * MB_IN_BYTES ) {
			$issues[] = self::issue(
				'memory_limit',
				'warning',
				/* translators: %s: PHP memory limit, e.g. 64M. */
				sprintf( __( 'Low PHP memory limit: %s', 'speed-x-ray' ), ini_get( 'memory_limit' ) ),
				__( 'Heavy themes and builders can run out of memory. Ask your host for at least 256M.', 'speed-x-ray' ),
				'hosting'
			);
		}

		// Autoloaded options: loaded on every single request, cached or not.
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$in     = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only diagnostic on core table; placeholders are built from $values.
		$autoload = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($in)", $values ) );
		$top      = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ($in) ORDER BY size DESC LIMIT 10", $values ), ARRAY_A );
		// phpcs:enable

		$report['server']['autoload_bytes'] = $autoload;
		$report['server']['autoload_top']   = $top;

		if ( $autoload > 800 * KB_IN_BYTES ) {
			$items = array();
			foreach ( array_slice( $top, 0, 5 ) as $row ) {
				$items[] = $row['option_name'] . ' — ' . SpeedXRay_Helpers::bytes( (int) $row['size'] );
			}
			$issues[] = self::issue(
				'autoload',
				$autoload > 2 * MB_IN_BYTES ? 'critical' : 'warning',
				/* translators: %s: size, e.g. 1.2 MB. */
				sprintf( __( 'Autoloaded options are %s (loaded on every request)', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $autoload ) ),
				__( 'Usually left behind by plugins you removed, or plugins storing logs/caches in options. The largest are listed below; the name prefix usually tells you which plugin owns each one. Delete leftovers of removed plugins, or ask the plugin author. Speed X-Ray does not change these automatically because deleting the wrong one breaks the site.', 'speed-x-ray' ),
				'manual',
				'database',
				$items
			);
		} else {
			/* translators: %s: size, e.g. 300 KB. */
			$issues[] = self::issue( 'autoload', 'good', sprintf( __( 'Autoloaded options size is fine (%s)', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $autoload ) ), '', 'manual' );
		}

		// Database clutter.
		$counts                 = SpeedXRay_DB_Cleaner::counts();
		$report['server']['db'] = $counts;
		$clutter                = array();
		$labels                 = SpeedXRay_DB_Cleaner::items();
		foreach ( $counts as $item => $count ) {
			$limit = 'orphan_postmeta' === $item ? 1000 : 200;
			if ( $count > $limit ) {
				$clutter[] = $labels[ $item ][0] . ': ' . number_format_i18n( $count );
			}
		}
		if ( $clutter ) {
			$issues[] = self::issue( 'db_clutter', 'warning', __( 'Database clutter found', 'speed-x-ray' ), __( 'These rows make queries and backups slower. They can be removed safely in step 4.', 'speed-x-ray' ), 'auto', 'database', $clutter );
		}

		// Plugin count.
		$active                             = count( (array) get_option( 'active_plugins', array() ) ) + count( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$report['server']['active_plugins'] = $active;
		if ( $active > 30 ) {
			$issues[] = self::issue(
				'plugin_count',
				'warning',
				/* translators: %d: number of active plugins. */
				sprintf( __( '%d active plugins', 'speed-x-ray' ), $active ),
				__( 'Every active plugin runs PHP on every request. The number alone matters less than what each one does — see "Database time by plugin / theme" below to find the expensive ones, and remove plugins you no longer use.', 'speed-x-ray' ),
				'manual'
			);
		}

		// Debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$issues[] = self::issue( 'wp_debug', 'warning', __( 'WP_DEBUG is on', 'speed-x-ray' ), __( 'Debug mode collects and often writes every notice to disk on every request. Turn it off in wp-config.php on a live site.', 'speed-x-ray' ), 'manual' );
		}

		// WP-Cron health.
		$overdue = 0;
		foreach ( array_keys( (array) _get_cron_array() ) as $timestamp ) {
			if ( $timestamp < time() - 10 * MINUTE_IN_SECONDS ) {
				++$overdue;
			}
		}
		if ( $overdue > 5 ) {
			$issues[] = self::issue(
				'cron',
				'warning',
				/* translators: %d: number of overdue scheduled tasks. */
				sprintf( _n( '%d scheduled task is overdue', '%d scheduled tasks are overdue', $overdue, 'speed-x-ray' ), $overdue ),
				__( 'WP-Cron only runs when someone visits, so tasks pile up and then run during a visitor\'s page load. Set up a real server cron job that calls wp-cron.php every 5 minutes and add define( \'DISABLE_WP_CRON\', true ); to wp-config.php.', 'speed-x-ray' ),
				'hosting'
			);
		}

		return $issues;
	}

	/* ------------------------------------------------ what visitors actually get */

	private static function delivery_checks( $plain, &$report ) {
		$issues   = array();
		$response = $plain['response'];
		$ms       = $plain['ms'];

		$cache_headers = array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-cache-status', 'x-proxy-cache', 'x-varnish', 'x-sucuri-cache', 'x-kinsta-cache', 'x-nginx-cache', 'x-srcache-fetch-status', 'x-hcdn-cache-status', 'x-wp-cache', 'age' );
		$found         = array();
		$hit           = false;
		foreach ( $cache_headers as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$found[] = $name . ': ' . $value;
			if ( false !== stripos( $value, 'hit' ) || ( 'age' === $name && (int) $value > 0 ) ) {
				$hit = true;
			}
		}
		$body     = (string) wp_remote_retrieve_body( $response );
		$encoding = (string) wp_remote_retrieve_header( $response, 'content-encoding' );
		if ( in_array( strtolower( $encoding ), array( 'gzip', 'deflate' ), true ) ) {
			$decoded = WP_Http_Encoding::decompress( $body );
			$body    = false === $decoded ? '' : (string) $decoded;
		}
		foreach ( array( 'WP-Super-Cache', 'W3 Total Cache', 'This website is like a Rocket', 'Cache Enabler', 'LiteSpeed Cache', 'WP Fastest Cache', 'Breeze' ) as $marker ) {
			if ( false !== stripos( $body, $marker ) && preg_match( '/<!--[^>]*' . preg_quote( $marker, '/' ) . '/i', $body ) ) {
				$hit = true;
				/* translators: %s: name of a caching plugin found in an HTML comment. */
				$found[] = sprintf( __( 'HTML comment: %s', 'speed-x-ray' ), $marker );
			}
		}
		$cache_plugin = defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );

		$report['server']['page_cached'] = $hit;

		if ( $hit ) {
			$issues[] = self::issue( 'page_cache', 'good', __( 'Page cache is serving this page', 'speed-x-ray' ), implode( "\n", $found ), 'manual' );
		} elseif ( $cache_plugin || $found ) {
			$detail = __( 'Visitors get the slow uncached version. Check whether this URL is excluded in your cache plugin, or whether a cookie or header is bypassing the cache.', 'speed-x-ray' );
			if ( $found ) {
				$detail .= "\n" . __( 'Headers seen:', 'speed-x-ray' ) . "\n" . implode( "\n", $found );
			}
			$issues[] = self::issue( 'page_cache', 'warning', __( 'A page cache exists but did not serve this page from cache', 'speed-x-ray' ), $detail, 'manual' );
		} else {
			$issues[] = self::issue(
				'page_cache',
				'critical',
				__( 'No page cache detected', 'speed-x-ray' ),
				__( 'Every visitor waits for PHP and the database to build the page from scratch. This is the biggest single speed fix for most sites. Use your host\'s cache if it has one; otherwise install a dedicated page cache plugin. Speed X-Ray does not do page caching itself.', 'speed-x-ray' ),
				'manual'
			);
		}

		if ( '' === $encoding ) {
			$issues[] = self::issue( 'compression', 'warning', __( 'HTML is sent without gzip/Brotli compression', 'speed-x-ray' ), __( 'Pages download several times larger than needed. Ask your host to enable gzip or Brotli (or enable it in your CDN).', 'speed-x-ray' ), 'hosting' );
		} else {
			/* translators: %s: compression method, e.g. gzip or br. */
			$issues[] = self::issue( 'compression', 'good', sprintf( __( 'Compression is on (%s)', 'speed-x-ray' ), $encoding ), '', 'hosting' );
		}

		$severity = $ms > 800 ? 'critical' : ( $ms > 400 ? 'warning' : 'good' );
		$detail   = '';
		if ( 'good' !== $severity ) {
			$detail = __( 'Measured from your server to itself, so real visitors add their network time on top.', 'speed-x-ray' ) . ' ' . (
				$hit
					? __( 'This page is cached and still slow — the server or its network is the bottleneck.', 'speed-x-ray' )
					: __( 'No cached copy was served, so this is the cost of building the page (see the server-side breakdown).', 'speed-x-ray' )
			);
		}
		$issues[] = self::issue(
			'response_time',
			$severity,
			/* translators: %d: time in milliseconds. */
			sprintf( __( 'Server response time: %d ms', 'speed-x-ray' ), $ms ),
			$detail,
			$hit ? 'hosting' : 'manual'
		);

		return $issues;
	}

	/* ----------------------------------------------------- server-side profile */

	private static function profile_checks( $profile, &$report ) {
		if ( ! is_array( $profile ) ) {
			return array(
				self::issue(
					'no_profile',
					'warning',
					__( 'Server-side profile could not be collected', 'speed-x-ray' ),
					__( 'The scan request was probably answered by a CDN or page cache that ignores query strings, or blocked by a security plugin. Timing per plugin is unavailable for this scan; file and HTML checks still ran.', 'speed-x-ray' ),
					'manual'
				),
			);
		}

		$issues  = array();
		$ms      = (int) round( $profile['server_time'] * 1000 );
		$query_s = (float) $profile['query_time'];

		$components = $profile['components'];
		uasort(
			$components,
			function ( $a, $b ) {
				return $b['time'] <=> $a['time'] ?: $b['queries'] <=> $a['queries'];
			}
		);
		$report['components'] = $components;
		$report['slow']       = $profile['slow'];
		$report['context']    = $profile['context'];

		$top = array();
		foreach ( array_slice( $components, 0, 5, true ) as $key => $c ) {
			$top[] = $profile['timed']
				/* translators: 1: plugin or theme name, 2: number of queries, 3: time in milliseconds. */
				? sprintf( __( '%1$s: %2$d queries, %3$d ms', 'speed-x-ray' ), SpeedXRay_Helpers::component_label( $key ), $c['queries'], round( $c['time'] * 1000 ) )
				/* translators: 1: plugin or theme name, 2: number of queries. */
				: sprintf( __( '%1$s: %2$d queries', 'speed-x-ray' ), SpeedXRay_Helpers::component_label( $key ), $c['queries'] );
		}

		if ( $ms > 500 ) {
			$issues[] = self::issue(
				'server_time',
				$ms > 1000 ? 'critical' : 'warning',
				/* translators: %d: time in milliseconds. */
				sprintf( __( 'Building this page takes %d ms of PHP time', 'speed-x-ray' ), $ms ),
				/* translators: %d: database time in milliseconds. */
				sprintf( __( 'This is what every uncached visit pays (logged-in users, cart, checkout, the first visit after the cache clears). Database time is %d ms of it; the rest is PHP in plugins and the theme. Biggest database users:', 'speed-x-ray' ), round( $query_s * 1000 ) ),
				'manual',
				'',
				$top
			);
		} else {
			/* translators: %d: time in milliseconds. */
			$issues[] = self::issue( 'server_time', 'good', sprintf( __( 'Page builds in %d ms', 'speed-x-ray' ), $ms ), '', 'manual' );
		}

		if ( $profile['queries'] > 150 ) {
			$issues[] = self::issue(
				'query_count',
				'warning',
				/* translators: %d: number of database queries. */
				sprintf( __( '%d database queries on one page', 'speed-x-ray' ), $profile['queries'] ),
				__( 'Usually one plugin or theme feature doing a query per item in a loop. Top sources:', 'speed-x-ray' ),
				'manual',
				'',
				$top
			);
		}

		if ( $profile['slow'] ) {
			$items = array();
			foreach ( array_slice( $profile['slow'], 0, 5 ) as $q ) {
				$items[] = round( $q['time'] * 1000 ) . ' ms — ' . SpeedXRay_Helpers::component_label( $q['component'] ) . ' — ' . substr( $q['sql'], 0, 160 );
			}
			$count    = count( $profile['slow'] );
			$issues[] = self::issue(
				'slow_queries',
				'warning',
				/* translators: %d: number of slow queries. */
				sprintf( _n( '%d slow database query (over 50 ms)', '%d slow database queries (over 50 ms)', $count, 'speed-x-ray' ), $count ),
				__( 'A slow query is usually a missing index or a plugin searching meta values. Show these to the plugin author or your developer; an object cache hides the cost but does not remove it.', 'speed-x-ray' ),
				'manual',
				'',
				$items
			);
		}

		if ( $profile['memory'] > 128 * MB_IN_BYTES ) {
			$issues[] = self::issue(
				'memory',
				'warning',
				/* translators: %s: amount of memory, e.g. 160 MB. */
				sprintf( __( 'Page uses %s of memory', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $profile['memory'] ) ),
				__( 'High memory use per request limits how many visitors the server can handle at once.', 'speed-x-ray' ),
				'manual'
			);
		}

		return $issues;
	}

	/* --------------------------------------------------------------- HTML scan */

	private static function analyze_html( $html ) {
		$r = array(
			'html_bytes'       => strlen( $html ),
			'dom_nodes'        => 0,
			'blocking_scripts' => array(),
			'stylesheets'      => 0,
			'images'           => 0,
			'img_no_dims'      => 0,
			'img_not_lazy'     => 0,
			'first_img_lazy'   => false,
			'legacy_images'    => 0,
			'iframes_not_lazy' => 0,
			'third_party'      => array(),
			'inline_js'        => 0,
			'inline_css'       => 0,
			'emoji'            => false !== strpos( $html, '_wpemojiSettings' ),
			'gfonts_no_swap'   => false,
			'block_markup'     => false,
			'classes'          => array(),
			'ids'              => array(),
			'dom_ok'           => class_exists( 'DOMDocument' ),
		);
		if ( ! $r['dom_ok'] ) {
			return $r;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$hosts = SpeedXRay_Helpers::site_hosts();
		$third = array();
		$track = function ( $url ) use ( &$third, $hosts ) {
			$url = trim( (string) $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return;
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host && ! in_array( $host, $hosts, true ) ) {
				$third[ $host ] = ( isset( $third[ $host ] ) ? $third[ $host ] : 0 ) + 1;
			}
		};

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property names.
		$all            = $dom->getElementsByTagName( '*' );
		$r['dom_nodes'] = $all->length;
		foreach ( $all as $el ) {
			$class = trim( $el->getAttribute( 'class' ) );
			if ( '' !== $class ) {
				foreach ( preg_split( '/\s+/', $class ) as $c ) {
					$r['classes'][ $c ] = true;
					if ( 0 === strpos( $c, 'wp-block-' ) ) {
						$r['block_markup'] = true;
					}
				}
			}
			$id = $el->getAttribute( 'id' );
			if ( '' !== $id ) {
				$r['ids'][ $id ] = true;
			}
		}

		$head = $dom->getElementsByTagName( 'head' )->item( 0 );

		foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
			$src  = $script->getAttribute( 'src' );
			$type = strtolower( $script->getAttribute( 'type' ) );
			if ( $src ) {
				$track( $src );
				if ( $head && self::inside( $script, $head ) && ! $script->hasAttribute( 'async' ) && ! $script->hasAttribute( 'defer' ) && 'module' !== $type ) {
					$r['blocking_scripts'][] = $src;
				}
			} elseif ( '' === $type || false !== strpos( $type, 'javascript' ) || 'module' === $type ) {
				$r['inline_js'] += strlen( $script->textContent );
			}
		}

		foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
			$rel  = strtolower( $link->getAttribute( 'rel' ) );
			$href = $link->getAttribute( 'href' );
			if ( false !== strpos( $rel, 'stylesheet' ) ) {
				++$r['stylesheets'];
				$track( $href );
				if ( false !== strpos( $href, 'fonts.googleapis.com' ) && false === strpos( $href, 'display=' ) ) {
					$r['gfonts_no_swap'] = true;
				}
			} elseif ( 'preload' === $rel ) {
				$track( $href );
			}
		}

		foreach ( $dom->getElementsByTagName( 'style' ) as $style ) {
			$r['inline_css'] += strlen( $style->textContent );
		}
		// phpcs:enable

		$index = 0;
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				$src = $img->getAttribute( 'data-src' ); // Lazy-load libraries swap this in later.
			}
			$track( $src );
			++$r['images'];
			$lazy = 'lazy' === strtolower( $img->getAttribute( 'loading' ) ) || $img->hasAttribute( 'data-src' ) || $img->hasAttribute( 'data-lazy-src' );
			if ( ! $img->hasAttribute( 'width' ) || ! $img->hasAttribute( 'height' ) ) {
				++$r['img_no_dims'];
			}
			if ( 0 === $index && $lazy ) {
				$r['first_img_lazy'] = true;
			}
			if ( $index >= 3 && ! $lazy ) {
				++$r['img_not_lazy'];
			}
			if ( preg_match( '/\.(jpe?g|png|gif)$/i', (string) wp_parse_url( $src, PHP_URL_PATH ) ) && ! self::served_modern( $img, $src ) ) {
				++$r['legacy_images'];
			}
			++$index;
		}

		foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
			$track( $iframe->getAttribute( 'src' ) );
			if ( 'lazy' !== strtolower( $iframe->getAttribute( 'loading' ) ) ) {
				++$r['iframes_not_lazy'];
			}
		}

		arsort( $third );
		$r['third_party'] = $third;
		return $r;
	}

	/** A JPEG/PNG <img> that browsers actually receive as WebP/AVIF (via <picture> or the server rewrite). */
	private static function served_modern( $img, $src ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		$parent = $img->parentNode;
		if ( $parent && 'picture' === strtolower( $parent->nodeName ) ) {
			foreach ( $parent->getElementsByTagName( 'source' ) as $source ) {
				if ( in_array( strtolower( $source->getAttribute( 'type' ) ), array( 'image/webp', 'image/avif' ), true ) ) {
					return true;
				}
			}
		}
		if ( 'htaccess' === SpeedXRay_Images::settings()['serve'] && SpeedXRay_Images::settings()['enabled'] ) {
			$path = SpeedXRay_Helpers::url_to_local_path( $src );
			return $path && ( is_file( $path . '.webp' ) || is_file( $path . '.avif' ) );
		}
		return false;
	}

	private static function inside( $node, $ancestor ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property name.
		for ( $n = $node->parentNode; $n; $n = $n->parentNode ) {
			if ( $n->isSameNode( $ancestor ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------- CSS/JS files */

	private static function analyze_assets( $raw, $page, $path ) {
		// Dependency map including aliases (e.g. "jquery" -> jquery-core + jquery-migrate).
		$deps    = array();
		$aliases = array();
		foreach ( $raw as $a ) {
			$deps[ $a['type'] ][ $a['handle'] ] = $a['deps'];
			if ( $a['alias'] ) {
				$aliases[ $a['handle'] ] = true;
			}
		}

		$assets = array();
		foreach ( $raw as $a ) {
			if ( $a['alias'] ) {
				continue;
			}
			$owner = SpeedXRay_Helpers::owner_from_url( $a['src'] );
			$file  = SpeedXRay_Helpers::url_to_local_path( $a['src'] );
			$item  = array(
				'type'        => $a['type'],
				'handle'      => $a['handle'],
				'src'         => $a['src'],
				'owner'       => $owner,
				'size'        => $file ? filesize( $file ) : null,
				'position'    => 'style' === $a['type'] ? 'head' : ( $a['footer'] ? 'footer' : 'head' ),
				'strategy'    => $a['strategy'],
				'usage'       => null,
				'status'      => 'used',
				'required_by' => self::required_by( $a['type'], $a['handle'], $deps, $aliases ),
				'rule'        => SpeedXRay_Asset_Manager::matching_rule( $a['type'], $a['handle'], $path ),
			);

			if ( 'third-party' === $owner['type'] ) {
				$item['status'] = 'third-party';
			} elseif ( 'core' === $owner['type'] ) {
				$item['status'] = 'core';
			} elseif ( 'style' === $a['type'] && $file ) {
				$item['usage'] = self::css_usage( $file, $page );
				if ( $item['usage'] ) {
					$used  = $item['usage']['used'];
					$total = $item['usage']['total'];
					if ( $total >= 5 && 0 === $used ) {
						$item['status'] = 'unused';
					} elseif ( $total >= 20 && $used / $total < 0.05 ) {
						$item['status'] = 'mostly_unused';
					}
				}
			} elseif ( 'script' === $a['type'] ) {
				$item['status'] = 'review';
			}
			// Bytes minifying would save, for local plugin/theme files that ship unminified.
			$item['min_saving'] = ( $file && ! in_array( $owner['type'], array( 'core', 'third-party' ), true ) ) ? SpeedXRay_Minify::saving( $file, 'style' === $a['type'] ? 'css' : 'js' ) : 0;
			$assets[]           = $item;
		}

		// A plugin whose every stylesheet here is unused almost certainly doesn't need its JS here either.
		$css_state = array();
		foreach ( $assets as $item ) {
			if ( 'style' === $item['type'] && in_array( $item['owner']['type'], array( 'plugin', 'mu-plugin' ), true ) ) {
				$key               = $item['owner']['slug'];
				$css_state[ $key ] = ( isset( $css_state[ $key ] ) ? $css_state[ $key ] : true ) && 'unused' === $item['status'];
			}
		}
		foreach ( $assets as &$item ) {
			if ( 'review' === $item['status'] && ! empty( $css_state[ $item['owner']['slug'] ] ) ) {
				$item['status'] = 'likely_unused';
			}
		}
		unset( $item );

		$order = array_flip( array( 'unused', 'likely_unused', 'mostly_unused', 'review', 'used', 'third-party', 'core' ) );
		usort(
			$assets,
			function ( $a, $b ) use ( $order ) {
				return $order[ $a['status'] ] <=> $order[ $b['status'] ] ?: (int) $b['size'] <=> (int) $a['size'];
			}
		);
		return $assets;
	}

	/** Loaded, non-alias handles that (directly or through aliases) depend on this one. */
	private static function required_by( $type, $handle, $deps, $aliases ) {
		$out   = array();
		$queue = array( $handle );
		$seen  = array( $handle => true );
		while ( $queue ) {
			$target = array_shift( $queue );
			foreach ( $deps[ $type ] as $h => $d ) {
				if ( isset( $seen[ $h ] ) || ! in_array( $target, $d, true ) ) {
					continue;
				}
				$seen[ $h ] = true;
				$queue[]    = $h; // Walk through aliases and chains.
				if ( ! isset( $aliases[ $h ] ) ) {
					$out[] = $h;
				}
			}
		}
		return $out;
	}

	/**
	 * How many class/id selectors in a stylesheet match an element in this page's HTML.
	 * Heuristic by design: classes added later by JavaScript (popups, menus, sliders) are
	 * not in the HTML, so "unused" means "verify", never "delete blindly".
	 */
	private static function css_usage( $file, $page ) {
		if ( filesize( $file ) > 1500 * KB_IN_BYTES ) {
			return null;
		}
		$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local stylesheet, not a remote URL.
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! preg_match_all( '/([^{}]+)\{/', $css, $m ) ) {
			return null;
		}
		$tokens = array();
		foreach ( $m[1] as $prelude ) {
			$prelude = trim( $prelude );
			if ( '' === $prelude || '@' === $prelude[0] ) {
				continue;
			}
			$prelude = preg_replace( '/\[[^\]]*\]|"[^"]*"|\'[^\']*\'/', '', $prelude );
			if ( preg_match_all( '/([.#])(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $prelude, $t, PREG_SET_ORDER ) ) {
				foreach ( $t as $x ) {
					$tokens[ $x[1] . $x[2] ] = true;
				}
			}
		}
		if ( ! $tokens ) {
			return null;
		}
		$used = 0;
		foreach ( array_keys( $tokens ) as $token ) {
			$name = substr( $token, 1 );
			if ( '.' === $token[0] ? isset( $page['classes'][ $name ] ) : isset( $page['ids'][ $name ] ) ) {
				++$used;
			}
		}
		return array(
			'used'  => $used,
			'total' => count( $tokens ),
		);
	}

	/* ------------------------------------------------------------- page checks */

	private static function page_checks( $page, $assets ) {
		$issues = array();

		if ( ! $page['dom_ok'] ) {
			$issues[] = self::issue( 'no_dom', 'info', __( 'PHP DOM extension missing', 'speed-x-ray' ), __( 'HTML-level checks were skipped. Ask your host to enable the php-xml / dom extension.', 'speed-x-ray' ), 'hosting' );
			return $issues;
		}

		// Builder / theme bloat.
		if ( $page['dom_nodes'] > 1500 ) {
			$issues[] = self::issue(
				'dom_size',
				$page['dom_nodes'] > 3000 ? 'critical' : 'warning',
				/* translators: %s: number of HTML elements. */
				sprintf( __( 'Very large page structure: %s HTML elements', 'speed-x-ray' ), number_format_i18n( $page['dom_nodes'] ) ),
				__( 'Typical of page builders nesting sections → columns → wrappers. The browser must lay out and repaint all of them, which hurts mobile most. Fix it in the builder: fewer nested sections, Flexbox/Container layouts (Elementor: enable "Optimized DOM Output" and "Flexbox Container"), and no hidden-on-mobile duplicates. No plugin can safely rewrite this for you.', 'speed-x-ray' ),
				'manual'
			);
		}
		if ( $page['html_bytes'] > 250 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'html_size',
				'warning',
				/* translators: %s: size, e.g. 300 KB. */
				sprintf( __( 'HTML document is %s', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $page['html_bytes'] ) ),
				__( 'Large HTML delays the first paint. Usually inline CSS/JS from the builder, or long pages with many hidden sections.', 'speed-x-ray' ),
				'manual'
			);
		}
		if ( $page['inline_css'] + $page['inline_js'] > 100 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'inline_code',
				'warning',
				/* translators: 1: size of inline CSS, 2: size of inline JavaScript. */
				sprintf( __( 'Inline code in HTML: %1$s CSS, %2$s JS', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $page['inline_css'] ), SpeedXRay_Helpers::bytes( $page['inline_js'] ) ),
				__( 'Inline code is downloaded again on every page and cannot be cached by the browser. Many builders have a setting to print CSS to an external file instead.', 'speed-x-ray' ),
				'manual'
			);
		}

		// Render-blocking scripts.
		$blocking = count( $page['blocking_scripts'] );
		if ( $blocking ) {
			$items = array();
			foreach ( array_slice( $page['blocking_scripts'], 0, 8 ) as $src ) {
				$items[] = SpeedXRay_Helpers::owner_from_url( $src )['label'] . ' — ' . basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			}
			$issues[] = self::issue(
				'blocking_js',
				$blocking > 5 ? 'critical' : 'warning',
				/* translators: %d: number of scripts. */
				sprintf( _n( '%d render-blocking script in <head>', '%d render-blocking scripts in <head>', $blocking, 'speed-x-ray' ), $blocking ),
				__( 'The browser stops drawing the page until each of these downloads and runs.', 'speed-x-ray' ) . ' ' . (
					SpeedXRay_Optimizations::defer_supported()
						? __( 'Turn on "Defer JavaScript" in step 3.', 'speed-x-ray' )
						: __( 'Update WordPress to 6.3 or newer to use the safe defer fix.', 'speed-x-ray' )
				),
				SpeedXRay_Optimizations::defer_supported() ? 'auto' : 'manual',
				'optimize',
				$items
			);
		}

		// Unused files.
		$unused = array_filter(
			$assets,
			function ( $a ) {
				return in_array( $a['status'], array( 'unused', 'likely_unused', 'mostly_unused' ), true ) && ! $a['required_by'];
			}
		);
		if ( $unused ) {
			$bytes = 0;
			$items = array();
			foreach ( $unused as $a ) {
				$bytes  += (int) $a['size'];
				$items[] = $a['owner']['label'] . ' — ' . $a['handle'] . ' (' . ( 'script' === $a['type'] ? 'JS' : 'CSS' ) . ', ' . SpeedXRay_Helpers::bytes( $a['size'] ) . ')';
			}
			$count    = count( $unused );
			$issues[] = self::issue(
				'unused_assets',
				$count > 5 ? 'critical' : 'warning',
				/* translators: 1: number of files, 2: total size. */
				sprintf( _n( '%1$d file loads here but looks unused (%2$s)', '%1$d files load here but look unused (%2$s)', $count, 'speed-x-ray' ), $count, SpeedXRay_Helpers::bytes( $bytes ) ),
				__( 'Plugins load their CSS/JS on every page even where their feature is not used. Review and stop them per page in step 2.', 'speed-x-ray' ),
				'auto',
				'assets',
				array_slice( $items, 0, 10 )
			);
		}

		// Files shipped unminified.
		foreach ( array( 'style' => 'minify_css', 'script' => 'minify_js' ) as $type => $fix ) {
			if ( SpeedXRay_Optimizations::enabled( $fix ) ) {
				continue;
			}
			$saving = 0;
			$items  = array();
			foreach ( $assets as $a ) {
				if ( $type === $a['type'] && ! empty( $a['min_saving'] ) ) {
					$saving += $a['min_saving'];
					$items[] = $a['owner']['label'] . ' — ' . basename( (string) wp_parse_url( $a['src'], PHP_URL_PATH ) ) . ' (−' . SpeedXRay_Helpers::bytes( $a['min_saving'] ) . ')';
				}
			}
			if ( $saving >= 2 * KB_IN_BYTES ) {
				$issues[] = self::issue(
					$fix . '_files',
					'info',
					'style' === $type
						/* translators: 1: number of files, 2: size. */
						? sprintf( _n( '%1$d CSS file is not minified — %2$s can be saved', '%1$d CSS files are not minified — %2$s can be saved', count( $items ), 'speed-x-ray' ), count( $items ), SpeedXRay_Helpers::bytes( $saving ) )
						/* translators: 1: number of files, 2: size. */
						: sprintf( _n( '%1$d JavaScript file is not minified — %2$s can be saved', '%1$d JavaScript files are not minified — %2$s can be saved', count( $items ), 'speed-x-ray' ), count( $items ), SpeedXRay_Helpers::bytes( $saving ) ),
					__( 'Sizes before compression; with gzip on, the real saving is smaller but still worth it. Turn on minifying in step 3.', 'speed-x-ray' ),
					'auto',
					'optimize',
					array_slice( $items, 0, 8 )
				);
			}
		}

		// Weight.
		$js = 0;
		foreach ( $assets as $a ) {
			if ( 'script' === $a['type'] ) {
				$js += (int) $a['size'];
			}
		}
		if ( $js > 500 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'js_weight',
				'warning',
				/* translators: %s: size, e.g. 800 KB. */
				sprintf( __( 'Local JavaScript on this page: %s (uncompressed)', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( $js ) ),
				__( 'JavaScript is the most expensive resource on phones. Stop unused files in step 2; for the rest, question whether each feature (sliders, animations, popups) is worth it.', 'speed-x-ray' ),
				'manual',
				'assets'
			);
		}
		if ( $page['stylesheets'] > 20 ) {
			$issues[] = self::issue(
				'css_count',
				'warning',
				/* translators: %d: number of stylesheets. */
				sprintf( __( '%d separate stylesheets', 'speed-x-ray' ), $page['stylesheets'] ),
				__( 'Each stylesheet in <head> blocks rendering. Stopping unused ones (step 2) is the safe way to reduce this.', 'speed-x-ray' ),
				'auto',
				'assets'
			);
		}

		// Specific quick wins.
		if ( $page['emoji'] ) {
			$issues[] = self::issue( 'disable_emojis', 'info', __( 'WordPress emoji script is loaded', 'speed-x-ray' ), __( 'Not needed by modern browsers.', 'speed-x-ray' ), 'auto', 'optimize' );
		}
		if ( $page['gfonts_no_swap'] ) {
			$issues[] = self::issue( 'google_fonts_swap', 'warning', __( 'Google Fonts without display=swap', 'speed-x-ray' ), __( 'Text stays invisible until the font file arrives.', 'speed-x-ray' ), 'auto', 'optimize' );
		}
		$handles = wp_list_pluck( $assets, 'handle' );
		if ( in_array( 'jquery-migrate', $handles, true ) ) {
			$issues[] = self::issue( 'remove_jquery_migrate', 'info', __( 'jQuery Migrate is loaded', 'speed-x-ray' ), __( 'Only needed by very old jQuery code.', 'speed-x-ray' ), 'auto', 'optimize' );
		}
		if ( in_array( 'wp-block-library', $handles, true ) && ! $page['block_markup'] ) {
			$issues[] = self::issue( 'unload_block_css', 'warning', __( 'Block editor CSS is loaded but this page has no blocks', 'speed-x-ray' ), __( 'Common on Elementor, Divi, WPBakery and classic-theme sites.', 'speed-x-ray' ), 'auto', 'optimize' );
		}
		if ( in_array( 'dashicons', $handles, true ) ) {
			$issues[] = self::issue( 'dashicons_guests', 'info', __( 'Dashicons (admin icon font) is loaded for visitors', 'speed-x-ray' ), '', 'auto', 'optimize' );
		}
		if ( in_array( 'wp-embed', $handles, true ) ) {
			$issues[] = self::issue( 'disable_embeds', 'info', __( 'wp-embed script is loaded', 'speed-x-ray' ), '', 'auto', 'optimize' );
		}

		// Images.
		if ( $page['first_img_lazy'] ) {
			$issues[] = self::issue(
				'lcp_lazy',
				'warning',
				__( 'The first image on the page is lazy-loaded', 'speed-x-ray' ),
				__( 'If this is your hero/banner image (usually the Largest Contentful Paint), lazy-loading delays it. Exclude it from lazy-loading in your theme, builder or lazy-load plugin.', 'speed-x-ray' ),
				'manual'
			);
		}
		if ( $page['img_no_dims'] > 0 ) {
			$issues[] = self::issue(
				'img_dims',
				'warning',
				/* translators: %d: number of images. */
				sprintf( _n( '%d image without width/height', '%d images without width/height', $page['img_no_dims'], 'speed-x-ray' ), $page['img_no_dims'] ),
				__( 'Content jumps while images load (Cumulative Layout Shift). Set image dimensions in the builder, or re-insert the images through the media library.', 'speed-x-ray' ),
				'manual'
			);
		}
		if ( $page['img_not_lazy'] > 3 ) {
			$issues[] = self::issue(
				'img_lazy',
				'warning',
				/* translators: %d: number of images. */
				sprintf( __( '%d below-the-fold images are not lazy-loaded', 'speed-x-ray' ), $page['img_not_lazy'] ),
				__( 'WordPress lazy-loads content images, but builders and sliders often bypass it. Enable lazy-loading in your builder or theme settings.', 'speed-x-ray' ),
				'manual'
			);
		}
		if ( $page['legacy_images'] > 3 ) {
			$on       = (bool) SpeedXRay_Images::settings()['enabled'];
			$issues[] = self::issue(
				'img_format',
				$page['legacy_images'] > 10 ? 'warning' : 'info',
				/* translators: %d: number of images. */
				sprintf( __( '%d images are sent as JPG/PNG/GIF', 'speed-x-ray' ), $page['legacy_images'] ),
				$on
					? __( 'WebP is on, so these are images uploaded before you turned it on, images from outside the media library, or GIFs. Speed X-Ray Pro converts your existing library in bulk.', 'speed-x-ray' )
					: __( 'WebP copies are smaller — a little for detailed photos, a lot for graphics and PNGs. Turn on modern image formats in step 6 to see the real saving on your images; originals are never changed.', 'speed-x-ray' ),
				$on ? 'manual' : 'auto',
				'images'
			);
		}
		if ( $page['iframes_not_lazy'] > 0 ) {
			$issues[] = self::issue(
				'iframes',
				'info',
				/* translators: %d: number of iframes. */
				sprintf( _n( '%d iframe (video/map) loads immediately', '%d iframes (videos/maps) load immediately', $page['iframes_not_lazy'], 'speed-x-ray' ), $page['iframes_not_lazy'] ),
				__( 'Embedded videos and maps are heavy. Use a click-to-load preview or add loading="lazy".', 'speed-x-ray' ),
				'manual'
			);
		}

		// Third parties.
		$domains = count( $page['third_party'] );
		if ( $domains > 5 ) {
			$items = array();
			foreach ( array_slice( $page['third_party'], 0, 10, true ) as $host => $n ) {
				$items[] = $host . ' (' . $n . ')';
			}
			$issues[] = self::issue(
				'third_party',
				'warning',
				/* translators: %d: number of external domains. */
				sprintf( __( '%d external domains contacted', 'speed-x-ray' ), $domains ),
				__( 'Each domain costs a DNS lookup and a connection, and their scripts (tracking, chat, ads) are often the heaviest thing on the page. Remove what you do not use, and load chat widgets and pixels with a delay.', 'speed-x-ray' ),
				'manual',
				'',
				$items
			);
		}

		return $issues;
	}

	/* ---------------------------------------------------------------- metrics */

	private static function metrics( $profile, $plain, $page, $assets ) {
		$m = array(
			'server_ms'        => is_array( $profile ) ? (int) round( $profile['server_time'] * 1000 ) : null,
			'response_ms'      => is_wp_error( $plain['response'] ) ? null : $plain['ms'],
			'queries'          => is_array( $profile ) ? (int) $profile['queries'] : null,
			'query_ms'         => is_array( $profile ) && $profile['timed'] ? (int) round( $profile['query_time'] * 1000 ) : null,
			'memory'           => is_array( $profile ) ? (int) $profile['memory'] : null,
			'js_files'         => 0,
			'js_bytes'         => 0,
			'css_files'        => 0,
			'css_bytes'        => 0,
			'html_bytes'       => $page ? $page['html_bytes'] : null,
			'dom_nodes'        => $page ? $page['dom_nodes'] : null,
			'blocking_scripts' => $page ? count( $page['blocking_scripts'] ) : null,
			'third_party'      => $page ? count( $page['third_party'] ) : null,
		);
		foreach ( $assets as $a ) {
			$prefix = 'script' === $a['type'] ? 'js' : 'css';
			++$m[ $prefix . '_files' ];
			$m[ $prefix . '_bytes' ] += (int) $a['size'];
		}
		return $m;
	}

	/* ---------------------------------------------------------------- storage */

	public static function reports() {
		$reports = get_option( self::REPORTS_OPTION, array() );
		return is_array( $reports ) ? $reports : array();
	}

	private static function save( $report ) {
		$reports = self::reports();
		$path    = $report['path'];
		$entry   = isset( $reports[ $path ] ) ? $reports[ $path ] : array(
			'baseline' => null,
			'latest'   => null,
		);
		if ( ! $entry['baseline'] && ! $report['error'] ) {
			$entry['baseline'] = $report;
		}
		$entry['latest'] = $report;
		unset( $reports[ $path ] );
		$reports = array( $path => $entry ) + $reports; // Most recent first.
		update_option( self::REPORTS_OPTION, array_slice( $reports, 0, self::MAX_REPORTS, true ), false );
		update_option( 'speedxray_last_path', $path, false );
	}

	public static function reset_baseline( $path ) {
		$reports = self::reports();
		if ( isset( $reports[ $path ]['latest'] ) ) {
			$reports[ $path ]['baseline'] = $reports[ $path ]['latest'];
			update_option( self::REPORTS_OPTION, $reports, false );
		}
	}
}
