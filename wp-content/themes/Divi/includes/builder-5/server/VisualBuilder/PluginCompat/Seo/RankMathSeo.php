<?php
/**
 * Rank Math SEO compatibility for Divi 5.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\PluginCompat\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\FrontEnd\BlockParser\BlockParser;

/**
 * Supplies rendered Divi 5 content to Rank Math's sitemap image parser.
 *
 * @since ??
 */
class RankMathSeo {

	/**
	 * Check whether Rank Math SEO is active.
	 *
	 * Rank Math Pro is an add-on that requires this plugin, so the free plugin path
	 * covers both.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'seo-by-rank-math/rank-math.php' );
	}

	/**
	 * Register Rank Math compatibility hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'rank_math/sitemap/content_before_parse_html_images', [ self::class, 'render_sitemap_content' ], 9, 2 );
	}

	/**
	 * Render Divi 5 blocks before Rank Math extracts sitemap images.
	 *
	 * @since ??
	 *
	 * @param string $content Raw post content.
	 * @param int    $post_id Post ID provided by Rank Math.
	 *
	 * @return string
	 */
	public static function render_sitemap_content( $content, $post_id = 0 ): string {
		if ( ! is_string( $content ) || ! BlockParser::has_any_divi_block( $content ) ) {
			return is_string( $content ) ? $content : '';
		}

		return SeoRenderedContent::render( $content, absint( $post_id ) );
	}
}
