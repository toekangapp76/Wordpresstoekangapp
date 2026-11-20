<?php
/**
 * MemberPress frontend compatibility.
 *
 * @since   ??
 * @package Divi
 */

namespace ET\Builder\FrontEnd\PluginCompat\MemberPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ET\Builder\Framework\Utility\Conditions;

/**
 * Registers MemberPress compatibility for Divi 5 frontend content.
 *
 * @since ??
 * @link https://memberpress.com/
 */
final class MemberPress {

	/**
	 * Register MemberPress compatibility hooks.
	 *
	 * Priority 20 runs after MemberPress's block adapter at priority 10, preserving
	 * the plugin's native and Gutenberg product-page classifications.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( ! class_exists( '\MeprProduct' ) ) {
			return;
		}

		add_filter( 'mepr_is_product_page', [ self::class, 'filter_is_product_page' ], 20, 2 );
	}

	/**
	 * Classify decoded current-post Divi content for MemberPress.
	 *
	 * @since ??
	 *
	 * @param mixed $result MemberPress's current product-page result.
	 * @param mixed $post   Post MemberPress is classifying.
	 *
	 * @return mixed Original result or a valid MemberPress product.
	 */
	public static function filter_is_product_page( $result, $post ) {
		if ( false !== $result ) {
			return $result;
		}

		if (
			! ( $post instanceof \WP_Post )
			|| ! class_exists( '\MeprProduct' )
			|| Conditions::is_vb_top_window()
			|| Conditions::is_vb_app_window()
		) {
			return $result;
		}

		$product_id = MemberPressContent::get_product_id( (string) $post->post_content );

		if ( 1 > $product_id ) {
			return $result;
		}

		try {
			$product = new \MeprProduct( $product_id );
		} catch ( \Throwable $error ) {
			return $result;
		}

		return 0 < absint( $product->ID ?? 0 ) ? $product : $result;
	}
}
