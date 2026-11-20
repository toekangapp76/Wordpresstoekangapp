<?php
/**
 * Instagram token error classification.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Services\InstagramAccountService;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Meta Graph API error classification and retry policy.
 *
 * @since ??
 */
class InstagramTokenError {

	/**
	 * Maximum refresh retry attempts.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const MAX_REFRESH_ATTEMPTS = 3;

	/**
	 * Definitive expired error subcodes for code 190.
	 *
	 * @since ??
	 *
	 * @var int[]
	 */
	private const EXPIRED_SUBCODES = [ 463, 467, 460, 458 ];

	/**
	 * Retryable Meta API error codes.
	 *
	 * @since ??
	 *
	 * @var int[]
	 */
	private const RETRYABLE_CODES = [ 1, 2, 4, 17 ];

	/**
	 * Definitive expired error codes without subcode.
	 *
	 * @since ??
	 *
	 * @var int[]
	 */
	private const EXPIRED_CODES_WITHOUT_SUBCODE = [ 190, 102 ];

	/**
	 * Whether the Meta error code indicates an expired or invalid token.
	 *
	 * @since ??
	 *
	 * @param int      $code    Error code.
	 * @param int|null $subcode Error subcode.
	 *
	 * @return bool
	 */
	public static function is_expired( int $code, ?int $subcode ): bool {
		if ( in_array( $code, self::EXPIRED_CODES_WITHOUT_SUBCODE, true ) && null === $subcode ) {
			return true;
		}

		if ( 190 === $code && null !== $subcode && in_array( $subcode, self::EXPIRED_SUBCODES, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the error should be retried.
	 *
	 * @since ??
	 *
	 * @param int $code        Error code.
	 * @param int $http_status HTTP status code.
	 *
	 * @return bool
	 */
	public static function is_retryable( int $code, int $http_status ): bool {
		if ( 0 === $code ) {
			return true;
		}

		if ( in_array( $code, self::RETRYABLE_CODES, true ) ) {
			return true;
		}

		return $http_status >= 500 && $http_status < 600;
	}
}
