<?php
/**
 * Module: Lead Form ("Request Information")
 *
 * Standalone "Request Information" lead capture modal for WooCommerce single
 * product pages. Replaces the theme's third-party targetWeb.js /
 * displayQuotationForm() flow with a module-owned form, environment-aware
 * endpoint configuration, and a WordPress AJAX submit handler.
 *
 * Architecture:
 *   [#tw-request-info-btn click] -> [modal opens] -> [renders lead form]
 *   -> [submit] -> [wp_ajax_tw_crm_submit_lead] -> [active environment endpoint]
 *   -> [success / error UI in modal]
 *
 * IMPORTANT: This module does NOT load targetWeb.js and does NOT call
 * displayQuotationForm(). It owns the full UI + submit flow itself.
 *
 * Loaded by the main TargetWeb plugin bootstrap (../../targetweb.php), which
 * discovers and requires every modules/*\/module.php file automatically.
 *
 * @package TargetWeb\LeadForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TW_CRM_VERSION', '1.0.0' );
define( 'TW_CRM_FILE', __FILE__ );
define( 'TW_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_CRM_URL', plugin_dir_url( __FILE__ ) );

define( 'TW_CRM_OPTION', 'tw_crm_settings' );
define( 'TW_CRM_NONCE_ACTION', 'tw_crm_lead_form' );
define( 'TW_CRM_AJAX_ACTION', 'tw_crm_submit_lead' );

/** Supported environments, in display order. */
define( 'TW_CRM_ENVIRONMENTS', array( 'dev', 'qa', 'staging', 'production' ) );

require_once TW_CRM_DIR . 'includes/class-tw-crm-logger.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-settings.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-api.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-frontend.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-ajax.php';

/**
 * Boot the plugin once all plugins are loaded (so we can soft-detect WooCommerce).
 */
function tw_crm_init() {
	TW_CRM_Settings::init();
	TW_CRM_Ajax::init();

	// Soft dependency on WooCommerce: only wire frontend behavior if Woo is active.
	if ( class_exists( 'WooCommerce' ) ) {
		TW_CRM_Frontend::init();
	} else {
		add_action( 'admin_notices', 'tw_crm_missing_woocommerce_notice' );
	}
}
add_action( 'plugins_loaded', 'tw_crm_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function tw_crm_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>TargetWeb — Lead Form module</strong>: WooCommerce is not active. The Request Information button/modal only appears on WooCommerce product pages, so this module is currently inactive on the frontend.</p></div>';
}

/**
 * Set sensible defaults on activation (does not overwrite existing settings).
 */
function tw_crm_activate() {
	if ( false === get_option( TW_CRM_OPTION, false ) ) {
		update_option( TW_CRM_OPTION, TW_CRM_Settings::defaults() );
	}
}
// Registered against the main plugin file (TW_FILE), not this module file —
// WordPress only fires activation hooks tied to the plugin's main entry file.
register_activation_hook( TW_FILE, 'tw_crm_activate' );
