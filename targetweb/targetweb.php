<?php
/**
 * Plugin Name: TargetWeb
 * Description: TargetWeb's WordPress/WooCommerce integrations, organized as independent modules (Request Information lead form, and more to come).
 * Version: 1.0.0
 * Author: TargetWeb
 * Text Domain: targetweb
 * Requires PHP: 7.4
 *
 * Structure:
 *   targetweb.php          This file — discovers and loads every module.
 *   modules/<name>/module.php   One self-contained feature per folder.
 *
 * Adding a new feature later means adding a new modules/<name>/module.php —
 * this file does not need to change. See README.md for the full module
 * contract (constants, hooks, activation) new modules should follow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TW_VERSION', '1.0.0' );
define( 'TW_FILE', __FILE__ );
define( 'TW_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_URL', plugin_dir_url( __FILE__ ) );
define( 'TW_MODULES_DIR', TW_DIR . 'modules/' );

/**
 * Discover and load every module. A module is any immediate subdirectory of
 * modules/ that contains a module.php file; each module.php is responsible
 * for its own constants, classes, and hook registration (typically on
 * `plugins_loaded`, so soft dependencies like WooCommerce can be checked).
 */
function tw_load_modules() {
	$module_files = glob( TW_MODULES_DIR . '*/module.php' );
	if ( ! $module_files ) {
		return;
	}
	foreach ( $module_files as $module_file ) {
		require_once $module_file;
	}
}
tw_load_modules();
