<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares this plugin's version against the one running on the remote site.
 *
 * Sync pushes and pulls move meta through this plugin's own REST route, so both
 * ends have to agree on what that route accepts and returns. A remote running a
 * different version may store fields differently, or not expose the route at
 * all — pushing into that is how you get silent data loss rather than an error.
 */
class Rest_In_Sync_Version {

	/** Cached lookup, so a blocked button does not re-query on every render. */
	const TRANSIENT = 'rest_in_sync_remote_version';

	/** How long a successful lookup is trusted for. */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Returned when the remote answered but is not running this plugin. */
	const UNKNOWN = '';

	/** This site's plugin version. */
	public static function local() {
		return REST_IN_SYNC_VERSION;
	}

	/**
	 * Records the version seen on a response from the remote.
	 *
	 * Every sync request already comes back with the header, so the cache is
	 * kept warm by ordinary traffic and the dedicated route below is only
	 * needed when nothing has been talked to recently — a freshly loaded
	 * settings page, say.
	 *
	 * @param array|WP_Error $response A wp_remote_* response.
	 */
	public static function observe( $response ) {
		if ( is_wp_error( $response ) ) {
			return;
		}

		$version = wp_remote_retrieve_header( $response, strtolower( Rest_In_Sync_Rest_Controller::VERSION_HEADER ) );

		if ( is_string( $version ) && '' !== $version ) {
			set_transient( self::TRANSIENT, $version, self::CACHE_TTL );
		}
	}

	/**
	 * The remote site's plugin version, or a WP_Error explaining why it could
	 * not be determined.
	 *
	 * @param bool $force Skip the cache.
	 * @return string|WP_Error
	 */
	public static function remote( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		if ( ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return new WP_Error(
				'rest_in_sync_not_configured',
				__( 'The connection to the remote site is not configured.', 'rest-in-sync' )
			);
		}

		$response = wp_remote_get(
			trailingslashit( Rest_In_Sync_Settings::get_site_url() ) . 'wp-json/' . Rest_In_Sync_Rest_Controller::NAMESPACE_NAME . '/version',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode(
						Rest_In_Sync_Settings::get_username() . ':' . Rest_In_Sync_Settings::get_app_password()
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			// The route is missing entirely, so the remote is either running a
			// version that predates it or does not have the plugin at all.
			return new WP_Error(
				'rest_in_sync_no_version_route',
				__( 'The remote site did not report a REST in Sync version. It is either running a version older than 0.4.0 or does not have the plugin active.', 'rest-in-sync' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'rest_in_sync_version_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The remote site returned HTTP %d when asked for its version.', 'rest-in-sync' ),
					$code
				)
			);
		}

		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$version = is_array( $body ) && isset( $body['version'] ) ? (string) $body['version'] : self::UNKNOWN;

		if ( self::UNKNOWN === $version ) {
			return new WP_Error(
				'rest_in_sync_version_unreadable',
				__( 'The remote site answered but did not include a version.', 'rest-in-sync' )
			);
		}

		set_transient( self::TRANSIENT, $version, self::CACHE_TTL );

		return $version;
	}

	/**
	 * Whether pushing and pulling should be allowed.
	 *
	 * @param bool $force Skip the cache.
	 * @return true|WP_Error True when both ends match; otherwise why not.
	 */
	public static function check( $force = false ) {
		$remote = self::remote( $force );

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( $remote === self::local() ) {
			return true;
		}

		return new WP_Error(
			'rest_in_sync_version_mismatch',
			sprintf(
				/* translators: 1: local version, 2: remote version */
				__( 'This site runs REST in Sync %1$s but the remote site runs %2$s. Update both to the same version before syncing — the two versions may not store or accept fields the same way.', 'rest-in-sync' ),
				self::local(),
				$remote
			),
			array( 'local' => self::local(), 'remote' => $remote )
		);
	}

	/** True when both ends are on the same version. */
	public static function matches() {
		return true === self::check();
	}

	/** Drops the cached lookup, e.g. after the connection settings change. */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}
}
