<?php
/**
 * Module: Parts Portal
 *
 * Creates and keeps in sync the storefront "Part Finder" Page + main-menu
 * entry that embeds the TargetWeb Parts Portal iframe on WooCommerce sites -
 * the WordPress counterpart of what PartsPortalService does natively for
 * Shopify via its Admin GraphQL API.
 *
 * Shopify dealers give TargetWeb an Admin OAuth token, so the backend can
 * create the Page/menu directly. WordPress dealers only ever give TargetWeb
 * a WooCommerce consumer key/secret, which cannot create a Page or a
 * nav-menu item. So instead, TargetWeb calls directly into this site (by
 * the domain already on file for this dealer) whenever a dealer saves or
 * enables Parts Portal, and this module does the actual
 * wp_insert_post()/nav-menu work locally, right there in that same request.
 *
 * Architecture:
 *   [Dealer saves/enables Parts Portal in TargetWeb]
 *                     |
 *                     v
 *   POST {this site}/wp-json/targetweb/v1/parts-portal/sync
 *   (Site-Url header self-checked against home_url() - same pattern as the
 *   TargetWeb Locations plugin's existing /locations endpoint; no shared
 *   secret, no cron, no local settings)
 *                     |
 *                     v
 *   wp_insert_post()/wp_update_post() (Page, [tw_parts_portal] shortcode)
 *   + wp_update_nav_menu_item() (site's nav menu)
 *                     |
 *                     v
 *   Response carries {pageId, pageUrl, menuItemId} back to TargetWeb directly
 *
 * Zero manual configuration: there is nothing to set up on this module at
 * all. See includes/class-tw-parts-sync.php for the endpoint itself.
 *
 * Loaded by the main TargetWeb plugin bootstrap (../../targetweb.php), which
 * discovers and requires every modules/*\/module.php file automatically.
 *
 * @package TargetWeb\PartsPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TW_PARTS_VERSION', '1.0.0' );
define( 'TW_PARTS_FILE', __FILE__ );
define( 'TW_PARTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_PARTS_URL', plugin_dir_url( __FILE__ ) );

define( 'TW_PARTS_SHORTCODE', 'tw_parts_portal' );
define( 'TW_PARTS_PAGE_SLUG', 'part-finder' );

require_once TW_PARTS_DIR . 'includes/class-tw-parts-settings.php';
require_once TW_PARTS_DIR . 'includes/class-tw-parts-sync.php';
require_once TW_PARTS_DIR . 'includes/class-tw-parts-frontend.php';

/**
 * Boot the module once all plugins are loaded (so we can soft-detect WooCommerce).
 */
function tw_parts_init() {
	TW_Parts_Settings::init();

	// The REST route itself always registers (so a request arriving before
	// WooCommerce is confirmed active still gets a clear 424, not a 404),
	// but the frontend shortcode/script only make sense on a WooCommerce store.
	TW_Parts_Sync::init();

	if ( class_exists( 'WooCommerce' ) ) {
		TW_Parts_Frontend::init();
	} else {
		add_action( 'admin_notices', 'tw_parts_missing_woocommerce_notice' );
	}
}
add_action( 'plugins_loaded', 'tw_parts_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function tw_parts_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>TargetWeb — Parts Portal module</strong>: WooCommerce is not active. The Part Finder page cannot be created until WooCommerce is active.</p></div>';
}
