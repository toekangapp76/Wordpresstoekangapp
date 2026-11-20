<?php
/**
 * InstagramAccountService class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Services\InstagramAccountService;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use WP_Error;

/**
 * Instagram account service.
 *
 * @since ??
 */
class InstagramAccountService {
	/**
	 * Option name used to persist Instagram accounts.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const OPTION_NAME = 'et_builder_5_instagram_accounts';

	/**
	 * Prefix used for media cache options.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const MEDIA_CACHE_OPTION_PREFIX = 'et_ig_media_';

	/**
	 * Cache TTL for media responses.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const MEDIA_TTL = HOUR_IN_SECONDS;

	/**
	 * Current media cache payload version.
	 *
	 * Increment when cache compatibility changes, including the API fetch limit.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const MEDIA_CACHE_VERSION = 1;

	/**
	 * Maximum amount of media items fetched from API.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const MAX_LIMIT = 100;

	/**
	 * Default lifetime for a Meta long-lived Instagram token when refresh is unavailable.
	 *
	 * Used when a newly issued token is valid for `/me` but cannot be refreshed until it is 24 hours old.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const LONG_LIVED_TOKEN_TTL = 60 * DAY_IN_SECONDS;

	/**
	 * Get persisted Instagram accounts.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	private static function _get_accounts(): array {
		$accounts = get_option( self::OPTION_NAME, [] );

		return is_array( $accounts ) ? $accounts : [];
	}

	/**
	 * Whether any Instagram accounts are persisted.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function has_accounts(): bool {
		return ! empty( self::_get_accounts() );
	}

	/**
	 * Persist Instagram accounts.
	 *
	 * @since ??
	 *
	 * @param array $accounts Instagram accounts.
	 *
	 * @return void
	 */
	private static function _set_accounts( array $accounts ): void {
		update_option( self::OPTION_NAME, $accounts, false );
	}

	/**
	 * Return account definition payload.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function definition(): array {
		$accounts = self::_get_accounts();

		foreach ( $accounts as $account_id => $account ) {
			if ( is_array( $account ) && isset( $account['access_token'] ) && is_string( $account['access_token'] ) ) {
				$accounts[ $account_id ]['access_token'] = str_repeat( '*', strlen( $account['access_token'] ) );
			}
		}

		return $accounts;
	}

	/**
	 * Fetch account data.
	 *
	 * @since ??
	 *
	 * @param string $account_id Optional account ID.
	 *
	 * @return array
	 */
	public static function fetch_account( string $account_id = '' ): array {
		$accounts = self::_get_accounts();

		if ( '' !== $account_id && isset( $accounts[ $account_id ] ) ) {
			$accounts[ $account_id ]['updated_at'] = gmdate( 'c' );
			self::_set_accounts( $accounts );
		}

		return $accounts;
	}

	/**
	 * Fetch media for the selected account.
	 *
	 * @since ??
	 *
	 * @param string $account_id    The account ID.
	 * @param int    $limit         Number of media items.
	 * @param bool   $force_refresh Whether cache should be bypassed.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_media( string $account_id, int $limit = self::MAX_LIMIT, bool $force_refresh = false ) {
		$accounts = self::_get_accounts();

		if ( '' === $account_id || ! isset( $accounts[ $account_id ] ) ) {
			return new WP_Error( 'instagram_account_missing', esc_html__( 'Instagram account not found.', 'et_builder_5' ) );
		}

		$account          = $accounts[ $account_id ];
		$token            = isset( $account['access_token'] ) ? sanitize_text_field( (string) $account['access_token'] ) : '';
		$normalized_limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		if ( '' === $token ) {
			return new WP_Error( 'instagram_access_token_missing', esc_html__( 'Instagram access token is missing.', 'et_builder_5' ) );
		}

		if ( 'expired' === ( $account['status'] ?? '' ) ) {
			$cached = self::_read_media_cache( $account_id );

			return is_array( $cached ) ? array_slice( $cached['items'], 0, $normalized_limit ) : [];
		}

		$cached = self::_read_media_cache( $account_id );

		if ( ! $force_refresh && is_array( $cached ) && self::_is_cache_fresh( $cached ) ) {
			return array_slice( $cached['items'], 0, $normalized_limit );
		}

		$raw_response = InstagramAPI::get_media( $token, self::MAX_LIMIT );

		if ( is_wp_error( $raw_response ) ) {
			$error_meta = InstagramAPI::get_error_meta( $raw_response );
			$subcode    = $error_meta['error_subcode'];

			if ( InstagramTokenError::is_expired( $error_meta['code'], $subcode ) ) {
				self::_mark_account_expired( $account_id );
			} else {
				InstagramTokenLogger::log_media_fetch_failure( $account_id, $raw_response );
			}

			if ( is_array( $cached ) ) {
				return array_slice( $cached['items'], 0, $normalized_limit );
			}

			return [];
		}

		$normalized_items = InstagramAPI::normalize_items( $raw_response );

		self::_write_media_cache( $account_id, $normalized_items );

		return array_slice( $normalized_items, 0, $normalized_limit );
	}

	/**
	 * Delete an account.
	 *
	 * @since ??
	 *
	 * @param string $account_id The account ID.
	 *
	 * @return array
	 */
	public static function delete_account( string $account_id ): array {
		$accounts = self::_get_accounts();

		if ( isset( $accounts[ $account_id ] ) ) {
			unset( $accounts[ $account_id ] );
			self::_set_accounts( $accounts );
			delete_option( self::MEDIA_CACHE_OPTION_PREFIX . $account_id );
			delete_transient( self::MEDIA_CACHE_OPTION_PREFIX . $account_id );
			InstagramTokenCron::clear_scheduled_if_empty();
		}

		return $accounts;
	}

	/**
	 * Create an account.
	 *
	 * @since ??
	 *
	 * @param string $account_name The account label.
	 * @param string $access_token The account access token.
	 *
	 * @return array|WP_Error
	 */
	public static function create_account( string $account_name, string $access_token = '' ) {
		$normalized_account_name = sanitize_text_field( $account_name );
		$normalized_access_token = sanitize_text_field( $access_token );

		if ( '' === $normalized_account_name || '' === $normalized_access_token ) {
			return self::_get_accounts();
		}

		do {
			$account_id = sanitize_key( uniqid( 'ig_', true ) );
		} while ( isset( self::_get_accounts()[ $account_id ] ) );

		$token_result = self::_resolve_token_for_save( $normalized_access_token );

		if ( is_wp_error( $token_result ) ) {
			InstagramTokenLogger::log_refresh_failure( $account_id, $token_result );

			return $token_result;
		}

		$profile  = self::_resolve_profile_fields( $token_result['access_token'] );
		$now      = gmdate( 'c' );
		$accounts = self::_get_accounts();

		$accounts[ $account_id ] = [
			'id'           => $account_id,
			'account_name' => $normalized_account_name,
			'ig_user_id'   => $profile['ig_user_id'],
			'username'     => $profile['username'],
			'access_token' => $token_result['access_token'],
			'expires_at'   => $token_result['expires_at'],
			'status'       => 'active',
			'created_at'   => $now,
			'updated_at'   => $now,
		];

		self::_set_accounts( $accounts );
		InstagramTokenCron::ensure_scheduled();

		return $accounts;
	}

	/**
	 * Update an existing account.
	 *
	 * @since ??
	 *
	 * @param string $account_id   The account ID.
	 * @param string $account_name The account label.
	 * @param string $access_token The new access token.
	 *
	 * @return array|WP_Error
	 */
	public static function update_account( string $account_id, string $account_name, string $access_token = '' ) {
		$accounts = self::_get_accounts();

		if ( ! isset( $accounts[ $account_id ] ) ) {
			return $accounts;
		}

		$normalized_account_name = sanitize_text_field( $account_name );
		$normalized_access_token = sanitize_text_field( $access_token );

		if ( '' === $normalized_access_token ) {
			if ( '' !== $normalized_account_name ) {
				$accounts[ $account_id ]['account_name'] = $normalized_account_name;
			}

			$accounts[ $account_id ]['updated_at'] = gmdate( 'c' );
			self::_set_accounts( $accounts );

			return $accounts;
		}

		$token_result = self::_resolve_token_for_save( $normalized_access_token );

		if ( is_wp_error( $token_result ) ) {
			InstagramTokenLogger::log_refresh_failure( $account_id, $token_result );

			return $token_result;
		}

		$profile = self::_resolve_profile_fields( $token_result['access_token'] );

		$accounts = self::_get_accounts();

		if ( ! isset( $accounts[ $account_id ] ) ) {
			return $accounts;
		}

		if ( '' !== $normalized_account_name ) {
			$accounts[ $account_id ]['account_name'] = $normalized_account_name;
		}

		$accounts[ $account_id ]['access_token'] = $token_result['access_token'];
		$accounts[ $account_id ]['expires_at']   = $token_result['expires_at'];
		$accounts[ $account_id ]['status']       = 'active';
		$accounts[ $account_id ]['updated_at']   = gmdate( 'c' );

		if ( '' !== $profile['ig_user_id'] ) {
			$accounts[ $account_id ]['ig_user_id'] = $profile['ig_user_id'];
		}

		if ( '' !== $profile['username'] ) {
			$accounts[ $account_id ]['username'] = $profile['username'];
		}

		self::_set_accounts( $accounts );

		return $accounts;
	}

	/**
	 * Refresh a stored account token (cron / batch refresh).
	 *
	 * @since ??
	 *
	 * @param string $account_id Account ID.
	 *
	 * @return bool Whether refresh succeeded.
	 */
	public static function refresh_account_token( string $account_id ): bool {
		$accounts = self::_get_accounts();

		if ( ! isset( $accounts[ $account_id ] ) ) {
			return false;
		}

		$access_token = isset( $accounts[ $account_id ]['access_token'] ) ? (string) $accounts[ $account_id ]['access_token'] : '';

		if ( '' === $access_token ) {
			return false;
		}

		$refresh_result = self::_refresh_token_with_retries( $account_id, $access_token );

		if ( is_wp_error( $refresh_result ) ) {
			InstagramTokenLogger::log_refresh_failure( $account_id, $refresh_result );

			$error_meta = InstagramAPI::get_error_meta( $refresh_result );
			$subcode    = $error_meta['error_subcode'];

			if ( InstagramTokenError::is_expired( $error_meta['code'], $subcode ) ) {
				self::_mark_account_expired( $account_id );
			}

			return false;
		}

		$accounts = self::_get_accounts();

		if ( ! isset( $accounts[ $account_id ] ) ) {
			return false;
		}

		$accounts[ $account_id ]['access_token'] = $refresh_result['access_token'];
		$accounts[ $account_id ]['expires_at']   = $refresh_result['expires_at'];
		$accounts[ $account_id ]['status']       = 'active';
		$accounts[ $account_id ]['updated_at']   = gmdate( 'c' );

		self::_set_accounts( $accounts );

		return true;
	}

	/**
	 * Batch refresh all near-expire active accounts.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function refresh_near_expire_accounts(): array {
		$accounts = self::_get_accounts();

		foreach ( $accounts as $account_id => $account ) {
			if ( ! is_array( $account ) || 'active' !== ( $account['status'] ?? '' ) ) {
				continue;
			}

			$expires_at = isset( $account['expires_at'] ) ? (string) $account['expires_at'] : '';

			if ( '' === $expires_at ) {
				continue;
			}

			$expires_timestamp = strtotime( $expires_at );

			if ( false === $expires_timestamp ) {
				continue;
			}

			$seconds_remaining = $expires_timestamp - time();

			if ( $seconds_remaining > ( InstagramTokenCron::NEAR_EXPIRE_NOTICE_DAYS * DAY_IN_SECONDS ) ) {
				continue;
			}

			self::refresh_account_token( (string) $account_id );
		}

		return self::_get_accounts();
	}

	/**
	 * Resolve Instagram profile fields from a token.
	 *
	 * Failed `/me` lookups do not invalidate a token that refresh or `/me` already accepted.
	 *
	 * @since ??
	 *
	 * @param string $access_token Access token.
	 *
	 * @return array{ig_user_id: string, username: string}
	 */
	private static function _resolve_profile_fields( string $access_token ): array {
		$profile_response = InstagramAPI::get_me( $access_token );

		if ( is_wp_error( $profile_response ) ) {
			return [
				'ig_user_id' => '',
				'username'   => '',
			];
		}

		return [
			'ig_user_id' => isset( $profile_response['user_id'] ) ? sanitize_text_field( (string) $profile_response['user_id'] ) : '',
			'username'   => isset( $profile_response['username'] ) ? sanitize_text_field( (string) $profile_response['username'] ) : '',
		];
	}

	/**
	 * Resolve a submitted token for Create/Update persistence.
	 *
	 * Prefers Meta refresh so `expires_at` comes from `expires_in`. If refresh fails because a
	 * newly issued long-lived token is under 24 hours old, accept the submitted token when `/me` succeeds.
	 *
	 * @since ??
	 *
	 * @param string $access_token Submitted access token.
	 *
	 * @return array{access_token: string, expires_at: string}|WP_Error
	 */
	private static function _resolve_token_for_save( string $access_token ) {
		$refresh_result = self::_refresh_token_with_retries( '', $access_token );

		if ( ! is_wp_error( $refresh_result ) ) {
			return $refresh_result;
		}

		$profile_response = InstagramAPI::get_me( $access_token );

		if ( is_wp_error( $profile_response ) ) {
			return $refresh_result;
		}

		return [
			'access_token' => $access_token,
			'expires_at'   => self::_compute_expires_at( self::LONG_LIVED_TOKEN_TTL ),
		];
	}

	/**
	 * Refresh a token with retry policy.
	 *
	 * @since ??
	 *
	 * @param string $account_id   Account ID for logging.
	 * @param string $access_token Token to refresh.
	 *
	 * @return array{access_token: string, expires_at: string}|WP_Error
	 */
	private static function _refresh_token_with_retries( string $account_id, string $access_token ) {
		$last_error = null;

		for ( $attempt = 1; $attempt <= InstagramTokenError::MAX_REFRESH_ATTEMPTS; $attempt++ ) {
			$response = InstagramAPI::refresh_access_token( $access_token );

			if ( ! is_wp_error( $response ) ) {
				$new_token  = isset( $response['access_token'] ) ? sanitize_text_field( (string) $response['access_token'] ) : '';
				$expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 0;

				if ( '' === $new_token || $expires_in <= 0 ) {
					return new WP_Error(
						'instagram_api_error',
						esc_html__( 'Invalid refresh response from Instagram API.', 'et_builder_5' ),
						[
							'code'        => 0,
							'http_status' => 200,
						]
					);
				}

				return [
					'access_token' => $new_token,
					'expires_at'   => self::_compute_expires_at( $expires_in ),
				];
			}

			$last_error = $response;
			$error_meta = InstagramAPI::get_error_meta( $response );
			$subcode    = $error_meta['error_subcode'];

			if ( InstagramTokenError::is_expired( $error_meta['code'], $subcode ) ) {
				return $response;
			}

			if ( ! InstagramTokenError::is_retryable( $error_meta['code'], $error_meta['http_status'] ) ) {
				return $response;
			}
		}

		return $last_error instanceof WP_Error ? $last_error : new WP_Error(
			'instagram_api_error',
			esc_html__( 'Instagram token refresh failed.', 'et_builder_5' ),
			[
				'code'        => 0,
				'http_status' => 0,
			]
		);
	}

	/**
	 * Compute absolute expiry timestamp from expires_in seconds.
	 *
	 * @since ??
	 *
	 * @param int $expires_in Expiry interval in seconds.
	 *
	 * @return string
	 */
	private static function _compute_expires_at( int $expires_in ): string {
		return gmdate( 'c', time() + $expires_in );
	}

	/**
	 * Mark an account as expired.
	 *
	 * @since ??
	 *
	 * @param string $account_id Account ID.
	 *
	 * @return void
	 */
	private static function _mark_account_expired( string $account_id ): void {
		$accounts = self::_get_accounts();

		if ( ! isset( $accounts[ $account_id ] ) ) {
			return;
		}

		$accounts[ $account_id ]['status']     = 'expired';
		$accounts[ $account_id ]['updated_at'] = gmdate( 'c' );

		self::_set_accounts( $accounts );
	}

	/**
	 * Read media cache in the { version, items, created_at } shape.
	 *
	 * Invalidates unversioned caches so payloads created with an older fetch limit are not reused.
	 *
	 * @since ??
	 *
	 * @param string $account_id Account ID.
	 *
	 * @return array{version: int, items: array, created_at: string}|null
	 */
	private static function _read_media_cache( string $account_id ): ?array {
		$cache_key = self::MEDIA_CACHE_OPTION_PREFIX . $account_id;
		$cached    = get_option( $cache_key, false );

		if ( false === $cached ) {
			$legacy_cached = get_transient( $cache_key );

			if ( ! is_array( $legacy_cached ) ) {
				return null;
			}

			delete_transient( $cache_key );

			return null;
		}

		if ( ! is_array( $cached ) ) {
			return null;
		}

		if ( self::MEDIA_CACHE_VERSION !== ( $cached['version'] ?? null ) || ! isset( $cached['created_at'] ) ) {
			delete_option( $cache_key );

			return null;
		}

		$items = $cached['items'] ?? [];

		return [
			'version'    => self::MEDIA_CACHE_VERSION,
			'items'      => is_array( $items ) ? $items : [],
			'created_at' => (string) $cached['created_at'],
		];
	}

	/**
	 * Whether cache is within the fresh window.
	 *
	 * @since ??
	 *
	 * @param array{version: int, items: array, created_at: string} $cache Cache payload.
	 *
	 * @return bool
	 */
	private static function _is_cache_fresh( array $cache ): bool {
		$created_timestamp = strtotime( $cache['created_at'] );

		if ( false === $created_timestamp ) {
			return false;
		}

		return ( time() - $created_timestamp ) < self::MEDIA_TTL;
	}

	/**
	 * Write media cache as a non-autoloaded option.
	 *
	 * @since ??
	 *
	 * @param string $account_id Account ID.
	 * @param array  $items      Normalized media items.
	 *
	 * @return void
	 */
	private static function _write_media_cache( string $account_id, array $items ): void {
		update_option(
			self::MEDIA_CACHE_OPTION_PREFIX . $account_id,
			[
				'version'    => self::MEDIA_CACHE_VERSION,
				'items'      => $items,
				'created_at' => gmdate( 'c' ),
			],
			false
		);
	}
}
