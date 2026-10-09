<?php
/**
 * Server-side profiler for scan requests.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs only on a scan request (?speedxray_profile=<one-time token>). Measures server time,
 * memory, every database query attributed to the plugin/theme that made it, and the
 * exact CSS/JS files WordPress printed. Results go to a short-lived transient the
 * scanner reads back.
 *
 * The scan request is made by the server to itself without a logged-in session, so it is
 * authorised by a random single-use token stored in an option (valid 5 minutes) rather
 * than a nonce. The token only switches on measurement; it grants no other access.
 */
class SpeedXRay_Profiler {

	private static $token      = null;
	private static $timed      = false;
	private static $queries    = 0;
	private static $query_time = 0.0;
	private static $components = array();
	private static $slow       = array();

	public static function maybe_start() {
		if ( empty( $_GET['speedxray_profile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-authorised, see class comment.
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['speedxray_profile'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-authorised, see class comment.
		$saved = get_option( 'speedxray_profile_token' );
		if ( ! is_array( $saved ) || empty( $saved['token'] ) || (int) $saved['expires'] < time() || ! hash_equals( $saved['token'], $token ) ) {
			return;
		}

		self::$token = $token;

		// Needed for per-query timing. Only ever set on this single token-authorised request.
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		}
		self::$timed = (bool) SAVEQUERIES;
		if ( self::$timed ) {
			add_filter( 'log_query_custom_data', array( __CLASS__, 'on_logged_query' ), 10, 5 );
		} else {
			// SAVEQUERIES was forced off in wp-config.php: we can still count, just not time.
			add_filter( 'query', array( __CLASS__, 'on_query' ) );
		}

		// A scan must measure the uncached page, never a cached copy.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard constant honoured by page cache plugins.
		}
		add_action( 'send_headers', 'nocache_headers' );
		add_action( 'shutdown', array( __CLASS__, 'save' ), PHP_INT_MAX );
	}

	public static function is_active() {
		return null !== self::$token;
	}

	public static function on_logged_query( $data, $query, $time, $callstack, $start ) {
		self::record( (float) $time, $query, $callstack );
		return $data;
	}

	public static function on_query( $query ) {
		self::record( 0.0, $query, '' );
		return $query;
	}

	private static function record( $time, $query, $callstack ) {
		$component = self::component();
		if ( ! isset( self::$components[ $component ] ) ) {
			self::$components[ $component ] = array(
				'queries' => 0,
				'time'    => 0.0,
			);
		}
		self::$components[ $component ]['queries']++;
		self::$components[ $component ]['time'] += $time;
		self::$queries++;
		self::$query_time += $time;

		if ( $time >= 0.05 ) {
			self::$slow[] = array(
				'sql'       => substr( trim( preg_replace( '/\s+/', ' ', $query ) ), 0, 400 ),
				'time'      => $time,
				'component' => $component,
				'caller'    => substr( (string) $callstack, -300 ),
			);
			if ( count( self::$slow ) > 50 ) {
				self::$slow = self::top_slow( 20 );
			}
		}
	}

	/** First plugin/theme file in the call stack, walking outward from wpdb. */
	private static function component() {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- attributes each query to the plugin/theme that made it; scan requests only.
			if ( empty( $frame['file'] ) || __FILE__ === $frame['file'] ) {
				continue;
			}
			$owner = SpeedXRay_Helpers::owner_from_path( $frame['file'] );
			if ( 'core' !== $owner['type'] ) {
				return $owner['type'] . ':' . $owner['slug'];
			}
		}
		return 'core:wordpress';
	}

	private static function top_slow( $limit ) {
		$slow = self::$slow;
		usort(
			$slow,
			function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		return array_slice( $slow, 0, $limit );
	}

	public static function save() {
		global $wpdb;

		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		/**
		 * Filters the data collected on a scan request before the scanner reads it.
		 * Extensions (such as Speed X-Ray Pro) add their own measurements here.
		 *
		 * @param array $data Profile data.
		 */
		$data = apply_filters(
			'speedxray_profile_data',
			array(
				'server_time'  => microtime( true ) - $start,
				'memory'       => memory_get_peak_usage( true ),
				'queries'      => max( self::$queries, (int) $wpdb->num_queries ),
				'query_time'   => self::$query_time,
				'timed'        => self::$timed,
				'components'   => self::$components,
				'slow'         => self::top_slow( 15 ),
				'assets'       => self::collect_assets(),
				'disabled'     => SpeedXRay_Asset_Manager::disabled_log(),
				'object_cache' => (bool) wp_using_ext_object_cache(),
				'context'      => array(
					'template'   => self::template_type(),
					'has_blocks' => is_singular() ? has_blocks( get_post() ) : null,
				),
			)
		);
		set_transient( 'speedxray_profile_' . self::$token, $data, 10 * MINUTE_IN_SECONDS );
	}

	/** The one-time token of the current scan request, or null. */
	public static function token() {
		return self::$token;
	}

	/** Every CSS/JS handle WordPress actually printed on this page, aliases included (for dependency maps). */
	private static function collect_assets() {
		$out = array();
		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $type => $deps ) {
			foreach ( (array) $deps->done as $handle ) {
				if ( empty( $deps->registered[ $handle ] ) ) {
					continue;
				}
				$obj   = $deps->registered[ $handle ];
				$out[] = array(
					'type'     => $type,
					'handle'   => $handle,
					'src'      => $obj->src ? SpeedXRay_Helpers::absolute_url( $obj->src ) : '',
					'alias'    => ! $obj->src,
					'deps'     => array_values( (array) $obj->deps ),
					'footer'   => 'script' === $type && in_array( $handle, (array) $deps->in_footer, true ),
					'strategy' => 'script' === $type ? (string) $deps->get_data( $handle, 'strategy' ) : '',
				);
			}
		}
		return $out;
	}

	private static function template_type() {
		if ( is_front_page() ) {
			return 'front page';
		}
		if ( is_home() ) {
			return 'blog index';
		}
		if ( is_singular() ) {
			return 'single ' . get_post_type();
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		if ( is_404() ) {
			return '404';
		}
		return 'other';
	}
}
