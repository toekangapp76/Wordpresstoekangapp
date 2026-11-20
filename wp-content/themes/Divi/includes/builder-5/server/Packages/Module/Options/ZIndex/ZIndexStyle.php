<?php
/**
 * Module: ZIndexStyle class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\Module\Options\ZIndex;

use ET\Builder\Framework\Breakpoint\Breakpoint;
use ET\Builder\Packages\Module\Layout\Components\Style\Utils\Utils;
use ET\Builder\Packages\StyleLibrary\Declarations\ZIndex\ZIndex;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * ZIndexStyle class.
 *
 * @since ??
 */
class ZIndexStyle {

	/**
	 * Get z-index's style component.
	 *
	 * This function is equivalent of JS function:
	 * {@link /api/js/divi-module/functions/ZIndexStyle/ ZIndexStyle} in
	 * `@divi/module` package.
	 *
	 * @since ??
	 *
	 * @param array $args {
	 *     Optional. An array of arguments for styling.
	 *
	 *     @type array    $selectors         Optional. List of selectors. Default `[]`.
	 *     @type callable $selectorFunction  Optional. Selector function. Default `null`.
	 *     @type bool     $important         Optional. Whether to apply "!important" flag to the style declarations.
	 *                                       Default `false`.
	 *                                       Optional. Default is false.
	 *     @type bool     $asStyle           Optional. Whether to wrap the styled output in a style tag.
	 *                                       Default `true`.
	 *     @type string|null   $orderClass   Optional. The selector class name.
	 *     @type bool          $isInsideStickyModule Optional. Whether the module is inside a sticky module or not. Default `false`.
	 *     @type string|null   $stickyParentOrderClass Optional. The sticky parent order class name. Default `null`.
	 *     @type string        $returnType        Optional. This is the type of value that the function will return.
	 *                                       Can be either `string` or `array`. Default `array`.
	 *     @type string   $atRules           Optional. The at-rules to be applied to the style declaration. Default `''`.
	 * }
	 * @return string|array The z-index style component.
	 *
	 * @example
	 * ```php
	 * $args = [
	 *     'selectors'        => ['.my-selector'],
	 *     'selectorFunction' => 'my_selector_function',
	 *     'important'        => true,
	 *     'asStyle'          => false,
	 * ];
	 * $style = self::style($args);
	 * ```
	 */
	public static function style( array $args ) {
		$args = wp_parse_args(
			$args,
			[
				'selectors'        => [],
				'selectorFunction' => null,
				'important'        => false,
				'asStyle'          => true,
				'orderClass'       => null,
				'returnType'       => 'array',
				'atRules'          => '',
			]
		);

		$selector          = $args['selector'];
		$selectors         = $args['selectors'];
		$selector_function = $args['selectorFunction'];
		$attr              = $args['attr'];
		$important         = $args['important'];
		$as_style          = $args['asStyle'];
		$order_class       = $args['orderClass'];
		$at_rules          = $args['atRules'];

		$is_inside_sticky_module   = $args['isInsideStickyModule'] ?? false;
		$sticky_parent_order_class = $args['stickyParentOrderClass'] ?? null;

		// Bail, if noting is there to process.
		if ( empty( $attr ) ) {
			return 'array' === $args['returnType'] ? [] : '';
		}

		$sticky_style_args = self::get_sticky_z_index_style_args(
			[
				'attr'                    => $attr,
				'important'               => $important,
				// Live order, including custom breakpoints. No shortened desktop/tablet/phone fallback.
				'styleBreakpointOrder'    => Breakpoint::get_style_breakpoint_order(),
				'styleBreakpointSettings' => Breakpoint::get_style_breakpoint_settings(),
				'isInsideStickyModule'    => $is_inside_sticky_module,
			]
		);
		$attr                  = $sticky_style_args['attr'];
		$important             = $sticky_style_args['important'];

		$children = Utils::style_statements(
			[
				'selectors'               => ! empty( $selectors ) ? $selectors : [ 'desktop' => [ 'value' => $selector ] ],
				'selectorFunction'        => $selector_function,
				'attr'                    => $attr,
				'defaultPrintedStyleAttr' => $args['defaultPrintedStyleAttr'] ?? [],
				'important'               => $important,
				'declarationFunction'     => function ( $params ) {
					return ZIndex::style_declaration( $params );
				},
				'orderClass'              => $order_class,
				'isInsideStickyModule'    => $is_inside_sticky_module,
				'stickyParentOrderClass'  => $sticky_parent_order_class,
				'returnType'              => $args['returnType'],
				'atRules'                 => $at_rules,
			]
		);

		return Utils::style_wrapper(
			[
				'attr'     => $attr,
				'asStyle'  => $as_style,
				'children' => $children,
			]
		);
	}

	/**
	 * Print the user z-index on the sticky selector, with `!important` only on that rule.
	 *
	 * An explicit sticky style-state value is kept. When no earlier explicit sticky value covers
	 * this breakpoint, the value-state z-index is copied onto the sticky state. The non-sticky
	 * rule is left unchanged.
	 *
	 * This function is equivalent of JS function `getStickyZIndexStyleArgs`
	 * in `@divi/module` package.
	 *
	 * @since ??
	 *
	 * @param array $args {
	 *     Style args before sticky z-index adjustment.
	 *
	 *     @type array         $attr                     Z-index attribute.
	 *     @type array|bool    $important                Caller important flag.
	 *     @type string[]      $styleBreakpointOrder     Style breakpoint print order.
	 *     @type array         $styleBreakpointSettings  Style breakpoint settings.
	 *     @type bool          $isInsideStickyModule     Whether this module sits inside a sticky parent.
	 * }
	 *
	 * @return array{attr: array, important: array|bool}
	 */
	private static function get_sticky_z_index_style_args( array $args ): array {
		// The inline sticky z-index is written on the sticky module, not on descendants.
		if ( ! empty( $args['isInsideStickyModule'] ) ) {
			return [
				'attr'      => $args['attr'],
				'important' => $args['important'],
			];
		}

		$printed_attr               = $args['attr'];
		$important                  = $args['important'];
		$style_breakpoint_order     = $args['styleBreakpointOrder'];
		$style_breakpoint_settings  = $args['styleBreakpointSettings'];
		$explicit_sticky_breakpoints = [];
		$sticky_important            = true === $important || false === $important ? [] : $important;

		// One pass over the style breakpoint order, matching getStickyZIndexStyleArgs.
		// Style statements do not print breakpoints outside that order.
		foreach ( $style_breakpoint_order as $breakpoint ) {
			self::apply_sticky_z_index_breakpoint(
				$printed_attr,
				$sticky_important,
				$explicit_sticky_breakpoints,
				$breakpoint,
				$style_breakpoint_settings,
				true === $important
			);
		}

		return [
			'attr'      => $printed_attr,
			'important' => true === $important ? true : $sticky_important,
		];
	}

	/**
	 * Copy or keep the sticky z-index for one breakpoint and mark that rule important.
	 *
	 * @since ??
	 *
	 * @param array  $printed_attr                Z-index attribute, passed by reference.
	 * @param array  $sticky_important            Important map, passed by reference.
	 * @param array  $explicit_sticky_breakpoints Earlier explicit sticky breakpoints, passed by reference.
	 * @param string $breakpoint                  Breakpoint name.
	 * @param array  $style_breakpoint_settings   Style breakpoint settings.
	 * @param bool   $important_everywhere        Whether the caller already marks every declaration important.
	 *
	 * @return void
	 */
	private static function apply_sticky_z_index_breakpoint(
		array &$printed_attr,
		array &$sticky_important,
		array &$explicit_sticky_breakpoints,
		string $breakpoint,
		array $style_breakpoint_settings,
		bool $important_everywhere
	): void {
		if ( ! isset( $printed_attr[ $breakpoint ] ) || ! is_array( $printed_attr[ $breakpoint ] ) ) {
			return;
		}

		$has_explicit_sticky = self::is_z_index_value( $printed_attr[ $breakpoint ]['sticky'] ?? null );
		$covered             = self::is_covered_by_explicit_sticky(
			$breakpoint,
			$explicit_sticky_breakpoints,
			$style_breakpoint_settings
		);

		if ( ! $has_explicit_sticky && ! $covered && self::is_z_index_value( $printed_attr[ $breakpoint ]['value'] ?? null ) ) {
			$printed_attr[ $breakpoint ]['sticky'] = $printed_attr[ $breakpoint ]['value'];
		}

		if ( ! $important_everywhere && self::is_z_index_value( $printed_attr[ $breakpoint ]['sticky'] ?? null ) ) {
			if ( ! isset( $sticky_important[ $breakpoint ] ) || ! is_array( $sticky_important[ $breakpoint ] ) ) {
				$sticky_important[ $breakpoint ] = [];
			}

			if ( ! isset( $sticky_important[ $breakpoint ]['sticky'] ) || ! is_array( $sticky_important[ $breakpoint ]['sticky'] ) ) {
				$sticky_important[ $breakpoint ]['sticky'] = [];
			}

			$sticky_important[ $breakpoint ]['sticky']['z-index'] = true;
		}

		if ( $has_explicit_sticky ) {
			$explicit_sticky_breakpoints[] = $breakpoint;
		}
	}

	/**
	 * Whether an earlier explicit sticky rule still wins at the current breakpoint.
	 *
	 * Base-device sticky CSS is not wrapped in a media query, and the sticky selector is more
	 * specific than a value-state rule, so it wins at every viewport. Smaller breakpoints use
	 * max-width queries. Larger breakpoints use min-width queries.
	 *
	 * @since ??
	 *
	 * @param string   $breakpoint                   Current breakpoint.
	 * @param string[] $explicit_sticky_breakpoints  Earlier breakpoints with an explicit sticky z-index.
	 * @param array    $style_breakpoint_settings    Style breakpoint settings.
	 *
	 * @return bool
	 */
	private static function is_covered_by_explicit_sticky(
		string $breakpoint,
		array $explicit_sticky_breakpoints,
		array $style_breakpoint_settings
	): bool {
		$current_setting = $style_breakpoint_settings[ $breakpoint ] ?? [];
		$base_order      = null;

		foreach ( $style_breakpoint_settings as $setting ) {
			if ( ! empty( $setting['baseDevice'] ) ) {
				$base_order = $setting['order'] ?? null;
				break;
			}
		}

		foreach ( $explicit_sticky_breakpoints as $earlier ) {
			$earlier_setting = $style_breakpoint_settings[ $earlier ] ?? [];

			if ( ! empty( $earlier_setting['baseDevice'] ) ) {
				return true;
			}

			$earlier_order = $earlier_setting['order'] ?? null;
			$current_order = $current_setting['order'] ?? null;
			$has_orders    = is_int( $earlier_order ) && is_int( $current_order ) && is_int( $base_order );

			if ( ! $has_orders ) {
				continue;
			}

			$earlier_is_below = $earlier_order < $base_order;
			$current_is_below = $current_order < $base_order;

			if ( $earlier_is_below && $current_is_below ) {
				$earlier_max = self::breakpoint_width( $earlier_setting['maxWidth']['value'] ?? null );
				$current_max = self::breakpoint_width( $current_setting['maxWidth']['value'] ?? null );

				if ( null !== $earlier_max && null !== $current_max && $current_max <= $earlier_max ) {
					return true;
				}

				continue;
			}

			$earlier_is_above = $earlier_order > $base_order;
			$current_is_above = $current_order > $base_order;

			if ( $earlier_is_above && $current_is_above ) {
				$earlier_min = self::breakpoint_width( $earlier_setting['minWidth']['value'] ?? null );
				$current_min = self::breakpoint_width( $current_setting['minWidth']['value'] ?? null );

				if ( null !== $earlier_min && null !== $current_min && $current_min >= $earlier_min ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a z-index attribute value should be printed.
	 *
	 * @since ??
	 *
	 * @param mixed $value Z-index attribute value.
	 *
	 * @return bool
	 */
	private static function is_z_index_value( $value ): bool {
		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Parse a breakpoint width token such as `980px`.
	 *
	 * @since ??
	 *
	 * @param mixed $width Breakpoint width token.
	 *
	 * @return int|null
	 */
	private static function breakpoint_width( $width ): ?int {
		if ( ! is_string( $width ) || '' === $width ) {
			return null;
		}

		if ( 1 !== preg_match( '/^(-?\d+)/', $width, $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}
}
