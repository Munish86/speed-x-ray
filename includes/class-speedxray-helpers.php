<?php
/**
 * Shared helpers.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small shared utilities: who owns a file, URL <-> path mapping, bypass checks.
 */
class SpeedXRay_Helpers {

	const CHANGES = 'speedxray_changes';

	private static $owner_cache = array();
	private static $plugin_names = null;
	private static $bypass = null;

	/**
	 * Records something the user changed (a fix turned on, a file stopped, images converted…),
	 * so before/after comparisons can show what happened in between.
	 *
	 * @param string $label Human-readable description.
	 * @param string $type  fix | undo | other.
	 */
	public static function log_change( $label, $type = 'fix' ) {
		$changes = self::changes();
		array_unshift(
			$changes,
			array(
				't'     => time(),
				'label' => $label,
				'type'  => $type,
			)
		);
		update_option( self::CHANGES, array_slice( $changes, 0, 100 ), false );

		/**
		 * Fires after a change was logged.
		 *
		 * @param string $label Description.
		 * @param string $type  fix | undo | other.
		 */
		do_action( 'speedxray_change_logged', $label, $type );
	}

	/** Logged changes, newest first; optionally only those after a timestamp. */
	public static function changes( $since = 0 ) {
		$changes = get_option( self::CHANGES, array() );
		$changes = is_array( $changes ) ? $changes : array();
		if ( $since ) {
			$changes = array_values(
				array_filter(
					$changes,
					function ( $c ) use ( $since ) {
						return $c['t'] > $since;
					}
				)
			);
		}
		return $changes;
	}

	/**
	 * True when fixes must not run for this request: the emergency pause is on,
	 * or an admin is viewing with ?speedxray_off=1 to compare against the untouched site.
	 */
	public static function is_bypassed() {
		if ( get_option( 'speedxray_paused' ) ) {
			return true;
		}
		if ( null === self::$bypass ) {
			self::$bypass = ! empty( $_GET['speedxray_off'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return self::$bypass;
	}

	public static function site_hosts() {
		$hosts = array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		);
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	public static function is_own_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return $host && in_array( $host, self::site_hosts(), true );
	}

	/** Turns the relative/protocol-relative src WordPress stores into a full URL. */
	public static function absolute_url( $src ) {
		$src = (string) $src;
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return untrailingslashit( site_url() ) . $src;
		}
		return $src;
	}

	/**
	 * Who ships the file at this URL.
	 *
	 * @return array{type:string,slug:string,label:string} type is plugin|mu-plugin|theme|core|generated|third-party|other.
	 */
	public static function owner_from_url( $url ) {
		$url  = self::absolute_url( $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( $host && ! in_array( $host, self::site_hosts(), true ) ) {
			return self::owner( 'third-party', $host );
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$roots = array(
			'plugin'    => plugins_url(),
			'mu-plugin' => WPMU_PLUGIN_URL,
			'theme'     => get_theme_root_uri(),
		);
		foreach ( $roots as $type => $root_url ) {
			$slug = self::segment_after( $path, (string) wp_parse_url( $root_url, PHP_URL_PATH ) );
			if ( $slug ) {
				return self::owner( $type, $slug );
			}
		}

		if ( false !== strpos( $path, '/wp-includes/' ) || false !== strpos( $path, '/wp-admin/' ) ) {
			return self::owner( 'core', 'wordpress' );
		}

		$uploads = wp_upload_dir( null, false );
		$slug    = self::segment_after( $path, (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
		if ( $slug ) {
			// Builders such as Elementor write generated CSS into uploads/<builder>/.
			return self::owner( 'generated', $slug );
		}

		return self::owner( 'other', 'other' );
	}

	/** Who owns a PHP file on disk (used to attribute database queries). */
	public static function owner_from_path( $file ) {
		if ( isset( self::$owner_cache[ $file ] ) ) {
			return self::$owner_cache[ $file ];
		}
		$norm  = wp_normalize_path( $file );
		$roots = array(
			'plugin'    => WP_PLUGIN_DIR,
			'mu-plugin' => WPMU_PLUGIN_DIR,
			'theme'     => get_theme_root(),
		);
		$owner = null;
		foreach ( $roots as $type => $dir ) {
			$prefix = trailingslashit( wp_normalize_path( $dir ) );
			if ( 0 === strpos( $norm, $prefix ) ) {
				$owner = array(
					'type' => $type,
					'slug' => strtok( substr( $norm, strlen( $prefix ) ), '/' ),
				);
				break;
			}
		}
		if ( ! $owner ) {
			$owner = array(
				'type' => 'core',
				'slug' => 'wordpress',
			);
		}
		self::$owner_cache[ $file ] = $owner;
		return $owner;
	}

	/** "plugin:woocommerce" -> "WooCommerce (plugin)". */
	public static function component_label( $key ) {
		list( $type, $slug ) = array_pad( explode( ':', $key, 2 ), 2, '' );
		return self::owner( $type, $slug )['label'];
	}

	public static function owner( $type, $slug ) {
		switch ( $type ) {
			case 'plugin':
				/* translators: %s: plugin name. */
				$label = sprintf( __( '%s (plugin)', 'speed-x-ray' ), self::plugin_name( $slug ) );
				break;
			case 'mu-plugin':
				/* translators: %s: must-use plugin name. */
				$label = sprintf( __( '%s (must-use plugin)', 'speed-x-ray' ), self::plugin_name( $slug ) );
				break;
			case 'theme':
				$theme = wp_get_theme( $slug );
				/* translators: %s: theme name. */
				$label = sprintf( __( '%s (theme)', 'speed-x-ray' ), $theme->exists() ? $theme->get( 'Name' ) : $slug );
				break;
			case 'core':
				$label = __( 'WordPress core', 'speed-x-ray' );
				break;
			case 'generated':
				/* translators: %s: folder name inside wp-content/uploads, e.g. "elementor". */
				$label = sprintf( __( 'Generated file (uploads/%s)', 'speed-x-ray' ), $slug );
				break;
			case 'third-party':
				/* translators: %s: external domain name. */
				$label = sprintf( __( '%s (external)', 'speed-x-ray' ), $slug );
				break;
			default:
				$label = __( 'Other', 'speed-x-ray' );
		}
		return array(
			'type'  => $type,
			'slug'  => $slug,
			'label' => $label,
		);
	}

	public static function plugin_name( $slug ) {
		if ( null === self::$plugin_names ) {
			self::$plugin_names = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$dir                        = dirname( $file );
				$key                        = '.' === $dir ? $file : $dir;
				self::$plugin_names[ $key ] = $data['Name'];
			}
		}
		return isset( self::$plugin_names[ $slug ] ) ? self::$plugin_names[ $slug ] : $slug;
	}

	/** Maps a URL on this site to the file on disk, or null when it is not a local static file. */
	public static function url_to_local_path( $url ) {
		$url = self::absolute_url( $url );
		if ( ! self::is_own_url( $url ) ) {
			return null;
		}
		$path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$bases = array(
			array( content_url( '/' ), trailingslashit( WP_CONTENT_DIR ) ),
			array( includes_url(), ABSPATH . WPINC . '/' ),
			array( site_url( '/' ), ABSPATH ),
		);
		foreach ( $bases as $base ) {
			$base_path = trailingslashit( (string) wp_parse_url( $base[0], PHP_URL_PATH ) );
			if ( 0 !== strpos( $path, $base_path ) ) {
				continue;
			}
			$file = realpath( $base[1] . substr( $path, strlen( $base_path ) ) );
			if ( $file && is_file( $file ) && self::is_inside_install( $file ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * The key that identifies a page for reports and per-page rules: path (leading slash, no trailing
	 * slash) plus its query string, so sites with plain permalinks (?p=123) keep pages apart.
	 * Tracking and Speed X-Ray parameters are dropped and the rest sorted, so they don't split a page.
	 */
	public static function normalize_path( $path ) {
		$path  = (string) strtok( trim( (string) $path ), '#' );
		$query = '';
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $query ) = explode( '?', $path, 2 );
		}
		$key = '/' . trim( $path, '/' );
		if ( '' !== $query ) {
			parse_str( $query, $vars );
			foreach ( array_keys( $vars ) as $name ) {
				if ( 0 === strpos( $name, 'speedxray_' ) || 0 === strpos( $name, 'utm_' ) || in_array( $name, array( 'fbclid', 'gclid', 'msclkid', '_ga' ), true ) ) {
					unset( $vars[ $name ] );
				}
			}
			ksort( $vars );
			if ( $vars ) {
				$key .= '?' . http_build_query( $vars );
			}
		}
		return $key;
	}

	/** Page key of a full URL (see normalize_path()). */
	public static function page_key( $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return self::normalize_path( $path . ( '' !== $query ? '?' . $query : '' ) );
	}

	public static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::page_key( $uri );
	}

	public static function bytes( $bytes ) {
		if ( null === $bytes ) {
			return '—';
		}
		return $bytes < 1024 ? $bytes . ' B' : size_format( $bytes, 1 );
	}

	private static function segment_after( $path, $prefix ) {
		$prefix = trailingslashit( $prefix );
		if ( '/' === $prefix || 0 !== strpos( $path, $prefix ) ) {
			return null;
		}
		$segment = strtok( substr( $path, strlen( $prefix ) ), '/' );
		return $segment ? $segment : null;
	}

	private static function is_inside_install( $file ) {
		$file = wp_normalize_path( $file );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			$root = realpath( $root );
			if ( $root && 0 === strpos( $file, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				return true;
			}
		}
		return false;
	}
}
