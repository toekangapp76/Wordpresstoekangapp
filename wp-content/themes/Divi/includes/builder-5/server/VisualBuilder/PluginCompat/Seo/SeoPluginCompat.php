<?php
/**
 * SEO plugin compatibility bootstrap for Divi 5.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\PluginCompat\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;

/**
 * Loads per-plugin SEO compatibility classes.
 *
 * @since ??
 */
class SeoPluginCompat implements DependencyInterface {

	/**
	 * Register each SEO plugin's compatibility hooks.
	 *
	 * Individual classes still gate themselves on plugin activity.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		( new AioSeo() )->load();
		( new RankMathSeo() )->load();
		( new YoastSeo() )->load();
	}
}
