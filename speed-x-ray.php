<?php
/**
 * Plugin Name:       Speed X-Ray
 * Plugin URI:        https://wordpress.org/plugins/speed-x-ray/
 * Description:       Diagnose-first performance plugin. Finds what is actually slowing each page (server, database, plugins, theme/builder, files), says honestly what a plugin can and cannot fix, then lets you fix it step by step with one-click rollback.
 * Version:           1.2.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            munishk4486
 * Author URI:        https://profiles.wordpress.org/munishk4486/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       speed-x-ray
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

define( 'SPEEDXRAY_VERSION', '1.2.0' );
define( 'SPEEDXRAY_FILE', __FILE__ );
define( 'SPEEDXRAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPEEDXRAY_URL', plugin_dir_url( __FILE__ ) );

require_once SPEEDXRAY_DIR . 'includes/class-speedxray-helpers.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-profiler.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-asset-manager.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-optimizations.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-scanner.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-db-cleaner.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-output.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-images.php';
require_once SPEEDXRAY_DIR . 'includes/class-speedxray-minify.php';

// Start as early as possible so queries made by plugins loaded after this one are measured.
SpeedXRay_Profiler::maybe_start();

/**
 * Creates the options read on every page load, autoloaded, so a missing option never costs a query.
 */
function speedxray_add_default_options() {
	add_option( 'speedxray_paused', 0, '', true );
	add_option( 'speedxray_opts', array(), '', true );
	add_option( 'speedxray_asset_rules', array(), '', true );
	add_option( 'speedxray_images', array(), '', true );
}
register_activation_hook( __FILE__, 'speedxray_add_default_options' );
add_action(
	'admin_init',
	function () {
		if ( false === get_option( 'speedxray_opts' ) || false === get_option( 'speedxray_images' ) ) {
			speedxray_add_default_options(); // Installs updated from 1.0.0 never ran the activation hook.
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		SpeedXRay_Asset_Manager::init();
		SpeedXRay_Optimizations::init();
		SpeedXRay_Output::init();
		SpeedXRay_Images::init();
		SpeedXRay_Minify::init();

		if ( is_admin() ) {
			require_once SPEEDXRAY_DIR . 'includes/class-speedxray-admin.php';
			require_once SPEEDXRAY_DIR . 'includes/class-speedxray-images-admin.php';
			require_once SPEEDXRAY_DIR . 'includes/class-speedxray-pagespeed.php';
			SpeedXRay_Admin::init();
			SpeedXRay_Images_Admin::init();
			SpeedXRay_PageSpeed::init();

			// What Pro adds — only while Pro is not installed and active.
			if ( ! defined( 'SPEEDXRAY_PRO_VERSION' ) ) {
				require_once SPEEDXRAY_DIR . 'includes/class-speedxray-promo.php';
				SpeedXRay_Promo::init();
			}
		}
	}
);
