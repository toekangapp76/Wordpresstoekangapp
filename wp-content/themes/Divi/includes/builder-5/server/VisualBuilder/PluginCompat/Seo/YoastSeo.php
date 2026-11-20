<?php
/**
 * Yoast SEO compatibility for Divi 5.
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
 * Supplies rendered Divi 5 content to Yoast's sitemap image parser.
 *
 * @since ??
 */
class YoastSeo {

	/**
	 * Post ID captured from Yoast's sitemap URL filter.
	 *
	 * Yoast's image-content filter only receives the raw content string. The
	 * preceding `wpseo_xml_sitemap_post_url` filter receives the scanned post.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	private static $current_sitemap_post_id = 0;

	/**
	 * Check whether Yoast SEO is active.
	 *
	 * Yoast Premium is an add-on that requires this plugin, so the free plugin path
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

		return is_plugin_active( 'wordpress-seo/wp-seo.php' );
	}

	/**
	 * Register Yoast compatibility hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'wpseo_xml_sitemap_post_url', [ self::class, 'capture_sitemap_post' ], 9, 2 );
		add_filter( 'wpseo_sitemap_content_before_parse_html_images', [ self::class, 'render_sitemap_content' ], 9 );
	}

	/**
	 * Remember the post Yoast is currently adding to the sitemap.
	 *
	 * @since ??
	 *
	 * @param string $url  Permalink Yoast will use in the sitemap.
	 * @param object $post Post being scanned.
	 *
	 * @return string
	 */
	public static function capture_sitemap_post( $url, $post ) {
		self::$current_sitemap_post_id = is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : 0;

		return $url;
	}

	/**
	 * Render Divi 5 blocks before Yoast extracts sitemap images.
	 *
	 * @since ??
	 *
	 * @param string $content Raw post content.
	 *
	 * @return string
	 */
	public static function render_sitemap_content( $content ): string {
		if ( ! is_string( $content ) || ! BlockParser::has_any_divi_block( $content ) ) {
			return is_string( $content ) ? $content : '';
		}

		$post_id                       = self::$current_sitemap_post_id;
		self::$current_sitemap_post_id = 0;

		return SeoRenderedContent::render( $content, $post_id );
	}
}
