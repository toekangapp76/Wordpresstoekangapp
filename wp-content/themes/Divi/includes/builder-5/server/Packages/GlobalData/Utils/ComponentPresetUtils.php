<?php
/**
 * Component Preset Utilities
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\GlobalData\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Packages\GlobalData\GlobalPreset;

/**
 * Component Preset Utilities
 *
 * @since ??
 */
class ComponentPresetUtils {

	/**
	 * Compose Component source presets with instance-local preset segments.
	 *
	 * @param array $merged_attrs   Attributes after selective-sync filtering.
	 * @param array $template_attrs Source Component attributes.
	 * @param array $local_attrs    Complete instance snapshot containing local preset segments.
	 *
	 * @return array Attributes with effective source and local preset stacks.
	 */
	public static function compose_component_preset_attrs(
		array $merged_attrs,
		array $template_attrs,
		array $local_attrs
	): array {
		unset(
			$merged_attrs['modulePreset'],
			$merged_attrs['groupPreset'],
			$merged_attrs['localModulePreset'],
			$merged_attrs['localGroupPreset']
		);

		$source_module_presets    = self::_normalize_unique_preset_ids(
			$template_attrs['modulePreset'] ?? []
		);
		$local_module_presets     = self::_get_local_preset_ids(
			$local_attrs['localModulePreset'] ?? [],
			$source_module_presets
		);
		$effective_module_presets = self::_append_preset_ids(
			$source_module_presets,
			$local_module_presets
		);

		if ( ! empty( $effective_module_presets ) ) {
			$merged_attrs['modulePreset'] = $effective_module_presets;
		}

		if ( ! empty( $local_module_presets ) ) {
			$merged_attrs['localModulePreset'] = $local_module_presets;
		}

		$effective_group_presets       = [];
		$effective_local_group_presets = [];
		$source_group_presets          = is_array( $template_attrs['groupPreset'] ?? null )
			? $template_attrs['groupPreset']
			: [];

		foreach ( $source_group_presets as $group_id => $source_group_preset ) {
			if ( ! is_array( $source_group_preset ) ) {
				continue;
			}

			$source_preset_ids = self::_normalize_unique_preset_ids(
				$source_group_preset['presetId'] ?? []
			);

			if ( ! empty( $source_preset_ids ) ) {
				$effective_group_presets[ $group_id ]             = self::_copy_group_preset_item( $source_group_preset );
				$effective_group_presets[ $group_id ]['presetId'] = $source_preset_ids;
			}
		}

		$local_group_presets = is_array( $local_attrs['localGroupPreset'] ?? null )
			? $local_attrs['localGroupPreset']
			: [];

		foreach ( $local_group_presets as $group_id => $local_group_preset ) {
			if ( ! is_array( $local_group_preset ) ) {
				continue;
			}

			$source_group_preset = $effective_group_presets[ $group_id ] ?? [];
			$source_preset_ids   = self::_normalize_unique_preset_ids(
				$source_group_preset['presetId'] ?? []
			);
			$local_preset_ids    = self::_get_local_preset_ids(
				$local_group_preset['presetId'] ?? [],
				$source_preset_ids
			);

			if ( empty( $local_preset_ids ) ) {
				continue;
			}

			$effective_local_group_presets[ $group_id ]             = self::_copy_group_preset_item( $local_group_preset );
			$effective_local_group_presets[ $group_id ]['presetId'] = $local_preset_ids;
			$effective_group_presets[ $group_id ]                   = array_merge(
				self::_copy_group_preset_item( $local_group_preset ),
				$source_group_preset,
				[
					'presetId' => self::_append_preset_ids( $source_preset_ids, $local_preset_ids ),
				]
			);
		}

		if ( ! empty( $effective_group_presets ) ) {
			$merged_attrs['groupPreset'] = $effective_group_presets;
		}

		if ( ! empty( $effective_local_group_presets ) ) {
			$merged_attrs['localGroupPreset'] = $effective_local_group_presets;
		}

		return $merged_attrs;
	}

	/**
	 * Append unique preset IDs while preserving source-before-local order.
	 *
	 * @param array $source_preset_ids Source-owned preset IDs.
	 * @param array $local_preset_ids  Instance-local preset IDs.
	 *
	 * @return array Effective preset stack.
	 */
	private static function _append_preset_ids( array $source_preset_ids, array $local_preset_ids ): array {
		return array_values( array_unique( array_merge( $source_preset_ids, $local_preset_ids ) ) );
	}

	/**
	 * Copy supported group preset item properties.
	 *
	 * @param array $group_preset Group preset item.
	 *
	 * @return array Copied group preset item.
	 */
	private static function _copy_group_preset_item( array $group_preset ): array {
		return array_intersect_key(
			$group_preset,
			array_flip( [ 'groupName', 'presetId', 'panel', 'label' ] )
		);
	}

	/**
	 * Normalize and deduplicate a preset stack while preserving order.
	 *
	 * @param mixed $preset_value Preset stack value.
	 *
	 * @return array Normalized unique preset IDs.
	 */
	private static function _normalize_unique_preset_ids( $preset_value ): array {
		return array_values( array_unique( GlobalPreset::normalize_preset_stack( $preset_value ) ) );
	}

	/**
	 * Return instance-local IDs that are not owned by the source stack.
	 *
	 * @param mixed $local_preset_value Local preset stack value.
	 * @param array $source_preset_ids  Source-owned preset IDs.
	 *
	 * @return array Instance-local preset IDs.
	 */
	private static function _get_local_preset_ids( $local_preset_value, array $source_preset_ids ): array {
		return array_values(
			array_diff(
				self::_normalize_unique_preset_ids( $local_preset_value ),
				$source_preset_ids
			)
		);
	}
}
