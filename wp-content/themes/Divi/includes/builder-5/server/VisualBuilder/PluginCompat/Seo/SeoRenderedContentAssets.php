<?php
/**
 * WordPress editor assets for D5 SEO rendered-content compatibility.
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
 * Enqueues the shared WordPress editor adapter for Divi 5 layouts.
 *
 * @since ??
 */
class SeoRenderedContentAssets {

	/**
	 * Check whether an SEO plugin that consumes rendered content is running.
	 *
	 * @since ??
	 *
	 * @return bool
	 */
	public static function has_supported_plugin(): bool {
		return YoastSeo::is_active() || RankMathSeo::is_active();
	}

	/**
	 * Enqueue the adapter when an SEO plugin is active and the post contains Divi 5 blocks.
	 *
	 * @since ??
	 *
	 * @param int|null $post_id Post being edited.
	 *
	 * @return void
	 */
	public static function enqueue( ?int $post_id ): void {
		if ( ! $post_id || ! self::has_supported_plugin() ) {
			return;
		}

		$content = get_post_field( 'post_content', $post_id );

		if ( ! is_string( $content ) || ! BlockParser::has_any_divi_block( $content ) ) {
			return;
		}

		/*
		 * The compatibility package has no runtime dependencies on purpose. Bundles built by
		 * the Visual Builder webpack config resolve `@wordpress/*` imports against
		 * `window.vendor`, which only exists inside the builder, so editor screens can only
		 * load a package that imports nothing.
		 */
		wp_enqueue_script(
			'divi-seo-plugin-compat',
			ET_BUILDER_5_URI . '/visual-builder/build/seo-plugin-compat.js',
			[],
			ET_BUILDER_PRODUCT_VERSION,
			true
		);

		wp_enqueue_script(
			'divi-seo-rendered-content',
			ET_BUILDER_5_URI . '/visual-builder/build/script-library-seo-rendered-content.js',
			[ 'divi-seo-plugin-compat', 'jquery', 'wp-hooks' ],
			ET_BUILDER_PRODUCT_VERSION,
			true
		);

		wp_localize_script(
			'divi-seo-rendered-content',
			'diviSeoRenderedContent',
			[
				'postId'    => $post_id,
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'restUrl'   => rest_url( 'divi/v1/seo/rendered-content' ),
			]
		);
	}
}
