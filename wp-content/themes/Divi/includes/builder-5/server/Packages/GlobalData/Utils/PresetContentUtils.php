<?php
/**
 * Preset Content Utilities
 *
 * Shared utilities for applying preset IDs to block content.
 * Used by both migration path and regular import path.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\GlobalData\Utils;

use ET\Builder\Framework\Utility\ArrayUtility;
use ET\Builder\Packages\GlobalData\GlobalPreset;
use ET\Builder\Packages\ModuleLibrary\ModuleRegistration;
use ET\Builder\Packages\ModuleUtils\ComponentLocalAttrsUtils;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Preset Content Utilities
 *
 * @since ??
 */
class PresetContentUtils {

	/**
	 * Apply default imported preset IDs to block content.
	 *
	 * Only assigns presets when modulePreset is missing, empty, or "default".
	 *
	 * @param string $post_content The post content (Gutenberg blocks).
	 * @param array  $default_imported_presets Default imported module preset IDs. Format: [ 'divi/section' => [ 'presetId' => 'abc123', 'moduleName' => 'divi/section' ], ... ].
	 * @return string The updated post content.
	 */
	public static function apply_default_imported_presets_to_content( string $post_content, array $default_imported_presets ): string {
		if ( empty( $post_content ) || empty( $default_imported_presets ) ) {
			return $post_content;
		}

		$blocks = parse_blocks( $post_content );

		if ( empty( $blocks ) ) {
			return $post_content;
		}

		$blocks = self::_apply_default_imported_presets_to_blocks( $blocks, $default_imported_presets );

		return serialize_blocks( $blocks );
	}

	/**
	 * Apply remapped preset IDs to block content.
	 *
	 * Replaces old preset IDs with new ones in modulePreset and groupPreset block attributes.
	 * This handles cases where preset IDs were remapped during import (e.g., reserved IDs
	 * like `_initial` or `default` that were assigned new unique IDs).
	 *
	 * @since ??
	 *
	 * @param string $post_content The post content (Gutenberg blocks).
	 * @param array  $preset_id_mappings Preset ID mappings from process_presets().
	 *     Format: [
	 *         'module' => [ 'divi/heading' => [ '_initial' => 'new_id' ] ],
	 *         'group'  => [ 'group_name'   => [ 'old_id'   => 'new_id' ] ],
	 *     ].
	 *
	 * @return string The updated post content.
	 */
	public static function apply_preset_id_mappings_to_content( string $post_content, array $preset_id_mappings ): string {
		if ( empty( $post_content ) || empty( $preset_id_mappings ) ) {
			return $post_content;
		}

		$blocks = parse_blocks( $post_content );

		if ( empty( $blocks ) ) {
			return $post_content;
		}

		$blocks = self::_apply_preset_id_mappings_to_blocks( $blocks, $preset_id_mappings );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively apply remapped preset IDs to blocks.
	 *
	 * @since ??
	 *
	 * @param array $blocks Blocks array.
	 * @param array $preset_id_mappings Preset ID mappings.
	 *
	 * @return array Updated blocks array.
	 */
	private static function _apply_preset_id_mappings_to_blocks( array $blocks, array $preset_id_mappings ): array {
		$module_mappings = $preset_id_mappings['module'] ?? [];
		$group_mappings  = $preset_id_mappings['group'] ?? [];

		foreach ( $blocks as &$block ) {
			$block_name = $block['blockName'] ?? '';
			$attrs      = $block['attrs'] ?? [];

			if ( ! empty( $block_name ) ) {
				$block['attrs'] = self::_apply_preset_id_mappings_to_attrs(
					$attrs,
					$block_name,
					$module_mappings,
					$group_mappings
				);

				if ( isset( $attrs['localAttrs'] ) && is_array( $attrs['localAttrs'] ) ) {
					$local_block_name             = $attrs['blockName'] ?? $block_name;
					$block['attrs']['localAttrs'] = self::_apply_preset_id_mappings_to_attrs(
						$attrs['localAttrs'],
						$local_block_name,
						$module_mappings,
						$group_mappings,
						'localModulePreset',
						'localGroupPreset'
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::_apply_preset_id_mappings_to_blocks(
					$block['innerBlocks'],
					$preset_id_mappings
				);
			}
		}

		return $blocks;
	}

	/**
	 * Apply imported preset ID mappings to one attribute collection.
	 *
	 * @param array  $attrs Attributes to update.
	 * @param string $block_name Module block name.
	 * @param array  $module_mappings Module preset mappings.
	 * @param array  $group_mappings Group preset mappings.
	 * @param string $module_attr_name Module preset attribute name.
	 * @param string $group_attr_name Group preset attribute name.
	 *
	 * @return array Updated attributes.
	 */
	private static function _apply_preset_id_mappings_to_attrs(
		array $attrs,
		string $block_name,
		array $module_mappings,
		array $group_mappings,
		string $module_attr_name = 'modulePreset',
		string $group_attr_name = 'groupPreset'
	): array {
		if ( isset( $module_mappings[ $block_name ] ) && isset( $attrs[ $module_attr_name ] ) ) {
			$module_preset              = $attrs[ $module_attr_name ];
			$id_map                     = $module_mappings[ $block_name ];
			$attrs[ $module_attr_name ] = self::_apply_id_map_to_preset_value( $module_preset, $id_map );
		}

		if ( empty( $group_mappings ) || ! isset( $attrs[ $group_attr_name ] ) || ! is_array( $attrs[ $group_attr_name ] ) ) {
			return $attrs;
		}

		foreach ( $attrs[ $group_attr_name ] as $group_key => $group_data ) {
			if ( ! is_array( $group_data ) || ! isset( $group_data['presetId'] ) ) {
				continue;
			}

			$group_name = $group_data['groupName'] ?? $group_key;
			$preset_id  = $group_data['presetId'];

			if ( ( ! is_string( $group_name ) && ! is_int( $group_name ) ) || ! isset( $group_mappings[ $group_name ] ) || ! is_array( $group_mappings[ $group_name ] ) ) {
				continue;
			}

			$group_id_map = $group_mappings[ $group_name ];

			$attrs[ $group_attr_name ][ $group_key ]['presetId'] = self::_apply_id_map_to_preset_value(
				$preset_id,
				$group_id_map
			);
		}

		return $attrs;
	}

	/**
	 * Apply an ID map to a scalar or stacked preset value.
	 *
	 * @param mixed $preset_value Preset ID or preset ID stack.
	 * @param array $id_map Preset ID mappings.
	 *
	 * @return mixed Remapped preset value.
	 */
	private static function _apply_id_map_to_preset_value( $preset_value, array $id_map ) {
		if ( is_string( $preset_value ) || is_int( $preset_value ) ) {
			return $id_map[ $preset_value ] ?? $preset_value;
		}

		if ( ! is_array( $preset_value ) ) {
			return $preset_value;
		}

		return array_map(
			static function ( $preset_id ) use ( $id_map ) {
				return ( is_string( $preset_id ) || is_int( $preset_id ) )
					? ( $id_map[ $preset_id ] ?? $preset_id )
					: $preset_id;
			},
			$preset_value
		);
	}

	/**
	 * Inline preset attributes into block content and remove preset assignments.
	 *
	 * Explicit module attributes take precedence over preset values, producing
	 * self-contained content that does not depend on the source preset IDs.
	 * Source assignments are removed after inlining, but destination default
	 * presets intentionally remain active for attributes that were not inlined.
	 * Do not replace removed assignments with a synthetic no-default preset.
	 *
	 * @since ??
	 *
	 * @param string $post_content Serialized block content.
	 * @param array  $presets      Preset data used to resolve assignments.
	 *
	 * @return string Updated block content.
	 */
	public static function inline_preset_attrs_into_content( string $post_content, array $presets ): string {
		if ( '' === $post_content || empty( $presets ) ) {
			return $post_content;
		}

		$blocks = parse_blocks( $post_content );
		if ( empty( $blocks ) ) {
			return $post_content;
		}

		$blocks = self::_inline_preset_attrs_into_blocks( $blocks, $presets );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively inline preset attributes into parsed blocks.
	 *
	 * @since ??
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param array $presets Preset data used to resolve assignments.
	 *
	 * @return array Updated blocks.
	 */
	private static function _inline_preset_attrs_into_blocks( array $blocks, array $presets ): array {
		foreach ( $blocks as &$block ) {
			$block_name   = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$module_attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			$is_module_block = str_starts_with( $block_name, 'divi/' )
				|| null !== ModuleRegistration::get_custom_metadata_folder( $block_name );

			if ( $is_module_block ) {
				$inlined_direct_local_preset_attrs = self::_get_inlined_local_preset_attrs(
					$block_name,
					$module_attrs,
					$presets
				);
				$effective_module_attrs            = empty( $inlined_direct_local_preset_attrs )
					? $module_attrs
					: ComponentPresetUtils::compose_component_preset_attrs(
						$module_attrs,
						$module_attrs,
						$module_attrs
					);
				$block['attrs']                    = self::_get_inlined_preset_attrs(
					$block_name,
					$effective_module_attrs,
					$presets
				);

				unset(
					$block['attrs']['modulePreset'],
					$block['attrs']['groupPreset'],
					$block['attrs']['localModulePreset'],
					$block['attrs']['localGroupPreset']
				);

				if ( ! empty( $inlined_direct_local_preset_attrs ) ) {
					$local_preset_override_paths = self::_merge_local_preset_override_paths(
						$module_attrs,
						$inlined_direct_local_preset_attrs
					);
					if ( ! empty( $local_preset_override_paths ) ) {
						$block['attrs']['localAttrOverridePaths'] = $local_preset_override_paths;
					}
				}
			}

			$local_block_name = isset( $module_attrs['blockName'] ) && is_string( $module_attrs['blockName'] )
				? $module_attrs['blockName']
				: '';
			$local_attrs      = isset( $module_attrs['localAttrs'] ) && is_array( $module_attrs['localAttrs'] )
				? $module_attrs['localAttrs']
				: [];

			if ( 'divi/global-layout' === $block_name && '' !== $local_block_name && ! empty( $local_attrs ) ) {
				$inlined_local_preset_attrs = self::_get_inlined_local_preset_attrs(
					$local_block_name,
					$local_attrs,
					$presets
				);
				$effective_local_attrs      = empty( $inlined_local_preset_attrs )
					? $local_attrs
					: ComponentPresetUtils::compose_component_preset_attrs(
						$local_attrs,
						$local_attrs,
						$local_attrs
					);
				$inlined_snapshot_attrs     = self::_get_inlined_preset_attrs(
					$local_block_name,
					$effective_local_attrs,
					$presets
				);

				unset(
					$inlined_snapshot_attrs['modulePreset'],
					$inlined_snapshot_attrs['groupPreset'],
					$inlined_snapshot_attrs['localModulePreset'],
					$inlined_snapshot_attrs['localGroupPreset']
				);

				$block['attrs']['localAttrs'] = $inlined_snapshot_attrs;
				$local_preset_override_paths  = self::_merge_local_preset_override_paths(
					$module_attrs,
					$inlined_local_preset_attrs
				);
				if ( ! empty( $local_preset_override_paths ) ) {
					$block['attrs']['localAttrOverridePaths'] = $local_preset_override_paths;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::_inline_preset_attrs_into_blocks( $block['innerBlocks'], $presets );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Resolve only explicitly assigned local Component preset segments.
	 *
	 * @since ??
	 *
	 * @param string $module_name  Module name.
	 * @param array  $module_attrs Module or snapshot attributes.
	 * @param array  $presets      Preset data used to resolve assignments.
	 *
	 * @return array Resolved local preset attributes.
	 */
	private static function _get_inlined_local_preset_attrs(
		string $module_name,
		array $module_attrs,
		array $presets
	): array {
		$local_preset_attrs      = [];
		$local_module_preset_ids = GlobalPreset::normalize_preset_stack( $module_attrs['localModulePreset'] ?? [] );
		$has_local_group_preset  = ! empty( $module_attrs['localGroupPreset'] )
			&& is_array( $module_attrs['localGroupPreset'] );

		if ( empty( $local_module_preset_ids ) && ! $has_local_group_preset ) {
			return [];
		}

		if ( ! empty( $local_module_preset_ids ) ) {
			$local_preset_attrs['modulePreset'] = $module_attrs['localModulePreset'];
		}

		if ( $has_local_group_preset ) {
			$local_preset_attrs['groupPreset'] = $module_attrs['localGroupPreset'];
		}

		$section_type = $module_attrs['module']['advanced']['type'] ?? null;
		if ( is_array( $section_type ) ) {
			$local_preset_attrs['module']['advanced']['type'] = $section_type;
		}

		// PHP arrays use copy-on-write, so changing nested values in this copy does not mutate $presets.
		$local_presets = $presets;
		if (
			empty( $local_module_preset_ids )
			&& isset( $local_presets['module'] )
			&& is_array( $local_presets['module'] )
		) {
			foreach ( $local_presets['module'] as &$module_preset_data ) {
				if ( is_array( $module_preset_data ) ) {
					$module_preset_data['default'] = '';
				}
			}
			unset( $module_preset_data );
		}

		if ( isset( $local_presets['group'] ) && is_array( $local_presets['group'] ) ) {
			foreach ( $local_presets['group'] as &$group_preset_data ) {
				if ( is_array( $group_preset_data ) ) {
					$group_preset_data['default'] = '';
				}
			}
			unset( $group_preset_data );
		}

		$inlined_attrs = self::_get_inlined_preset_attrs(
			$module_name,
			$local_preset_attrs,
			$local_presets
		);

		unset( $inlined_attrs['modulePreset'], $inlined_attrs['groupPreset'] );
		if ( is_array( $section_type ) ) {
			unset( $inlined_attrs['module']['advanced']['type'] );

			if ( empty( $inlined_attrs['module']['advanced'] ) ) {
				unset( $inlined_attrs['module']['advanced'] );
			}
			if ( empty( $inlined_attrs['module'] ) ) {
				unset( $inlined_attrs['module'] );
			}
		}

		return $inlined_attrs;
	}

	/**
	 * Resolve preset attributes using the same module-render precedence as runtime.
	 *
	 * @since ??
	 *
	 * @param string $module_name  Module name.
	 * @param array  $module_attrs Module attributes containing preset assignments.
	 * @param array  $presets      Preset data used to resolve assignments.
	 *
	 * @return array Fully resolved attributes.
	 */
	private static function _get_inlined_preset_attrs(
		string $module_name,
		array $module_attrs,
		array $presets
	): array {
		$args                      = [
			'moduleName'  => $module_name,
			'moduleAttrs' => $module_attrs,
			'allData'     => $presets,
		];
		$module_attrs_from_presets = GlobalPreset::get_merged_module_preset_attrs( $args );
		$module_render_attrs       = GlobalPreset::get_merged_preset_render_attrs( $args );
		$module_style_attrs        = GlobalPreset::get_merged_preset_style_attrs( $args );
		$selected_group_presets    = GlobalPreset::get_selected_group_presets( $args );
		$merged_group_presets      = GlobalPreset::merge_selected_group_presets_by_priority(
			$selected_group_presets
		);
		$module_preset_attrs       = array_replace_recursive(
			$module_attrs_from_presets,
			$module_render_attrs,
			$module_style_attrs
		);
		$group_preset_attrs        = array_replace_recursive(
			$merged_group_presets['attrs'],
			$merged_group_presets['renderAttrs'],
			$merged_group_presets['styleAttrs']
		);
		$merged_attrs              = array_replace_recursive(
			$module_preset_attrs,
			$group_preset_attrs,
			$module_attrs
		);

		return ArrayUtility::apply_mergeable_fields_logic(
			$merged_attrs,
			$module_preset_attrs,
			$group_preset_attrs,
			$module_attrs
		);
	}

	/**
	 * Merge existing ownership paths with leaves generated from inlined local presets.
	 *
	 * @since ??
	 *
	 * @param array $attrs                Snapshot attributes containing existing ownership.
	 * @param array $inlined_preset_attrs Resolved local preset attributes.
	 *
	 * @return string[] Merged exact ownership paths.
	 */
	private static function _merge_local_preset_override_paths(
		array $attrs,
		array $inlined_preset_attrs
	): array {
		$existing_paths = isset( $attrs['localAttrOverridePaths'] )
			&& is_array( $attrs['localAttrOverridePaths'] )
			? $attrs['localAttrOverridePaths']
			: [];
		$preset_paths   = self::_collect_leaf_paths( $inlined_preset_attrs );

		// When the source map is serialized with the snapshot, discard synchronized paths now.
		// Otherwise retain candidates so Component expansion can authorize them against its source map.
		if ( array_key_exists( 'localAttrsMap', $attrs ) && is_array( $attrs['localAttrsMap'] ) ) {
			$local_attrs_map = $attrs['localAttrsMap'];
			$preset_paths    = array_values(
				array_filter(
					$preset_paths,
					static function ( string $path ) use ( $local_attrs_map ): bool {
						return ComponentLocalAttrsUtils::is_local_attr_override_path_authorized(
							$path,
							$local_attrs_map
						);
					}
				)
			);
		}

		return array_values(
			array_unique(
				array_merge(
					$existing_paths,
					$preset_paths
				)
			)
		);
	}

	/**
	 * Collect exact leaf paths from an attribute tree.
	 *
	 * @since ??
	 *
	 * @param array $attrs Attribute tree.
	 * @param array $path  Current path.
	 *
	 * @return string[] Dot-delimited leaf paths.
	 */
	private static function _collect_leaf_paths( array $attrs, array $path = [] ): array {
		$paths = [];

		foreach ( $attrs as $key => $value ) {
			$current_path = array_merge( $path, [ (string) $key ] );

			if ( is_array( $value ) && ! empty( $value ) && ! ArrayUtility::is_list( $value ) ) {
				$paths = array_merge( $paths, self::_collect_leaf_paths( $value, $current_path ) );
				continue;
			}

			$paths[] = implode( '.', $current_path );
		}

		return $paths;
	}

	/**
	 * Recursively apply default imported preset IDs to blocks.
	 *
	 * @param array $blocks Blocks array.
	 * @param array $default_imported_presets Default imported module preset IDs.
	 * @return array Updated blocks array.
	 */
	private static function _apply_default_imported_presets_to_blocks( array $blocks, array $default_imported_presets ): array {
		foreach ( $blocks as &$block ) {
			$block_name = $block['blockName'] ?? '';

			if ( ! empty( $block_name ) ) {
				if ( isset( $default_imported_presets[ $block_name ] ) ) {
					$attrs              = $block['attrs'] ?? [];
					$module_preset_attr = $attrs['modulePreset'] ?? '';

					$normalized = GlobalPreset::normalize_preset_stack( $module_preset_attr );

					if ( empty( $normalized ) ) {
						$block['attrs']['modulePreset'] = $default_imported_presets[ $block_name ]['presetId'];
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::_apply_default_imported_presets_to_blocks(
					$block['innerBlocks'],
					$default_imported_presets
				);
			}
		}

		return $blocks;
	}
}
