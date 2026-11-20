<?php
/**
 * Class BlockParserStore
 *
 * @since ??
 *
 * @package Divi
 */

namespace ET\Builder\FrontEnd\BlockParser;

// phpcs:disable ET.ValidVariableName.PropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\FrontEnd\BlockParser\BlockParserBlock;
use ET\Builder\FrontEnd\BlockParser\OrderIndexResetManager;
use ET\Builder\Framework\Utility\ArrayUtility;
use ET\Builder\Framework\Utility\HTMLUtility;
use ET\Builder\Migration\GlobalModuleSyncNodeMigration;
use ET\Builder\Migration\Utils\MigrationUtils;
use ET\Builder\Packages\GlobalData\Utils\ComponentPresetUtils;
use ET\Builder\Packages\Module\Options\Conditions\ConditionsRenderer;
use ET\Builder\Packages\ModuleLibrary\GlobalSlot\GlobalSlotUtils;
use ET\Builder\Packages\ModuleUtils\ComponentLocalAttrsUtils;
use WP_Block;

/**
 * Class BlockParserStore
 *
 * Holds the block structure in memory as flatten associative array. This class is counterparts of EditPostStore in VB, with a slight
 * difference that this class can have multiple instances. A new store instance will be created when `do_blocks` function is invoked. This is intended to prevent
 * the data for previous call of `do_blocks` get overridden by a later call of `do_blocks`.
 * Each item stored in the store will have a `storeInstance` property that hold the data to which store instance is the item belongs to.
 *
 * @since ??
 */
class BlockParserStore {

	/**
	 * Flag indicating whether the block parser is currently rendering inner content.
	 *
	 * This static property tracks the parsing state to help prevent infinite loops
	 * and ensure proper behavior when processing nested blocks. It's used by
	 * the block parser to know when it's in the middle of processing inner content
	 * so it can avoid reprocessing blocks that are already being handled.
	 *
	 * @since ??
	 * @var bool
	 */
	private static $_rendering_inner_content = false;

	/**
	 * Flag indicating whether the order index has been reset during this page render.
	 *
	 * This static property ensures that order indexes are only reset once per page render,
	 * preventing multiple resets that could cause incorrect order index numbering.
	 *
	 * @since ??
	 * @var bool
	 */
	private static $_has_reset_order_index = false;

	/**
	 * Request-scoped cache for global layout template post_content read from the database.
	 *
	 * Each entry stores the loaded content and metadata from the initial DB read. Repeated
	 * expansions of the same template reuse the cached entry without additional queries.
	 * Invalidation is handled by clean_post_cache and post_updated hooks.
	 *
	 * @since ??
	 *
	 * @var array<int, array{content: string|null, modified_gmt: string, content_hash: string}>
	 */
	private static $_fresh_global_layout_post_content_cache = [];

	/**
	 * Component template IDs currently being expanded.
	 *
	 * The stack permits nested Components while preventing direct and indirect
	 * self-containment from recursing during front-end rendering.
	 *
	 * @since ??
	 *
	 * @var string[]
	 */
	private static $_global_layout_expansion_stack = [];

	/**
	 * Check if currently rendering inner content.
	 *
	 * Returns the current state of the inner content rendering flag. This is used
	 * to determine whether the block parser is in the middle of processing inner
	 * content, which helps prevent infinite loops and ensures proper parsing behavior.
	 *
	 * @since ??
	 * @return bool True if currently rendering inner content, false otherwise.
	 */
	public static function is_rendering_inner_content(): bool {
		return self::$_rendering_inner_content;
	}

	/**
	 * Set the inner content rendering state.
	 *
	 * Controls whether the block parser is currently rendering inner content. This flag
	 * is used to track parsing state and help prevent infinite loops or incorrect parsing
	 * behavior when processing nested blocks.
	 *
	 * @since ??
	 * @param bool $state True if currently rendering inner content, false otherwise.
	 * @return void
	 */
	public static function set_rendering_inner_content( bool $state ): void {
		self::$_rendering_inner_content = $state;
	}

	/**
	 * Renders inner content by applying WordPress the_content filter while setting appropriate internal state.
	 *
	 * This method temporarily enables inner content rendering state, applies the 'the_content' filter
	 * to the provided content, then disables the inner content rendering state. This is used for
	 * rendering content within blocks while ensuring that the rendering state is properly tracked
	 * by the BlockParserStore instance.
	 *
	 * The inner content rendering state is used by other methods in this class to determine
	 * whether they are currently processing content that's inside a block, which may affect
	 * how content is parsed or rendered.
	 *
	 * @since ??
	 *
	 * @param string $content The content to render. This content will be processed through
	 *                       WordPress's 'the_content' filter chain.
	 *
	 * @return string The rendered content after applying WordPress content filters.
	 *
	 * @example
	 * ```php
	 * $raw_content   = 'Some content with shortcodes and HTML';
	 * $rendered_html = BlockParserStore::render_inner_content( $raw_content );
	 * // $rendered_html now contains processed HTML with shortcodes expanded
	 * ```
	 */
	public static function render_inner_content( string $content ): string {
		self::set_rendering_inner_content( true );

		try {
			$content = apply_filters( 'the_content', $content );
		} finally {
			self::set_rendering_inner_content( false );
		}

		return $content;
	}

	/**
	 * Add root block.
	 *
	 * Root is a read-only and unique block. It can only to be added using this method.
	 * The `innerBlocks` data will be populated when calling `BlockParserStore::get('divi/root')`.
	 *
	 * @since ??
	 *
	 * @param int $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return void
	 */
	protected static function _add_root( $instance = null ) {
		$use_instance = self::_use_instance( $instance );

		self::$_data[ $use_instance ]['divi/root'] = new BlockParserBlockRoot( $use_instance );
	}


	/**
	 * Add item to store.
	 *
	 * @since ??
	 *
	 * @param BlockParserBlock $block The block object.
	 *
	 * @return BlockParserBlock
	 */
	public static function add( BlockParserBlock $block ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
		if ( ! $block->blockName ) {
			return $block;
		}

		if ( self::_is_root( $block->id ) || self::has( $block->id, $block->storeInstance ) ) {
			return $block;
		}

		self::$_data[ self::_use_instance( $block->storeInstance ) ][ $block->id ] = $block;

		return $block;
	}


	/**
	 * Find the ancestor of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string   $child_id The unique ID of the child block.
	 * @param callable $matcher  Callable function that will be invoked to determine if the ancestor is match.
	 * @param int      $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock|null
	 */
	public static function find_ancestor( $child_id, $matcher, $instance = null ) {
		return ArrayUtility::find( self::get_ancestors( $child_id, $instance ), $matcher );
	}


	/**
	 * Get all of existing items in the store.
	 *
	 * @since ??
	 *
	 * @param int $instance The instance of the store you want to use.
	 *
	 * @return BlockParserBlock[]
	 */
	public static function get_all( $instance = null ) {
		$all_blocks = self::$_data[ self::_use_instance( $instance ) ] ?? [];

		if ( isset( $all_blocks['divi/root'] ) ) {
			$all_blocks['divi/root'] = self::get( 'divi/root', $instance );
		}

		return $all_blocks;
	}

	/**
	 * Get the ancestors of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string $child_id The unique ID of the child block.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock[] An array of ancestors sorted from bottom to the very top level of the structure tree.
	 */
	public static function get_ancestors( $child_id, $instance = null ) {
		$ancestors = [];
		$parent    = self::get_parent( $child_id, $instance );

		while ( $parent ) {
			$ancestors[] = $parent;

			$parent = self::get_parent( $parent->id, $instance );
		}

		return $ancestors;
	}

	/**
	 * Get the ancestor ids of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string   $child_id The unique ID of the child block.
	 * @param int|null $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock[] An array of ancestors sorted from bottom to the very top level of the structure tree.
	 */
	public static function get_ancestor_ids( string $child_id, $instance = null ): array {
		$ancestors = [];
		$parent    = self::get_parent( $child_id, $instance );

		while ( $parent ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
			if ( isset( $parent->blockName ) && 'divi/placeholder' !== $parent->blockName ) {
				$ancestors[] = $parent->id;
			}

			$parent = self::get_parent( $parent->id, $instance );
		}

		return $ancestors;
	}

	/**
	 * Get the ancestor of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string   $child_id The unique ID of the child block.
	 * @param callable $matcher  Optional.
	 *                           Callable function that will be invoked to determine to return early if it returns a `true`.
	 *                           If not provided, it will match up to the very top level of the structure tree.
	 *                           Default `null`.
	 * @param int      $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock|null
	 */
	public static function get_ancestor( $child_id, $matcher = null, $instance = null ) {
		$ancestor = null;
		$parent   = self::get_parent( $child_id, $instance );

		while ( $parent ) {
			$ancestor = $parent;

			if ( is_callable( $matcher ) && true === call_user_func( $matcher, $ancestor ) ) {
				return $ancestor;
			}

			$parent = self::get_parent( $ancestor->id, $instance );
		}

		return $ancestor;
	}


	/**
	 * Get an array of all the children of a given block.
	 *
	 * @since ??
	 *
	 * @param string $id       The id of the block you want to get.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock[] An array of the children of the block.
	 */
	public static function get_children( $id, $instance = null ) {
		$current = self::get( $id, $instance );

		if ( ! $current ) {
			return [];
		}

		$inner_blocks = [];
		$all_blocks   = self::$_data[ self::_use_instance( $instance ) ];

		foreach ( $all_blocks as $block ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
			if ( $block->parentId === $current->id ) {
				$inner_blocks[] = self::get( $block->id, $instance );
			}
		}

		if ( 1 < count( $inner_blocks ) ) {
			usort(
				$inner_blocks,
				function ( BlockParserBlock $a, BlockParserBlock $b ) {
					if ( $a->index === $b->index ) {
						return 0;
					}

					return ( $a->index < $b->index ) ? -1 : 1;
				}
			);
		}

		return $inner_blocks;
	}

	/**
	 * Apply explicit local attribute overrides authorized by localAttrsMap.
	 *
	 * The template is the base. The instance retains a complete attribute snapshot, while
	 * localAttrOverridePaths identifies the exact snapshot paths that may override it.
	 *
	 * @since ??
	 *
	 * @param array $template_attrs            Template attributes (base), including localAttrsMap.
	 * @param array $local_attrs               Complete instance attribute snapshot.
	 * @param array $local_attr_override_paths Exact instance-owned value paths.
	 * @param bool  $initialize_override_paths Whether paths should be inferred for a legacy snapshot.
	 *
	 * @return array Merged attributes with selective filtering applied.
	 */
	public static function apply_local_attrs_filtering(
		array $template_attrs,
		array $local_attrs,
		array $local_attr_override_paths = [],
		bool $initialize_override_paths = false
	): array {
		// Start with template attributes as base.
		$merged_attrs    = $template_attrs;
		$is_local_module = true === ( $local_attrs['localModule'] ?? false );

		if ( $is_local_module ) {
			$local_module_attrs = $local_attrs;

			foreach (
				[
					'globalModule',
					'globalParent',
					'globalModuleTemplate',
					'syncNodeId',
					'localAttrsMap',
					'localAttrOverridePaths',
					'localAttrs',
					'localModule',
					'localChildren',
					'blockName',
				] as $metadata_attr_name
			) {
				unset( $local_module_attrs[ $metadata_attr_name ] );
			}

			$merged_attrs = $local_module_attrs;

			foreach (
				[
					'blockName',
					'builderVersion',
					'globalModule',
					'globalParent',
					'globalModuleTemplate',
					'localAttrsMap',
					'localChildren',
					'syncNodeId',
				] as $metadata_attr_name
			) {
				if (
					! array_key_exists( $metadata_attr_name, $merged_attrs )
					&& array_key_exists( $metadata_attr_name, $template_attrs )
				) {
					$merged_attrs[ $metadata_attr_name ] = $template_attrs[ $metadata_attr_name ];
				}
			}

			$merged_attrs['localModule'] = true;

			return $merged_attrs;
		}

		// Get localAttrsMap from template (source of truth).
		$local_attrs_map = $template_attrs['localAttrsMap'] ?? [];
		$local_attrs_map = is_array( $local_attrs_map ) ? $local_attrs_map : [];

		if ( $initialize_override_paths && ! empty( $local_attrs ) ) {
			$local_attr_override_paths = ComponentLocalAttrsUtils::get_local_attr_override_paths(
				$local_attrs,
				$local_attrs_map
			);
		}

		$local_attr_override_paths = ComponentLocalAttrsUtils::normalize_local_attr_override_paths(
			$local_attr_override_paths,
			$local_attrs_map
		);
		$merged_attrs              = ComponentLocalAttrsUtils::apply_authorized_override_paths(
			$merged_attrs,
			$local_attrs,
			$local_attrs_map,
			$local_attr_override_paths
		);
		$merged_attrs              = ComponentPresetUtils::compose_component_preset_attrs( $merged_attrs, $template_attrs, $local_attrs );

		// Keep value ownership metadata on the effective block so preset cleanup can
		// distinguish instance CSS from values already emitted by preset classes.
		if ( ! empty( $local_attrs_map ) ) {
			$merged_attrs['localAttrsMap']          = $local_attrs_map;
			$merged_attrs['localAttrOverridePaths'] = $local_attr_override_paths;
		}

		return $merged_attrs;
	}

	/**
	 * Recursively apply local attribute filtering to nested child blocks by synchronized identity.
	 *
	 * Template structure remains the source of truth for synced children. Snapshot children are
	 * matched by `syncNodeId`. Positional fallback is used only for identity-less template nodes.
	 *
	 * @since ??
	 *
	 * @param array $template_blocks Template child blocks.
	 * @param array $snapshot_blocks Snapshot child blocks from instance content.
	 * @param array $snapshot_blocks_by_sync_node_id Snapshot blocks indexed by persistent identity.
	 *
	 * @return array Template blocks with selective local attribute overrides applied recursively.
	 */
	private static function _apply_nested_local_attrs_filtering(
		array $template_blocks,
		array $snapshot_blocks,
		?array $snapshot_blocks_by_sync_node_id = null
	): array {
		$filter_blocks = static function ( array $blocks ): array {
			return array_values(
				array_filter(
					self::_filter_whitespace_blocks( $blocks ),
					static function ( $block ): bool {
						return ! empty( $block['blockName'] ) && 'divi/placeholder' !== $block['blockName'];
					}
				)
			);
		};

		if ( null === $snapshot_blocks_by_sync_node_id ) {
			$snapshot_blocks_by_sync_node_id = [];
			$duplicate_sync_node_ids         = [];

			$index_snapshot_blocks = static function ( array $blocks ) use (
				&$index_snapshot_blocks,
				&$snapshot_blocks_by_sync_node_id,
				&$duplicate_sync_node_ids,
				$filter_blocks
			): void {
				foreach ( $filter_blocks( $blocks ) as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}

					$sync_node_id = $block['attrs']['syncNodeId'] ?? '';

					if ( is_string( $sync_node_id ) && '' !== $sync_node_id ) {
						if ( isset( $snapshot_blocks_by_sync_node_id[ $sync_node_id ] ) ) {
							unset( $snapshot_blocks_by_sync_node_id[ $sync_node_id ] );
							$duplicate_sync_node_ids[ $sync_node_id ] = true;
						} elseif ( ! isset( $duplicate_sync_node_ids[ $sync_node_id ] ) ) {
							$snapshot_blocks_by_sync_node_id[ $sync_node_id ] = $block;
						}
					}

					if (
						'divi/global-slot' === ( $block['blockName'] ?? '' )
						|| true === ( $block['attrs']['localChildren'] ?? false )
					) {
						continue;
					}

					$inner_blocks = $block['innerBlocks'] ?? [];
					if ( is_array( $inner_blocks ) ) {
						$index_snapshot_blocks( $inner_blocks );
					}
				}
			};

			$index_snapshot_blocks( $snapshot_blocks );
		}

		// Positional matching must use the same normalized child lists on both sides.
		$template_blocks = $filter_blocks( $template_blocks );
		$snapshot_blocks = $filter_blocks( $snapshot_blocks );
		$merged_blocks   = $template_blocks;

		foreach ( $merged_blocks as $index => $template_block ) {
			if ( ! is_array( $template_block ) ) {
				continue;
			}

			$positional_snapshot_block = $snapshot_blocks[ $index ] ?? null;
			$template_sync_node_id     = $template_block['attrs']['syncNodeId'] ?? '';

			if ( is_string( $template_sync_node_id ) && '' !== $template_sync_node_id ) {
				$snapshot_block = $snapshot_blocks_by_sync_node_id[ $template_sync_node_id ] ?? null;

				if ( null === $snapshot_block && is_array( $positional_snapshot_block ) ) {
					$positional_sync_node_id = $positional_snapshot_block['attrs']['syncNodeId'] ?? '';

					// Legacy snapshots without identity remain positionally compatible.
					if ( ! is_string( $positional_sync_node_id ) || '' === $positional_sync_node_id ) {
						$snapshot_block = $positional_snapshot_block;
					}
				}
			} else {
				$snapshot_block = $positional_snapshot_block;
			}

			if ( ! is_array( $snapshot_block ) ) {
				continue;
			}

			$template_block_name = $template_block['blockName'] ?? '';
			$snapshot_block_name = $snapshot_block['blockName'] ?? '';

			if ( ! $template_block_name || $template_block_name !== $snapshot_block_name ) {
				continue;
			}

			$template_attrs = $template_block['attrs'] ?? null;
			$snapshot_attrs = $snapshot_block['attrs'] ?? null;

			if ( is_array( $template_attrs ) && is_array( $snapshot_attrs ) ) {
				if ( 'divi/global-layout' === $template_block_name ) {
					$template_global_module = $template_attrs['globalModule'] ?? null;
					$snapshot_global_module = $snapshot_attrs['globalModule'] ?? null;
					$template_module_name   = $template_attrs['blockName'] ?? null;
					$snapshot_module_name   = $snapshot_attrs['blockName'] ?? null;

					if (
						! ( is_string( $template_global_module ) || is_int( $template_global_module ) )
						|| ! ( is_string( $snapshot_global_module ) || is_int( $snapshot_global_module ) )
						|| (string) $template_global_module !== (string) $snapshot_global_module
						|| ! is_string( $template_module_name )
						|| ! is_string( $snapshot_module_name )
						|| $template_module_name !== $snapshot_module_name
					) {
						continue;
					}

					$merged_reference_attrs = $template_attrs;

					foreach ( [ 'localAttrs', 'localAttrOverridePaths' ] as $snapshot_attr_name ) {
						unset( $merged_reference_attrs[ $snapshot_attr_name ] );

						if ( array_key_exists( $snapshot_attr_name, $snapshot_attrs ) ) {
							$merged_reference_attrs[ $snapshot_attr_name ] = $snapshot_attrs[ $snapshot_attr_name ];
						}
					}

					$merged_blocks[ $index ]['attrs']        = $merged_reference_attrs;
					$snapshot_inner_blocks                   = $snapshot_block['innerBlocks'] ?? [];
					$merged_blocks[ $index ]['innerBlocks']  = is_array( $snapshot_inner_blocks )
						? $filter_blocks( $snapshot_inner_blocks )
						: [];
					$snapshot_inner_content                  = $snapshot_block['innerContent'] ?? [];
					$merged_blocks[ $index ]['innerContent'] = is_array( $snapshot_inner_content )
						? $snapshot_inner_content
						: [];
					$snapshot_inner_html                     = $snapshot_block['innerHTML'] ?? '';
					$merged_blocks[ $index ]['innerHTML']    = is_string( $snapshot_inner_html )
						? $snapshot_inner_html
						: '';
					continue;
				} else {
					$initialize_override_paths = ! array_key_exists( 'localAttrOverridePaths', $snapshot_attrs );
					$local_attr_override_paths = $snapshot_attrs['localAttrOverridePaths'] ?? [];
					$local_attr_override_paths = is_array( $local_attr_override_paths ) ? $local_attr_override_paths : [];

					$merged_blocks[ $index ]['attrs'] = self::apply_local_attrs_filtering(
						$template_attrs,
						$snapshot_attrs,
						$local_attr_override_paths,
						$initialize_override_paths
					);
				}
			}

			$template_inner_blocks = $template_block['innerBlocks'] ?? [];
			$snapshot_inner_blocks = $snapshot_block['innerBlocks'] ?? [];

			if ( true === ( $template_attrs['localChildren'] ?? false ) ) {
				$merged_blocks[ $index ]['innerBlocks'] = is_array( $snapshot_inner_blocks )
					? $filter_blocks( $snapshot_inner_blocks )
					: [];
				continue;
			}

			if ( is_array( $template_inner_blocks ) && is_array( $snapshot_inner_blocks ) ) {
				$merged_blocks[ $index ]['innerBlocks'] = self::_apply_nested_local_attrs_filtering(
					$template_inner_blocks,
					$snapshot_inner_blocks,
					$snapshot_blocks_by_sync_node_id
				);
			}
		}

		return $merged_blocks;
	}

	/**
	 * Merge nested synchronized snapshot overrides before loop parsing.
	 *
	 * @since ??
	 *
	 * @param string $template_content Template block content.
	 * @param string $snapshot_content Serialized instance child snapshot.
	 *
	 * @return string Template content with nested overrides applied.
	 */
	private static function _inject_nested_local_attrs_into_content(
		string $template_content,
		string $snapshot_content
	): string {
		$parsed_blocks = MigrationUtils::with_core_block_parser(
			static function () use ( $template_content, $snapshot_content ): array {
				return [
					'template' => self::_filter_whitespace_blocks( parse_blocks( $template_content ) ),
					'snapshot' => self::_filter_whitespace_blocks( parse_blocks( $snapshot_content ) ),
				];
			}
		);

		$template_blocks = $parsed_blocks['template'];
		$snapshot_blocks = $parsed_blocks['snapshot'];

		foreach ( $template_blocks as $index => $template_block ) {
			if (
				! is_array( $template_block )
				|| empty( $template_block['blockName'] )
				|| 'divi/placeholder' === $template_block['blockName']
			) {
				continue;
			}

			$template_attrs = $template_block['attrs'] ?? [];
			if (
				is_array( $template_attrs )
				&& true !== ( $template_attrs['localChildren'] ?? false )
			) {
				$template_inner_blocks = $template_block['innerBlocks'] ?? [];

				if ( is_array( $template_inner_blocks ) ) {
					$merged_inner_blocks = self::_apply_nested_local_attrs_filtering(
						$template_inner_blocks,
						$snapshot_blocks
					);
					$replacements        = [];

					self::_collect_changed_nested_attrs(
						$template_inner_blocks,
						$merged_inner_blocks,
						$replacements
					);

					return self::_replace_block_attrs_by_sync_node_id(
						$template_content,
						$replacements
					);
				}
			}

			break;
		}

		return $template_content;
	}

	/**
	 * Collect changed nested attributes keyed by synchronized identity.
	 *
	 * @since ??
	 *
	 * @param array $template_blocks Original template blocks.
	 * @param array $merged_blocks   Blocks after applying instance ownership.
	 * @param array $replacements    Collected replacement attributes.
	 *
	 * @return void
	 */
	private static function _collect_changed_nested_attrs(
		array $template_blocks,
		array $merged_blocks,
		array &$replacements
	): void {
		$filter_blocks = static function ( array $blocks ): array {
			return array_values(
				array_filter(
					self::_filter_whitespace_blocks( $blocks ),
					static function ( $block ): bool {
						return is_array( $block )
							&& ! empty( $block['blockName'] )
							&& 'divi/placeholder' !== $block['blockName'];
					}
				)
			);
		};

		$template_blocks = $filter_blocks( $template_blocks );
		$merged_blocks   = $filter_blocks( $merged_blocks );

		foreach ( $template_blocks as $index => $template_block ) {
			$merged_block = $merged_blocks[ $index ] ?? null;

			if (
				! is_array( $template_block )
				|| ! is_array( $merged_block )
				|| ( $template_block['blockName'] ?? '' ) !== ( $merged_block['blockName'] ?? '' )
			) {
				continue;
			}

			$template_attrs = is_array( $template_block['attrs'] ?? null ) ? $template_block['attrs'] : [];
			$merged_attrs   = is_array( $merged_block['attrs'] ?? null ) ? $merged_block['attrs'] : [];
			$sync_node_id   = $template_attrs['syncNodeId'] ?? '';

			if (
				$template_attrs !== $merged_attrs
				&& is_string( $sync_node_id )
				&& '' !== $sync_node_id
			) {
				$replacements[ $sync_node_id ] = [
					'blockName' => $template_block['blockName'],
					'attrs'     => $merged_attrs,
				];
			}

			if (
				'divi/global-slot' === ( $template_block['blockName'] ?? '' )
				|| true === ( $template_attrs['localChildren'] ?? false )
			) {
				continue;
			}

			$template_inner_blocks = $template_block['innerBlocks'] ?? [];
			$merged_inner_blocks   = $merged_block['innerBlocks'] ?? [];

			if ( is_array( $template_inner_blocks ) && is_array( $merged_inner_blocks ) ) {
				self::_collect_changed_nested_attrs(
					$template_inner_blocks,
					$merged_inner_blocks,
					$replacements
				);
			}
		}
	}

	/**
	 * Replace only opening-block attribute JSON for changed synchronized nodes.
	 *
	 * @since ??
	 *
	 * @param string $content      Original serialized block content.
	 * @param array  $replacements Replacement attributes keyed by syncNodeId.
	 *
	 * @return string Content with targeted attribute replacements.
	 */
	private static function _replace_block_attrs_by_sync_node_id(
		string $content,
		array $replacements
	): string {
		if (
			empty( $replacements )
			|| ! preg_match_all(
				'/<!--\s+wp:divi\/([a-z][a-z0-9_-]*)\s+/',
				$content,
				$matches,
				PREG_OFFSET_CAPTURE | PREG_SET_ORDER
			)
		) {
			return $content;
		}

		$edits = [];

		foreach ( $matches as $match ) {
			$block_name  = 'divi/' . $match[1][0];
			$json_start  = $match[0][1] + strlen( $match[0][0] );
			$json_bounds = BlockParserUtils::extract_json_object_bounds( $content, $json_start );

			if ( null === $json_bounds ) {
				continue;
			}

			$attrs = json_decode( $json_bounds['json'], true );

			if ( ! is_array( $attrs ) ) {
				continue;
			}

			$sync_node_id = $attrs['syncNodeId'] ?? '';
			$replacement  = is_string( $sync_node_id )
				? ( $replacements[ $sync_node_id ] ?? null )
				: null;

			if (
				is_array( $replacement )
				&& ( $replacement['blockName'] ?? '' ) === $block_name
				&& is_array( $replacement['attrs'] ?? null )
			) {
				$edits[] = [
					'start'       => $json_bounds['start'],
					'length'      => $json_bounds['end'] - $json_bounds['start'] + 1,
					'replacement' => serialize_block_attributes( $replacement['attrs'] ),
				];
			}
		}

		foreach ( array_reverse( $edits ) as $edit ) {
			$content = substr_replace(
				$content,
				$edit['replacement'],
				$edit['start'],
				$edit['length']
			);
		}

		return $content;
	}

	/**
	 * Filter out whitespace-only blocks that WordPress parse_blocks() creates.
	 *
	 * WordPress parse_blocks() creates blocks with null blockName for whitespace/newlines between blocks.
	 * When these get normalized and serialized, they become empty <!-- wp: --> blocks in rendered output.
	 * This method filters them out to prevent rendering artifacts.
	 *
	 * @since ??
	 *
	 * @param array $blocks Array of parsed blocks from parse_blocks().
	 * @return array Filtered blocks with whitespace-only blocks removed.
	 */
	public static function _filter_whitespace_blocks( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				function ( $block ) {
					// Keep blocks that have a blockName.
					if ( ! empty( $block['blockName'] ) ) {
						return true;
					}

					// Remove blocks with no blockName that only contain whitespace.
					if ( empty( $block['blockName'] ) && isset( $block['innerHTML'] ) ) {
						return ! empty( trim( $block['innerHTML'] ) );
					}

					return false;
				}
			)
		);
	}

	/**
	 * Parse blocks with error recovery to handle malformed block structures.
	 *
	 * WordPress parse_blocks() can fail when encountering malformed blocks,
	 * causing all subsequent blocks to be lost. This method attempts to recover
	 * by extracting valid block comments individually and parsing them separately.
	 *
	 * @since ??
	 *
	 * @param string $content The content to parse.
	 * @return array Parsed blocks array, with recovery for malformed blocks.
	 */
	private static function _parse_blocks_with_error_recovery( string $content ): array {
		// First try normal parsing.
		$blocks = self::_parse_blocks_with_core_parser( $content );

		// If we got multiple valid blocks, return them.
		if ( count( $blocks ) > 1 ) {
			// Filter out any empty blocks.
			return array_filter(
				$blocks,
				function ( $block ) {
					return ! empty( $block['blockName'] ) || ! empty( trim( $block['innerHTML'] ?? '' ) );
				}
			);
		}

		// If we have one valid named block, return it.
		if ( count( $blocks ) === 1 && ! empty( $blocks[0]['blockName'] ) ) {
			return $blocks;
		}

		// If parsing failed, use original blocks as-is.
		// Malformed blocks naturally break parsing, which is expected behavior.
		return $blocks;
	}

	/**
	 * Parse serialized Component content without invoking the front-end parser pipeline.
	 *
	 * Component materialization runs during the front-end parser's parse method. Calling
	 * parse_blocks() with the registered Divi parser here can execute loop duplication
	 * before nested instance overrides have been merged into the template.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 *
	 * @return array Parsed blocks with whitespace-only blocks removed.
	 */
	private static function _parse_blocks_with_core_parser( string $content ): array {
		return MigrationUtils::with_core_block_parser(
			static function () use ( $content ): array {
				return self::_filter_whitespace_blocks( parse_blocks( $content ) );
			}
		);
	}

	/**
	 * Collapse consecutive duplicate placeholder wrappers into a single wrapper.
	 *
	 * Some imported content can contain:
	 * - `<!-- wp:divi/placeholder --><!-- wp:divi/placeholder -->`
	 * - `<!-- /wp:divi/placeholder --><!-- /wp:divi/placeholder -->`
	 *
	 * This method reduces these duplicated open/close markers while preserving the
	 * wrapped module content.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 * @return string Content with duplicate placeholder wrappers collapsed.
	 */
	private static function _collapse_duplicate_placeholder_wrappers( string $content ): string {
		$normalized = $content;

		do {
			$previous            = $normalized;
			$normalized_openings = preg_replace(
				'/(<!--\s*wp:divi\/placeholder\b[^>]*-->)(?:\s*<!--\s*wp:divi\/placeholder\b[^>]*-->)+/s',
				'$1',
				$normalized
			);
			$normalized_closings = null;

			// Keep original content if preg_replace fails to avoid dropping global module content.
			if ( null === $normalized_openings ) {
				return $content;
			}

			$normalized_closings = preg_replace(
				'/(<!--\s*\/wp:divi\/placeholder\s*-->)(?:\s*<!--\s*\/wp:divi\/placeholder\s*-->)+/s',
				'$1',
				$normalized_openings
			);

			// Keep original content if preg_replace fails to avoid dropping global module content.
			if ( null === $normalized_closings ) {
				return $content;
			}

			$normalized = $normalized_closings;
		} while ( $previous !== $normalized );

		return $normalized;
	}

	/**
	 * Expand placeholder-wrapped global module content for WordPress block parsing compatibility.
	 *
	 * Global modules are intentionally stored with placeholder wrappers around content blocks.
	 * However, WordPress parse_blocks() treats this as a single block with innerHTML instead
	 * of separate blocks, preventing proper selective sync attribute extraction.
	 *
	 * Transforms: <!-- wp:divi/placeholder --><!-- wp:divi/text {...} /--><!-- /wp:divi/placeholder -->
	 * Into:       <!-- wp:divi/placeholder /-->
	 *
	 *             <!-- wp:divi/text {...} -->
	 *             <p>Content here</p>
	 *             <!-- /wp:divi/text -->
	 *
	 * @since ??
	 *
	 * @param string $content The placeholder-wrapped block content.
	 * @return string The expanded block content with separate blocks.
	 */
	public static function _expand_placeholder_wrapped_blocks( string $content ): string {
		$content = self::_collapse_duplicate_placeholder_wrappers( $content );

		// Pattern to match placeholder wrapper with any inner content.
		$pattern = '/<!--\s*wp:divi\/placeholder\b[^>]*-->(.+?)<!--\s*\/wp:divi\/placeholder\s*-->/s';

		if ( preg_match( $pattern, $content, $matches ) ) {
			$inner_content = trim( $matches[1] );

			// FIX: Handle malformed blocks by trying to recover and continue parsing.
			$parsed_blocks = self::_parse_blocks_with_error_recovery( $inner_content );

			// Start with placeholder block.
			$fixed_content = "<!-- wp:divi/placeholder /-->\n\n";

			// Process each block found inside the placeholder.
			foreach ( $parsed_blocks as $block ) {
				if ( empty( $block['blockName'] ) ) {
					// Handle free-form content (HTML between blocks).
					if ( ! empty( trim( $block['innerHTML'] ) ) ) {
						$fixed_content .= trim( $block['innerHTML'] ) . "\n";
					}
					continue;
				}

				// Reconstruct block with proper WordPress block format.
				$block_name  = $block['blockName'];
				$block_attrs = ! empty( $block['attrs'] ) ? serialize_block_attributes( $block['attrs'] ) : '{}';

				// Extract innerHTML content for blocks that have it.
				$inner_html = '';
				if ( isset( $block['attrs']['content']['innerContent']['desktop']['value'] ) ) {
					$inner_content_value = $block['attrs']['content']['innerContent']['desktop']['value'];
					if ( is_array( $inner_content_value ) ) {
						$inner_content_value = $inner_content_value['text'] ?? '';
					}
					if ( is_string( $inner_content_value ) ) {
						// Use safe entity decoding to prevent XSS security vulnerability.
						// This decodes WordPress block entities (&quot;, &amp;, etc.) but NOT dangerous ones (&lt;, &gt;).
						// See security review: https://github.com/elegantthemes/submodule-builder-5/pull/6776#discussion_r2434267097.
						// See: https://github.com/elegantthemes/Divi/issues/9664.
						$inner_html = HTMLUtility::decode_wordpress_block_entities( $inner_content_value );
					}
				}
				// If the attribute value was present but could not be normalized to a usable string
				// (e.g. an object with no 'text' key), fall back to the block's parsed innerHTML so
				// that blocks already stored in non-self-closing format are not silently dropped.
				if ( '' === $inner_html && ! empty( $block['innerHTML'] ) ) {
					$inner_html = $block['innerHTML'];
				}

				// Trim and check if innerHTML is actually meaningful content.
				$has_content = ! empty( trim( $inner_html ) );

				// Check if this block has child modules (inner blocks).
				if ( ! empty( $block['innerBlocks'] ) ) {
					// Parent block with child modules - use full block format.
					$fixed_content .= "<!-- wp:{$block_name} {$block_attrs} -->\n";
					// Serialize child blocks with proper formatting.
					$serialized_children = serialize_blocks( $block['innerBlocks'] );
					// Ensure each child block is on its own line.
					$serialized_children = str_replace( '/--><!-- wp:', "/-->\n<!-- wp:", $serialized_children );
					// Add children without trailing newline to avoid innerHTML pollution.
					$fixed_content .= rtrim( $serialized_children );
					$fixed_content .= "\n<!-- /wp:{$block_name} -->";
				} elseif ( $has_content ) {
					// Block with meaningful innerHTML content - use full block format.
					$fixed_content .= "<!-- wp:{$block_name} {$block_attrs} -->\n";
					$fixed_content .= $inner_html . "\n";
					$fixed_content .= "<!-- /wp:{$block_name} -->";
				} else {
					// Self-closing block (no content).
					$fixed_content .= "<!-- wp:{$block_name} {$block_attrs} /-->";
				}
			}

			return $fixed_content;
		}

		// Return original content if no placeholder wrapper pattern found.
		return $content;
	}

	/**
	 * Extract global module template markup for portability export without re-serializing blocks.
	 *
	 * Portability materialization must preserve `$variable(...)` tokens from the library template.
	 * parse_blocks()/serialize_blocks() round-trips can corrupt dynamic variable syntax in attrs.
	 *
	 * @since ??
	 *
	 * @param string $decoded_content Decoded global layout template post content.
	 *
	 * @return string Inline module block markup without placeholder wrappers.
	 */
	private static function _extract_global_layout_template_markup_for_portability( string $decoded_content ): string {
		if ( preg_match( '/<!--\s*wp:divi\/placeholder\b[^>]*-->\s*(.+?)\s*<!--\s*\/wp:divi\/placeholder\s*-->/s', $decoded_content, $matches ) ) {
			return trim( $matches[1] );
		}

		return trim( $decoded_content );
	}

	/**
	 * Replace dynamic variable tokens with serialization-safe placeholders.
	 *
	 * @since ??
	 *
	 * @param string $content          Serialized block content.
	 * @param array  $tokens           Placeholder-to-token map populated by this method.
	 * @param string $reserved_content Additional content in which placeholders must not occur.
	 *
	 * @return string Content with complete dynamic variable tokens protected.
	 */
	private static function _protect_dynamic_variable_tokens_for_portability( string $content, array &$tokens, string $reserved_content = '' ): string {
		$marker = '$variable(';
		$offset = 0;

		while ( false !== ( $position = strpos( $content, $marker, $offset ) ) ) {
			$json_start  = $position + strlen( $marker );
			$json_bounds = BlockParserUtils::extract_json_object_bounds( $content, $json_start );

			if (
				null === $json_bounds
				|| ')$' !== substr( $content, $json_bounds['end'] + 1, 2 )
			) {
				$offset = $json_start;
				continue;
			}

			$token_end         = $json_bounds['end'] + 3;
			$placeholder_index = count( $tokens );

			do {
				$placeholder = '__DIVI_PORTABILITY_DYNAMIC_VARIABLE_' . $placeholder_index . '__';
				++$placeholder_index;
			} while (
				str_contains( $content, $placeholder )
				|| str_contains( $reserved_content, $placeholder )
				|| isset( $tokens[ $placeholder ] )
			);

			$tokens[ $placeholder ] = substr( $content, $position, $token_end - $position );
			$content                = substr_replace( $content, $placeholder, $position, $token_end - $position );
			$offset                 = $position + strlen( $placeholder );
		}

		return $content;
	}

	/**
	 * Restore dynamic variable tokens protected during portability materialization.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 * @param array  $tokens  Placeholder-to-token map.
	 *
	 * @return string Content with original dynamic variable tokens restored.
	 */
	private static function _restore_dynamic_variable_tokens_for_portability( string $content, array $tokens ): string {
		return $tokens ? strtr( $content, $tokens ) : $content;
	}


	/**
	 * Filter a complete snapshot to explicit local overrides that should be injected.
	 *
	 * This implements selective filtering similar to apply_local_attrs_filtering(), but returns
	 * the exact snapshot values listed in localAttrOverridePaths instead of merged attributes.
	 * Paths must also be authorized by the template's localAttrsMap.
	 *
	 * Loop settings and values that replace loop variables follow the same explicit ownership
	 * and authorization rules as every other attribute.
	 *
	 * @since ??
	 *
	 * @param array $local_attrs               Complete instance attribute snapshot.
	 * @param array $local_attrs_map           localAttrsMap from template (source of truth).
	 * @param array $local_attr_override_paths Exact instance-owned value paths.
	 *
	 * @return array Filtered localAttrs containing exact authorized overrides.
	 */
	private static function _filter_local_attrs_by_map(
		array $local_attrs,
		array $local_attrs_map,
		array $local_attr_override_paths = []
	): array {
		return ComponentLocalAttrsUtils::apply_authorized_override_paths(
			[],
			$local_attrs,
			$local_attrs_map,
			$local_attr_override_paths
		);
	}

	/**
	 * Inject local attributes into template content by modifying the JSON in block comments.
	 * This must happen BEFORE parsing so loop settings are merged before loop execution.
	 *
	 * Filters localAttrs based on the template's localAttrsMap before injection. When localAttrsMap
	 * is empty (fully synced), skips injection so unauthorized instance values cannot replace
	 * source values.
	 *
	 * @param string $content                   Template content.
	 * @param array  $local_attrs               Complete instance attribute snapshot.
	 * @param array  $local_attr_override_paths Exact instance-owned value paths.
	 * @param bool   $did_inject                 Whether authorized local attributes were injected.
	 * @param bool   $initialize_override_paths Whether paths should be inferred for a legacy snapshot.
	 *
	 * @return string Modified content with localAttrs injected, or original content if no injection needed.
	 */
	private static function _inject_local_attrs_into_content(
		string $content,
		array $local_attrs,
		array $local_attr_override_paths = [],
		bool &$did_inject = false,
		bool $initialize_override_paths = false
	) {
		$did_inject = false;

		// Find ALL blocks and pick the first non-placeholder one.
		if ( ! preg_match_all( '/<!-- wp:divi\/([a-z]+)\s+/', $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $content;
		}

		$target_match = null;
		foreach ( $matches as $match ) {
			$block_name = $match[1][0];
			if ( 'placeholder' !== $block_name ) {
				$target_match = $match;
				break;
			}
		}

		if ( ! $target_match ) {
			return $content;
		}

		$json_start  = $target_match[0][1] + strlen( $target_match[0][0] );
		$json_bounds = BlockParserUtils::extract_json_object_bounds( $content, $json_start );

		if ( null === $json_bounds ) {
			return $content;
		}

		$attrs = json_decode( $json_bounds['json'], true );

		if ( ! is_array( $attrs ) ) {
			return $content;
		}

		// Extract localAttrsMap from template attributes to determine which attributes to inject.
		$local_attrs_map = $attrs['localAttrsMap'] ?? [];
		$local_attrs_map = is_array( $local_attrs_map ) ? $local_attrs_map : [];

		if ( $initialize_override_paths ) {
			$local_attr_override_paths = ComponentLocalAttrsUtils::get_local_attr_override_paths(
				$local_attrs,
				$local_attrs_map
			);
		}

		$local_attr_override_paths = ComponentLocalAttrsUtils::normalize_local_attr_override_paths(
			$local_attr_override_paths,
			$local_attrs_map
		);

		// Filter localAttrs to exact owned paths authorized by localAttrsMap.
		$filtered_local_attrs = self::_filter_local_attrs_by_map(
			$local_attrs,
			$local_attrs_map,
			$local_attr_override_paths
		);

		// Only inject if there are filtered attributes to inject.
		if ( empty( $filtered_local_attrs ) ) {
			return $content;
		}

		// Apply each authorized path as an exact replacement.
		// This prevents object-valued overrides from retaining synchronized template siblings.
		$did_inject                      = true;
		$attrs                           = ComponentLocalAttrsUtils::apply_authorized_override_paths(
			$attrs,
			$local_attrs,
			$local_attrs_map,
			$local_attr_override_paths
		);
		$attrs['localAttrsMap']          = $local_attrs_map;
		$attrs['localAttrOverridePaths'] = $local_attr_override_paths;
		$new_json                        = serialize_block_attributes( $attrs );

		return substr( $content, 0, $json_bounds['start'] ) . $new_json . substr( $content, $json_bounds['end'] + 1 );
	}

	/**
	 * Reset order indexes data
	 *
	 * Resets all module order indexes using the central module order manager.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function reset_order_index() {
		$layout_type = self::get_layout_type();

		// Use the central module order manager.
		\ET_Builder_Module_Order::reset_indexes( $layout_type );
	}

	/**
	 * Check if blocks have already been parsed in any store instance.
	 *
	 * This method checks if there are any blocks (other than root blocks) in any
	 * store instance, indicating that blocks have already been parsed.
	 *
	 * @since ??
	 *
	 * @return bool True if blocks have been parsed, false otherwise.
	 */
	public static function has_parsed_blocks() {
		// PERFORMANCE: Optimize for the common case - pages typically have blocks,
		// so we want to return true as fast as possible.
		foreach ( self::$_data as $instance_blocks ) {
			// Skip empty instances immediately (common after instance creation).
			if ( empty( $instance_blocks ) ) {
				continue;
			}

			// PERFORMANCE: Quick check - if there's more than one block, at least one must be non-root.
			// This is the most common case and avoids iterating through blocks.
			$block_count = count( $instance_blocks );
			if ( $block_count > 1 ) {
				return true;
			}

			// Only one block - check if it's non-root.
			// PERFORMANCE: Use foreach directly to avoid array_keys() overhead.
			foreach ( $instance_blocks as $block_id => $block ) {
				if ( 'divi/root' !== $block_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if order index has been reset during this page render.
	 *
	 * @since ??
	 *
	 * @return bool True if order index has been reset, false otherwise.
	 */
	public static function has_reset_order_index() {
		return self::$_has_reset_order_index;
	}

	/**
	 * Set the order index reset flag.
	 *
	 * This is used internally by OrderIndexResetManager to track reset state.
	 *
	 * @since ??
	 *
	 * @param bool $value The value to set for the reset flag.
	 * @return void
	 */
	public static function set_has_reset_order_index( $value ) {
		self::$_has_reset_order_index = $value;
	}

	/**
	 * Check if blocks have been parsed for a specific layout type in the current instance.
	 *
	 * This is used by OrderIndexResetManager to determine if blocks were parsed
	 * after a reset occurred in the same store instance.
	 *
	 * PERFORMANCE: This method loops through all blocks in the current instance.
	 * Results should be cached when called multiple times for the same layout type.
	 *
	 * @since ??
	 *
	 * @param string $layout_type The layout type to check.
	 * @return bool True if blocks exist for this layout type in current instance, false otherwise.
	 */
	public static function has_blocks_for_layout_type( $layout_type ) {
		// PERFORMANCE: Early return if no current instance or it's empty.
		if ( ! isset( self::$_data[ self::$_instance ] ) || empty( self::$_data[ self::$_instance ] ) ) {
			return false;
		}

		$instance_blocks = self::$_data[ self::$_instance ];

		// PERFORMANCE: Quick check - if there's only one block and it's root, return false immediately.
		$block_count = count( $instance_blocks );
		if ( 1 === $block_count && isset( $instance_blocks['divi/root'] ) ) {
			return false;
		}

		// Iterate through blocks to find a match.
		// PERFORMANCE: Use foreach directly instead of array operations to avoid creating intermediate arrays.
		foreach ( $instance_blocks as $block_id => $block ) {
			// Skip root blocks early (most common case).
			if ( 'divi/root' === $block_id ) {
				continue;
			}

			// Check if this block has the layout type we're looking for.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Matches WordPress block parser conventions.
			if ( isset( $block->layout_type ) && $layout_type === $block->layout_type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if blocks have been parsed for a specific layout type across all store instances.
	 *
	 * This is used by OrderIndexResetManager to detect if blocks were parsed during CSS generation
	 * in a previous store instance, which would require a reset before HTML rendering.
	 *
	 * PERFORMANCE: This method is expensive as it loops through all store instances and all blocks.
	 * It should only be called when absolutely necessary, and results should be cached.
	 *
	 * @since ??
	 *
	 * @param string $layout_type The layout type to check.
	 * @return bool True if blocks exist for this layout type in any instance, false otherwise.
	 */
	public static function has_blocks_for_layout_type_anywhere( $layout_type ) {
		// PERFORMANCE: Early termination if no instances exist.
		if ( empty( self::$_data ) ) {
			return false;
		}

		// PERFORMANCE: Iterate through instances and blocks with early returns.
		// This is the most expensive operation in the OrderIndexResetManager,
		// so we optimize for the common case: finding a match quickly or
		// determining no match exists with minimal iterations.
		foreach ( self::$_data as $instance_blocks ) {
			// Skip empty instances immediately (common case after instance creation).
			if ( empty( $instance_blocks ) ) {
				continue;
			}

			// PERFORMANCE: For most instances, we can skip if there are only root blocks.
			// Check the count first to avoid iterating if there's only one block (the root).
			$block_count = count( $instance_blocks );
			if ( 1 === $block_count && isset( $instance_blocks['divi/root'] ) ) {
				continue;
			}

			// Iterate through blocks to find a match.
			// PERFORMANCE: Use foreach directly instead of array_keys() to avoid creating an intermediate array.
			foreach ( $instance_blocks as $block_id => $block ) {
				// Skip root blocks early (most common case).
				if ( 'divi/root' === $block_id ) {
					continue;
				}

				// PERFORMANCE: Check layout_type property directly without isset() check first.
				// The property should always exist on non-root blocks, so the isset() is redundant overhead.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Matches WordPress block parser conventions.
				if ( $layout_type === $block->layout_type ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reset order indexes data once per page render.
	 *
	 * This method ensures that order indexes are only reset once per page render,
	 * preventing multiple resets that could cause incorrect order index numbering.
	 * The reset flag is automatically managed internally using a static property.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function reset_order_index_once() {
		if ( ! self::$_has_reset_order_index ) {
			self::reset_order_index();
			self::$_has_reset_order_index = true;
		}
	}

	/**
	 * Read global layout template post_content directly from the database.
	 *
	 * Bypasses the WordPress post object cache so template edits are always reflected
	 * on the next expansion, even within the same request. Results are memoized per
	 * request and invalidated when the layout post cache is cleaned or the post is updated.
	 *
	 * @since ??
	 *
	 * @param int $post_id Global layout template post ID.
	 *
	 * @return string|null Raw post_content from the database, or null when missing or empty.
	 */
	public static function get_fresh_global_layout_post_content( int $post_id ): ?string {
		self::_maybe_register_fresh_global_layout_post_content_cache_hooks();

		if ( array_key_exists( $post_id, self::$_fresh_global_layout_post_content_cache ) ) {
			return self::$_fresh_global_layout_post_content_cache[ $post_id ]['content'] ?? null;
		}

		return self::_load_fresh_global_layout_post_content( $post_id );
	}

	/**
	 * Load global layout template post_content from the database and seed the request cache.
	 *
	 * @since ??
	 *
	 * @param int $post_id Global layout template post ID.
	 *
	 * @return string|null Raw post_content from the database, or null when missing or empty.
	 */
	private static function _load_fresh_global_layout_post_content( int $post_id ): ?string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_content, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			self::$_fresh_global_layout_post_content_cache[ $post_id ] = [
				'content'      => null,
				'modified_gmt' => '',
				'content_hash' => md5( '' ),
			];

			return null;
		}

		$post_content      = is_string( $row['post_content'] ) ? $row['post_content'] : null;
		$post_modified_gmt = is_string( $row['post_modified_gmt'] ) ? $row['post_modified_gmt'] : '';

		if ( '' === $post_content ) {
			$post_content = null;
		}

		self::$_fresh_global_layout_post_content_cache[ $post_id ] = [
			'content'      => $post_content,
			'modified_gmt' => $post_modified_gmt,
			'content_hash' => is_string( $post_content ) ? md5( $post_content ) : md5( '' ),
		];

		return $post_content;
	}

	/**
	 * Flush request-scoped global layout template post_content cache entries.
	 *
	 * @since ??
	 *
	 * @param int|null $post_id Optional layout post ID. When omitted, flushes all cached entries.
	 *
	 * @return void
	 */
	public static function flush_fresh_global_layout_post_content_cache( ?int $post_id = null ): void {
		if ( null === $post_id ) {
			self::$_fresh_global_layout_post_content_cache = [];

			return;
		}

		unset( self::$_fresh_global_layout_post_content_cache[ $post_id ] );
	}

	/**
	 * Invalidate cached global layout post_content when a layout post cache is cleaned.
	 *
	 * @since ??
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Optional post object.
	 *
	 * @return void
	 */
	public static function flush_fresh_global_layout_post_content_cache_on_clean_post_cache( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( array_key_exists( $post_id, self::$_fresh_global_layout_post_content_cache ) ) {
			self::flush_fresh_global_layout_post_content_cache( $post_id );

			return;
		}

		if ( $post instanceof \WP_Post ) {
			$post_type = $post->post_type;
		} else {
			$post_type = get_post_type( $post_id );
		}

		if ( defined( 'ET_BUILDER_LAYOUT_POST_TYPE' ) && ET_BUILDER_LAYOUT_POST_TYPE === $post_type ) {
			self::flush_fresh_global_layout_post_content_cache( $post_id );
		}
	}

	/**
	 * Register hooks that keep the fresh global layout post_content cache coherent.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	private static function _maybe_register_fresh_global_layout_post_content_cache_hooks(): void {
		if ( has_action( 'clean_post_cache', [ self::class, 'flush_fresh_global_layout_post_content_cache_on_clean_post_cache' ] ) ) {
			return;
		}

		add_action( 'clean_post_cache', [ self::class, 'flush_fresh_global_layout_post_content_cache_on_clean_post_cache' ], 10, 2 );
		add_action( 'post_updated', [ self::class, 'flush_fresh_global_layout_post_content_cache_on_post_updated' ], 10, 3 );
	}

	/**
	 * Invalidate cached global layout post_content after a layout post update.
	 *
	 * @since ??
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post object following the update.
	 * @param \WP_Post $post_before Post object before the update.
	 *
	 * @return void
	 */
	public static function flush_fresh_global_layout_post_content_cache_on_post_updated( $post_id, $post_after, $post_before ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}

		if ( $post_after->post_content === $post_before->post_content ) {
			return;
		}

		if ( defined( 'ET_BUILDER_LAYOUT_POST_TYPE' ) && ET_BUILDER_LAYOUT_POST_TYPE === $post_after->post_type ) {
			self::flush_fresh_global_layout_post_content_cache( $post_id );
		}
	}

	/**
	 * Materialize Component references while preserving all surrounding serialized markup.
	 *
	 * @since ??
	 *
	 * @param string $content                Serialized block content.
	 * @param bool   $for_portability_export Whether to preserve portability materialization behavior.
	 *
	 * @return string Content with valid Component references materialized.
	 */
	private static function _expand_global_layout_blocks_in_serialized_content( string $content, bool $for_portability_export ): string {
		$marker   = '<!-- wp:divi/global-layout';
		$offset   = 0;
		$expanded = '';

		while ( false !== ( $position = strpos( $content, $marker, $offset ) ) ) {
			$reference_block = BlockParserUtils::extract_global_layout_block( $content, $position );

			if ( null === $reference_block ) {
				$next_offset = $position + strlen( $marker );
				$expanded   .= substr( $content, $offset, $next_offset - $offset );
				$offset      = $next_offset;
				continue;
			}

			$expanded .= substr( $content, $offset, $position - $offset );

			$attrs            = $reference_block['attrs'];
			$module_id        = isset( $attrs['globalModule'] ) ? (string) absint( $attrs['globalModule'] ) : '';
			$serialized_block = substr(
				$content,
				$reference_block['start'],
				$reference_block['end'] - $reference_block['start']
			);

			if ( '' === $module_id || '0' === $module_id ) {
				$expanded .= $serialized_block;
				$offset    = $reference_block['end'];
				continue;
			}

			$block_for_parsing = '<!-- wp:divi/global-layout ' . serialize_block_attributes( $attrs ) . ' /-->';
			$replacement       = self::get_global_layout_content(
				$block_for_parsing,
				$module_id,
				[
					'post_type'   => ET_BUILDER_LAYOUT_POST_TYPE,
					'post_status' => 'publish',
				],
				[],
				true,
				$reference_block['innerHTML'],
				$reference_block['hasChildrenSnapshot'],
				$for_portability_export
			);

			$expanded .= null === $replacement || $block_for_parsing === $replacement
				? $serialized_block
				: $replacement;
			$offset    = $reference_block['end'];
		}

		return $expanded . substr( $content, $offset );
	}

	/**
	 * Expand all Component reference blocks in serialized content.
	 *
	 * Nested references are expanded recursively by get_global_layout_content().
	 * The expansion stack leaves an invalid cyclic reference unexpanded.
	 *
	 * @since ??
	 *
	 * @param string $content                Serialized block content.
	 * @param bool   $for_portability_export Whether to preserve portability materialization behavior.
	 *
	 * @return string Content with valid nested Component references expanded.
	 */
	public static function expand_global_layout_blocks( string $content, bool $for_portability_export = false ): string {
		if ( ! str_contains( $content, '<!-- wp:divi/global-layout' ) ) {
			return $content;
		}

		return self::_expand_global_layout_blocks_in_serialized_content( $content, $for_portability_export );
	}

	/**
	 * Get `post_content` of a global layout if the post exists and matches the given arguments.
	 *
	 * This helper is intended to simplify the way to get `post_content` object of a global layout since we already know the ID.
	 * Instead of using the complex and heavy `WP_Query` class, we use the light and cached `get_post` build-in function.
	 *
	 * @since ??
	 *
	 * @param string  $content The content of the global layout.
	 * @param string  $post_id The ID of the post.
	 * @param array   $fields Optional. An array of `key => value` arguments to match against the post object. Default `[]`.
	 * @param array   $capabilities Optional. An array of user capability to match against the current user. Default `[]`.
	 * @param boolean $mask_post_password Optional. Whether to mask `post_password` field. Default `true`.
	 * @param string  $inner_html Optional. The innerHTML content from global-layout block for local children. Default `''`.
	 * @param boolean $has_children_snapshot Optional. Whether the instance explicitly contains a children snapshot. Default `false`.
	 * @param boolean $for_portability_export Optional. When true, preserve template markup without a parse/serialize round-trip. Default `false`.
	 *
	 * @return string|null The post content or null on failure.
	 */
	public static function get_global_layout_content( string $content, string $post_id, array $fields = [], array $capabilities = [], bool $mask_post_password = true, string $inner_html = '', bool $has_children_snapshot = false, bool $for_portability_export = false ) {
		global $_is_parsing_global_layout;

		// Preserve the external parser re-entry contract while allowing stack-managed nested Components.
		if ( $_is_parsing_global_layout && empty( self::$_global_layout_expansion_stack ) ) {
			return $content;
		}

		// A repeated template ID is a direct or indirect self-containment cycle.
		if ( in_array( $post_id, self::$_global_layout_expansion_stack, true ) ) {
			return $content;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		// Clone to avoid mutating the cached WP_Post object returned by get_post().
		$post = clone $post;

		$raw_post_content = self::get_fresh_global_layout_post_content( (int) $post_id );

		if ( null === $raw_post_content ) {
			return null;
		}

		$was_parsing_global_layout              = (bool) $_is_parsing_global_layout;
		self::$_global_layout_expansion_stack[] = $post_id;

		try {
			self::set_layout(
				[
					'id'   => 'global_layout_' . $post_id,
					'type' => 'global_layout',
				]
			);
			// Set $_is_parsing_global_layout so parser knows that it's parsing a global layout.
			$_is_parsing_global_layout = true;

			// Migrate the instance wrapper before reading its local ownership metadata.
			// Use a non-template post context so legacy localAttrs snapshots gain inferred
			// localAttrOverridePaths from their referenced global module template.
			$content = GlobalModuleSyncNodeMigration::migrate_content_block_for_post( $content, 0 );

			$local_attrs                     = [];
			$local_attr_override_paths       = [];
			$initialize_local_override_paths = false;

			foreach ( BlockParserUtils::parse_global_layout_blocks( $content ) as $reference_block ) {
				if ( 'divi/global-layout' !== ( $reference_block['blockName'] ?? '' ) ) {
					continue;
				}

				$global_layout_attrs             = is_array( $reference_block['attrs'] ?? null )
					? $reference_block['attrs']
					: [];
				$local_attrs                     = is_array( $global_layout_attrs['localAttrs'] ?? null )
					? $global_layout_attrs['localAttrs']
					: [];
				$initialize_local_override_paths = ! array_key_exists(
					'localAttrOverridePaths',
					$global_layout_attrs
				);
				$local_attr_override_paths       = is_array( $global_layout_attrs['localAttrOverridePaths'] ?? null )
					? $global_layout_attrs['localAttrOverridePaths']
					: [];
				break;
			}

			// Decode and convert D4 content before applying the ordered D5 migration suite.
			// Use the fresh database content so template edits are reflected within the same request.
			$decoded_content = GlobalModuleSyncNodeMigration::migrate_global_template_content(
				$raw_post_content,
				(int) $post_id
			);

			$decoded_content = self::_collapse_duplicate_placeholder_wrappers( $decoded_content );

			// CRITICAL: Inject localAttrs into template content BEFORE parsing.
			//
			// WHY HERE: Explicitly owned values affecting $variable() parsing must be merged
			// before the loop is executed. Post-parse merging would be too late.
			//
			// WHAT IT DOES: The _inject_local_attrs_into_content() method handles double-escaped
			// attributes that break WordPress parse_blocks() after database save/load cycles with
			// wp_slash(). It does this by:
			// - Extracting JSON manually using brace-matching (handles double-escaping)
			// - json_decode() processes the double-escaped JSON correctly
			// - Extracts template's localAttrsMap from the decoded attributes
			// - Filters localAttrs to exact localAttrOverridePaths authorized by localAttrsMap
			// - If either ownership or authorization metadata is empty, skips injection entirely
			// - Otherwise, merges filtered localAttrs with template attributes
			// - serialize_block_attributes() creates fresh, block-comment-safe JSON.
			// - Replaces the old JSON in content
			// Result: Content no longer has double-escaped attributes after this step.
			//
			// WHY FILTER: localAttrsMap authorizes which fields may be local, while
			// localAttrOverridePaths identifies the exact complete-snapshot values owned by
			// this instance. Unauthorized or unlisted snapshot values must not overwrite
			// synchronized template values such as Global Colors or dynamic content.
			//
			// OPTIMIZATION: Only call when the template or instance snapshot contains $variable() syntax.
			// This method is expensive (manual JSON parsing), but double-escaping issues
			// only occur with $variable() syntax. Instance-owned loop variables must be
			// injected before parsing even when the synchronized template contains static values.
			// Without variables, rely on post-parse attribute merging instead.
			$local_attrs_injected_before_parsing = false;
			$encoded_local_attrs                 = wp_json_encode( $local_attrs );
			$local_attrs_contain_variables       = is_string( $encoded_local_attrs )
				&& str_contains( $encoded_local_attrs, '$variable(' );

			if (
				! empty( $local_attrs )
				&& (
					str_contains( $decoded_content, '$variable(' )
					|| $local_attrs_contain_variables
				)
			) {
				$decoded_content = self::_inject_local_attrs_into_content(
					$decoded_content,
					$local_attrs,
					$local_attr_override_paths,
					$local_attrs_injected_before_parsing,
					$initialize_local_override_paths
				);
			}

			// An explicit children snapshot, even an empty one, must go through the snapshot path so the
			// instance's own children are not replaced by the template's.
			$should_materialize_without_serialize_round_trip = $for_portability_export
				&& ! $has_children_snapshot
				&& empty( $inner_html )
				&& (
					empty( $local_attrs )
					|| str_contains( $decoded_content, '$variable(' )
					|| str_contains( $raw_post_content, '$variable(' )
				);

			$should_process_children_snapshot = $has_children_snapshot || ! empty( $inner_html );

			if ( $should_materialize_without_serialize_round_trip ) {
				$post->post_content = self::_extract_global_layout_template_markup_for_portability( $decoded_content );
			} else {
				$dynamic_tokens = [];

				if ( $for_portability_export ) {
					$decoded_content = self::_protect_dynamic_variable_tokens_for_portability(
						$decoded_content,
						$dynamic_tokens,
						$inner_html
					);
					$inner_html      = self::_protect_dynamic_variable_tokens_for_portability(
						$inner_html,
						$dynamic_tokens,
						$decoded_content
					);
				}

				// Preprocess placeholder-wrapped global module content before parsing.
				// The _expand_placeholder_wrapped_blocks() method also fixes double-escaping by
				// reconstructing blocks with serialize_block_attributes(), creating block-comment-safe JSON.
				$preprocessed_content = self::_expand_placeholder_wrapped_blocks( $decoded_content );

				if (
					$should_process_children_snapshot
					&& ! empty( $inner_html )
				) {
					$preprocessed_content = self::_inject_nested_local_attrs_into_content(
						$preprocessed_content,
						$inner_html
					);
				}

				// Parse the preprocessed content. At this point, any double-escaped attributes have been
				// fixed by the re-encoding process above, so parse_blocks() works correctly.
				$parsed_actual_post = self::_parse_blocks_with_core_parser( $preprocessed_content );

				// Find the first non-placeholder block and update its attributes.
				foreach ( $parsed_actual_post as $index => $block ) {
					if (
					isset( $block['blockName'] ) &&
					'divi/placeholder' !== $block['blockName']
					) {
						// Apply only exact complete-snapshot paths explicitly owned by the instance and
						// authorized by the template's localAttrsMap. Missing paths inherit the template.
						if ( ! empty( $local_attrs ) ) {
							$template_attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

							if (
							$local_attrs_injected_before_parsing
							&& true !== ( $local_attrs['localModule'] ?? false )
							) {
								// Preserve values resolved during loop expansion while still composing
								// source and instance-local preset stacks.
								$parsed_actual_post[ $index ]['attrs'] = ComponentPresetUtils::compose_component_preset_attrs(
									$template_attrs,
									$template_attrs,
									$local_attrs
								);
							} else {
								$parsed_actual_post[ $index ]['attrs'] = self::apply_local_attrs_filtering(
									$template_attrs,
									$local_attrs,
									$local_attr_override_paths,
									$initialize_local_override_paths
								);
							}
						}
						break;
					}
				}

				// Handle children snapshot filtering based on template's localChildren.
				// innerHTML (innerBlocks) always contains children snapshot (complete snapshot).
				// Template's localChildren boolean determines whether to use snapshot or template children.
				if ( $should_process_children_snapshot ) {
					// Parse innerHTML to get children snapshot and filter whitespace blocks.
					$parsed_inner_html = self::_parse_blocks_with_core_parser( $inner_html );

					// Filter out placeholder blocks and get actual child modules.
					// Use array_values to re-index the array and avoid sparse indices that break serialize_blocks.
					$children_snapshot = array_values(
						array_filter(
							$parsed_inner_html,
							function ( $block ) {
								return ! empty( $block['blockName'] ) && 'divi/placeholder' !== $block['blockName'];
							}
						)
					);

					// Ensure all blocks have the required properties for WordPress serialization.
					foreach ( $children_snapshot as $index => $block ) {
						$children_snapshot[ $index ] = array_merge(
							[
								'blockName'    => '',
								'attrs'        => [],
								'innerBlocks'  => [],
								'innerHTML'    => '',
								'innerContent' => [],
							],
							$block
						);
					}

					// Find the main module in parsed_actual_post (first non-placeholder block).
					// Note: Don't require ! empty( $block['attrs'] ) as complex JSON may not parse correctly.
					$main_module_index = null;
					foreach ( $parsed_actual_post as $index => $block ) {
						if (
						isset( $block['blockName'] ) &&
						! empty( $block['blockName'] ) &&
						'divi/placeholder' !== $block['blockName']
						) {
							$main_module_index = $index;
							break;
						}
					}

					if ( null !== $main_module_index ) {
						// Get template's localChildren value (source of truth).
						$template_local_children = $parsed_actual_post[ $main_module_index ]['attrs']['localChildren'] ?? false;

						// Template's localChildren determines which children to use.
						if ( true === $template_local_children ) {
							// Use children from snapshot (serialized block).
							$parsed_actual_post[ $main_module_index ]['innerBlocks'] = $children_snapshot;
						} elseif ( false === $template_local_children ) {
							if ( ! empty( $children_snapshot ) && ! empty( $parsed_actual_post[ $main_module_index ]['innerBlocks'] ) ) {
								// localChildren is synced (template structure), but nested modules can still have their own
								// localAttrsMap selective-sync settings. Merge local values recursively per node.
								$parsed_actual_post[ $main_module_index ]['innerBlocks'] = self::_apply_nested_local_attrs_filtering(
									$parsed_actual_post[ $main_module_index ]['innerBlocks'],
									$children_snapshot
								);
							}

							$slot_payloads = GlobalSlotUtils::collect_payloads( $children_snapshot );

							if ( $slot_payloads ) {
								$parsed_actual_post = GlobalSlotUtils::apply_payloads( $parsed_actual_post, $slot_payloads );
							}
						}
						// Else: Use children from template (already in parsed_actual_post).

						// Note: localChildren is already in template attributes, no need to set it again.
					}
				}

				// Filter out placeholder blocks before final serialization - they're only needed for internal processing.
				$parsed_actual_post = array_values(
					array_filter(
						$parsed_actual_post,
						function ( $block ) {
							return isset( $block['blockName'] ) && 'divi/placeholder' !== $block['blockName'];
						}
					)
				);

				// Recursively ensure all blocks have complete structure before serialization with depth limit.
				$parsed_actual_post = self::_normalize_block_structure( $parsed_actual_post );

				// serialize updated post content and add it to the post object.
				$post->post_content = serialize_blocks( $parsed_actual_post );

				if ( $for_portability_export ) {
					$post->post_content = self::_restore_dynamic_variable_tokens_for_portability(
						$post->post_content,
						$dynamic_tokens
					);
				}
			}

			// Expand nested Component references only after this instance's snapshot has been merged.
			// This keeps template and snapshot block shapes aligned while applying nested local overrides.
			$post->post_content = self::expand_global_layout_blocks(
				$post->post_content,
				$for_portability_export
			);

			$match = true;

			if ( $fields ) {
				foreach ( $fields as $field => $value ) {
					if ( ! isset( $post->{$field} ) ) {
						$match = false;
						break;
					}

					$match = is_array( $value ) && ! is_array( $post->{$field} ) ? in_array( $post->{$field}, $value, true ) : $post->{$field} === $value;

					if ( ! $match ) {
						break;
					}
				}
			}

			if ( $match && $capabilities ) {
				foreach ( $capabilities as $capability ) {
					if ( ! current_user_can( $capability, $post->ID ) ) {
						$match = false;
						break;
					}
				}
			}

			if ( $match ) {
				if ( $mask_post_password && $post->post_password ) {
					$post->post_password = '***';
				}

				return $post->post_content;
			}

			return null;
		} finally {
			array_pop( self::$_global_layout_expansion_stack );
			$_is_parsing_global_layout = $was_parsing_global_layout;
			self::reset_order_index();
			self::reset_layout();
		}
	}


	/**
	 * Get the ID of the currently active store instance.
	 *
	 * @since ??
	 *
	 * @return int|null The active store instance ID. Will return `null` when no instance has been created.
	 */
	public static function get_instance() {
		return self::$_instance;
	}

	/**
	 * Get all active store instance IDs.
	 *
	 * @since ??
	 *
	 * @return array<int, int>
	 */
	public static function get_instance_ids(): array {
		return array_map( 'intval', array_keys( self::$_data ) );
	}

	/**
	 * Capture the current parser state for later restoration.
	 *
	 * Used to isolate side effects of nested `do_blocks()` calls (e.g. preset
	 * selector registration) from the active frontend render pipeline. Call
	 * `restore_state()` with the returned snapshot to undo any state mutations
	 * introduced by the nested call, including Theme Builder layout area tracking.
	 *
	 * @since ??
	 *
	 * @return array Snapshot of the current parser state.
	 */
	public static function capture_state(): array {
		return [
			'instance'                => self::$_instance,
			'data'                    => self::$_data,
			'has_reset_order_index'   => self::$_has_reset_order_index,
			'rendering_inner_content' => self::$_rendering_inner_content,
			'layout'                  => self::$_layout,
			'layouts'                 => self::$_layouts,
		];
	}

	/**
	 * Restore the parser state from a previously captured snapshot.
	 *
	 * Reverses any state mutations introduced by a nested `do_blocks()` call so
	 * the active frontend render pipeline continues from its pre-call position.
	 *
	 * @since ??
	 *
	 * @param array $state Snapshot previously returned by `capture_state()`.
	 *
	 * @return void
	 */
	public static function restore_state( array $state ): void {
		self::$_instance                = $state['instance'];
		self::$_data                    = $state['data'];
		self::$_has_reset_order_index   = $state['has_reset_order_index'];
		self::$_rendering_inner_content = $state['rendering_inner_content'];
		self::$_layout                  = $state['layout'] ?? [
			'id'   => '',
			'type' => 'default',
		];
		self::$_layouts                 = $state['layouts'] ?? [];
	}

	/**
	 * Get the parent of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string $child_id The unique ID of the child block.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock|null
	 */
	public static function get_parent( $child_id, $instance = null ) {
		$current = self::$_data[ self::_use_instance( $instance ) ][ $child_id ] ?? null;

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP use snakeCase in \WP_Block_Parser_Block
		if ( ! $current || ! $current->parentId || ! self::has( $current->parentId, $instance ) ) {
			return null;
		}

		return self::get( $current->parentId, $instance );
	}


	/**
	 * Get the siblings of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to get the sibling of.
	 * @param string $location Sibling location. Can be either `before` or `after`.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return BlockParserBlock[] Array of siblings sorted from the closest sibling. Will return empty array on failure.
	 */
	public static function get_siblings( $id, $location, $instance = null ) {
		$parent = self::get_parent( $id, $instance );

		if ( ! $parent ) {
			return [];
		}

		$inner_blocks = [];
		$all_blocks   = self::$_data[ self::_use_instance( $instance ) ];

		foreach ( $all_blocks as $block ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName -- Matches WordPress block parser conventions.
			if ( $block->parentId === $parent->id ) {
				$inner_blocks[] = $block;
			}
		}

		$inner_blocks_count = count( $inner_blocks );

		if ( 1 < $inner_blocks_count ) {
			usort(
				$inner_blocks,
				function ( $a, $b ) {
					if ( $a->index === $b->index ) {
						return 0;
					}

					return ( $a->index < $b->index ) ? -1 : 1;
				}
			);
		}

		$siblings    = [];
		$index_found = null;
		$index_last  = $inner_blocks_count - 1;

		foreach ( $inner_blocks as $index => $inner_block ) {
			if ( $id === $inner_block->id ) {
				$index_found = $index;
				break;
			}
		}

		if ( null !== $index_found ) {
			if ( 'before' === $location && 0 < $index_found ) {
				$inner_blocks_before = array_reverse( array_slice( $inner_blocks, 0, $index_found ) );

				foreach ( $inner_blocks_before as $inner_block ) {
					$siblings[] = self::get( $inner_block->id, $instance );
				}
			}

			if ( 'after' === $location && $index_last > $index_found ) {
				$inner_blocks_after = array_slice( $inner_blocks, ( $index_found + 1 ) );

				foreach ( $inner_blocks_after as $inner_block ) {
					$siblings[] = self::get( $inner_block->id, $instance );
				}
			}
		}

		return $siblings;
	}

	/**
	 * Get the direct sibling of existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to get the sibling of.
	 * @param string $location Sibling location. Can be either `before` or `after`.
	 * @param int    $instance Optional. The instance of the store you want to use. Default null.
	 *
	 * @return BlockParserBlock|null
	 */
	public static function get_sibling( $id, $location, $instance = null ): ?BlockParserBlock {
		$siblings = self::get_siblings( $id, $location, $instance );

		if ( ! $siblings ) {
			return null;
		}

		return $siblings[0] ?? null;
	}


	/**
	 * Get existing item in the store.
	 *
	 * @since ??
	 *
	 * @param string $id       The unique ID of the block.
	 * @param int    $instance The instance of the store you want to use.
	 *
	 * @return BlockParserBlock|null
	 */
	public static function get( $id, $instance = null ) {
		if ( ! self::has( $id, $instance ) ) {
			return null;
		}

		$use_instance = self::_use_instance( $instance );

		$item = self::$_data[ $use_instance ][ $id ];

		// Populate `innerBlocks` data for root block.
		if ( self::_is_root( $id ) ) {
			$inner_blocks = [];

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Matches WordPress block parser conventions.
			foreach ( self::$_data[ $use_instance ] as $block ) {
				if ( ! self::_is_root( $block->parentId ) ) {
					continue;
				}

				$inner_blocks[] = (array) $block;
			}

			$item->innerBlocks = $inner_blocks;
		}

		return $item;
	}

	/**
	 * Block Parser Store: Instance check.
	 *
	 * Check if a store ID exists in the current instance's `$_data`.
	 *
	 * @since ??
	 *
	 * @param int $instance The instance ID of the store.
	 *
	 * @return bool
	 */
	public static function has_instance( $instance ) {
		if ( null === $instance ) {
			return false;
		}

		return isset( self::$_data[ $instance ] );
	}


	/**
	 * Block Parser Store: Block check.
	 *
	 * Check if a particular block exists in the instance store.
	 *
	 * @since ??
	 *
	 * @param string $id       The unique ID of the block.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 *
	 * @return bool
	 */
	public static function has( $id, $instance = null ) {
		return isset( self::$_data[ self::_use_instance( $instance ) ][ $id ] );
	}

	/**
	 * Block Parser Store: Is First check.
	 *
	 * Check if the given block is the first block in the parent block.
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to check.
	 * @param int    $instance The instance of the store you want to use.
	 *
	 * @return bool
	 */
	public static function is_first( $id, $instance = null ) {
		if ( self::_is_root( $id ) ) {
			return true;
		}

		$parent = self::get_parent( $id, $instance );

		if ( ! $parent ) {
			return false;
		}

		$children = self::get_children( $parent->id, $instance );

		$first_child_id = $children[0]->id ?? null;

		// Checking if children blocks is renderable or not.
		foreach ( $children as $index => $child_block ) {
			$child_block_arr = get_object_vars( $child_block ) ?? [];
			// First parameter `Block` is for the fake block_content, if condition doesn't meet, it will return empty string.
			// We need to check ConditionsRenderer::should_render() to ensure the child block is renderable.
			// If the child block is not renderable, we need to remove this block from the $children.
			if ( ConditionsRenderer::should_render( true, new WP_Block( $child_block_arr ), $child_block_arr['attrs'] ?? [] ) ) {
				$first_child_id = $child_block->id ?? null;
				break;
			}
		}

		return $id === $first_child_id;
	}

	/**
	 * Block Parser Store: Is Last check.
	 *
	 * Check if the given block is the last block in the parent block.
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to check.
	 * @param int    $instance The instance of the store you want to use.
	 *
	 * @return bool
	 */
	public static function is_last( $id, $instance = null ) {
		if ( self::_is_root( $id ) ) {
			return true;
		}

		$parent = self::get_parent( $id, $instance );

		if ( ! $parent ) {
			return false;
		}

		$children = self::get_children( $parent->id, $instance );

		if ( ! $children ) {
			return false;
		}

		$last_index = count( $children ) - 1;

		return isset( $children[ $last_index ]->id ) && $id === $children[ $last_index ]->id;
	}

	/**
	 * Block Parser Store: Is Nested Module.
	 *
	 * Check if the given block is a nested module (eg. row inside row module).
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to check.
	 * @param int    $instance The instance of the store you want to use.
	 *
	 * @return bool
	 */
	public static function is_nested_module( $id, $instance = null ) {
		$module = self::get( $id, $instance );

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Matches WordPress block parser conventions.
		$ancestor_module_name = array_map(
			function ( $module ) {
				return $module->blockName;
			},
			self::get_ancestors(
				$id,
				$instance
			)
		);

		return in_array( $module->blockName, $ancestor_module_name, true );
	}

	/**
	 * Check if given block is root block.
	 *
	 * Checks if the given ID is equal to `divi/root`.
	 *
	 * @since ??
	 *
	 * @param string $id The ID of the block you want to check.
	 */
	protected static function _is_root( $id ) {
		return 'divi/root' === $id;
	}

	/**
	 * Set layout area before parsing module / block.
	 * This allows module to know which area it is being rendered in.
	 *
	 * @since ??
	 *
	 * @param array $layout The layout area. The format is matched to layout array passed by `et_theme_builder_begin_layout` filter.
	 */
	public static function set_layout( $layout ) {

		// Set the param as current layout.
		self::$_layout = [
			'id'   => $layout['id'],
			'type' => $layout['type'] ?? 'default',
		];

		// Append the given layout to array of layouts. This will be used when resetting layout.
		self::$_layouts[] = self::$_layout;
	}

	/**
	 * Reset layout area.
	 * After any (theme builder) layout is done rendered, its layout should be reset.
	 *
	 * @since ??
	 */
	public static function reset_layout() {
		// Remove the last (to be reset) layout from array of layouts.
		array_pop( self::$_layouts );

		// Get the previous (last layout after the to be reset layout is removed) layout from array of layouts.
		$last_layout = end( self::$_layouts );

		// Set previous (or default) layout as current layout.
		self::$_layout = $last_layout ? $last_layout : [
			'id'   => '',
			'type' => 'default',
		];
	}

	/**
	 * Get layout type.
	 *
	 * @since ??
	 *
	 * @return string
	 */
	public static function get_layout_type() {
		return self::$_layout['type'];
	}

	/**
	 * Get layout types.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function get_layout_types() {
		return apply_filters(
			'et_theme_builder_layout_types',
			[
				'default',
				'et_header_layout',
				'et_body_layout',
				'et_footer_layout',
				'migration',
				'global_layout',
				'saving_content',
			]
		);
	}

		/**
		 * Create or return existing instance.
		 *
		 * Create new store instance when no instance has created yet.
		 * Otherwise returns existing latest instance.
		 *
		 * @since ??
		 *
		 * @internal Do not use this method outside the `BlockParser::parse()`.
		 *
		 * @return int The store instance ID.
		 */
	public static function maybe_new_instance() {
		if ( null !== self::$_instance ) {
			return self::$_instance;
		}

		return self::new_instance();
	}

	/**
	 * Create new store instance and switch to the new instance instantly.
	 *
	 * @since ??
	 *
	 * @internal Do not use this method outside the `BlockParser::parse()`.
	 *
	 * @return int The new store instance ID.
	 */
	public static function new_instance() {
		$previous_instance = self::$_instance;
		self::$_instance   = null === self::$_instance ? 0 : count( self::$_data );

		// Reset orderIndex when creating a new store instance (but not the first one).
		// This ensures each new rendering phase starts with orderIndex 0.
		// Use the unified OrderIndexResetManager to handle reset logic consistently
		// with other reset points (handles off-canvas, inner content, parsed blocks checks).
		if ( null !== $previous_instance ) {
			OrderIndexResetManager::maybe_reset( OrderIndexResetManager::PHASE_NEW_STORE_INSTANCE );
			// Reset the flag so reset_order_index_once can work for the new instance.
			// This is always safe to reset here since we're creating a new store instance.
			self::$_has_reset_order_index = false;
		}

		self::_add_root( self::$_instance );

		return self::$_instance;
	}

		/**
		 * Reset specific store instance.
		 *
		 * Will reset the store to an empty array `[]`.
		 *
		 * @since ??
		 *
		 * @param int $instance The instance of the store you want to reset.
		 *
		 * @return int|null The given store instance ID or `null` if the given ID is not found.
		 */
	public static function reset_instance( $instance ) {
		if ( self::has_instance( $instance ) ) {
			self::$_data[ $instance ] = [];

			self::_add_root( $instance );

			return $instance;
		}

		return null;
	}

	/**
	 * Store active instance
	 *
	 * @since ??
	 *
	 * @var int
	 */
	protected static $_instance = null;

	/**
	 * Store data
	 *
	 * @since ??
	 *
	 * @var BlockParserBlock[]
	 */
	protected static $_data = [];

	/**
	 * Current layout area.
	 *
	 * @since ??
	 *
	 * @var array
	 */
	protected static $_layout = [
		'id'   => '',
		'type' => 'default',
	];

	/**
	 * Array of currently used layouts.
	 *
	 * Collect all currently used layout so when there are nested layout like body > post content, correct previous
	 * layout gets restored correctly when the layout is being reset.
	 *
	 * @since ??
	 *
	 * @var array
	 */
	protected static $_layouts = [];

		/**
		 * Reset whole store data.
		 *
		 * @since ??
		 */
	public static function reset() {
		self::$_data                          = [];
		self::$_instance                      = null;
		self::$_global_layout_expansion_stack = [];
	}

	/**
	 * Set property of existing block item in the store.
	 *
	 * @since ??
	 *
	 * @param string $id       The ID of the block you want to set the property for.
	 * @param string $property The property/key you want to set.
	 * @param mixed  $value    The value to set.
	 * @param int    $instance Optional. The instance of the store you want to use. Default `null`.
	 */
	public static function set_property( $id, $property, $value, $instance = null ) {
		$use_instance = self::_use_instance( $instance );

		if (
			self::_is_root( $id )
			|| ! self::has( $id, $instance )
			|| ! property_exists( self::$_data[ $use_instance ][ $id ], $property )
		) {
			return;
		}

		self::$_data[ $use_instance ][ $id ]->{$property} = $value;
	}

	/**
	 * Switch to specific store instance.
	 *
	 * @since ??
	 *
	 * @param int $instance The instance you want to switch to.
	 *
	 * @return int|null The previous instance before the switch. Will return null on failure or when no instance created yet.
	 */
	public static function switch_instance( int $instance ) {
		if ( self::$_instance !== $instance && self::has_instance( $instance ) ) {
			$previous_instance = self::$_instance;
			self::$_instance   = $instance;

			return $previous_instance;
		}

		return null;
	}


	/**
	 * Get the store instance that will be used.
	 *
	 * @since ??
	 *
	 * @param int $instance The instance of the store you want to use.
	 *
	 * @return int The instance of the store that will be used.
	 */
	private static function _use_instance( $instance ) {
		return self::has_instance( $instance ) ? $instance : self::$_instance;
	}

	/**
	 * Recursively normalize block structure to ensure compatibility with serialize_blocks().
	 *
	 * @since ??
	 *
	 * @param array $blocks Array of blocks to normalize.
	 * @param int   $depth  Current recursion depth for preventing infinite loops.
	 *
	 * @return array Normalized blocks.
	 */
	private static function _normalize_block_structure( $blocks, $depth = 0 ) {
		if ( ! is_array( $blocks ) ) {
			return $blocks;
		}

		/**
		 * Filter the maximum depth allowed for global module block structure normalization.
		 *
		 * This filter allows modification of the maximum depth limit for processing
		 * nested block structures in global modules. Prevents infinite recursion and
		 * stack overflow errors when processing deeply nested or circular structures.
		 *
		 * @since ??
		 *
		 * @param int $max_depth Maximum allowed depth for block structure normalization.
		 *                       Default is 20 levels deep.
		 */
		$max_depth = apply_filters( 'divi_frontend_block_parser_global_module_max_depth', 20 );

		// Prevent infinite recursion by limiting depth.
		if ( $depth >= $max_depth ) {
			return $blocks;
		}

		foreach ( $blocks as $index => $block ) {
			if ( is_array( $block ) ) {
				// Ensure all required properties exist.
				$blocks[ $index ]['blockName']   = $block['blockName'] ?? '';
				$blocks[ $index ]['attrs']       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) && ! empty( $block['attrs'] ) ? $block['attrs'] : null;
				$blocks[ $index ]['innerBlocks'] = $block['innerBlocks'] ?? [];
				$blocks[ $index ]['innerHTML']   = $block['innerHTML'] ?? '';

				// Recursively normalize innerBlocks first with depth tracking.
				if ( ! empty( $blocks[ $index ]['innerBlocks'] ) ) {
					$blocks[ $index ]['innerBlocks'] = self::_normalize_block_structure( $blocks[ $index ]['innerBlocks'], $depth + 1 );
				}

				// Fix innerContent based on block type - this is critical for serialize_blocks().
				if ( ! empty( $blocks[ $index ]['innerBlocks'] ) ) {
					// Parent block with children - needs null placeholders for each child.
					$inner_content = [ '' ]; // Opening content.
					foreach ( $blocks[ $index ]['innerBlocks'] as $child ) {
						$inner_content[] = null; // Placeholder for each child.
					}
					$inner_content[]                  = ''; // Closing content.
					$blocks[ $index ]['innerContent'] = $inner_content;
				} elseif (
					'divi/global-layout' === $blocks[ $index ]['blockName']
					&& ! empty( $block['innerContent'] )
				) {
					$explicit_snapshot_content = implode(
						'',
						array_filter(
							$block['innerContent'],
							static fn( $fragment ): bool => is_string( $fragment )
						)
					);
					$explicit_snapshot_content = '' !== $explicit_snapshot_content ? $explicit_snapshot_content : "\n";

					$blocks[ $index ]['innerHTML']    = $explicit_snapshot_content;
					$blocks[ $index ]['innerContent'] = [ $explicit_snapshot_content ];
				} elseif ( ! empty( $blocks[ $index ]['innerHTML'] ) ) {
					// FIX: For Divi blocks without innerBlocks, clear innerHTML to prevent duplication.
					// When a module's content comes from attributes (not child blocks), WordPress's,
					// serialize_blocks() should not include innerHTML. The innerContent array is used,
					// by WordPress to determine what HTML to include between block comment tags.
					//
					// CONTEXT: For modules like Text, Button, etc., content is stored in attributes,
					// (e.g., content.innerContent.desktop.value), not as actual WordPress innerBlocks.
					// However, when WordPress parses the saved content, it populates the innerHTML,
					// with the rendered HTML. When we re-serialize for global modules, this causes,
					// the content to appear both in attributes AND as innerHTML, leading to,
					// duplication on the frontend.
					//
					// SOLUTION: For Divi blocks without actual innerBlocks, produce a self-closing,
					// block comment without innerHTML. Non-Divi blocks keep their innerHTML as-is.
					$is_divi_block = ! empty( $blocks[ $index ]['blockName'] ) && str_starts_with( $blocks[ $index ]['blockName'], 'divi/' );
					if ( $is_divi_block ) {
						// Self-closing Divi block - clear innerHTML to prevent duplication.
						$blocks[ $index ]['innerContent'] = [];
					} else {
						// Non-Divi block with innerHTML content - keep as-is.
						$blocks[ $index ]['innerContent'] = [ $blocks[ $index ]['innerHTML'] ];
					}
				} else {
					// Self-closing or empty block.
					$blocks[ $index ]['innerContent'] = [];
				}
			}
		}

		return $blocks;
	}
}
