<?php
/**
 * Shared service for BackWPup file operations and state checks.
 *
 * Centralizes path resolution, recursive removal, and flag toggling so logic
 * isn't duplicated between web/AJAX handlers and WP-CLI commands. The
 * service focuses on safe, test-friendly operations intended to simplify
 * BackWPup testing and local development workflows.
 *
 * PHP: 7.4+ compatible
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared service for BackWPup Helper file operations and state checks.
 * Prefixed to avoid conflicts with BackWPup plugin.
 */
class BWH_Service {

    /**
     * Transient key for caching backup directory size info.
     */
    const BACKUP_DIR_INFO_TRANSIENT = 'bwh_backup_dir_info';

    /**
     * TTL (seconds) for backup directory info cache.
     */
    const BACKUP_DIR_INFO_TTL = 20;

    /**
     * Return uploads base directory reliably using WP API.
     *
     * @return string
     */
    public static function get_uploads_base() {
        $uploads = wp_get_upload_dir();
        return isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
    }

    /**
     * Return directories to clear under uploads.
     *
     * @return string[]
     */
    public static function get_backup_dirs() {
        $base = self::get_uploads_base();
        return array( $base . '/backwpup', $base . '/backwpup-restore' );
    }

    /**
     * Remove backup directories and return structured results.
     *
     * @return array dir => status ('removed'|'not_found'|'error')
     */
    public static function clear_backups() {
        $targets = self::get_backup_dirs();
        $results = array();

        foreach ( $targets as $dir ) {
            if ( is_dir( $dir ) ) {
                $ok = self::rrmdir( $dir );
                $results[ $dir ] = $ok ? 'removed' : 'error';
            } else {
                $results[ $dir ] = 'not_found';
            }
        }

        // Invalidate cached size info after any clear attempt.
        delete_transient( self::BACKUP_DIR_INFO_TRANSIENT );

        return $results;
    }

    /**
     * Return size information about the uploads/backwpup directory.
     *
     * Walks the directory tree with SPL iterators to sum file sizes.
     * Used for the admin-bar hover refresh display.
     *
     * @return array{exists: bool, size: int, size_human: string, file_count: int}
     */
    public static function get_backup_dir_info() {
        $cached = get_transient( self::BACKUP_DIR_INFO_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $dir = self::get_uploads_base() . '/backwpup';

        if ( ! is_dir( $dir ) ) {
            $result = array(
                'exists'     => false,
                'size'       => 0,
                'size_human' => '',
                'file_count' => 0,
            );
            set_transient( self::BACKUP_DIR_INFO_TRANSIENT, $result, self::BACKUP_DIR_INFO_TTL );
            return $result;
        }

        $size  = 0;
        $count = 0;

        try {
            $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
            $ri = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::LEAVES_ONLY );
            foreach ( $ri as $file ) {
                if ( $file->isFile() ) {
                    $size += $file->getSize();
                    $count++;
                }
            }
        } catch ( Exception $e ) {
            $result = array(
                'exists'     => true,
                'size'       => 0,
                'size_human' => '',
                'file_count' => 0,
            );
            set_transient( self::BACKUP_DIR_INFO_TRANSIENT, $result, self::BACKUP_DIR_INFO_TTL );
            return $result;
        }

        // Treat empty directory as non-existent for display purposes.
        if ( 0 === $count ) {
            $result = array(
                'exists'     => false,
                'size'       => 0,
                'size_human' => '',
                'file_count' => 0,
            );
            set_transient( self::BACKUP_DIR_INFO_TRANSIENT, $result, self::BACKUP_DIR_INFO_TTL );
            return $result;
        }

        $result = array(
            'exists'     => true,
            'size'       => $size,
            'size_human' => size_format( $size, 1 ),
            'file_count' => $count,
        );

        set_transient( self::BACKUP_DIR_INFO_TRANSIENT, $result, self::BACKUP_DIR_INFO_TTL );
        return $result;
    }

    /**
     * Aggregate data returned on admin-bar hover refresh.
     *
     * Designed to be extended with additional keys in the future
     * without requiring new JavaScript code or AJAX endpoints.
     *
     * @return array<string, mixed>
     */
    public static function get_hover_data() {
        return array(
            'backup_dir' => self::get_backup_dir_info(),
        );
    }

    /**
     * Path to the bigFiles flag file.
     *
     * @return string
     */
    public static function get_flag_path() {
        return WP_CONTENT_DIR . '/bigFiles/.donotbackup';
    }

    /**
     * Return true if big backup is active (flag does NOT exist).
     *
     * @return bool
     */
    public static function is_bigbackup_active() {
        return ! file_exists( self::get_flag_path() );
    }

    /**
     * Toggle the flag file. Returns new state 'active'|'inactive' or 'error'.
     *
     * @return string
     */
    public static function toggle_bigbackup() {
        $flag = self::get_flag_path();
        $dir  = dirname( $flag );

        clearstatcache( true, $flag );

        if ( file_exists( $flag ) ) {
            if ( @unlink( $flag ) ) {
                return 'active';
            }

            // Retry with permissive chmod when possible (common in mixed user/group setups).
            @chmod( $flag, 0664 );
            @chmod( $dir, 0775 );
            clearstatcache( true, $flag );
            if ( @unlink( $flag ) ) {
                return 'active';
            }

            // Fallback via WP_Filesystem (non-direct filesystem setups).
            if ( function_exists( 'WP_Filesystem' ) ) {
                if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                global $wp_filesystem;
                if ( empty( $wp_filesystem ) ) {
                    WP_Filesystem();
                }

                if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
                    $deleted = $wp_filesystem->delete( $flag, false, 'f' );
                    if ( $deleted ) {
                        return 'active';
                    }
                }
            }

            return 'error';
        }

        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return 'error';
        }

        if ( ! is_writable( $dir ) ) {
            @chmod( $dir, 0775 );
        }

        if ( @touch( $flag ) ) {
            @chmod( $flag, 0664 );
            return 'inactive';
        }

        // Fallback when touch() is restricted but writes are allowed.
        $written = @file_put_contents( $flag, '' );
        if ( false !== $written ) {
            @chmod( $flag, 0664 );
            return 'inactive';
        }

        // Fallback via WP_Filesystem.
        if ( function_exists( 'WP_Filesystem' ) ) {
            if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                WP_Filesystem();
            }

            if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'put_contents' ) ) {
                $ok = $wp_filesystem->put_contents( $flag, '', false );
                if ( $ok ) {
                    return 'inactive';
                }
            }
        }

        return 'error';
    }

    /**
     * Return diagnostics useful for troubleshooting bigbackup toggle failures.
     *
     * @return array{flag: string, dir: string, dir_exists: bool, dir_writable: bool, file_exists: bool, file_writable: bool}
     */
    public static function get_bigbackup_diagnostics() {
        $flag = self::get_flag_path();
        $dir  = dirname( $flag );

        clearstatcache( true, $flag );

        return array(
            'flag'          => $flag,
            'dir'           => $dir,
            'dir_exists'    => is_dir( $dir ),
            'dir_writable'  => is_dir( $dir ) ? is_writable( $dir ) : false,
            'file_exists'   => is_file( $flag ),
            'file_writable' => is_file( $flag ) ? is_writable( $flag ) : false,
        );
    }

    /* ---------------------------------------------------------------
     * Debug log monitoring
     * ------------------------------------------------------------- */

    /**
     * Maximum bytes to read from debug.log when serving content.
     * Prevents memory exhaustion on very large log files.
     */
    const DEBUG_LOG_MAX_READ = 524288; // 512 KB

    /**
     * Option key used to persist the debug monitor on/off state.
     *
     * @see https://developer.wordpress.org/plugins/settings/options-api/
     */
    const DEBUG_MONITOR_OPTION = 'bwh_debug_monitor';

    /**
     * Resolve the path to WordPress debug.log.
     *
     * WP_DEBUG_LOG can be:
     *  - (bool) true  → default path wp-content/debug.log
     *  - (string)     → absolute custom path set in wp-config.php
     *  - anything else → default path
     *
     * @see https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
     *
     * @return string Absolute path to the debug log file.
     */
    public static function get_debug_log_path() {
        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
            return WP_DEBUG_LOG;
        }
        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Whether the debug monitor feature is currently enabled.
     *
     * @return bool
     */
    public static function is_debug_monitor_active() {
        return '1' === get_option( self::DEBUG_MONITOR_OPTION, '0' );
    }

    /**
     * Toggle the debug monitor option and return the new state.
     *
     * @return string 'active'|'inactive'
     */
    public static function toggle_debug_monitor() {
        $current = self::is_debug_monitor_active();
        $new     = $current ? '0' : '1';
        update_option( self::DEBUG_MONITOR_OPTION, $new, true );
        return $new === '1' ? 'active' : 'inactive';
    }

    /**
     * Return lightweight status information about debug.log.
     *
     * The fingerprint is built from mtime + size (single stat call, O(1))
     * so JavaScript can detect changes without reading the file.
     *
     * @return array{exists: bool, size: int, size_human: string, fingerprint: string}
     */
    public static function get_debug_log_status() {
        $path = self::get_debug_log_path();

        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return array(
                'exists'      => false,
                'size'        => 0,
                'size_human'  => '',
                'fingerprint' => '',
            );
        }

        // clearstatcache for this specific file to avoid stale data.
        clearstatcache( true, $path );

        $size  = (int) filesize( $path );
        $mtime = (int) filemtime( $path );

        return array(
            'exists'      => true,
            'size'        => $size,
            'size_human'  => $size > 0 ? size_format( $size, 1 ) : '',
            'fingerprint' => $mtime . '-' . $size,
        );
    }

    /**
     * Read the tail portion of the debug log file.
     *
     * If the file exceeds DEBUG_LOG_MAX_READ bytes the response will contain
     * an indicator that the content was truncated, along with the total size
     * in human-readable form.
     *
     * @return array{content: string, truncated: bool, total_size: string}|array{error: string}
     */
    public static function get_debug_log_content() {
        $path = self::get_debug_log_path();

        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return array( 'error' => 'File not found or not readable.' );
        }

        clearstatcache( true, $path );
        $size = (int) filesize( $path );

        if ( 0 === $size ) {
            return array(
                'content'    => '',
                'truncated'  => false,
                'total_size' => '0 B',
            );
        }

        $truncated = false;
        $handle    = fopen( $path, 'rb' );

        if ( false === $handle ) {
            return array( 'error' => 'Could not open file.' );
        }

        if ( $size > self::DEBUG_LOG_MAX_READ ) {
            // Seek to the last DEBUG_LOG_MAX_READ bytes.
            fseek( $handle, -self::DEBUG_LOG_MAX_READ, SEEK_END );
            $truncated = true;
        }

        $content = fread( $handle, self::DEBUG_LOG_MAX_READ );
        fclose( $handle );

        if ( false === $content ) {
            return array( 'error' => 'Could not read file.' );
        }

        return array(
            'content'    => $content,
            'truncated'  => $truncated,
            'total_size' => size_format( $size, 1 ),
        );
    }

    /**
     * Delete the debug.log file.
     *
     * @return string 'deleted'|'not_found'|'error'
     */
    public static function delete_debug_log() {
        $path = self::get_debug_log_path();

        if ( ! is_file( $path ) ) {
            return 'not_found';
        }

        if ( @unlink( $path ) ) {
            return 'deleted';
        }

        return 'error';
    }

    /* ---------------------------------------------------------------
     * Filesystem utilities
     * ------------------------------------------------------------- */

    /**
     * Recursively remove a directory using SPL iterators.
     *
     * @param string $dir
     * @return bool
     */
    public static function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        // Prefer WP_Filesystem when available — supports non-direct filesystems
        if ( function_exists( 'WP_Filesystem' ) ) {
            // Ensure WP_Filesystem is available
            if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            // Initialize global filesystem object if not present
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                WP_Filesystem();
            }

            if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'rmdir' ) ) {
                // Second parameter true => recursive
                $res = $wp_filesystem->rmdir( $dir, true );
                return (bool) $res;
            }
        }

        // Fallback: use SPL iterators for direct filesystem access
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
