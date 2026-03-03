<?php
/**
 * Uninstall handler for BackWPup Helper.
 *
 * Runs when the plugin is deleted via the WordPress admin. Removes
 * plugin-specific options from the database.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options.
delete_option( 'bwh_debug_monitor' );
