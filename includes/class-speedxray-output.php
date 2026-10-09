<?php
/**
 * One output buffer for every feature that needs to adjust the page HTML (images, Pro script delay).
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_Output {

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'start' ), PHP_INT_MAX );
	}

	public static function start() {
		/**
		 * Filters the full HTML of a front-end page before it is sent.
		 * The buffer only starts when something is hooked here.
		 *
		 * @param string $html Page HTML.
		 */
		if ( ! has_filter( 'speedxray_html' ) ) {
			return;
		}
		if ( is_feed() || is_embed() || is_robots() || is_trackback() || is_customize_preview() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || SpeedXRay_Helpers::is_bypassed() ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter' ) );
	}

	public static function filter( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 200 || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html; // JSON, XML sitemaps, files.
			}
		}
		$out = apply_filters( 'speedxray_html', $html );
		return is_string( $out ) && '' !== $out ? $out : $html;
	}
}
