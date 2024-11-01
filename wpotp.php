<?php
/**
 * Plugin initialization.
 *
 * @package wpotp
 */

/**
 * Plugin Name: Two-factor authentication - SMS OTP Security
 * Plugin URI: https://wordpress.org/plugins/wpotp/
 * Description: Temporary passcode via SMS for a second authentication factor (2FA).
 * Version: 1.0.1
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Francis Santerre
 * Author URI: https://santerref.com/
 * Text Domain: wpotp
 * Domain Path: /languages
 */

const WPOTP_VERSION  = '1.0.1';
const WPOTP_BASE_URL = 'https://wpotp.com/';

require_once 'class-wp-otp.php';
require_once 'class-wp-otp-api.php';

if ( ! function_exists( 'wpotp' ) ) {

	/**
	 * Global function for developers.
	 *
	 * @return mixed|WP_OTP
	 */
	function wpotp() {
		static $plugin;

		if ( ! isset( $plugin ) ) {
			$plugin = new WP_OTP();
		}

		return $plugin;
	}

	wpotp();

}
