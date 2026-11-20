<?php
/**
 * All in One SEO compatibility for Divi 5.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\PluginCompat\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\FrontEnd\BlockParser\BlockParser;
use WP_HTML_Tag_Processor;

/**
 * Supplies images from rendered Divi 5 content to All in One SEO's sitemap.
 *
 * All in One SEO owns its editor analysis integration and has no public
 * rendered-content filter equivalent to the Yoast or Rank Math APIs.
 *
 * @since ??
 */
class AioSeo {

	/**
	 * Check whether All in One SEO is active.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			|| is_plugin_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
	}

	/**
	 * Register All in One SEO compatibility hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'aioseo_sitemap_images', [ self::class, 'append_sitemap_images' ], 10, 2 );
	}

	/**
	 * Append image URLs found in rendered Divi 5 blocks.
	 *
	 * @since ??
	 *
	 * AIOSEO passes a `WP_Post` when scanning directly and a database result
	 * object when its scheduled scanner processes a batch.
	 *
	 * @param array  $images Existing image IDs and URLs.
	 * @param object $post   Post being scanned.
	 *
	 * @return array
	 */
	public static function append_sitemap_images( $images, $post ): array {
		if (
			! is_array( $images )
			|| ! is_object( $post )
			|| ! isset( $post->ID, $post->post_content )
			|| ! is_string( $post->post_content )
			|| ! BlockParser::has_any_divi_block( $post->post_content )
		) {
			return is_array( $images ) ? $images : [];
		}

		$rendered_content = SeoRenderedContent::render( $post->post_content, absint( $post->ID ) );
		$processor        = new WP_HTML_Tag_Processor( $rendered_content );

		while ( $processor->next_tag( 'img' ) ) {
			$src = $processor->get_attribute( 'src' );

			if ( is_string( $src ) && '' !== $src && ! in_array( $src, $images, true ) ) {
				$images[] = $src;
			}
		}

		return $images;
	}
}
