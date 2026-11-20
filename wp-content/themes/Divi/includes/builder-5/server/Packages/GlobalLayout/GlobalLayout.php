<?php
/**
 * GlobalLayout Class
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\GlobalLayout;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Utility\Shortcode;
use ET\Builder\FrontEnd\BlockParser\BlockParserUtils;

/**
 * Contains Global Layout utilities.
 *
 * @since ??
 */
class GlobalLayout {
	/**
	 * Check if a post is a global layout template.
	 *
	 * A global layout template is a Divi Library item (post type: et_pb_layout)
	 * with the 'scope' taxonomy term set to 'global'. This can be any layout type
	 * including sections, rows, columns, modules, or full layouts.
	 *
	 * @since ??
	 *
	 * @param int|null $post_id Post ID to check. If null, returns false.
	 *
	 * @return bool True if the post is a global layout template, false otherwise.
	 */
	public static function is_global_layout_template( ?int $post_id ): bool {
		// Return false if no post ID provided.
		if ( empty( $post_id ) ) {
			return false;
		}

		// Check if post exists and is a Divi Library item.
		$post = get_post( $post_id );
		if ( ! $post || ET_BUILDER_LAYOUT_POST_TYPE !== $post->post_type ) {
			return false;
		}

		// Check if the post has 'global' scope taxonomy term.
		$scope_terms = get_the_terms( $post_id, 'scope' );
		if ( ! empty( $scope_terms ) && ! is_wp_error( $scope_terms ) ) {
			// Check if 'global' is in the scope terms.
			$scope_slugs = wp_list_pluck( $scope_terms, 'slug' );
			if ( in_array( 'global', $scope_slugs, true ) ) {
				return true;
			}
		}

		// Fallback for imported D4 global module templates where taxonomy may not exist yet.
		$module_type      = get_post_meta( $post_id, '_et_pb_module_type', true );
		$excluded_options = get_post_meta( $post_id, '_et_pb_excluded_global_options', true );

		return ! empty( $module_type ) && ! empty( $excluded_options );
	}

	/**
	 * Check whether Component content creates a direct or indirect self-reference.
	 *
	 * @since ??
	 *
	 * @param int    $layout_id       Component template ID being saved.
	 * @param string $content         Serialized Component template content.
	 * @param array  $pending_content Optional Component content keyed by template ID.
	 *
	 * @return bool Whether the content creates a reference cycle.
	 */
	public static function has_component_reference_cycle( int $layout_id, string $content, array $pending_content = [] ): bool {
		$reference_ids = self::get_component_reference_ids( $content );

		foreach ( $reference_ids as $reference_id ) {
			if ( self::_component_reference_reaches( $reference_id, $layout_id, $pending_content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get Component template IDs referenced by supported block and shortcode formats.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized Component content.
	 *
	 * @return int[] Referenced Component template IDs.
	 */
	public static function get_component_reference_ids( string $content ): array {
		$reference_ids = self::_collect_shortcode_component_reference_ids( $content );
		$reference_ids = array_merge(
			$reference_ids,
			self::_collect_component_reference_ids(
				BlockParserUtils::parse_global_layout_blocks( $content )
			)
		);

		return array_values( array_unique( array_filter( $reference_ids ) ) );
	}

	/**
	 * Collect Component template references from actual legacy shortcode attributes.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized Component content.
	 *
	 * @return int[] Referenced Component template IDs.
	 */
	private static function _collect_shortcode_component_reference_ids( string $content ): array {
		if ( '' === $content || ! str_contains( $content, 'global_module' ) ) {
			return [];
		}

		$reference_ids = [];

		Shortcode::replace_opening_tags(
			$content,
			static function ( string $tag_name, string $attribute_text ) use ( &$reference_ids ): string {
				$attributes   = shortcode_parse_atts( $attribute_text );
				$reference_id = is_array( $attributes ) ? absint( $attributes['global_module'] ?? 0 ) : 0;

				if ( 0 < $reference_id ) {
					$reference_ids[] = $reference_id;
				}

				return $attribute_text;
			}
		);

		return array_values( array_unique( $reference_ids ) );
	}

	/**
	 * Collect Component template references from parsed blocks.
	 *
	 * @since ??
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 *
	 * @return int[] Referenced Component template IDs.
	 */
	private static function _collect_component_reference_ids( array $blocks ): array {
		$reference_ids = [];

		foreach ( $blocks as $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( isset( $attrs['globalModule'] ) ) {
				$reference_id = absint( $attrs['globalModule'] );

				if ( 0 < $reference_id ) {
					$reference_ids[] = $reference_id;
				}
			}

			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( $inner_blocks ) {
				$reference_ids = array_merge(
					$reference_ids,
					self::_collect_component_reference_ids( $inner_blocks )
				);
			}
		}

		return array_values( array_unique( $reference_ids ) );
	}

	/**
	 * Check whether a Component reference has a path back to a target template.
	 *
	 * @since ??
	 *
	 * @param int   $source_id       Component template ID where traversal starts.
	 * @param int   $target_id       Component template ID that must not be reached.
	 * @param array $pending_content Optional Component content keyed by template ID.
	 *
	 * @return bool Whether the reference path reaches the target.
	 */
	private static function _component_reference_reaches( int $source_id, int $target_id, array $pending_content = [] ): bool {
		$pending_ids = [ $source_id ];
		$visited_ids = [];

		while ( $pending_ids ) {
			$current_id = array_pop( $pending_ids );

			if ( $current_id === $target_id ) {
				return true;
			}

			if ( isset( $visited_ids[ $current_id ] ) ) {
				continue;
			}

			$visited_ids[ $current_id ] = $current_id;
			$source_content             = $pending_content[ $current_id ] ?? get_post_field( 'post_content', $current_id );

			if ( ! is_string( $source_content ) || '' === $source_content ) {
				continue;
			}

			foreach ( self::get_component_reference_ids( $source_content ) as $reference_id ) {
				if ( ! isset( $visited_ids[ $reference_id ] ) ) {
					$pending_ids[] = $reference_id;
				}
			}
		}

		return false;
	}
}
