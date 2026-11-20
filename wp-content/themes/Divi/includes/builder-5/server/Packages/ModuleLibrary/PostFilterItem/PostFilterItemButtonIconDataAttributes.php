<?php
/**
 * Post Filter Item button icon data-attribute helper.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\ModuleLibrary\PostFilterItem;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Packages\IconLibrary\IconFont\Utils;

/**
 * Builds responsive `data-icon*` attributes from button decoration attrs.
 *
 * @since ??
 */
class PostFilterItemButtonIconDataAttributes {
	/**
	 * Build responsive `data-icon*` attributes from button decoration attrs.
	 *
	 * When module `icon.settings` are absent on desktop, fall back to Customizer
	 * desktop icon settings. Do not invent tablet/phone Customizer attrs.
	 *
	 * @since ??
	 *
	 * @param array $button_decoration            Button decoration attrs.
	 * @param array $customizer_button_decoration Customizer button decoration attrs used as a desktop icon fallback.
	 *
	 * @return array<string, string>
	 */
	public static function get( array $button_decoration = [], array $customizer_button_decoration = [] ): array {
		if ( empty( $button_decoration ) ) {
			return [];
		}

		$is_icon_enabled = 'on' === ( $button_decoration['desktop']['value']['icon']['enable'] ?? null );

		if ( ! $is_icon_enabled ) {
			return [];
		}

		$icon_data_attrs = [];

		foreach ( $button_decoration as $breakpoint => $breakpoint_value ) {
			if ( ! is_array( $breakpoint_value ) ) {
				continue;
			}

			$processed_icon = self::_process_icon_settings( $breakpoint_value['value']['icon']['settings'] ?? null );

			if ( '' === $processed_icon ) {
				continue;
			}

			$icon_data_attrs[ self::_breakpoint_to_data_attr( (string) $breakpoint ) ] = $processed_icon;
		}

		if ( ! isset( $icon_data_attrs['data-icon'] ) ) {
			$processed_customizer_icon = self::_process_icon_settings(
				$customizer_button_decoration['desktop']['value']['icon']['settings'] ?? null,
			);

			if ( '' !== $processed_customizer_icon ) {
				$icon_data_attrs['data-icon'] = $processed_customizer_icon;
			}
		}

		return $icon_data_attrs;
	}

	/**
	 * Convert a breakpoint name to the matching button icon data-attribute key.
	 *
	 * @since ??
	 *
	 * @param string $breakpoint Breakpoint name.
	 *
	 * @return string
	 */
	private static function _breakpoint_to_data_attr( string $breakpoint ): string {
		if ( 'desktop' === $breakpoint ) {
			return 'data-icon';
		}

		return 'data-icon-' . strtolower( (string) preg_replace( '/([A-Z])/', '-$1', $breakpoint ) );
	}

	/**
	 * Process icon settings into an escaped data-icon attribute value.
	 *
	 * @since ??
	 *
	 * @param array|null $icon_settings Icon settings.
	 *
	 * @return string
	 */
	private static function _process_icon_settings( ?array $icon_settings ): string {
		if ( empty( $icon_settings ) ) {
			return '';
		}

		$processed_icon = Utils::escape_font_icon( Utils::process_font_icon( $icon_settings ) );

		return is_string( $processed_icon ) ? $processed_icon : '';
	}
}
