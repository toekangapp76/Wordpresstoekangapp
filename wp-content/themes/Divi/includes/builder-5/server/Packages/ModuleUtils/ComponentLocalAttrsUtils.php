<?php
/**
 * Component Local Attributes Utilities
 *
 * @since ??
 *
 * @package Divi
 */

namespace ET\Builder\Packages\ModuleUtils;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Utility\ArrayUtility;

/**
 * Component Local Attributes Utilities class.
 *
 * @since ??
 */
class ComponentLocalAttrsUtils {

	/**
	 * Infer exact local override paths from a legacy complete snapshot.
	 *
	 * @since ??
	 *
	 * @param array $snapshot_attrs  Complete instance snapshot.
	 * @param mixed $local_attrs_map Local attribute descriptors.
	 *
	 * @return string[] Explicit local attribute override paths.
	 */
	public static function get_local_attr_override_paths( array $snapshot_attrs, $local_attrs_map ): array {
		if ( ! is_array( $local_attrs_map ) ) {
			return [];
		}

		$override_paths = [];

		foreach ( $local_attrs_map as $map_entry ) {
			if ( ! is_array( $map_entry ) ) {
				continue;
			}

			$attr_name = $map_entry['attrName'] ?? '';
			if ( ! is_string( $attr_name ) || '' === $attr_name ) {
				continue;
			}

			$attr_path  = explode( '.', $attr_name );
			$sub_name   = $map_entry['subName'] ?? '';
			$attr_value = $snapshot_attrs;

			foreach ( $attr_path as $part ) {
				if ( ! is_array( $attr_value ) || ! array_key_exists( $part, $attr_value ) ) {
					$attr_value = null;
					break;
				}

				$attr_value = $attr_value[ $part ];
			}

			$sub_path              = is_string( $sub_name ) && '' !== $sub_name ? explode( '.', $sub_name ) : [];
			$direct_path           = array_merge( $attr_path, $sub_path );
			$found_responsive_path = false;

			if ( is_array( $attr_value ) ) {
				foreach ( $attr_value as $breakpoint => $states ) {
					if ( ! is_array( $states ) ) {
						continue;
					}

					foreach ( array_keys( $states ) as $state ) {
						$path = array_merge( $attr_path, [ (string) $breakpoint, (string) $state ], $sub_path );
						if ( ArrayUtility::has( $snapshot_attrs, $path ) ) {
							$override_paths[ implode( '.', $path ) ] = true;
							$found_responsive_path                   = true;
						}
					}
				}
			}

			if ( ! $found_responsive_path && ArrayUtility::has( $snapshot_attrs, $direct_path ) ) {
				$override_paths[ implode( '.', $direct_path ) ] = true;
			}
		}

		$paths = array_keys( $override_paths );
		sort( $paths );

		return $paths;
	}

	/**
	 * Apply exact local override paths authorized by a local attribute map.
	 *
	 * @since ??
	 *
	 * @param array $base_attrs                Base attributes.
	 * @param array $snapshot_attrs            Complete instance snapshot.
	 * @param array $local_attrs_map           Local attribute descriptors.
	 * @param array $local_attr_override_paths Exact instance-owned value paths.
	 *
	 * @return array Attributes with authorized overrides applied.
	 */
	public static function apply_authorized_override_paths(
		array $base_attrs,
		array $snapshot_attrs,
		array $local_attrs_map,
		array $local_attr_override_paths
	): array {
		return self::apply_override_paths(
			$base_attrs,
			$snapshot_attrs,
			self::normalize_local_attr_override_paths( $local_attr_override_paths, $local_attrs_map )
		);
	}

	/**
	 * Normalize exact override paths against the local attribute map.
	 *
	 * @since ??
	 *
	 * @param array $local_attr_override_paths Exact instance-owned value paths.
	 * @param array $local_attrs_map           Local attribute descriptors.
	 *
	 * @return string[] Sorted, unique, authorized override paths.
	 */
	public static function normalize_local_attr_override_paths(
		array $local_attr_override_paths,
		array $local_attrs_map
	): array {
		$authorized_override_paths = [];

		foreach ( $local_attr_override_paths as $override_path ) {
			if (
				is_string( $override_path )
				&& '' !== $override_path
				&& self::is_local_attr_override_path_authorized( $override_path, $local_attrs_map )
			) {
				$authorized_override_paths[ $override_path ] = true;
			}
		}

		$normalized_paths = array_keys( $authorized_override_paths );
		sort( $normalized_paths );

		return $normalized_paths;
	}

	/**
	 * Apply exact local override paths.
	 *
	 * @since ??
	 *
	 * @param array $base_attrs                Base attributes.
	 * @param array $snapshot_attrs            Complete instance snapshot.
	 * @param array $local_attr_override_paths Exact instance-owned value paths.
	 *
	 * @return array Attributes with exact overrides applied.
	 */
	public static function apply_override_paths(
		array $base_attrs,
		array $snapshot_attrs,
		array $local_attr_override_paths
	): array {
		foreach ( $local_attr_override_paths as $override_path ) {
			if (
				! is_string( $override_path )
				|| '' === $override_path
			) {
				continue;
			}

			$path = explode( '.', $override_path );
			if ( in_array( '', $path, true ) ) {
				continue;
			}

			$snapshot_value = self::get_array_path_value_with_presence( $snapshot_attrs, $path );

			if ( $snapshot_value['found'] ) {
				$base_attrs = ArrayUtility::set_value( $base_attrs, $path, $snapshot_value['value'] );
			}
		}

		return $base_attrs;
	}

	/**
	 * Determine whether an exact override path is authorized by a local attribute map.
	 *
	 * @since ??
	 *
	 * @param string $override_path  Exact dot-notation value path.
	 * @param array  $local_attrs_map Local attribute descriptors.
	 *
	 * @return bool Whether the exact path is authorized.
	 */
	public static function is_local_attr_override_path_authorized( string $override_path, array $local_attrs_map ): bool {
		$override_parts = explode( '.', $override_path );

		if ( in_array( '', $override_parts, true ) ) {
			return false;
		}

		foreach ( $local_attrs_map as $map_entry ) {
			if ( ! is_array( $map_entry ) ) {
				continue;
			}

			$attr_name = $map_entry['attrName'] ?? '';
			if ( ! is_string( $attr_name ) || '' === $attr_name ) {
				continue;
			}

			$attr_parts = explode( '.', $attr_name );
			if ( array_slice( $override_parts, 0, count( $attr_parts ) ) !== $attr_parts ) {
				continue;
			}

			$sub_name = $map_entry['subName'] ?? '';
			if ( ! is_string( $sub_name ) || '' === $sub_name ) {
				return count( $override_parts ) >= count( $attr_parts );
			}

			$sub_name_parts = explode( '.', $sub_name );
			if ( in_array( '', $sub_name_parts, true ) ) {
				continue;
			}

			$direct_sub_name_parts = array_merge( $attr_parts, $sub_name_parts );
			if ( array_slice( $override_parts, 0, count( $direct_sub_name_parts ) ) === $direct_sub_name_parts ) {
				return true;
			}

			$sub_name_offset = count( $attr_parts ) + 2;

			if (
				$sub_name_offset + count( $sub_name_parts ) <= count( $override_parts )
				&& array_slice( $override_parts, $sub_name_offset, count( $sub_name_parts ) ) === $sub_name_parts
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get an array path value while preserving explicit null values.
	 *
	 * @since ??
	 *
	 * @param array $source Array to inspect.
	 * @param array $path   Path to retrieve.
	 *
	 * @return array{found: bool, value: mixed} Path presence and value.
	 */
	public static function get_array_path_value_with_presence( array $source, array $path ): array {
		$value = $source;

		foreach ( $path as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return [
					'found' => false,
					'value' => null,
				];
			}

			$value = $value[ $part ];
		}

		return [
			'found' => true,
			'value' => $value,
		];
	}
}
