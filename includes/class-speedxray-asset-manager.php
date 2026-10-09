<?php
/**
 * Step 2: per-page CSS/JS rules.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies the "stop this file" rules the user creates in step 2.
 * A rule is scoped to one page path or to the whole site.
 */
class SpeedXRay_Asset_Manager {

	const OPTION = 'speedxray_asset_rules';

	private static $disabled = array();

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		// Run late at enqueue time and again right before each print, to catch late enqueues.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'apply' ), PHP_INT_MAX );
		add_action( 'wp_print_styles', array( __CLASS__, 'apply' ), 1 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'apply' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'apply' ), 1 );
	}

	public static function rules() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function rule_key( $type, $handle, $scope, $path ) {
		return $type . '|' . $handle . '|' . $scope . '|' . ( 'page' === $scope ? $path : '*' );
	}

	public static function add_rule( $type, $handle, $scope, $path ) {
		$rules         = self::rules();
		$key           = self::rule_key( $type, $handle, $scope, $path );
		$rules[ $key ] = array(
			'type'    => $type,
			'handle'  => $handle,
			'scope'   => $scope,
			'path'    => 'page' === $scope ? $path : '',
			'created' => time(),
		);
		update_option( self::OPTION, $rules, true );
	}

	public static function remove_rule( $key ) {
		$rules = self::rules();
		unset( $rules[ $key ] );
		update_option( self::OPTION, $rules, true );
	}

	/** The rule that would stop this file on this path, if any. */
	public static function matching_rule( $type, $handle, $path ) {
		foreach ( self::rules() as $key => $rule ) {
			if ( $rule['type'] === $type && $rule['handle'] === $handle && ( 'everywhere' === $rule['scope'] || $rule['path'] === $path ) ) {
				return $key;
			}
		}
		return null;
	}

	public static function apply() {
		if ( SpeedXRay_Helpers::is_bypassed() ) {
			return;
		}
		$path = SpeedXRay_Helpers::current_path();
		foreach ( self::rules() as $key => $rule ) {
			if ( 'page' === $rule['scope'] && $rule['path'] !== $path ) {
				continue;
			}
			$deps = 'style' === $rule['type'] ? wp_styles() : wp_scripts();
			// Only files queued directly can be stopped; a file another loaded file depends on
			// will still load, which the scanner reports as "needed by".
			if ( ! in_array( $rule['handle'], $deps->queue, true ) ) {
				continue;
			}
			$deps->dequeue( $rule['handle'] );
			self::$disabled[ $key ] = $rule['handle'];
		}
	}

	public static function disabled_log() {
		return self::$disabled;
	}
}
