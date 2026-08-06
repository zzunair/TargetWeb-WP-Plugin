<?php
/**
 * Minimal debug logger. Only ever writes when the admin "Debug logging" toggle
 * is on AND the active environment is not "production" — so nothing sensitive
 * gets logged in prod even if someone forgets to flip the toggle off.
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Logger {

	/**
	 * Log a message with optional context, gated by settings.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context to append (already scrubbed by caller).
	 */
	public static function log( $message, $context = array() ) {
		$settings = TW_CRM_Settings::get_settings();

		if ( empty( $settings['debug_mode'] ) ) {
			return;
		}
		if ( 'production' === $settings['environment'] ) {
			return; // Never log in production, even if the toggle was left on.
		}

		$line = '[TW CRM] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
