<?php
/**
 * Step 3: individual, reversible fixes.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Step 3: individual, reversible fixes. Each one is off until the user turns it on,
 * and each maps to an issue id the scanner can raise.
 */
class SpeedXRay_Optimizations {

	const OPTION         = 'speedxray_opts';
	const EXCLUDE_OPTION = 'speedxray_defer_exclude';

	public static function definitions() {
		return array(
			'disable_emojis'        => array(
				'label' => __( 'Remove WordPress emoji script', 'speed-x-ray' ),
				'desc'  => __( 'Browsers show emojis natively. Removes an inline script and a DNS lookup from every page.', 'speed-x-ray' ),
				'risk'  => 'safe',
			),
			'disable_embeds'        => array(
				'label' => __( 'Remove oEmbed discovery and wp-embed script', 'speed-x-ray' ),
				'desc'  => __( 'Only needed if other sites embed your posts as cards. YouTube/Twitter embeds in your own posts keep working.', 'speed-x-ray' ),
				'risk'  => 'safe',
			),
			'remove_jquery_migrate' => array(
				'label' => __( 'Stop loading jQuery Migrate', 'speed-x-ray' ),
				'desc'  => __( 'A compatibility layer for very old jQuery code. Check sliders, popups and forms after turning this on.', 'speed-x-ray' ),
				'risk'  => 'low',
			),
			'unload_block_css'      => array(
				'label' => __( 'Unload block (Gutenberg) CSS on pages not built with blocks', 'speed-x-ray' ),
				'desc'  => __( 'Classic themes and builder pages (Elementor, Divi, WPBakery) still load the whole block stylesheet. Only removed on single pages/posts that contain no blocks, and never when block widgets are in use.', 'speed-x-ray' ),
				'risk'  => 'low',
			),
			'dashicons_guests'      => array(
				'label' => __( 'Unload Dashicons for logged-out visitors', 'speed-x-ray' ),
				'desc'  => __( 'The admin icon font. A few themes use it for front-end icons — check your menus and footer after enabling.', 'speed-x-ray' ),
				'risk'  => 'low',
			),
			'google_fonts_swap'     => array(
				'label' => __( 'Google Fonts: show text immediately (display=swap)', 'speed-x-ray' ),
				'desc'  => __( 'Text shows in a fallback font instead of staying invisible while the font downloads.', 'speed-x-ray' ),
				'risk'  => 'safe',
			),
			'defer_js'              => array(
				'label' => __( 'Defer JavaScript (WordPress 6.3+ safe mode)', 'speed-x-ray' ),
				'desc'  => __( 'Marks scripts as defer through WordPress\'s own loading-strategy API, which automatically keeps a script blocking when inline code or other scripts need it immediately. Inline scripts written straight into page HTML by a theme/builder are the remaining risk — test key pages, and add handles to the exclude list if something stops working.', 'speed-x-ray' ),
				'risk'  => 'medium',
			),
			'minify_css'            => array(
				'label' => __( 'Minify CSS files that are not minified', 'speed-x-ray' ),
				'desc'  => __( 'Only plugin/theme stylesheets shipped unminified are minified, one by one (never combined). The minified copy is cached and rebuilt when the original changes; relative image and font paths are corrected.', 'speed-x-ray' ),
				'risk'  => 'low',
			),
			'minify_js'             => array(
				'label' => __( 'Minify JavaScript files that are not minified', 'speed-x-ray' ),
				'desc'  => __( 'Same as above for scripts. Minifying JavaScript is reliable but not risk-free — test forms, menus and sliders, and add a handle to the exclude list below if one stops working.', 'speed-x-ray' ),
				'risk'  => 'medium',
			),
			'limit_revisions'       => array(
				'label' => __( 'Keep only 5 revisions per post from now on', 'speed-x-ray' ),
				'desc'  => __( 'Stops the posts table from growing without limit. Existing revisions are cleaned in step 4.', 'speed-x-ray' ),
				'risk'  => 'safe',
			),
			'heartbeat'             => array(
				'label' => __( 'Slow down the Heartbeat API to every 60 seconds', 'speed-x-ray' ),
				'desc'  => __( 'Reduces admin-ajax.php load while editors have the dashboard open. Post locking and autosave still work.', 'speed-x-ray' ),
				'risk'  => 'safe',
			),
		);
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		return is_array( $opts ) ? $opts : array();
	}

	public static function enabled( $key ) {
		$opts = self::all();
		return ! empty( $opts[ $key ] );
	}

	public static function defer_supported() {
		return version_compare( get_bloginfo( 'version' ), '6.3', '>=' );
	}

	public static function defer_exclusions() {
		$custom = preg_split( '/[\s,]+/', (string) get_option( self::EXCLUDE_OPTION, '' ) );
		return array_values( array_filter( array_merge( array( 'jquery', 'jquery-core', 'jquery-migrate' ), $custom ) ) );
	}

	public static function init() {
		if ( get_option( 'speedxray_paused' ) ) {
			return;
		}
		if ( self::enabled( 'limit_revisions' ) ) {
			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'limit_revisions' ) );
		}
		if ( self::enabled( 'heartbeat' ) ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat' ) );
		}
		if ( ! is_admin() ) {
			add_action( 'init', array( __CLASS__, 'apply_frontend' ), 5 );
		}
	}

	public static function apply_frontend() {
		if ( SpeedXRay_Helpers::is_bypassed() ) {
			return;
		}
		if ( self::enabled( 'disable_emojis' ) ) {
			self::disable_emojis();
		}
		if ( self::enabled( 'disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_embed' ), PHP_INT_MAX );
		}
		if ( self::enabled( 'remove_jquery_migrate' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_jquery_migrate' ), PHP_INT_MAX );
		}
		if ( self::enabled( 'unload_block_css' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'unload_block_css' ), PHP_INT_MAX );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'unload_block_css' ), 1 );
		}
		if ( self::enabled( 'dashicons_guests' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dashicons_guests' ), PHP_INT_MAX );
		}
		if ( self::enabled( 'google_fonts_swap' ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'google_fonts_swap' ) );
		}
		if ( self::enabled( 'defer_js' ) && self::defer_supported() ) {
			add_action( 'wp_print_scripts', array( __CLASS__, 'defer_scripts' ), 1 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'defer_scripts' ), 1 );
		}
	}

	private static function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		// Also removes the emoji CDN dns-prefetch hint: core skips an empty emoji_svg_url.
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	public static function dequeue_embed() {
		wp_dequeue_script( 'wp-embed' );
	}

	public static function remove_jquery_migrate() {
		$scripts = wp_scripts();
		if ( isset( $scripts->registered['jquery'] ) ) {
			$scripts->registered['jquery']->deps = array_values( array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) ) );
		}
	}

	public static function unload_block_css() {
		if ( ! self::page_has_no_blocks() ) {
			return;
		}
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/** Conservative: only a single post/page on a classic theme, with no blocks in content or widgets. */
	private static function page_has_no_blocks() {
		if ( ! is_singular() || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return false;
		}
		if ( has_blocks( get_post() ) ) {
			return false;
		}
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( 0 === strpos( $widget_id, 'block-' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function dashicons_guests() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public static function google_fonts_swap( $src ) {
		if ( false !== strpos( $src, 'fonts.googleapis.com' ) && false === strpos( $src, 'display=' ) ) {
			// Appended by hand: add_query_arg() would collapse the repeated family= params css2 URLs use.
			$src .= ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'display=swap';
		}
		return $src;
	}

	public static function defer_scripts() {
		$scripts = wp_scripts();
		$exclude = self::defer_exclusions();
		foreach ( $scripts->registered as $handle => $obj ) {
			if ( ! $obj->src || in_array( $handle, $exclude, true ) || in_array( $handle, $scripts->done, true ) ) {
				continue;
			}
			if ( $scripts->get_data( $handle, 'strategy' ) ) {
				continue; // Already async/defer by its author.
			}
			$scripts->add_data( $handle, 'strategy', 'defer' );
		}
	}

	public static function limit_revisions( $num ) {
		return ( $num < 0 || $num > 5 ) ? 5 : $num;
	}

	public static function heartbeat( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}
}
