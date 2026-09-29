<?php
/**
 * Optional debug logger. Writes to the PHP error log only when Debug
 * logging is enabled and the active environment is not production.
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Logger {

	/**
	 * Log a message with optional context, gated by the Debug logging toggle.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context to append.
	 */
	public static function log( $message, $context = array() ) {
		$settings = TW_CRM_Settings::get_settings();

		if ( empty( $settings['debug_mode'] ) ) {
			return;
		}

		if ( 'production' === TW_CRM_Settings::get_active_environment() ) {
			return;
		}

		$line = '[TW CRM] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
