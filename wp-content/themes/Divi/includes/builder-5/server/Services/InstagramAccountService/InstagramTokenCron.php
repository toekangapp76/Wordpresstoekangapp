<?php
/**
 * Instagram token refresh cron handler.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Services\InstagramAccountService;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Background cron job for refreshing Instagram access tokens.
 *
 * @since ??
 */
class InstagramTokenCron {

	/**
	 * Cron hook name.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const HOOK = 'et_instagram_token_refresh_cron';

	/**
	 * Transient lock key to prevent overlapping cron runs.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const LOCK_KEY = 'et_instagram_token_refresh_lock';

	/**
	 * Days before expiry when cron should attempt refresh.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const REFRESH_WINDOW_DAYS = 21;

	/**
	 * Days before expiry when near-expire admin notice is shown.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const NEAR_EXPIRE_NOTICE_DAYS = 14;

	/**
	 * Lock TTL in seconds.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register cron hook. Schedule the event only when accounts exist.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );

		if ( InstagramAccountService::has_accounts() ) {
			self::ensure_scheduled();
		}
	}

	/**
	 * Schedule the refresh event when at least one account exists.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( ! InstagramAccountService::has_accounts() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'et_instagram_token_refresh_interval', self::HOOK );
		}
	}

	/**
	 * Clear the refresh event when no accounts remain.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function clear_scheduled_if_empty(): void {
		if ( InstagramAccountService::has_accounts() ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Add custom cron interval (every 3 days).
	 *
	 * @since ??
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	public static function add_cron_interval( array $schedules ): array {
		$schedules['et_instagram_token_refresh_interval'] = [
			'interval' => 3 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Every 3 Days', 'et_builder_5' ),
		];

		return $schedules;
	}

	/**
	 * Run the cron refresh job.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		$accounts = get_option( InstagramAccountService::OPTION_NAME, [] );

		if ( ! is_array( $accounts ) ) {
			delete_transient( self::LOCK_KEY );

			return;
		}

		foreach ( $accounts as $account_id => $account ) {
			if ( ! is_array( $account ) || ! self::_is_eligible_for_refresh( $account ) ) {
				continue;
			}

			InstagramAccountService::refresh_account_token( (string) $account_id );
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Whether an account is eligible for cron refresh.
	 *
	 * @since ??
	 *
	 * @param array<string, mixed> $account Account record.
	 *
	 * @return bool
	 */
	private static function _is_eligible_for_refresh( array $account ): bool {
		if ( 'expired' === ( $account['status'] ?? '' ) ) {
			return false;
		}

		$expires_at = isset( $account['expires_at'] ) ? (string) $account['expires_at'] : '';

		if ( '' === $expires_at ) {
			return true;
		}

		$expires_timestamp = strtotime( $expires_at );

		if ( false === $expires_timestamp ) {
			return true;
		}

		$seconds_remaining = $expires_timestamp - time();

		return $seconds_remaining <= ( self::REFRESH_WINDOW_DAYS * DAY_IN_SECONDS );
	}
}
