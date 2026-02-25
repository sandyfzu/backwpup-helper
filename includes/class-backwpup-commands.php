<?php
/**
 * WP-CLI commands for BackWPup Helper
 *
 * Provides two subcommands under the `bwh` namespace:
 *  - `bwh backups clear [--dry-run]`  : clear backup directories under uploads
 *  - `bwh bigbackup status|toggle`     : inspect and toggle the bigFiles flag
 */

// Bail early when WP-CLI is not available in this environment
if ( ! ( defined( 'WP_CLI' ) || class_exists( 'WP_CLI' ) ) ) {
    return;
}

// Load shared service
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-service.php';

/**
 * WP-CLI command: `wp bwh backups clear [--dry-run]`
 */
class BWH_CLI_Backups {
    /**
     * Clear backup directories under the uploads folder.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function clear( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );

        $targets = BWH_Service::get_backup_dirs();
        foreach ( $targets as $dir ) {
            if ( is_dir( $dir ) ) {
                if ( $dry_run ) {
                    call_user_func( array( 'WP_CLI', 'log' ), "Would remove: {$dir}" );
                } else {
                    $results = BWH_Service::clear_backups();
                    $status = isset( $results[ $dir ] ) ? $results[ $dir ] : 'error';
                    if ( $status === 'removed' ) {
                        call_user_func( array( 'WP_CLI', 'success' ), "Removed: {$dir}" );
                    } else {
                        call_user_func( array( 'WP_CLI', 'warning' ), "Failed to remove: {$dir}" );
                    }
                }
            } else {
                call_user_func( array( 'WP_CLI', 'log' ), "Not found: {$dir}" );
            }
        }
    }
}

/**
 * WP-CLI command: `wp bwh bigbackup <status|toggle>`
 */
class BWH_CLI_BigBackup {
    /**
     * Toggle the bigFiles/.donotbackup flag file.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function toggle( $args, $assoc_args ) {
        $new_state = BWH_Service::toggle_bigbackup();
        if ( $new_state === 'error' ) {
            call_user_func( array( 'WP_CLI', 'error' ), 'Could not toggle big backup flag' );
        } else {
            call_user_func( array( 'WP_CLI', 'success' ), sprintf( 'Big backup -> %s', $new_state ) );
        }
    }

    /**
     * Show current big backup status.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status( $args, $assoc_args ) {
        $state = BWH_Service::is_bigbackup_active() ? 'active' : 'inactive';
        call_user_func( array( 'WP_CLI', 'log' ), $state );
    }
}

// Register the two subcommands under `bwh` namespace
call_user_func( array( 'WP_CLI', 'add_command' ), 'bwh backups', 'BWH_CLI_Backups' );
call_user_func( array( 'WP_CLI', 'add_command' ), 'bwh bigbackup', 'BWH_CLI_BigBackup' );
