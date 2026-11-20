<?php
/**
 * Instagram token refresh error logger.
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
 * Structured logger for Instagram token and media fetch failures.
 *
 * @since ??
 */
class InstagramTokenLogger {

	/**
	 * In-memory log entries for tests.
	 *
	 * @since ??
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $_entries = [];

	/**
	 * Log a refresh failure with structured Meta error context.
	 *
	 * @since ??
	 *
	 * @param string   $account_id Account ID.
	 * @param WP_Error $error      API error.
	 *
	 * @return void
	 */
	public static function log_refresh_failure( string $account_id, WP_Error $error ): void {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		$entry = [
			'account_id'  => $account_id,
			'error'       => [
				'code'          => isset( $data['code'] ) ? (int) $data['code'] : 0,
				'error_subcode' => isset( $data['error_subcode'] ) ? (int) $data['error_subcode'] : 0,
				'message'       => $error->get_error_message(),
			],
			'http_status' => isset( $data['http_status'] ) ? (int) $data['http_status'] : 0,
		];

		self::$_entries[] = $entry;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional structured error logging.
		error_log(
			sprintf(
				'Instagram token refresh failed for account %s: code=%d subcode=%d status=%d message=%s',
				$account_id,
				$entry['error']['code'],
				$entry['error']['error_subcode'],
				$entry['http_status'],
				$entry['error']['message']
			)
		);
	}

	/**
	 * Log a media fetch failure with structured Meta error context.
	 *
	 * @since ??
	 *
	 * @param string   $account_id Account ID.
	 * @param WP_Error $error      API error.
	 *
	 * @return void
	 */
	public static function log_media_fetch_failure( string $account_id, WP_Error $error ): void {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		$entry = [
			'account_id'  => $account_id,
			'error'       => [
				'code'          => isset( $data['code'] ) ? (int) $data['code'] : 0,
				'error_subcode' => isset( $data['error_subcode'] ) ? (int) $data['error_subcode'] : 0,
				'message'       => $error->get_error_message(),
			],
			'http_status' => isset( $data['http_status'] ) ? (int) $data['http_status'] : 0,
		];

		self::$_entries[] = $entry;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional structured error logging.
		error_log(
			sprintf(
				'Instagram media fetch failed for account %s: code=%d subcode=%d status=%d message=%s',
				$account_id,
				$entry['error']['code'],
				$entry['error']['error_subcode'],
				$entry['http_status'],
				$entry['error']['message']
			)
		);
	}

	/**
	 * Return logged entries.
	 *
	 * @since ??
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries(): array {
		return self::$_entries;
	}
}
