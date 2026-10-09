<?php
/**
 * Step 6 — Images: settings, status and undo for WebP/AVIF copies.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

class SpeedXRay_Images_Admin {

	public static function init() {
		add_action( 'speedxray_render_tab_images', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_speedxray_images_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_speedxray_images_remove', array( __CLASS__, 'remove' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'speed-x-ray' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg ) {
		wp_safe_redirect( SpeedXRay_Admin::tab_url( 'images', array( 'sxr_img' => $msg ) ) );
		exit;
	}

	public static function save() {
		self::guard( 'speedxray_images_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$old   = SpeedXRay_Images::settings();
		$serve = isset( $_POST['serve'] ) && 'htaccess' === $_POST['serve'] && SpeedXRay_Images::apache() ? 'htaccess' : 'picture';
		$new   = array(
			'enabled'       => empty( $_POST['enabled'] ) ? 0 : 1,
			'quality'       => isset( $_POST['quality'] ) ? max( 40, min( 95, absint( $_POST['quality'] ) ) ) : 80,
			'compress_jpeg' => empty( $_POST['compress_jpeg'] ) ? 0 : 1,
			'serve'         => $serve,
		);
		// phpcs:enable
		$msg   = 'saved';
		if ( $new['enabled'] && 'htaccess' === $serve ) {
			if ( ! SpeedXRay_Images::write_htaccess( true ) ) {
				$new['serve'] = 'picture';
				$msg          = 'htaccess_failed';
			}
		} elseif ( 'htaccess' === $old['serve'] ) {
			SpeedXRay_Images::write_htaccess( false );
		}
		update_option( SpeedXRay_Images::OPTION, $new, true );
		if ( $new['enabled'] !== (int) $old['enabled'] ) {
			SpeedXRay_Helpers::log_change( $new['enabled'] ? __( 'Turned on WebP images for new uploads', 'speed-x-ray' ) : __( 'Turned off modern image formats', 'speed-x-ray' ), $new['enabled'] ? 'fix' : 'undo' );
		}
		self::back( $msg );
	}

	public static function remove() {
		self::guard( 'speedxray_images_remove' );
		$total = 0;
		do {
			$n      = SpeedXRay_Images::remove_batch( 200 );
			$total += $n;
		} while ( 200 === $n && $total < 20000 );
		SpeedXRay_Helpers::log_change( __( 'Removed all WebP/AVIF copies (originals untouched)', 'speed-x-ray' ), 'undo' );
		self::back( 'removed' );
	}

	public static function render() {
		$s       = SpeedXRay_Images::settings();
		$webp    = SpeedXRay_Images::supported( 'webp' );
		$avif    = SpeedXRay_Images::supported( 'avif' );
		$formats = SpeedXRay_Images::formats();
		$counts  = SpeedXRay_Images::library_counts();
		$stats   = SpeedXRay_Images::stats();
		$msg     = isset( $_GET['sxr_img'] ) ? sanitize_key( wp_unslash( $_GET['sxr_img'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'saved'           => array( 'success', __( 'Image settings saved. New uploads get modern copies from now on.', 'speed-x-ray' ) ),
			'removed'         => array( 'success', __( 'All WebP/AVIF copies were deleted. Your original images were never changed.', 'speed-x-ray' ) ),
			'htaccess_failed' => array( 'error', __( 'Could not write to .htaccess, so images are served with <picture> tags instead.', 'speed-x-ray' ) ),
		);
		if ( isset( $notices[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notices[ $msg ][0] ), esc_html( $notices[ $msg ][1] ) );
		}

		echo '<div class="sxr-card"><h2>' . esc_html__( 'Step 6 — Smaller images (WebP / AVIF)', 'speed-x-ray' ) . '</h2>';
		echo '<p>' . esc_html__( 'Images are usually most of a page’s weight. Speed X-Ray saves a WebP copy of every size of each JPEG/PNG you upload and serves it to browsers that support it. How much it saves depends on the image: a few percent on detailed, already-compressed photos, often more than half on graphics, screenshots and PNGs — the real total is shown below. Your original files are never changed, and a copy is only kept when it is actually smaller.', 'speed-x-ray' ) . '</p>';

		echo '<ul class="sxr-support">';
		echo '<li class="' . esc_attr( $webp ? 'is-yes' : 'is-no' ) . '">' . esc_html( $webp ? __( 'WebP: supported by your server', 'speed-x-ray' ) : __( 'WebP: not supported by your server’s image library — ask your host to enable WebP in GD or Imagick', 'speed-x-ray' ) ) . '</li>';
		echo '<li class="' . esc_attr( $avif ? 'is-yes' : 'is-no' ) . '">' . esc_html( $avif ? __( 'AVIF: supported by your server', 'speed-x-ray' ) : __( 'AVIF: not supported by your server (needs PHP 8.1+ GD built with AVIF, or Imagick with AVIF)', 'speed-x-ray' ) ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'speedxray_images_save' );
		echo '<input type="hidden" name="action" value="speedxray_images_save"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Modern formats', 'speed-x-ray' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], 1, false ) . disabled( $webp, false, false ) . '> ' . esc_html__( 'Create WebP copies of new uploads and serve them', 'speed-x-ray' ) . '</label>';
		if ( in_array( 'avif', $formats, true ) ) {
			echo '<p class="description">' . esc_html__( 'AVIF copies are created too (Speed X-Ray Pro).', 'speed-x-ray' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th><label for="sxr-quality">' . esc_html__( 'Quality', 'speed-x-ray' ) . '</label></th><td><input type="number" id="sxr-quality" name="quality" min="40" max="95" value="' . esc_attr( $s['quality'] ) . '" class="small-text"> <span class="description">' . esc_html__( '80 is a good balance. Lower = smaller files; below 70 you may see blur on photos.', 'speed-x-ray' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Compress JPEG sizes', 'speed-x-ray' ) . '</th><td><label><input type="checkbox" name="compress_jpeg" value="1"' . checked( $s['compress_jpeg'], 1, false ) . '> ' . esc_html__( 'Also use this quality for the JPEG sizes WordPress creates from new uploads (thumbnails, medium, large)', 'speed-x-ray' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'How to serve', 'speed-x-ray' ) . '</th><td>';
		echo '<label><input type="radio" name="serve" value="picture"' . checked( $s['serve'], 'picture', false ) . '> ' . esc_html__( '<picture> tags (works on every server)', 'speed-x-ray' ) . '</label><p class="description">' . esc_html__( 'Images in the page are wrapped in <picture> with WebP/AVIF sources. Images set as CSS backgrounds are not changed. If a theme styles images with “div > img”, check that layout.', 'speed-x-ray' ) . '</p>';
		if ( SpeedXRay_Images::apache() ) {
			echo '<label><input type="radio" name="serve" value="htaccess"' . checked( $s['serve'], 'htaccess', false ) . '> ' . esc_html__( 'Server rewrite (Apache)', 'speed-x-ray' ) . '</label><p class="description">' . esc_html__( 'Adds rules to .htaccess so the server answers image URLs with the WebP/AVIF copy when the browser accepts it — also covers CSS backgrounds. If you use a CDN, make sure it respects the “Vary: Accept” header.', 'speed-x-ray' ) . '</p>';
		}
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save image settings', 'speed-x-ray' ) );
		echo '</form></div>';

		echo '<div class="sxr-metrics">';
		$cards = array(
			array( __( 'JPEG/PNG images in library', 'speed-x-ray' ), number_format_i18n( $counts['total'] ), '' ),
			array( __( 'Images with modern copies', 'speed-x-ray' ), number_format_i18n( $counts['converted'] ), '' ),
			/* translators: %s: number of files. */
			array( __( 'Saved so far', 'speed-x-ray' ), SpeedXRay_Helpers::bytes( (int) $stats['saved'] ), sprintf( __( 'across %s files', 'speed-x-ray' ), number_format_i18n( $stats['files'] ) ) ),
		);
		foreach ( $cards as $c ) {
			echo '<div class="sxr-metric"><span class="sxr-metric-label">' . esc_html( $c[0] ) . '</span><span class="sxr-metric-value">' . esc_html( $c[1] ) . '</span><span class="sxr-metric-hint">' . esc_html( $c[2] ) . '</span></div>';
		}
		echo '</div>';

		/**
		 * Runs below the image settings (Pro adds bulk conversion of existing images here).
		 *
		 * @param array $counts Library counts: total, converted.
		 */
		do_action( 'speedxray_images_tab_after', $counts );

		if ( $counts['converted'] ) {
			echo '<div class="sxr-card"><h2>' . esc_html__( 'Undo', 'speed-x-ray' ) . '</h2><p>' . esc_html__( 'Deletes every WebP/AVIF copy Speed X-Ray created. Your original images stay exactly as they are.', 'speed-x-ray' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'speedxray_images_remove' );
			echo '<input type="hidden" name="action" value="speedxray_images_remove">';
			echo '<button class="button button-link-delete" data-confirm="' . esc_attr__( 'Delete all WebP/AVIF copies? Originals are not touched.', 'speed-x-ray' ) . '">' . esc_html__( 'Remove all generated copies', 'speed-x-ray' ) . '</button></form></div>';
		}
	}
}
