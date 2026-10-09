<?php
/**
 * What Speed X-Ray Pro adds — shown only on the plugin's own screen, only where it is relevant,
 * never as a dashboard-wide notice, and not at all once Pro is active. Tips can be switched off.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_Promo {

	/** The store. Change this one line when the site moves to its real domain. */
	const STORE       = 'http://localhost/speedxray/';
	const HIDE_OPTION = 'speedxray_hide_pro_tips';

	private static $monitor_tip_shown = false;

	public static function init() {
		add_filter( 'speedxray_admin_tabs', array( __CLASS__, 'tab' ), 99 );
		add_action( 'speedxray_render_tab_pro', array( __CLASS__, 'render' ) );
		add_action( 'speedxray_diagnose_after', array( __CLASS__, 'after_diagnose' ) );
		add_action( 'speedxray_issue_after', array( __CLASS__, 'after_issue' ) );
		add_action( 'speedxray_verify_intro', array( __CLASS__, 'verify_tip' ) );
		add_action( 'speedxray_images_tab_after', array( __CLASS__, 'images_card' ) );
		add_action( 'speedxray_optimize_after', array( __CLASS__, 'delay_card' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SPEEDXRAY_FILE ), array( __CLASS__, 'action_link' ), 20 );
		add_action( 'admin_post_speedxray_pro_tips', array( __CLASS__, 'toggle_tips' ) );
	}

	/** Store link that says which part of the plugin it came from (no data about the site is sent). */
	public static function url( $campaign, $path = 'pricing/' ) {
		/**
		 * Filters the base URL of the Speed X-Ray store.
		 *
		 * @param string $store Store URL with trailing slash.
		 */
		$store = apply_filters( 'speedxray_store_url', self::STORE );
		return add_query_arg(
			array(
				'utm_source'   => 'speed-x-ray',
				'utm_medium'   => 'plugin',
				'utm_campaign' => $campaign,
			),
			trailingslashit( $store ) . $path
		);
	}

	public static function tips_on() {
		return ! get_option( self::HIDE_OPTION );
	}

	private static function tip( $text, $campaign, $label ) {
		if ( ! self::tips_on() ) {
			return;
		}
		printf(
			'<p class="sxr-pro-tip"><span class="sxr-pro-tag">%1$s</span> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s &rarr;</a></p>',
			esc_html__( 'Pro', 'speed-x-ray' ),
			esc_html( $text ),
			esc_url( self::url( $campaign ) ),
			esc_html( $label )
		);
	}

	/* ------------------------------------------------------ contextual tips */

	public static function after_diagnose( $report ) {
		if ( empty( $report['components'] ) ) {
			return;
		}
		$top = '';
		foreach ( array_keys( $report['components'] ) as $key ) {
			if ( 0 !== strpos( $key, 'core:' ) && 0 !== strpos( $key, 'plugin:speed-x-ray' ) ) {
				$top = SpeedXRay_Helpers::component_label( $key );
				break;
			}
		}
		self::tip(
			$top
				/* translators: %s: plugin or theme name. */
				? sprintf( __( 'The table above is database time only. Pro measures each plugin’s full PHP time — loading its files plus every hook it runs — so you see what %s really costs this page.', 'speed-x-ray' ), $top )
				: __( 'The table above is database time only. Pro measures each plugin’s full PHP time — loading its files plus every hook it runs.', 'speed-x-ray' ),
			'php-time',
			__( 'See PHP time per plugin', 'speed-x-ray' )
		);
	}

	public static function after_issue( $issue ) {
		if ( self::$monitor_tip_shown || ! in_array( $issue['id'], array( 'server_time', 'response_time' ), true ) || ! in_array( $issue['severity'], array( 'critical', 'warning' ), true ) ) {
			return;
		}
		self::$monitor_tip_shown = true;
		self::tip(
			__( 'Pro can check this page every day and email you the moment it gets slower — naming the plugin whose time grew and what was updated.', 'speed-x-ray' ),
			'monitoring',
			__( 'See monitoring', 'speed-x-ray' )
		);
	}

	public static function verify_tip() {
		self::tip(
			__( 'These numbers are measured by your server. Pro adds real visitors’ Core Web Vitals (LCP, INP, CLS) from their own phones and laptops, so you see what people actually experience.', 'speed-x-ray' ),
			'vitals',
			__( 'See real-visitor vitals', 'speed-x-ray' )
		);
	}

	/** Images tab: what happens to images uploaded before WebP was turned on. */
	public static function images_card( $counts ) {
		if ( ! self::tips_on() ) {
			return;
		}
		$old = max( 0, $counts['total'] - $counts['converted'] );
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Images you uploaded earlier', 'speed-x-ray' ) . ' <span class="sxr-pro-tag">' . esc_html__( 'Pro', 'speed-x-ray' ) . '</span></h2><p>';
		echo esc_html(
			$old
				/* translators: %s: number of images. */
				? sprintf( __( '%s images in your library have no modern copy yet. The free plugin converts new uploads; Speed X-Ray Pro converts your whole existing library in the background and adds AVIF, which is kept only where it beats WebP.', 'speed-x-ray' ), number_format_i18n( $old ) )
				: __( 'Speed X-Ray Pro adds AVIF copies — kept only where they beat WebP — and converts existing libraries in the background.', 'speed-x-ray' )
		);
		echo '</p><p><a class="button" href="' . esc_url( self::url( 'images' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See Pro', 'speed-x-ray' ) . '</a></p></div>';
	}

	/** Safe fixes tab: delaying third-party scripts. */
	public static function delay_card( $entry ) {
		if ( ! self::tips_on() ) {
			return;
		}
		$domains = $entry && ! empty( $entry['latest']['third_party'] ) ? count( $entry['latest']['third_party'] ) : 0;
		echo '<div class="sxr-card"><h2>' . esc_html__( 'Delay chat, tracking and ad scripts', 'speed-x-ray' ) . ' <span class="sxr-pro-tag">' . esc_html__( 'Pro', 'speed-x-ray' ) . '</span></h2><p>';
		echo esc_html(
			$domains
				/* translators: %d: number of external domains. */
				? sprintf( _n( 'This page loads scripts from %d external domain.', 'This page loads scripts from %d external domains.', $domains, 'speed-x-ray' ), $domains ) . ' '
				: ''
		);
		echo esc_html__( 'Speed X-Ray Pro holds back chat widgets, pixels and similar scripts until the visitor scrolls, taps or a few seconds pass — so they stop slowing the first view. Payment and consent scripts are never delayed.', 'speed-x-ray' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::url( 'delay' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See Pro', 'speed-x-ray' ) . '</a></p></div>';
	}

	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( self::url( 'plugins-page' ) ) . '" target="_blank" rel="noopener noreferrer" style="font-weight:600">' . esc_html__( 'Get Pro', 'speed-x-ray' ) . '</a>';
		return $links;
	}

	/* -------------------------------------------------------------- Pro tab */

	public static function tab( $tabs ) {
		$tabs['pro'] = __( 'Pro features', 'speed-x-ray' );
		return $tabs;
	}

	public static function toggle_tips() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speed-x-ray' ), 403 );
		}
		check_admin_referer( 'speedxray_pro_tips' );
		update_option( self::HIDE_OPTION, self::tips_on() ? 1 : 0, true );
		wp_safe_redirect( SpeedXRay_Admin::tab_url( 'pro' ) );
		exit;
	}

	public static function render() {
		$features = array(
			array(
				__( 'PHP time per plugin', 'speed-x-ray' ),
				__( 'The free diagnosis shows database time per plugin. Pro times everything each plugin and theme does on a page — loading its files and every hook callback — and lists its heaviest hooks.', 'speed-x-ray' ),
				__( 'e.g. “WooCommerce uses 884 ms of PHP time (53% of this page); init 297 ms, plugins_loaded 112 ms”', 'speed-x-ray' ),
			),
			array(
				__( 'Site scan', 'speed-x-ray' ),
				__( 'Scans every page of your site one after another and ranks them from slowest to fastest, with the heaviest plugin on each page — so you fix the pages that matter first.', 'speed-x-ray' ),
				__( 'e.g. “Slowest: /shop/ 1,779 ms · heaviest plugin on 4 of 4 pages: WooCommerce”', 'speed-x-ray' ),
			),
			array(
				__( 'Monitoring & email alerts', 'speed-x-ray' ),
				__( 'Checks your key pages every day or week. When one gets slower than usual you get an email that says which plugin’s time grew and what was updated since the last check.', 'speed-x-ray' ),
				__( 'e.g. “/checkout/ 420 ms → 1,310 ms · WooCommerce 300 → 1,200 ms · WooCommerce updated 9.1 → 9.2”', 'speed-x-ray' ),
			),
			array(
				__( 'Real-visitor Core Web Vitals', 'speed-x-ray' ),
				__( 'LCP, INP, CLS and TTFB measured in your visitors’ browsers, per page and device, at the 75th percentile Google uses. Collected on your own site: no third party, no cookies, no IP addresses.', 'speed-x-ray' ),
				__( 'e.g. “Mobile · LCP 3.1 s (needs improvement) · INP 260 ms · CLS 0.15”', 'speed-x-ray' ),
			),
			array(
				__( 'Whole image library to WebP + AVIF', 'speed-x-ray' ),
				__( 'The free plugin converts new uploads to WebP. Pro converts every image you already have, in the background, and adds AVIF where it is smaller than WebP.', 'speed-x-ray' ),
				__( 'e.g. “1,240 images converted · 312 MB saved”', 'speed-x-ray' ),
			),
			array(
				__( 'Delay chat & tracking scripts', 'speed-x-ray' ),
				__( 'Holds back chat widgets, pixels and ad scripts until the visitor interacts or a few seconds pass, so they stop slowing the first view. Payment and consent scripts are never delayed.', 'speed-x-ray' ),
				__( 'e.g. “Delaying 4 domains: connect.facebook.net, embed.tawk.to, …”', 'speed-x-ray' ),
			),
			array(
				__( 'Real-visitor before / after', 'speed-x-ray' ),
				__( 'Every change you make is marked on a chart of your visitors’ Core Web Vitals, with the 7 days before compared to the 7 days after.', 'speed-x-ray' ),
				__( 'e.g. “After “Stopped woocommerce-general on /blog”: mobile LCP 3.9 s → 2.4 s”', 'speed-x-ray' ),
			),
			array(
				__( 'White-label client reports', 'speed-x-ray' ),
				__( 'A clean report with your agency’s name, logo and colour, built from the scans on the site — ready to save as PDF and send to your client. Agency plan.', 'speed-x-ray' ),
				__( 'Key pages, what to improve and who should do it, where server time goes, real-visitor vitals, monitoring trend.', 'speed-x-ray' ),
			),
		);
		$rows = array(
			__( 'Diagnosis of any page: server, database, HTML, files', 'speed-x-ray' ) => array( true, true ),
			__( 'Database time per plugin and slowest queries', 'speed-x-ray' ) => array( true, true ),
			__( 'Unused CSS/JS detection with per-page rules', 'speed-x-ray' ) => array( true, true ),
			__( 'Eleven reversible fixes incl. minifying unminified CSS/JS', 'speed-x-ray' ) => array( true, true ),
			__( 'Database cleanup, before/after, Google PageSpeed before/after', 'speed-x-ray' ) => array( true, true ),
			__( 'WebP copies of new uploads', 'speed-x-ray' )            => array( true, true ),
			__( 'Whole existing library + AVIF', 'speed-x-ray' )         => array( false, true ),
			__( 'Delay chat, tracking and ad scripts', 'speed-x-ray' )   => array( false, true ),
			__( 'Real-visitor before/after around each change', 'speed-x-ray' ) => array( false, true ),
			__( 'PHP time per plugin and theme', 'speed-x-ray' )         => array( false, true ),
			__( 'Site scan — every page ranked by slowness', 'speed-x-ray' ) => array( false, true ),
			__( 'Scheduled monitoring with email alerts', 'speed-x-ray' ) => array( false, true ),
			__( 'Real-visitor Core Web Vitals', 'speed-x-ray' )          => array( false, true ),
			__( 'White-label client reports (Agency)', 'speed-x-ray' )   => array( false, true ),
			__( 'Email support', 'speed-x-ray' )                         => array( false, true ),
		);

		echo '<div class="sxr-card sxr-pro-hero">';
		echo '<h2>' . esc_html__( 'Speed X-Ray Pro — see deeper, keep watching', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'The free plugin stays complete: every diagnosis and every fix you have now stays free, and nothing here is taken away from it. Pro adds depth — the full cost of each plugin — and keeps watching your site after you have fixed it.', 'speed-x-ray' ) . '</p>';
		echo '<p class="sxr-pro-cta"><a class="button button-primary button-hero" href="' . esc_url( self::url( 'pro-tab' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See plans & prices', 'speed-x-ray' ) . '</a> <span>' . esc_html__( '14-day money-back guarantee · keeps working if you don’t renew', 'speed-x-ray' ) . '</span></p>';
		echo '</div>';

		echo '<div class="sxr-pro-grid">';
		foreach ( $features as $f ) {
			echo '<div class="sxr-pro-feature"><h3>' . esc_html( $f[0] ) . '</h3><p>' . esc_html( $f[1] ) . '</p><p class="sxr-pro-example">' . esc_html( $f[2] ) . '</p></div>';
		}
		echo '</div>';

		echo '<div class="sxr-card"><h2>' . esc_html__( 'Free and Pro side by side', 'speed-x-ray' ) . '</h2><table class="widefat striped sxr-pro-compare"><thead><tr><th>' . esc_html__( 'Feature', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Free', 'speed-x-ray' ) . '</th><th>' . esc_html__( 'Pro', 'speed-x-ray' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $label => $in ) {
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( $in as $yes ) {
				echo '<td>' . ( $yes ? '<span class="sxr-yes" aria-label="' . esc_attr__( 'Included', 'speed-x-ray' ) . '">&#10003;</span>' : '<span class="sxr-no" aria-label="' . esc_attr__( 'Not included', 'speed-x-ray' ) . '">&mdash;</span>' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Already bought Pro?', 'speed-x-ray' ) . ' <a href="' . esc_url( self::url( 'pro-tab-account', 'my-account/licenses/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Download it from your account', 'speed-x-ray' ) . '</a>, ' . esc_html__( 'install it next to this plugin and enter your license key.', 'speed-x-ray' ) . '</p></div>';

		echo '<div class="sxr-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'speedxray_pro_tips' );
		echo '<input type="hidden" name="action" value="speedxray_pro_tips">';
		echo '<p>' . esc_html( self::tips_on() ? __( 'Short Pro notes are shown on this screen next to the things Pro would add to — never anywhere else in your admin.', 'speed-x-ray' ) : __( 'Pro tips in the diagnosis are switched off.', 'speed-x-ray' ) ) . ' ';
		submit_button( self::tips_on() ? __( 'Hide Pro tips', 'speed-x-ray' ) : __( 'Show Pro tips', 'speed-x-ray' ), 'secondary small', 'submit', false );
		echo '</p></form></div>';
	}
}
