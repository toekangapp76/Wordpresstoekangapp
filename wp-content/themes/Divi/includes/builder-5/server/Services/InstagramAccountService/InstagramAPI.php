<?php
/**
 * InstagramAPI class.
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
 * Lightweight Instagram Graph API client.
 *
 * @since ??
 */
class InstagramAPI {
	/**
	 * Graph API base URL.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const GRAPH_BASE = 'https://graph.instagram.com/v25.0';

	/**
	 * Refresh token endpoint base URL.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const REFRESH_BASE = 'https://graph.instagram.com';

	/**
	 * Media fields requested from the Graph API.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	const MEDIA_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp';

	/**
	 * Request timeout in seconds.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Perform a GET request to Instagram Graph API.
	 *
	 * @since ??
	 *
	 * @param string $url          Full request URL.
	 * @param array  $query_params Optional query parameters to append.
	 *
	 * @return array|WP_Error
	 */
	private static function _request( string $url, array $query_params = [] ) {
		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::REQUEST_TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'instagram_api_error',
				$response->get_error_message(),
				[
					'code'        => 0,
					'http_status' => 0,
				]
			);
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $body, true );

		if ( ! is_array( $decoded_body ) ) {
			return new WP_Error(
				'instagram_api_error',
				esc_html__( 'Invalid response from Instagram API.', 'et_builder_5' ),
				[
					'code'        => 0,
					'http_status' => $response_code,
				]
			);
		}

		if ( isset( $decoded_body['error'] ) && is_array( $decoded_body['error'] ) ) {
			return self::_build_api_error( $decoded_body['error'], $response_code );
		}

		if ( $response_code < 200 || $response_code >= 300 ) {
			return new WP_Error(
				'instagram_api_error',
				esc_html__( 'Instagram API request failed.', 'et_builder_5' ),
				[
					'code'        => 0,
					'http_status' => $response_code,
				]
			);
		}

		return $decoded_body;
	}

	/**
	 * Build a structured WP_Error from a Meta API error payload.
	 *
	 * @since ??
	 *
	 * @param array $error         Meta error payload.
	 * @param int   $response_code HTTP status code.
	 *
	 * @return WP_Error
	 */
	private static function _build_api_error( array $error, int $response_code ): WP_Error {
		$message = isset( $error['message'] ) ? sanitize_text_field( (string) $error['message'] ) : esc_html__( 'Instagram API request failed.', 'et_builder_5' );
		$code    = isset( $error['code'] ) ? (int) $error['code'] : 0;
		$subcode = isset( $error['error_subcode'] ) ? (int) $error['error_subcode'] : null;

		return new WP_Error(
			'instagram_api_error',
			$message,
			[
				'code'          => $code,
				'error_subcode' => $subcode,
				'http_status'   => $response_code,
			]
		);
	}

	/**
	 * Extract error metadata from a WP_Error.
	 *
	 * @since ??
	 *
	 * @param WP_Error $error API error.
	 *
	 * @return array{code: int, error_subcode: int|null, http_status: int}
	 */
	public static function get_error_meta( WP_Error $error ): array {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		return [
			'code'          => isset( $data['code'] ) ? (int) $data['code'] : 0,
			'error_subcode' => array_key_exists( 'error_subcode', $data ) ? ( $data['error_subcode'] ?? null ) : null,
			'http_status'   => isset( $data['http_status'] ) ? (int) $data['http_status'] : 0,
		];
	}

	/**
	 * Refresh an access token.
	 *
	 * @since ??
	 *
	 * @param string $access_token Instagram access token.
	 *
	 * @return array|WP_Error
	 */
	public static function refresh_access_token( string $access_token ) {
		return self::_request(
			self::REFRESH_BASE . '/refresh_access_token',
			[
				'grant_type'   => 'ig_refresh_token',
				'access_token' => $access_token,
			]
		);
	}

	/**
	 * Fetch account profile for a token.
	 *
	 * @since ??
	 *
	 * @param string $access_token Instagram access token.
	 *
	 * @return array|WP_Error
	 */
	public static function get_me( string $access_token ) {
		return self::_request(
			self::GRAPH_BASE . '/me',
			[
				'fields'       => 'user_id,username',
				'access_token' => $access_token,
			]
		);
	}

	/**
	 * Fetch media for a token.
	 *
	 * @since ??
	 *
	 * @param string $access_token Instagram access token.
	 * @param int    $limit        Requested limit.
	 *
	 * @return array|WP_Error
	 */
	public static function get_media( string $access_token, int $limit ) {
		return self::_request(
			self::GRAPH_BASE . '/me/media',
			[
				'fields'       => self::MEDIA_FIELDS,
				'limit'        => $limit,
				'access_token' => $access_token,
			]
		);
	}

	/**
	 * Normalize Instagram media payload into module item shape.
	 *
	 * @since ??
	 *
	 * @param array $raw_response Raw API response payload.
	 *
	 * @return array
	 */
	public static function normalize_items( array $raw_response ): array {
		$data = $raw_response['data'] ?? [];

		if ( ! is_array( $data ) ) {
			return [];
		}

		$items = [];

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id            = isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '';
			$media_type    = isset( $item['media_type'] ) ? strtolower( sanitize_text_field( (string) $item['media_type'] ) ) : 'image';
			$media_url_raw = isset( $item['media_url'] ) ? esc_url_raw( (string) $item['media_url'] ) : '';
			$thumb_url_raw = isset( $item['thumbnail_url'] ) ? esc_url_raw( (string) $item['thumbnail_url'] ) : '';
			$permalink     = isset( $item['permalink'] ) ? esc_url_raw( (string) $item['permalink'] ) : '';
			$caption       = isset( $item['caption'] ) ? sanitize_text_field( (string) $item['caption'] ) : '';
			$timestamp     = isset( $item['timestamp'] ) ? sanitize_text_field( (string) $item['timestamp'] ) : '';

			if ( '' === $id ) {
				continue;
			}

			$media_url = $media_url_raw;

			if ( 'video' === $media_type && '' !== $thumb_url_raw ) {
				$media_url = $thumb_url_raw;
			}

			if ( '' === $media_url ) {
				continue;
			}

			$items[] = [
				'id'           => $id,
				'mediaType'    => $media_type,
				'mediaUrl'     => $media_url,
				'thumbnailUrl' => $thumb_url_raw,
				'videoUrl'     => $media_url_raw,
				'permalink'    => $permalink,
				'caption'      => $caption,
				'timestamp'    => $timestamp,
			];
		}

		return $items;
	}
}
