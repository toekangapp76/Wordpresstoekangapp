<?php
/**
 * REST: InstagramServiceController class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\REST\InstagramService;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress REST API callbacks require specific signatures.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Controllers\RESTController;
use ET\Builder\Framework\UserRole\UserRole;
use ET\Builder\Services\InstagramAccountService\InstagramAccountService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * InstagramServiceController class.
 *
 * @since ??
 */
class InstagramServiceController extends RESTController {

	/**
	 * Create a new account for an instagram service provider.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$result = InstagramAccountService::create_account(
			$request->get_param( 'account_name' ),
			$request->get_param( 'access_token' )
		);

		if ( is_wp_error( $result ) ) {
			return self::_account_save_error_response();
		}

		return self::_masked_accounts_response();
	}

	/**
	 * Create action arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function create_args(): array {
		return [
			'account_name' => [
				'description'       => esc_html__( 'The account name.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'access_token' => [
				'description'       => esc_html__( 'The Instagram access token.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Create action permission callback.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function create_permission(): bool {
		return UserRole::can_current_user_use_visual_builder() && current_user_can( 'manage_options' );
	}

	/**
	 * Delete an account for a instagram service provider.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function delete( WP_REST_Request $request ): WP_REST_Response {
		InstagramAccountService::delete_account( $request->get_param( 'accountId' ) );

		return self::_masked_accounts_response();
	}

	/**
	 * Delete action arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function delete_args(): array {
		return [
			'accountId' => [
				'description'       => esc_html__( 'The account ID.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Delete action permission callback.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function delete_permission(): bool {
		return UserRole::can_current_user_use_visual_builder() && current_user_can( 'manage_options' );
	}

	/**
	 * Read account for specific instagram service provider.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ): WP_REST_Response {
		InstagramAccountService::fetch_account( $request->get_param( 'accountId' ) );

		return self::_masked_accounts_response();
	}

	/**
	 * Read action arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function read_args(): array {
		return [
			'accountId' => [
				'description'       => esc_html__( 'The account ID.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Read action permission callback.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function read_permission(): bool {
		return UserRole::can_current_user_use_visual_builder() && current_user_can( 'manage_options' );
	}

	/**
	 * Update an existing Instagram account.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$result = InstagramAccountService::update_account(
			$request->get_param( 'accountId' ),
			$request->get_param( 'account_name' ),
			$request->get_param( 'access_token' )
		);

		if ( is_wp_error( $result ) ) {
			return self::_account_save_error_response();
		}

		return self::_masked_accounts_response();
	}

	/**
	 * Update action arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function update_args(): array {
		return [
			'accountId'    => [
				'description'       => esc_html__( 'The account ID.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'account_name' => [
				'description'       => esc_html__( 'The account name.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'access_token' => [
				'description'       => esc_html__( 'The Instagram access token.', 'et_builder_5' ),
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Update action permission callback.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function update_permission(): bool {
		return UserRole::can_current_user_use_visual_builder() && current_user_can( 'manage_options' );
	}

	/**
	 * Batch refresh near-expire Instagram accounts.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function refresh_near_expire( WP_REST_Request $request ): WP_REST_Response {
		InstagramAccountService::refresh_near_expire_accounts();

		return self::response_success( true );
	}

	/**
	 * Refresh near-expire action arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function refresh_near_expire_args(): array {
		return [];
	}

	/**
	 * Refresh near-expire action permission callback.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function refresh_near_expire_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST payload for account lists: same masking as VB bootstrap `definition()`.
	 *
	 * @since ??
	 *
	 * @return WP_REST_Response
	 */
	private static function _masked_accounts_response(): WP_REST_Response {
		return self::response_success( InstagramAccountService::definition() );
	}

	/**
	 * REST error for an account save rejected during token validation.
	 *
	 * @since ??
	 *
	 * @return WP_Error
	 */
	private static function _account_save_error_response(): WP_Error {
		return self::response_error(
			'instagram_account_save_failed',
			esc_html__( 'Unable to save the Instagram account. Check the access token and try again.', 'et_builder_5' )
		);
	}
}
