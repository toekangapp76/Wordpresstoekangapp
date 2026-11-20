<?php
/**
 * Instagram token admin notices.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Admin\InstagramToken;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Services\InstagramAccountService\InstagramAccountService;
use ET\Builder\Services\InstagramAccountService\InstagramTokenCron;

/**
 * Admin notice eligibility helpers for Instagram token status.
 *
 * @since ??
 */
class InstagramTokenAdminNotices {

	/**
	 * Get account names for expired tokens.
	 *
	 * @since ??
	 *
	 * @return string[]
	 */
	public static function get_expired_account_names(): array {
		$accounts = get_option( InstagramAccountService::OPTION_NAME, [] );
		$names    = [];

		if ( ! is_array( $accounts ) ) {
			return $names;
		}

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || 'expired' !== ( $account['status'] ?? '' ) ) {
				continue;
			}

			$name = isset( $account['account_name'] ) ? (string) $account['account_name'] : '';

			if ( '' === $name ) {
				continue;
			}

			$names[] = $name;
		}

		return $names;
	}

	/**
	 * Whether any active account is within the near-expire notice window.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function is_near_expire_notice_eligible(): bool {
		$accounts = get_option( InstagramAccountService::OPTION_NAME, [] );

		if ( ! is_array( $accounts ) ) {
			return false;
		}

		foreach ( $accounts as $account ) {
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

			if ( $seconds_remaining <= ( InstagramTokenCron::NEAR_EXPIRE_NOTICE_DAYS * DAY_IN_SECONDS ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any account has an expired token.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function has_expired_accounts(): bool {
		return ! empty( self::get_expired_account_names() );
	}

	/**
	 * Render grouped Instagram token admin notices.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! InstagramAccountService::has_accounts() ) {
			return;
		}

		if ( self::has_expired_accounts() ) {
			self::_render_expired_notice();
		}

		if ( self::is_near_expire_notice_eligible() ) {
			self::_render_near_expire_notice();
		}
	}

	/**
	 * Enqueue admin scripts for token notice actions.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function enqueue_admin_scripts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! InstagramAccountService::has_accounts() ) {
			return;
		}

		if ( ! self::is_near_expire_notice_eligible() ) {
			return;
		}

		$handle  = 'et-instagram-token-admin-notices';
		$version = defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : '1.0.0';
		$nonce   = wp_json_encode( wp_create_nonce( 'wp_rest' ) );

		wp_register_script( $handle, false, [], $version, true );
		wp_enqueue_script( $handle );

		$refresh_script = sprintf(
			'document.addEventListener("DOMContentLoaded",function(){var button=document.querySelector(".et-instagram-token-refresh-all");if(!button){return;}button.addEventListener("click",function(){var defaultLabel=button.textContent;button.disabled=true;button.textContent=%1$s;fetch(%2$s,{method:"POST",credentials:"same-origin",headers:{"X-WP-Nonce":%3$s,"Content-Type":"application/json"}}).then(function(response){return response.json().then(function(data){return{ok:response.ok,data:data};});}).then(function(result){if(result.ok){window.location.reload();return;}button.disabled=false;button.textContent=defaultLabel;}).catch(function(){button.disabled=false;button.textContent=defaultLabel;});});});',
			wp_json_encode( __( 'Refreshing…', 'et_builder_5' ) ),
			wp_json_encode( esc_url_raw( rest_url( 'divi/v1/instagram-service/refresh-near-expire' ) ) ),
			$nonce
		);

		wp_add_inline_script( $handle, $refresh_script );
	}

	/**
	 * Render the expired-token admin notice.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	private static function _render_expired_notice(): void {
		$names = self::get_expired_account_names();

		if ( empty( $names ) ) {
			return;
		}

		$intro = esc_html__(
			'Divi: The following Instagram accounts have expired tokens. Open the page that contains the Instagram module in Visual Builder and submit a new token to keep an account, or remove it if you no longer use it.',
			'et_builder_5'
		);

		$names_html = implode(
			', ',
			array_map(
				static function ( string $name ): string {
					return '<strong>' . esc_html( $name ) . '</strong>';
				},
				$names
			)
		);

		printf(
			'<div class="notice notice-error et-instagram-token-expired-notice"><p>%1$s %2$s</p></div>',
			$intro,
			et_core_esc_previously( $names_html )
		);
	}

	/**
	 * Render the near-expire admin notice.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	private static function _render_near_expire_notice(): void {
		$message = esc_html__(
			'Divi: Some of your Instagram tokens are about to expire and need to be refreshed.',
			'et_builder_5'
		);

		printf(
			'<div class="notice notice-warning et-instagram-token-near-expire-notice"><p>%1$s <button type="button" class="button button-secondary et-instagram-token-refresh-all">%2$s</button></p></div>',
			$message,
			esc_html__( 'Refresh all', 'et_builder_5' )
		);
	}
}
