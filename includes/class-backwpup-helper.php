<?php
/**
 * Core plugin class for BackWPup Helper
 *
 * Provides lightweight developer and testing utilities for BackWPup. Exposes
 * a discreet admin-topbar entry that helps operators and automated tests
 * manage backup folders and toggle the "Big backup" flag without touching
 * production configuration files manually.
 *
 * Responsible for registering admin bar items, AJAX handlers, and asset enqueues.
 *
 * PHP compatibility: 7.4+ (no PHP 8.x-only features used)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register WP-CLI commands if WP-CLI is available
// Load shared service
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-service.php';

// Register WP-CLI commands if WP-CLI is available
if ( defined( 'WP_CLI' ) || class_exists( 'WP_CLI' ) ) {
    require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-commands.php';
}
/**
 * Main plugin class (prefixed to avoid conflicts with BackWPup plugin).
 */
class BWH_Main {

    /**
     * Initialize hooks
     */
    public function init() {
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_items' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'wp_ajax_bwh_clear_backups', array( $this, 'ajax_clear_backups' ) );
        add_action( 'wp_ajax_bwh_toggle_big_backup', array( $this, 'ajax_toggle_big_backup' ) );
    }

    /**
     * Enqueue JS/CSS used by the admin bar helper.
     */
    public function enqueue_assets() {
        if ( ! is_admin_bar_showing() ) {
            return; // nothing to show
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return; // limit to site admins
        }

        $js_file = BWH_PLUGIN_DIR . 'assets/js/admin.js';
        $css_file = BWH_PLUGIN_DIR . 'assets/css/admin.css';

        // Use plugin version for cache-busting in production; during development (WP_DEBUG)
        // prefer file modification time so changes are visible immediately.
        if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ! ( defined( 'BWH_VERSION' ) && BWH_VERSION ) ) {
            $js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : BWH_VERSION;
            $css_ver = file_exists( $css_file ) ? filemtime( $css_file ) : BWH_VERSION;
        } else {
            $js_ver  = defined( 'BWH_VERSION' ) ? BWH_VERSION : false;
            $css_ver = defined( 'BWH_VERSION' ) ? BWH_VERSION : false;
        }

        wp_register_script( 'bwh-admin', BWH_PLUGIN_URL . 'assets/js/admin.js', array(), $js_ver, true );
        wp_register_style( 'bwh-admin', BWH_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );

        wp_enqueue_script( 'bwh-admin' );
        wp_enqueue_style( 'bwh-admin' );

        wp_localize_script(
            'bwh-admin',
            'bwh_ajax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'bwh_nonce' ),
                'state'    => BWH_Service::is_bigbackup_active() ? 'active' : 'inactive',
            )
        );
    }

    /**
     * Add entries to the WP Admin Bar
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_items( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Top-level item (not a button)
        $wp_admin_bar->add_node( array(
            'id'    => 'bwh_root',
            'title' => 'BackWPup Helper',
            'href'  => false,
            'meta'  => array( 'class' => 'bwh-root' ),
        ) );

        // Clear backup data
        $wp_admin_bar->add_node( array(
            'id'     => 'bwh_clear',
            'parent' => 'bwh_root',
            'title'  => 'Clear backup data',
            'href'   => '#',
            'meta'   => array( 'class' => 'bwh-clear' ),
        ) );

        // Big backup state (initial text will be updated by JS for colored tag)
        $state = BWH_Service::is_bigbackup_active() ? 'active' : 'inactive';

        $wp_admin_bar->add_node( array(
            'id'     => 'bwh_bigbackup',
            'parent' => 'bwh_root',
            'title'  => sprintf( 'Big backup: %s', $state ),
            'href'   => '#',
            'meta'   => array( 'class' => 'bwh-toggle', 'data-state' => $state ),
        ) );
    }

    /**
     * AJAX handler to clear backups directories.
     * Removes `backwpup` and `backwpup-restore` from the uploads directory.
     */
    public function ajax_clear_backups() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        check_ajax_referer( 'bwh_nonce', 'nonce' );

        $results = BWH_Service::clear_backups();
        wp_send_json_success( array( 'results' => $results ) );
    }

    /**
     * AJAX handler to toggle the `.donotbackup` flag file in wp-content/bigFiles/
     */
    public function ajax_toggle_big_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        check_ajax_referer( 'bwh_nonce', 'nonce' );

        $new_state = BWH_Service::toggle_bigbackup();
        if ( $new_state === 'error' ) {
            wp_send_json_error( array( 'state' => 'error' ) );
        }
        wp_send_json_success( array( 'state' => $new_state ) );
    }

    /**
     * Return true if big backup is active (i.e., `.donotbackup` does NOT exist)
     *
     * @return bool
     */
    // Service methods moved to BackWPup_Service

}
