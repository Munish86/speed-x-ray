<?php
/**
 * Removes everything Speed X-Ray stored or created when the plugin is deleted.
 * Original images are never touched; only the WebP/AVIF copies it made are removed.
 *
 * @package SpeedXRay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// WebP/AVIF copies, found through the attachment meta the plugin wrote.
$speedxray_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_speedxray_images'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $speedxray_ids as $speedxray_id ) {
	$speedxray_main = get_attached_file( (int) $speedxray_id );
	if ( ! $speedxray_main ) {
		continue;
	}
	$speedxray_files = array( $speedxray_main );
	$speedxray_meta  = wp_get_attachment_metadata( (int) $speedxray_id );
	if ( ! empty( $speedxray_meta['sizes'] ) ) {
		foreach ( $speedxray_meta['sizes'] as $speedxray_size ) {
			$speedxray_files[] = trailingslashit( dirname( $speedxray_main ) ) . $speedxray_size['file'];
		}
	}
	foreach ( $speedxray_files as $speedxray_file ) {
		foreach ( array( 'webp', 'avif' ) as $speedxray_format ) {
			if ( is_file( $speedxray_file . '.' . $speedxray_format ) ) {
				wp_delete_file( $speedxray_file . '.' . $speedxray_format );
			}
		}
	}
}
delete_post_meta_by_key( '_speedxray_images' );

// Minified file cache.
$speedxray_uploads = wp_upload_dir( null, false );
foreach ( (array) glob( trailingslashit( $speedxray_uploads['basedir'] ) . 'speedxray-cache/min/*' ) as $speedxray_cached ) {
	if ( is_file( $speedxray_cached ) ) {
		wp_delete_file( $speedxray_cached );
	}
}

// Image rewrite rules in .htaccess.
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( is_file( get_home_path() . '.htaccess' ) && wp_is_writable( get_home_path() . '.htaccess' ) ) {
	insert_with_markers( get_home_path() . '.htaccess', 'Speed X-Ray images', array() );
}

foreach ( array( 'speedxray_reports', 'speedxray_last_path', 'speedxray_asset_rules', 'speedxray_opts', 'speedxray_defer_exclude', 'speedxray_paused', 'speedxray_profile_token', 'speedxray_hide_pro_tips', 'speedxray_images', 'speedxray_images_stats', 'speedxray_changes', 'speedxray_psi', 'speedxray_psi_key', 'speedxray_minify_exclude' ) as $speedxray_option ) {
	delete_option( $speedxray_option );
}
