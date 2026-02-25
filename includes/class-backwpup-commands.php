<?php
/**
 * WP-CLI commands for BackWPup Helper
 *
 * Provides simple CLI commands for clearing backups and toggling Big backup flag.
 * Commands:
 *  - wp bwh clear [--dry-run]    Clear uploads/backwpup* directories
 *  - wp bwh toggle                Toggle bigFiles/.donotbackup
 *  - wp bwh status                Show current big backup status
 *
 * Designed to be safe: provide --dry-run for clear command.
 */

// Bail early when WP-CLI is not available in this environment
if ( ! ( defined( 'WP_CLI' ) || class_exists( 'WP_CLI' ) ) ) {
    return;
}

// Load shared service
require_once BWH_PLUGIN_DIR . 'includes/class-backwpup-service.php';

/**
 * WP-CLI commands for BackWPup Helper (prefixed to avoid conflicts).
 */
class BWH_CLI {
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

    /**
     * Internal recursive directory removal used by CLI command.
     * Copied logic mirrors the main plugin implementation.
     *
     * @param string $dir
     * @return bool
     */
    protected function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        try {
            $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
            $ri = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $ri as $file ) {
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

    call_user_func( array( 'WP_CLI', 'add_command' ), 'bwh', 'BWH_CLI' );
