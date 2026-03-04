<?php
/**
 * WP-CLI commands for BackWPup Helper
 *
 * Provides developer-oriented WP-CLI helpers under the `bwh` namespace that
 * make it easier to script and test BackWPup behaviors from the command line.
 *
 * Subcommands:
 *  - `bwh backups clear [--dry-run]`       : clear backup directories under uploads
 *  - `bwh bigbackup status|toggle`          : inspect and toggle the bigFiles flag
 *  - `bwh debugmonitor status|toggle`       : inspect and toggle the debug monitor option
 *  - `bwh debuglog status|view|delete`      : inspect, view, or delete the debug log file
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
            $d = BWH_Service::get_bigbackup_diagnostics();
            $message = sprintf(
                'Could not toggle big backup flag. flag=%s; dir_exists=%s; dir_writable=%s; file_exists=%s; file_writable=%s',
                $d['flag'],
                $d['dir_exists'] ? 'yes' : 'no',
                $d['dir_writable'] ? 'yes' : 'no',
                $d['file_exists'] ? 'yes' : 'no',
                $d['file_writable'] ? 'yes' : 'no'
            );

            if ( ! $d['dir_writable'] || ( $d['file_exists'] && ! $d['file_writable'] ) ) {
                $message .= ' Hint: run WP-CLI as the same OS user/group as the web server process, or adjust ownership/permissions for wp-content/bigFiles/.donotbackup.';
            }

            call_user_func( array( 'WP_CLI', 'error' ), $message );
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

/**
 * WP-CLI command: `wp bwh debugmonitor <status|toggle>`
 */
class BWH_CLI_DebugMonitor {
    /**
     * Toggle the debug monitor option.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function toggle( $args, $assoc_args ) {
        $new_state = BWH_Service::toggle_debug_monitor();
        call_user_func( array( 'WP_CLI', 'success' ), sprintf( 'Debug monitor -> %s', $new_state ) );
    }

    /**
     * Show current debug monitor status.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status( $args, $assoc_args ) {
        $state = BWH_Service::is_debug_monitor_active() ? 'active' : 'inactive';
        call_user_func( array( 'WP_CLI', 'log' ), $state );
    }
}

/**
 * WP-CLI command: `wp bwh debuglog <status|view|delete>`
 */
class BWH_CLI_DebugLog {
    /**
     * Show debug log file status.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status( $args, $assoc_args ) {
        $s = BWH_Service::get_debug_log_status();
        if ( ! $s['exists'] || 0 === $s['size'] ) {
            call_user_func( array( 'WP_CLI', 'log' ), 'clear' );
        } else {
            call_user_func( array( 'WP_CLI', 'log' ), sprintf( '%s (%s)', $s['size_human'], $s['fingerprint'] ) );
        }
    }

    /**
     * Output the debug log content to stdout (tail, max 512 KB).
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function view( $args, $assoc_args ) {
        $r = BWH_Service::get_debug_log_content();
        if ( isset( $r['error'] ) ) {
            call_user_func( array( 'WP_CLI', 'error' ), $r['error'] );
            return;
        }
        if ( $r['truncated'] ) {
            call_user_func( array( 'WP_CLI', 'warning' ), sprintf( 'Showing last 512 KB of %s', $r['total_size'] ) );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output
        echo $r['content'];
    }

    /**
     * Delete the debug log file.
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function delete( $args, $assoc_args ) {
        $result = BWH_Service::delete_debug_log();
        if ( 'deleted' === $result ) {
            call_user_func( array( 'WP_CLI', 'success' ), 'Debug log deleted.' );
        } elseif ( 'not_found' === $result ) {
            call_user_func( array( 'WP_CLI', 'log' ), 'No debug log found.' );
        } else {
            call_user_func( array( 'WP_CLI', 'error' ), 'Could not delete debug log.' );
        }
    }
}

call_user_func( array( 'WP_CLI', 'add_command' ), 'bwh debugmonitor', 'BWH_CLI_DebugMonitor' );
call_user_func( array( 'WP_CLI', 'add_command' ), 'bwh debuglog', 'BWH_CLI_DebugLog' );
