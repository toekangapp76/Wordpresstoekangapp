<?php
/**
 * Module: FontUtils class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\Module\Options\Font;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * FontUtils class.
 *
 * This class provides utility functions for font option styles.
 *
 * @since ??
 */
class FontUtils {

	/**
	 * Keep typography selector overrides aligned with text-align selectors.
	 *
	 * Many modules provide only `text-align` overrides, but related properties
	 * (`writing-mode`, `transform`, `text-wrap`, `hyphens`, `word-wrap`) should follow the
	 * same selector unless explicitly set.
	 *
	 * This function is equivalent of JS function:
	 * {@link /api/js/divi-module/functions/getAlignedFontPropertySelectors getAlignedFontPropertySelectors} in
	 * `@divi/module` package.
	 *
	 * @since ??
	 *
	 * @param array $font_property_selectors An array of font property selectors keyed by breakpoint and state.
	 *
	 * @return array The font property selectors map with related typography selectors aligned to `text-align`.
	 *
	 * @example:
	 * ```php
	 * $aligned = FontUtils::get_aligned_font_property_selectors(
	 *     [
	 *         'desktop' => [
	 *             'value' => [
	 *                 'text-align' => '.pagination ul',
	 *             ],
	 *         ],
	 *     ]
	 * );
	 *
	 * // `$aligned['desktop']['value']['writing-mode']` equals `.pagination ul`.
	 * ```
	 */
	public static function get_aligned_font_property_selectors( array $font_property_selectors ): array {
		if ( empty( $font_property_selectors ) ) {
			return [];
		}

		$aligned = [];

		foreach ( $font_property_selectors as $breakpoint => $state_map ) {
			$aligned[ $breakpoint ] = [];
			$states                 = is_array( $state_map ) ? $state_map : [];

			foreach ( $states as $current_state => $property_map ) {
				$text_align_selector = is_array( $property_map ) ? ( $property_map['text-align'] ?? '' ) : '';

				if ( is_string( $text_align_selector ) && '' !== $text_align_selector ) {
					$aligned[ $breakpoint ][ $current_state ] = array_merge(
						$property_map,
						[
							'writing-mode' => $property_map['writing-mode'] ?? $text_align_selector,
							'transform'    => $property_map['transform'] ?? $text_align_selector,
							'text-wrap'    => $property_map['text-wrap'] ?? $text_align_selector,
							'hyphens'      => $property_map['hyphens'] ?? $text_align_selector,
							'word-wrap'    => $property_map['word-wrap'] ?? $text_align_selector,
						]
					);
				} else {
					$aligned[ $breakpoint ][ $current_state ] = $property_map;
				}
			}
		}

		return $aligned;
	}

	/**
	 * Build default style selectors from a font property selector map.
	 *
	 * Ungrouped font declarations (properties not listed in the map) use these
	 * selectors instead of the form field / placeholder selector.
	 *
	 * @since ??
	 *
	 * @param array $font_property_map The font CSS property to selector map keyed by breakpoint and state.
	 *
	 * @return array The style selectors keyed by breakpoint and state.
	 */
	public static function get_style_selectors_from_font_property_map( array $font_property_map ): array {
		if ( empty( $font_property_map ) ) {
			return [];
		}

		$selectors = [];

		foreach ( $font_property_map as $breakpoint => $state_map ) {
			if ( ! is_array( $state_map ) ) {
				continue;
			}

			foreach ( $state_map as $state => $property_map ) {
				if ( ! is_array( $property_map ) ) {
					continue;
				}

				$mapped_selectors = array_filter(
					array_values( $property_map ),
					function ( $value ) {
						return is_string( $value ) && '' !== $value;
					}
				);
				$fallback         = $property_map['text-align'] ?? $property_map['color'] ?? ( $mapped_selectors[0] ?? '' );

				if ( ! is_string( $fallback ) || '' === $fallback ) {
					continue;
				}

				$selectors[ $breakpoint ][ $state ] = $fallback;
			}
		}

		return $selectors;
	}
}
