<?php
/**
 * REST: SeoRenderedContentController class.
 *
 * @package Builder\VisualBuilder\REST
 * @since ??
 */

namespace ET\Builder\VisualBuilder\REST\SeoRenderedContent;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Controllers\RESTController;
use ET\Builder\VisualBuilder\PluginCompat\Seo\SeoRenderedContent;
use WP_Post;
use WP_REST_Request;

/**
 * Renders a post's Divi 5 layout for SEO analysis.
 *
 * @since ??
 */
class SeoRenderedContentController extends RESTController {

	/**
	 * Render the stored Divi 5 layout.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function render( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'postId' );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return self::response_error( 'invalid_post', esc_html__( 'The requested post does not exist.', 'et_builder_5' ) );
		}

		return self::response_success(
			[
				'renderedContent' => SeoRenderedContent::render( $post->post_content, $post_id ),
			]
		);
	}

	/**
	 * Render endpoint arguments.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function render_args(): array {
		return [
			'postId' => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Check whether the current user can edit the requested post.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return bool|\WP_Error
	 */
	public static function render_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'edit_post', (int) $request->get_param( 'postId' ) ) ) {
			return self::response_error_permission();
		}

		return true;
	}
}
