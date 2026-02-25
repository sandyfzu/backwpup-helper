<?php
/**
 * Plugin Name: BackWPup Helper
 * Description: Developer/testing utilities to simplify BackWPup testing and development.
 *              Adds a discreet admin-topbar entry to manage BackWPup backup folders
 *              and toggle the "Big backup" state so tests and local workflows are easier.
 * Version: 1.0.0
 * Author: Sandy Figueroa
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
	define( 'BWH_VERSION', '1.0.0' );
}

// Autoload or include main class
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-helper.php';

// Initialize plugin
function bwh_init_plugin() {
	$plugin = new BWH_Main();
	$plugin->init();
}

bwh_init_plugin();
