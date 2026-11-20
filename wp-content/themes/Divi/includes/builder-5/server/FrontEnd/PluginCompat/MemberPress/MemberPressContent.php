<?php
/**
 * MemberPress compatibility content interpreter.
 *
 * @since   ??
 * @package Divi
 */

namespace ET\Builder\FrontEnd\PluginCompat\MemberPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Interprets MemberPress registration shortcodes stored in Divi module content.
 *
 * @since ??
 */
final class MemberPressContent {

	/**
	 * MemberPress registration shortcodes that identify a product page.
	 *
	 * @since ??
	 *
	 * @var string[]
	 */
	private const REGISTRATION_SHORTCODES = [
		'mepr_product_registration_form',
		'mepr_membership_registration_form',
		'mepr-product-registration-form',
		'mepr-membership-registration-form',
	];

	/**
	 * Find the first MemberPress product ID in saved Divi module content.
	 *
	 * Only the desktop value of `content.innerContent` is inspected. WordPress parses
	 * the block JSON before shortcode attributes are read, so escaped quotes are decoded
	 * without rendering blocks or executing shortcode callbacks.
	 *
	 * @since ??
	 *
	 * @param string $content Saved post content.
	 *
	 * @return int Positive MemberPress product ID, or zero when none is found.
	 */
	public static function get_product_id( string $content ): int {
		if ( ! str_contains( $content, '<!-- wp:divi/' ) || ! self::_contains_registration_shortcode( $content ) ) {
			return 0;
		}

		static $cache = [];

		$cache_key = md5( $content );

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$cache[ $cache_key ] = self::_find_product_id_in_blocks( parse_blocks( $content ) );

		return $cache[ $cache_key ];
	}

	/**
	 * Check whether content contains a supported registration shortcode opener.
	 *
	 * @since ??
	 *
	 * @param string $content Content to inspect.
	 *
	 * @return bool
	 */
	private static function _contains_registration_shortcode( string $content ): bool {
		foreach ( self::REGISTRATION_SHORTCODES as $shortcode ) {
			if ( str_contains( $content, '[' . $shortcode ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walk parsed blocks in render order and return the first product ID.
	 *
	 * @since ??
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 *
	 * @return int
	 */
	private static function _find_product_id_in_blocks( array $blocks ): int {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( is_string( $block_name ) && str_starts_with( $block_name, 'divi/' ) ) {
				$value      = $block['attrs']['content']['innerContent']['desktop']['value'] ?? null;
				$product_id = is_string( $value ) ? self::_find_product_id_in_module_content( $value ) : 0;

				if ( 0 < $product_id ) {
					return $product_id;
				}
			}

			$inner_blocks = $block['innerBlocks'] ?? [];

			if ( is_array( $inner_blocks ) && [] !== $inner_blocks ) {
				$product_id = self::_find_product_id_in_blocks( $inner_blocks );

				if ( 0 < $product_id ) {
					return $product_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Find the first valid product ID in one decoded module content value.
	 *
	 * WordPress owns the shortcode grammar through `get_shortcode_regex()` and
	 * `shortcode_parse_atts()`. No shortcode callback is invoked during detection.
	 *
	 * @since ??
	 *
	 * @param string $content Decoded module content.
	 *
	 * @return int
	 */
	private static function _find_product_id_in_module_content( string $content ): int {
		$matches = [];
		$pattern = '/' . get_shortcode_regex( self::REGISTRATION_SHORTCODES ) . '/s';

		if ( 1 > preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return 0;
		}

		foreach ( $matches as $match ) {
			if ( '[' === ( $match[1] ?? '' ) || ']' === ( $match[6] ?? '' ) ) {
				continue;
			}

			$attributes = shortcode_parse_atts( $match[3] ?? '' );

			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( [ 'id', 'product_id' ] as $attribute_name ) {
				$value = $attributes[ $attribute_name ] ?? '';
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';

				if ( '' !== $value && ctype_digit( $value ) && 0 < (int) $value ) {
					return (int) $value;
				}
			}
		}

		return 0;
	}
}
