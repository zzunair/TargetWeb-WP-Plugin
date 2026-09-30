<?php
/**
 * Module: Lead Form ("Request Information")
 *
 * Standalone "Request Information" lead capture modal for the homepage,
 * product pages, or any public page. Replaces the theme's third-party targetWeb.js /
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

define( 'TW_CRM_VERSION', '1.4.1' );
define( 'TW_CRM_FILE', __FILE__ );
define( 'TW_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_CRM_URL', plugin_dir_url( __FILE__ ) );

define( 'TW_CRM_OPTION', 'tw_crm_settings' );
define( 'TW_CRM_NONCE_ACTION', 'tw_crm_lead_form' );
define( 'TW_CRM_AJAX_ACTION', 'tw_crm_submit_lead' );

/** Put this id on any theme button to open the Request Information form. */
define( 'TW_CRM_TRIGGER_ID', 'tw-request-info-btn' );
/** Theme-owned modal shell (used when Modal source = Theme modal). */
define( 'TW_CRM_THEME_MODAL_ID', 'tw-crm-popup' );
/** Plugin-owned modal shell (used when Modal source = Plugin modal). */
define( 'TW_CRM_PLUGIN_MODAL_ID', 'tw-crm-app-popup' );
/** Where the form is injected inside a theme modal. */
define( 'TW_CRM_FORM_MOUNT_ID', 'leads-form-div' );

/** Supported environments, in display order. */
define( 'TW_CRM_ENVIRONMENTS', array( 'dev', 'qa', 'staging', 'production' ) );

require_once TW_CRM_DIR . 'includes/class-tw-crm-logger.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-settings.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-api.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-frontend.php';
require_once TW_CRM_DIR . 'includes/class-tw-crm-ajax.php';

/**
 * Boot the module. WooCommerce is optional — the form also works on the
 * homepage and other non-product pages (product ID is included only when
 * a real product is available).
 */
function tw_crm_init() {
	delete_option( 'tw_crm_submit_log' );
	TW_CRM_Settings::init();
	TW_CRM_Ajax::init();
	TW_CRM_Frontend::init();
}
add_action( 'plugins_loaded', 'tw_crm_init' );

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
