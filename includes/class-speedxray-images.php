<?php
/**
 * Modern image formats: a WebP (and, with Pro, AVIF) copy of every size of each JPEG/PNG upload,
 * kept only when it is smaller. Originals are never changed. Copies sit next to the original as
 * "photo.jpg.webp" and are served with <picture> tags, or with Apache rewrite rules.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_Images {

	const OPTION  = 'speedxray_images';
	const STATS   = 'speedxray_images_stats';
	const META    = '_speedxray_images';
	const MARKER  = 'Speed X-Ray images';
	const SOURCES = array( 'image/jpeg', 'image/png' );

	private static $exists = array();

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'enabled'       => 0,
				'quality'       => 80,
				'compress_jpeg' => 0,
				'serve'         => 'picture',
			)
		);
	}

	public static function init() {
		$s = self::settings();
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		if ( ! $s['enabled'] || get_option( 'speedxray_paused' ) ) {
			return;
		}
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate' ), 20, 2 );
		if ( $s['compress_jpeg'] ) {
			add_filter( 'wp_editor_set_quality', array( __CLASS__, 'jpeg_quality' ), 20, 2 );
		}
		if ( 'picture' === $s['serve'] ) {
			add_filter( 'speedxray_html', array( __CLASS__, 'rewrite_html' ), 20 );
		}
	}

	/* -------------------------------------------------------------- support */

	public static function supported( $format ) {
		return wp_image_editor_supports( array( 'mime_type' => 'image/' . $format ) );
	}

	/** Formats to create: WebP in the free plugin; extensions (Pro) may add AVIF. */
	public static function formats() {
		/**
		 * Filters the modern formats created for each image.
		 *
		 * @param string[] $formats e.g. array( 'webp' ).
		 */
		$formats = apply_filters( 'speedxray_image_formats', array( 'webp' ) );
		return array_values( array_filter( array_unique( (array) $formats ), array( __CLASS__, 'supported' ) ) );
	}

	public static function apache() {
		global $is_apache;
		return ! empty( $is_apache ) && function_exists( 'got_mod_rewrite' ) && got_mod_rewrite();
	}

	/* ----------------------------------------------------------- converting */

	/** Every file of an attachment: the main file and each generated size. */
	public static function files( $id ) {
		$main = get_attached_file( $id );
		if ( ! $main || ! is_file( $main ) || ! in_array( get_post_mime_type( $id ), self::SOURCES, true ) ) {
			return array();
		}
		$files = array( $main );
		$meta  = wp_get_attachment_metadata( $id );
		$dir   = trailingslashit( dirname( $main ) );
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && is_file( $dir . $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Creates the missing modern copies of one attachment.
	 *
	 * @param int           $id      Attachment ID.
	 * @param string[]|null $formats Formats, default self::formats().
	 * @return array{created:int,saved:int,errors:string[]}
	 */
	public static function convert_attachment( $id, $formats = null ) {
		$formats = null === $formats ? self::formats() : $formats;
		$quality = (int) self::settings()['quality'];
		$result  = array(
			'created' => 0,
			'saved'   => 0,
			'errors'  => array(),
		);
		$record  = get_post_meta( $id, self::META, true );
		$record  = is_array( $record ) ? $record : array();

		foreach ( self::files( $id ) as $file ) {
			$name     = basename( $file );
			$original = filesize( $file );
			foreach ( $formats as $format ) {
				$dest = $file . '.' . $format;
				if ( isset( $record[ $format ][ $name ] ) && ( is_file( $dest ) || false === $record[ $format ][ $name ] ) ) {
					continue; // Done earlier (false = tried, copy was not smaller).
				}
				$editor = wp_get_image_editor( $file );
				if ( is_wp_error( $editor ) ) {
					$result['errors'][] = $name . ': ' . $editor->get_error_message();
					continue;
				}
				// AVIF at the same number looks better than WebP and is heavier; ~25 lower matches WebP visually.
				$editor->set_quality( 'avif' === $format ? max( 30, $quality - 25 ) : $quality );
				$saved = $editor->save( $dest, 'image/' . $format );
				if ( is_wp_error( $saved ) || ! is_file( $dest ) ) {
					$result['errors'][] = $name . ': ' . ( is_wp_error( $saved ) ? $saved->get_error_message() : __( 'could not be written', 'speed-x-ray' ) );
					continue;
				}
				clearstatcache( true, $dest );
				$size  = filesize( $dest );
				$limit = $original;
				if ( 'avif' === $format && is_file( $file . '.webp' ) ) {
					$limit = min( $limit, filesize( $file . '.webp' ) ); // Browsers take AVIF first: it must beat WebP.
				}
				if ( $size >= $limit ) {
					wp_delete_file( $dest ); // Not smaller: keep serving the original.
					$record[ $format ][ $name ] = false;
					continue;
				}
				$record[ $format ][ $name ] = $size;
				$record['source'][ $name ]  = $original;
				++$result['created'];
				$result['saved'] += $original - $size;
			}
		}
		if ( $record ) {
			update_post_meta( $id, self::META, $record );
		}
		if ( $result['created'] ) {
			$stats           = self::stats();
			$stats['files'] += $result['created'];
			$stats['saved'] += $result['saved'];
			$stats['images'] = (int) $stats['images'] + 1;
			update_option( self::STATS, $stats, false );
		}
		return $result;
	}

	public static function stats() {
		$s = get_option( self::STATS, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'images' => 0,
				'files'  => 0,
				'saved'  => 0,
			)
		);
	}

	public static function on_generate( $metadata, $id ) {
		/**
		 * Filters the formats created during the upload request itself. Slow formats (AVIF) can be
		 * removed here and created in the background instead.
		 *
		 * @param string[] $formats Formats.
		 * @param int      $id      Attachment ID.
		 */
		$formats = apply_filters( 'speedxray_upload_formats', self::formats(), $id );
		// WordPress saves the metadata after this filter; the files exist already.
		self::convert_attachment( $id, $formats );
		return $metadata;
	}

	public static function jpeg_quality( $quality, $mime ) {
		return 'image/jpeg' === $mime ? (int) self::settings()['quality'] : $quality;
	}

	/** Removes the modern copies when the image itself is deleted. */
	public static function on_delete( $id ) {
		foreach ( self::files( $id ) as $file ) {
			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( is_file( $file . '.' . $format ) ) {
					wp_delete_file( $file . '.' . $format );
				}
			}
		}
	}

	/**
	 * Deletes up to $limit attachments' worth of generated copies (undo). Returns how many were cleaned.
	 */
	public static function remove_batch( $limit = 200 ) {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => $limit,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		foreach ( $ids as $id ) {
			self::on_delete( $id );
			delete_post_meta( $id, self::META );
		}
		if ( count( $ids ) < $limit ) {
			delete_option( self::STATS );
		}
		return count( $ids );
	}

	/* ------------------------------------------------------------- serving */

	private static function modern( $url, $format ) {
		$key = $url . '|' . $format;
		if ( ! isset( self::$exists[ $key ] ) ) {
			$path                 = SpeedXRay_Helpers::url_to_local_path( $url );
			self::$exists[ $key ] = $path && is_file( $path . '.' . $format );
		}
		return self::$exists[ $key ];
	}

	/** Wraps local JPEG/PNG <img> tags in <picture> with AVIF/WebP sources when every size has a copy. */
	public static function rewrite_html( $html ) {
		$formats = array_intersect( array( 'avif', 'webp' ), self::formats() ); // AVIF first: browsers take the first type they support.
		if ( ! $formats ) {
			return $html;
		}
		// Leave existing <picture>, scripts, noscript and form fields untouched.
		$parts = preg_split( '#(<picture\b.*?</picture>|<script\b.*?</script>|<noscript\b.*?</noscript>|<textarea\b.*?</textarea>|<template\b.*?</template>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				continue;
			}
			$parts[ $i ] = preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( $m ) use ( $formats ) {
					return self::picture( $m[0], $formats );
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	private static function attr( $tag, $name ) {
		return preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ? html_entity_decode( $m[2], ENT_QUOTES ) : null;
	}

	private static function picture( $img, $formats ) {
		// Lazy-load libraries swap data-src/srcset in later; changing their markup would break them.
		if ( preg_match( '#\sdata-(lazy-)?src(set)?\s*=#i', $img ) ) {
			return $img;
		}
		$src = self::attr( $img, 'src' );
		if ( ! $src || ! preg_match( '#\.(jpe?g|png)(\?.*)?$#i', $src ) ) {
			return $img;
		}
		$srcset     = self::attr( $img, 'srcset' );
		$sizes      = self::attr( $img, 'sizes' );
		$candidates = $srcset ? array_filter( array_map( 'trim', explode( ',', $srcset ) ) ) : array( $src );
		$sources    = '';
		foreach ( $formats as $format ) {
			$out = array();
			foreach ( $candidates as $candidate ) {
				$bits = preg_split( '/\s+/', $candidate, 2 );
				$url  = strtok( $bits[0], '?' );
				if ( ! self::modern( $url, $format ) ) {
					continue 2; // A size has no copy: skip this format rather than serve a wrong size.
				}
				$out[] = $url . '.' . $format . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
			}
			$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( implode( ', ', $out ) ) . '"' . ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
		}
		return $sources ? '<picture class="sxr-picture">' . $sources . $img . '</picture>' : $img;
	}

	/* -------------------------------------------------------- .htaccess mode */

	public static function htaccess_rules() {
		return array(
			'<IfModule mod_mime.c>',
			'AddType image/webp .webp',
			'AddType image/avif .avif',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_ACCEPT} image/avif',
			'RewriteCond %{REQUEST_FILENAME}.avif -f',
			'RewriteRule ^(.+\.(?:jpe?g|png))$ $1.avif [T=image/avif,E=SXR_IMG:1,L]',
			'RewriteCond %{HTTP_ACCEPT} image/webp',
			'RewriteCond %{REQUEST_FILENAME}.webp -f',
			'RewriteRule ^(.+\.(?:jpe?g|png))$ $1.webp [T=image/webp,E=SXR_IMG:1,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'Header append Vary Accept env=REDIRECT_SXR_IMG',
			'</IfModule>',
		);
	}

	/** Adds or removes the rewrite block in the site's .htaccess. Returns false if it could not be written. */
	public static function write_htaccess( $on ) {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$file = get_home_path() . '.htaccess';
		if ( ! file_exists( $file ) && ! $on ) {
			return true;
		}
		if ( ( file_exists( $file ) && ! wp_is_writable( $file ) ) || ( ! file_exists( $file ) && ! wp_is_writable( dirname( $file ) ) ) ) {
			return false;
		}
		return insert_with_markers( $file, self::MARKER, $on ? self::htaccess_rules() : array() );
	}

	/* --------------------------------------------------------------- admin */

	/** Number of JPEG/PNG images in the media library, and how many already have copies. */
	public static function library_counts() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only counts on core tables.
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" );
		$converted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );
		// phpcs:enable
		return array(
			'total'     => $total,
			'converted' => $converted,
		);
	}
}
