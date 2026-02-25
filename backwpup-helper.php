<?php
/**
 * Plugin Name: BackWPup Helper
 * Description: Adds an admin-topbar helper to manage BackWPup backup folders and toggle Big backup state.
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

// Autoload or include main class
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-helper.php';

// Initialize plugin
function bwh_init_plugin() {
	$plugin = new BWH_Helper();
	$plugin->init();
}

bwh_init_plugin();
