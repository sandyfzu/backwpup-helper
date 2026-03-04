<?php
/**
 * BackWPup Helper custom updater (external manifest, no third-party library).
 *
 * Provides native WordPress plugin update integration for non-wp.org releases:
 * - update checks via pre_set_site_transient_update_plugins
 * - plugin details modal via plugins_api
 * - package URL validation before download
 *
 * Security and reliability notes:
 * - HTTPS-only URLs
 * - host allowlist (manifest host)
 * - strict manifest validation
 * - transient caching to reduce remote calls
 * - graceful failure (never breaks normal WP flows)
 *
 * PHP: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWH_Updater {

	/**
	 * Transient cache time for remote manifest.
	 */
	const MANIFEST_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * @var string Absolute plugin file path.
	 */
	private $plugin_file;

	/**
	 * @var string Plugin basename (e.g. backwpup-helper/backwpup-helper.php).
	 */
	private $plugin_basename;

	/**
	 * @var string Plugin slug (e.g. backwpup-helper).
	 */
	private $plugin_slug;

	/**
	 * @var string Installed plugin version.
	 */
	private $current_version;

	/**
	 * @var string Manifest URL.
	 */
	private $manifest_url;

	/**
	 * @var string[] Allowed hosts for manifest/package downloads.
	 *                Pre-seeded with common GitHub hosts; manifest host is appended in constructor.
	 */
	private $allowed_hosts = array();

	/**
	 * @param string $plugin_file     Plugin main file path.
	 * @param string $current_version Current installed version.
	 * @param string $manifest_url    Remote JSON manifest URL.
	 */
	public function __construct( $plugin_file, $current_version, $manifest_url ) {
		$this->plugin_file     = (string) $plugin_file;
		$this->plugin_basename = plugin_basename( $this->plugin_file );

		$slug = dirname( $this->plugin_basename );
		$this->plugin_slug = ( '.' === $slug ) ? basename( $this->plugin_basename, '.php' ) : $slug;

		$this->current_version = (string) $current_version;
		$this->manifest_url = esc_url_raw( (string) $manifest_url );

		// Base allowlist: common GitHub hosts. Keep lowercase for comparison.
		$defaults = array( 'github.com', 'raw.githubusercontent.com' );

		// Parse manifest host and append if valid and not already present.
		$manifest_host = (string) wp_parse_url( $this->manifest_url, PHP_URL_HOST );
		$manifest_host = '' !== $manifest_host ? strtolower( $manifest_host ) : '';

		$this->allowed_hosts = array_map( 'strtolower', $defaults );
		if ( '' !== $manifest_host && ! in_array( $manifest_host, $this->allowed_hosts, true ) ) {
			$this->allowed_hosts[] = $manifest_host;
		}
	}

	/**
	 * Register WordPress hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'validate_package_before_download' ), 10, 4 );
	}

	/**
	 * Add available update to update transient.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! array_key_exists( $this->plugin_basename, $transient->checked ) ) {
			return $transient;
		}

		$manifest = $this->get_manifest();
		if ( empty( $manifest ) ) {
			return $transient;
		}

		if ( ! version_compare( $manifest['version'], $this->current_version, '>' ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'           => $this->manifest_url,
			'slug'         => $this->plugin_slug,
			'plugin'       => $this->plugin_basename,
			'new_version'  => $manifest['version'],
			'url'          => $manifest['homepage'],
			'package'      => $manifest['download_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
		);

		return $transient;
	}

	/**
	 * Provide plugin details modal data for update UI.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$manifest = $this->get_manifest();
		if ( empty( $manifest ) ) {
			return $result;
		}

		$sections = array(
			'description' => $manifest['description'],
			'changelog'   => $manifest['changelog'],
		);

		return (object) array(
			'name'          => $manifest['name'],
			'slug'          => $this->plugin_slug,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'],
			'homepage'      => $manifest['homepage'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'last_updated'  => $manifest['last_updated'],
			'download_link' => $manifest['download_url'],
			'sections'      => $sections,
		);
	}

	/**
	 * Validate update package URL before download for this plugin.
	 *
	 * @param bool|WP_Error $reply
	 * @param string        $package
	 * @param object        $upgrader
	 * @param array         $hook_extra
	 * @return bool|WP_Error
	 */
	public function validate_package_before_download( $reply, $package, $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return $reply;
		}

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $reply;
		}

		if ( ! $this->is_allowed_url( $package ) ) {
			return new WP_Error( 'bwh_invalid_package_url', 'Invalid package URL for plugin update.' );
		}

		return $reply;
	}

	/**
	 * Return parsed and validated remote manifest (cached).
	 *
	 * @return array<string,string>
	 */
	private function get_manifest() {
		$cache_key = 'bwh_update_manifest_' . md5( $this->manifest_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['ok'] ) && ! empty( $cached['manifest'] ) && is_array( $cached['manifest'] ) ) {
			return $cached['manifest'];
		}

		if ( ! $this->is_allowed_url( $this->manifest_url ) ) {
			return array();
		}

		$response = wp_remote_get(
			$this->manifest_url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'sslverify'   => true,
				'user-agent'  => 'BackWPup Helper/' . $this->current_version . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return array();
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$manifest = $this->normalize_manifest( $data );
		if ( empty( $manifest ) ) {
			return array();
		}

		set_transient(
			$cache_key,
			array(
				'ok'       => true,
				'manifest' => $manifest,
			),
			self::MANIFEST_TTL
		);

		return $manifest;
	}

	/**
	 * Validate and sanitize manifest payload.
	 *
	 * Expected JSON keys (minimum):
	 * - version
	 * - download_url
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,string>
	 */
	private function normalize_manifest( $data ) {
		$version      = isset( $data['version'] ) ? (string) $data['version'] : '';
		$download_url = isset( $data['download_url'] ) ? esc_url_raw( (string) $data['download_url'] ) : '';

		if ( '' === $version || '' === $download_url ) {
			return array();
		}

		if ( ! preg_match( '/^[0-9]+(\.[0-9A-Za-z\-\+]+)*$/', $version ) ) {
			return array();
		}

		if ( ! $this->is_allowed_url( $download_url ) ) {
			return array();
		}

		$sections = isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : array();
		$description = isset( $sections['description'] ) ? wp_kses_post( (string) $sections['description'] ) : '';
		$changelog   = isset( $sections['changelog'] ) ? wp_kses_post( (string) $sections['changelog'] ) : '';

		if ( '' === $description && isset( $data['description'] ) ) {
			$description = wp_kses_post( (string) $data['description'] );
		}

		if ( '' === $changelog && isset( $data['changelog'] ) ) {
			$changelog = wp_kses_post( (string) $data['changelog'] );
		}

		$homepage = isset( $data['homepage'] ) ? esc_url_raw( (string) $data['homepage'] ) : $this->manifest_url;
		if ( '' !== $homepage && ! $this->is_allowed_url( $homepage ) ) {
			$homepage = $this->manifest_url;
		}

		return array(
			'name'         => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : 'BackWPup Helper',
			'author'       => isset( $data['author'] ) ? wp_kses_post( (string) $data['author'] ) : 'BackWPup Helper',
			'version'      => $version,
			'download_url' => $download_url,
			'homepage'     => $homepage,
			'requires'     => isset( $data['requires'] ) ? sanitize_text_field( (string) $data['requires'] ) : '',
			'requires_php' => isset( $data['requires_php'] ) ? sanitize_text_field( (string) $data['requires_php'] ) : '',
			'tested'       => isset( $data['tested'] ) ? sanitize_text_field( (string) $data['tested'] ) : '',
			'last_updated' => isset( $data['last_updated'] ) ? sanitize_text_field( (string) $data['last_updated'] ) : '',
			'description'  => '' !== $description ? $description : 'BackWPup Helper external update metadata.',
			'changelog'    => '' !== $changelog ? $changelog : 'No changelog provided.',
		);
	}

	/**
	 * Validate URL safety constraints (https + allowed host).
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_allowed_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		// If no allowed hosts configured, deny by default for safety.
		if ( ! is_array( $this->allowed_hosts ) || empty( $this->allowed_hosts ) ) {
			return false;
		}

		// Normalized comparison against allowed hosts list.
		$normalized_allowed = array_map( 'strtolower', array_values( $this->allowed_hosts ) );
		if ( ! in_array( $host, $normalized_allowed, true ) ) {
			return false;
		}

		return true;
	}
}
