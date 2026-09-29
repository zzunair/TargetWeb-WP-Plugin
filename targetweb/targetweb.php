<?php
/**
 * Plugin Name: TargetWeb
 * Description: TargetWeb's WordPress/WooCommerce integrations, organized as independent modules (Request Information lead form, and more to come).
 * Version: 1.5.0
 * Author: TargetWeb
 * Text Domain: targetweb
 * Requires PHP: 7.4
 * Update URI: https://github.com/zzunair/TargetWeb-WP-Plugin
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

define( 'TW_VERSION', '1.5.0' );
define( 'TW_FILE', __FILE__ );
define( 'TW_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_URL', plugin_dir_url( __FILE__ ) );
define( 'TW_MODULES_DIR', TW_DIR . 'modules/' );

/**
 * Self-update via GitHub Releases, using the plugin-update-checker library
 * (https://github.com/YahnisElsts/plugin-update-checker). This repo hosts more
 * than one plugin, so releases are published with one ZIP asset per plugin —
 * this instance only watches for the "targetweb.zip" asset.
 *
 * To ship an update: bump the "Version" header above (and TW_VERSION), commit,
 * then push a new tag (e.g. `git tag v1.1.0 && git push origin v1.1.0`). The
 * repo's GitHub Actions workflow (.github/workflows/release.yml) builds the
 * ZIP and attaches it to a GitHub Release automatically.
 */
if ( file_exists( TW_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once TW_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$tw_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/zzunair/TargetWeb-WP-Plugin/',
		TW_FILE,
		'targetweb'
	);
	$tw_update_checker->setBranch( 'main' );
	$tw_update_checker->getVcsApi()->enableReleaseAssets( '/^targetweb\.zip$/i' );
}

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
