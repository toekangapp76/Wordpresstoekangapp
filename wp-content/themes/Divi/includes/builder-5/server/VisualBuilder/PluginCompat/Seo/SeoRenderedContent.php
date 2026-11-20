<?php
/**
 * Shared D5 HTML render helper for SEO plugin analysis.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\PluginCompat\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\FrontEnd\BlockParser\BlockParser;
use ET\Builder\Packages\Module\Layout\Components\DynamicContent\DynamicContentUtils;
use ET\Builder\Packages\Module\Layout\Components\DynamicData\DynamicData;
use ET_Builder_Module_Order;

/**
 * Renders Divi 5 block markup to HTML for SEO analyzers and sitemap parsers.
 *
 * Analysis HTML is never persisted to `post_content`.
 *
 * @since ??
 */
class SeoRenderedContent {

	/**
	 * Render D5 builder markup through `the_content` for plugin consumption.
	 *
	 * Restores module order indexes after the off-front-end render so analysis
	 * requests do not permanently advance CSS class indexes.
	 *
	 * Installs the post as the global `$post` while rendering. Sitemap scans and the
	 * REST analysis endpoint run outside the loop, so module PHP and other
	 * `the_content` callbacks would otherwise read an unrelated post.
	 *
	 * @since ??
	 *
	 * @param string $content Raw post content.
	 * @param int    $post_id Post ID used for dynamic data. Default `0`.
	 *
	 * @return string Rendered HTML, or the original content when it is not D5 markup.
	 */
	public static function render( string $content, int $post_id = 0 ): string {
		if ( ! BlockParser::has_any_divi_block( $content ) ) {
			return $content;
		}

		if ( 1 > $post_id ) {
			$post_id = (int) get_the_ID();
		}

		$order_snapshot = ET_Builder_Module_Order::capture_state();

		try {
			$render_callback = function () use ( $content, $post_id ) {
				return self::_render_content( $content, $post_id );
			};

			$rendered_content = 0 < $post_id
				? DynamicContentUtils::with_loop_post_context( $post_id, $render_callback )
				: $render_callback();
		} finally {
			ET_Builder_Module_Order::restore_state( $order_snapshot );
		}

		return is_string( $rendered_content ) ? $rendered_content : '';
	}

	/**
	 * Process dynamic data and run the content through `the_content`.
	 *
	 * @since ??
	 *
	 * @param string $content Raw post content.
	 * @param int    $post_id Post ID used for dynamic data.
	 *
	 * @return string Rendered HTML.
	 */
	private static function _render_content( string $content, int $post_id ): string {
		$normalized_content = DynamicData::get_processed_dynamic_data( $content, 0 < $post_id ? $post_id : null, true );

		if ( ! is_string( $normalized_content ) ) {
			$normalized_content = $content;
		}

		$rendered_content = apply_filters( 'the_content', $normalized_content );

		return is_string( $rendered_content ) ? $rendered_content : '';
	}
}
