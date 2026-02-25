<?php
/**
 * Core plugin class for BackWPup Helper
 *
 * Responsible for registering admin bar items, AJAX handlers, and asset enqueues.
 *
 * PHP compatibility: 7.4+ (no PHP 8.x-only features used)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BackWPup_Helper {

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

        wp_register_script( 'bwh-admin', BWH_PLUGIN_URL . 'assets/js/admin.js', array(), '1.0.0', true );
        wp_register_style( 'bwh-admin', BWH_PLUGIN_URL . 'assets/css/admin.css', array(), '1.0.0' );

        wp_enqueue_script( 'bwh-admin' );
        wp_enqueue_style( 'bwh-admin' );

        wp_localize_script(
            'bwh-admin',
            'bwh_ajax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'bwh_nonce' ),
                'state'    => $this->is_bigbackup_active() ? 'active' : 'inactive',
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
            'title' => 'BackWPup',
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
        $state = $this->is_bigbackup_active() ? 'active' : 'inactive';

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

        $uploads = wp_get_upload_dir();
        $base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

        $targets = array( $base . '/backwpup', $base . '/backwpup-restore' );
        $results = array();

        foreach ( $targets as $dir ) {
            if ( is_dir( $dir ) ) {
                $ok = $this->rrmdir( $dir );
                $results[ $dir ] = $ok ? 'removed' : 'error';
            } else {
                $results[ $dir ] = 'not_found';
            }
        }

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

        $flag = WP_CONTENT_DIR . '/bigFiles/.donotbackup';
        $dir  = dirname( $flag );

        if ( file_exists( $flag ) ) {
            // Currently inactive -> remove file to activate
            $ok = @unlink( $flag );
            if ( $ok ) {
                wp_send_json_success( array( 'state' => 'active' ) );
            }
            wp_send_json_error( array( 'state' => 'error' ) );
        } else {
            // Create directory if needed and create empty flag file to deactivate
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            $ok = @touch( $flag );
            if ( $ok ) {
                wp_send_json_success( array( 'state' => 'inactive' ) );
            }
            wp_send_json_error( array( 'state' => 'error' ) );
        }
    }

    /**
     * Return true if big backup is active (i.e., `.donotbackup` does NOT exist)
     *
     * @return bool
     */
    public function is_bigbackup_active() {
        $flag = WP_CONTENT_DIR . '/bigFiles/.donotbackup';
        return ! file_exists( $flag );
    }

    /**
     * Recursively remove a directory and its contents.
     * Uses SPL iterators for performance and reliability.
     *
     * @param string $dir
     * @return bool
     */
    protected function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        // Use RecursiveDirectoryIterator to iterate and remove files/dirs
        try {
            $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
            $ri = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $ri as $file ) {
                /** @var SplFileInfo $file */
                if ( $file->isDir() ) {
                    @rmdir( $file->getPathname() );
                } else {
                    @unlink( $file->getPathname() );
                }
            }
            return @rmdir( $dir );
        } catch ( Exception $e ) {
            return false;
        }
    }

}
