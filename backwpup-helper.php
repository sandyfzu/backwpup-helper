<?php
/**
 * Plugin Name: BackWPup Helper
 * Plugin URI: https://github.com/sandyfzu/backwpup-helper
 * Description: Developer/testing utilities to simplify BackWPup testing and development.
 *              Adds a discreet admin-topbar entry to manage BackWPup backup folders
 *              and toggle the "Big backup" state so tests and local workflows are easier.
 * Version: 1.2.0
 * Update URI: https://bwh.com/backwpup-helper
 * Author: Sandy Figueroa
 * Author URI: https://github.com/sandyfzu
 * Text Domain: backwpup-helper
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'BWH_PLUGIN_FILE', __FILE__ );
define( 'BWH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Plugin version (bump on releases)
if ( ! defined( 'BWH_VERSION' ) ) {
	define( 'BWH_VERSION', '1.2.0' );
}

// Autoload or include main class
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-helper.php';
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-updater.php';

// Initialize plugin
function bwh_init_plugin() {
	$plugin = new BWH_Main();
	$plugin->init();

	$updater = new BWH_Updater( BWH_PLUGIN_FILE, BWH_VERSION, 'https://raw.githubusercontent.com/sandyfzu/backwpup-helper/refs/heads/main/release.json' );
	$updater->init();
}

bwh_init_plugin();
