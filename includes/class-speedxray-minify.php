<?php
/**
 * Minifies the local CSS/JS files that ship unminified, one file at a time (no combining).
 * The minified copy is cached in uploads/speedxray-cache/min and rebuilt when the source file changes.
 * Uses the bundled matthiasmullie/minify library (MIT).
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_Minify {

	const DIR            = 'speedxray-cache/min';
	const EXCLUDE_OPTION = 'speedxray_minify_exclude';
	const MAX_SIZE       = 600; // KB; larger files are left alone.

	private static $loaded = false;

	public static function init() {
		if ( is_admin() || get_option( 'speedxray_paused' ) ) {
			return;
		}
		if ( SpeedXRay_Optimizations::enabled( 'minify_css' ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'css' ), 30, 2 );
		}
		if ( SpeedXRay_Optimizations::enabled( 'minify_js' ) ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'js' ), 30, 2 );
		}
	}

	private static function load_library() {
		if ( self::$loaded ) {
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				$map = array(
					'MatthiasMullie\\Minify\\'        => SPEEDXRAY_DIR . 'vendor/minify/src/',
					'MatthiasMullie\\PathConverter\\' => SPEEDXRAY_DIR . 'vendor/path-converter/src/',
				);
				foreach ( $map as $prefix => $dir ) {
					if ( 0 === strpos( $class, $prefix ) ) {
						$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
						if ( is_file( $file ) ) {
							require_once $file;
						}
					}
				}
			}
		);
		self::$loaded = true;
	}

	public static function exclusions() {
		return array_values( array_filter( preg_split( '/[\s,]+/', (string) get_option( self::EXCLUDE_OPTION, '' ) ) ) );
	}

	/** True for a file that is worth minifying: not .min, not tiny, not already compact. */
	public static function needs( $file ) {
		if ( ! $file || ! is_file( $file ) || false !== strpos( basename( $file ), '.min.' ) ) {
			return false;
		}
		$size = filesize( $file );
		if ( $size < 1024 || $size > self::MAX_SIZE * KB_IN_BYTES ) {
			return false;
		}
		$lines = substr_count( (string) file_get_contents( $file, false, null, 0, 200000 ), "\n" ) + 1; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return $size / $lines < 300; // Long average lines mean it is already minified.
	}

	/** Minified content of a file, or null if minifying does not help or fails. */
	public static function minify_file( $file, $type, $target = null ) {
		self::load_library();
		try {
			if ( 'css' === $type ) {
				$minifier = new \MatthiasMullie\Minify\CSS( $file );
				$minifier->setMaxImportSize( 0 );       // Never inline images as base64.
				$minifier->setImportExtensions( array() );
			} else {
				$minifier = new \MatthiasMullie\Minify\JS( $file );
			}
			$out = $target ? $minifier->minify( $target ) : $minifier->minify();
		} catch ( \Exception $e ) {
			return null;
		}
		return is_string( $out ) && '' !== trim( $out ) && strlen( $out ) < filesize( $file ) ? $out : null;
	}

	/** Bytes saved by minifying (for the diagnosis); 0 when not worth it. */
	public static function saving( $file, $type ) {
		if ( ! self::needs( $file ) ) {
			return 0;
		}
		$out = self::minify_file( $file, $type );
		return null === $out ? 0 : filesize( $file ) - strlen( $out );
	}

	public static function css( $src, $handle ) {
		return self::serve( $src, $handle, 'css' );
	}

	public static function js( $src, $handle ) {
		return self::serve( $src, $handle, 'js' );
	}

	private static function serve( $src, $handle, $type ) {
		if ( ! $src || SpeedXRay_Helpers::is_bypassed() || in_array( $handle, self::exclusions(), true ) ) {
			return $src;
		}
		$owner = SpeedXRay_Helpers::owner_from_url( $src );
		if ( in_array( $owner['type'], array( 'core', 'third-party' ), true ) ) {
			return $src;
		}
		$file = SpeedXRay_Helpers::url_to_local_path( $src );
		if ( ! self::needs( $file ) ) {
			return $src;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		$name    = md5( $file . '|' . filemtime( $file ) . '|' . filesize( $file ) . '|' . SPEEDXRAY_VERSION ) . '.' . $type;
		$path    = $dir . '/' . $name;
		$url     = trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $name;

		if ( is_file( $path ) ) {
			return $url;
		}
		if ( is_file( $path . '.skip' ) ) {
			return $src; // Tried before: minifying did not help or failed.
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return $src;
		}
		$out = self::minify_file( $file, $type, $path );
		if ( null === $out ) {
			file_put_contents( $path . '.skip', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			return $src;
		}
		if ( ! is_file( $path ) ) {
			file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $url;
	}

	/** Deletes all cached minified files. */
	public static function clear_cache() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
