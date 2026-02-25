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

        return $results;
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

        if ( file_exists( $flag ) ) {
            if ( @unlink( $flag ) ) {
                return 'active';
            }
            return 'error';
        }

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        if ( @touch( $flag ) ) {
            return 'inactive';
        }

        return 'error';
    }

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
