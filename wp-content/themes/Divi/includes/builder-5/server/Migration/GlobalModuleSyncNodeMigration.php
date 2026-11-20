<?php
/**
 * Global Module Sync Node Migration
 *
 * Adds persistent identities to synchronized template nodes and initializes explicit
 * local attribute ownership on legacy root global module instance snapshots.
 *
 * Released legacy content supported selective sync only on the root global module.
 * Nested selective sync is introduced by the current format, so this migration must
 * never infer ownership, override paths, or instance identities from legacy child
 * snapshots. Nested template traversal exists only to add the stable syncNodeId values
 * required by current-format nested selective sync.
 *
 * @since ??
 *
 * @package Divi
 */

namespace ET\Builder\Migration;

use ET\Builder\Framework\Utility\Conditions;
use ET\Builder\Framework\Utility\StringUtility;
use ET\Builder\Migration\MigrationContentBase;
use ET\Builder\Migration\MigrationContext;
use ET\Builder\Migration\Utils\MigrationUtils;
use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use ET\Builder\Packages\ModuleUtils\ComponentLocalAttrsUtils;

/**
 * Global Module Sync Node Migration class.
 *
 * @since ??
 */
class GlobalModuleSyncNodeMigration extends MigrationContentBase {

	/**
	 * Migration name.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	private static $_name = 'global-module-sync-nodes.v1';

	/**
	 * Migration release version.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	private static $_release_version = '5.15.0';

	/**
	 * Request-scoped migrated global template content.
	 *
	 * @var array<int, array{contentHash: string, isGlobalTemplate: bool, content: string}>
	 */
	private static $_migrated_global_template_content = [];

	/**
	 * Register migration hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function load(): void {
		add_filter( 'divi_framework_portability_import_migrated_post_content', [ __CLASS__, 'migrate_import_content' ] );
		add_action( 'wp', [ __CLASS__, 'migrate_fe_content' ] );
		add_filter( 'et_fb_load_raw_post_content', [ __CLASS__, 'migrate_vb_content' ], 10, 2 );
	}

	/**
	 * Get the migration name.
	 *
	 * @since ??
	 *
	 * @return string
	 */
	public static function get_name(): string {
		return self::$_name;
	}

	/**
	 * Get the migration release version.
	 *
	 * @since ??
	 *
	 * @return string
	 */
	public static function get_release_version(): string {
		return self::$_release_version;
	}

	/**
	 * Migrate global template content once per unchanged classification in a request.
	 *
	 * @param string $content Template post content.
	 * @param int    $post_id Template post ID.
	 *
	 * @return string Migrated template content.
	 */
	public static function migrate_global_template_content( string $content, int $post_id ): string {
		$content_hash       = md5( $content );
		$is_global_template = GlobalLayout::is_global_layout_template( $post_id );
		$cached             = self::$_migrated_global_template_content[ $post_id ] ?? null;

		if (
			is_array( $cached )
			&& ( $cached['contentHash'] ?? '' ) === $content_hash
			&& ( $cached['isGlobalTemplate'] ?? null ) === $is_global_template
		) {
			return $cached['content'];
		}

		$migrated_content = Migration::get_instance()->migrate_content_for_post( $content, $post_id );

		self::$_migrated_global_template_content[ $post_id ] = [
			'contentHash'      => $content_hash,
			'isGlobalTemplate' => $is_global_template,
			'content'          => $migrated_content,
		];

		return $migrated_content;
	}

	/**
	 * Shortcode content has no synchronized node identity attributes.
	 *
	 * @since ??
	 *
	 * @param string $content Shortcode content.
	 *
	 * @return string
	 */
	public static function migrate_content_shortcode( string $content ): string {
		return $content;
	}

	/**
	 * Leave imported references unresolved against coincidental local template IDs.
	 *
	 * @since ??
	 *
	 * @param mixed $content Imported block content.
	 *
	 * @return string Migrated content.
	 */
	public static function migrate_import_content( $content ): string {
		return is_string( $content ) ? self::_migrate_block_content( $content, 0, false ) : '';
	}

	/**
	 * Register frontend migration before page and Theme Builder content is rendered.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function migrate_fe_content(): void {
		if ( ! Conditions::is_fe_and_should_migrate_content() ) {
			return;
		}

		if ( MigrationUtils::get_current_content() ) {
			add_filter(
				'the_content',
				static function ( $content ) {
					$post_id = get_the_ID();

					return is_string( $content )
						? self::_migrate_block_content( $content, false !== $post_id ? (int) $post_id : 0 )
						: '';
				},
				8
			);
		}

		if ( false === has_filter( 'et_builder_render_layout', [ __CLASS__, 'migrate_rendered_layout_content' ] ) ) {
			add_filter( 'et_builder_render_layout', [ __CLASS__, 'migrate_rendered_layout_content' ], 8 );
		}
	}

	/**
	 * Migrate Theme Builder layout content before rendering.
	 *
	 * @since ??
	 *
	 * @param mixed $content Theme Builder layout content.
	 *
	 * @return string Migrated content.
	 */
	public static function migrate_rendered_layout_content( $content ): string {
		return is_string( $content ) ? self::_migrate_block_content( $content, 0 ) : '';
	}

	/**
	 * Migrate global module templates and page-side instance snapshots.
	 *
	 * @since ??
	 *
	 * @param string $content Block content.
	 *
	 * @return string
	 */
	public static function migrate_content_block( string $content ): string {
		$post_id = get_the_ID();

		return self::_migrate_block_content( $content, false !== $post_id ? (int) $post_id : 0 );
	}

	/**
	 * Migrate block content with an explicit post context.
	 *
	 * @since ??
	 *
	 * @param string $content Block content.
	 * @param int    $post_id Post ID.
	 *
	 * @return string Migrated content.
	 */
	public static function migrate_content_block_for_post( string $content, int $post_id ): string {
		return self::_migrate_block_content( $content, $post_id );
	}

	/**
	 * Migrate a global module template loaded by the Visual Builder.
	 *
	 * @since ??
	 *
	 * @param string $content Block content.
	 * @param int    $post_id Post ID.
	 *
	 * @return string
	 */
	public static function migrate_vb_content( string $content, int $post_id ): string {
		return self::_migrate_block_content( $content, $post_id );
	}

	/**
	 * Add template identities or initialize explicit ownership on legacy instances.
	 *
	 * @since ??
	 *
	 * @param string $content           Block content.
	 * @param int    $post_id           Current post ID, when available.
	 * @param bool   $migrate_instances Whether local template references may be resolved.
	 *
	 * @return string
	 */
	private static function _migrate_block_content(
		string $content,
		int $post_id,
		bool $migrate_instances = true
	): string {
		if ( ! self::has_divi_block( $content ) ) {
			return $content;
		}

		$is_global_template  = GlobalLayout::is_global_layout_template( $post_id );
		$has_global_instance = str_contains( $content, 'wp:divi/global-layout' );

		if (
			( ! $is_global_template && ! $migrate_instances )
			|| ( ! $is_global_template && ! $has_global_instance )
		) {
			return $content;
		}

		/*
		 * IMPORTANT: This is a document-level migration gate for both templates and pages.
		 * One module at or above this release version proves that the complete persisted
		 * document already ran this migration. Do not limit this check to global templates
		 * or replace it with per-instance migration: doing so needlessly reparses pages that
		 * are already current and breaks the migration framework's document-level contract.
		 *
		 * Unresolvable page-side instances are intentionally left unchanged. They must not
		 * force the page to migrate again; the runtime missing-key fallback infers their
		 * legacy ownership if the referenced Component later becomes available.
		 */
		if ( ! MigrationUtils::content_needs_migration( $content, self::$_release_version ) ) {
			return $content;
		}

		$original_content = $content;
		$content          = MigrationUtils::ensure_placeholder_wrapper( $content );

		$migration_was_active = MigrationContext::is_active();
		MigrationContext::start();

		try {
			$flat_objects = MigrationUtils::parse_serialized_post_into_flat_module_object( $content, self::$_name );
			$changes_made = $is_global_template
				? self::_migrate_template_modules( $flat_objects, $post_id )
				: self::_migrate_global_module_instances( $flat_objects );

			return $changes_made
				? MigrationUtils::serialize_flat_objects( $flat_objects )
				: $original_content;
		} finally {
			if ( ! $migration_was_active ) {
				MigrationContext::end();
			}
		}
	}

	/**
	 * Determine whether a module predates this migration.
	 *
	 * @since ??
	 *
	 * @param mixed $builder_version Module Builder version.
	 *
	 * @return bool Whether the module requires migration.
	 */
	private static function _is_below_release_version( $builder_version ): bool {
		return ! is_string( $builder_version )
			|| StringUtility::version_compare( $builder_version, self::$_release_version, '<' );
	}

	/**
	 * Add synchronized node identities to a global module template.
	 *
	 * @since ??
	 *
	 * @param array $flat_objects Flat template module objects.
	 * @param int   $post_id      Global module template post ID.
	 *
	 * @return bool Whether the template changed.
	 */
	private static function _migrate_template_modules( array &$flat_objects, int $post_id ): bool {
		$root_children = $flat_objects['root']['children'] ?? [];

		if ( ! is_array( $root_children ) ) {
			return false;
		}

		$changes_made       = false;
		$seen_sync_node_ids = [];
		$visited_module_ids = [];

		foreach ( $root_children as $root_index => $module_id ) {
			if ( ! is_string( $module_id ) ) {
				continue;
			}

			$changes_made = self::_migrate_module(
				$flat_objects,
				$module_id,
				true,
				$post_id,
				(string) $root_index,
				$seen_sync_node_ids,
				$visited_module_ids
			) || $changes_made;
		}

		return $changes_made;
	}

	/**
	 * Initialize explicit ownership metadata on root global module instance snapshots.
	 *
	 * Legacy instances stored root selective-sync values in `localAttrs`, but nested
	 * instance modules did not support selective sync. Only the `divi/global-layout`
	 * wrapper may therefore gain inferred `localAttrOverridePaths`. Do not recurse into
	 * its child snapshot: nested ownership must originate from current-format edits,
	 * where it is serialized explicitly.
	 *
	 * @since ??
	 *
	 * @param array $flat_objects Flat page module objects.
	 *
	 * @return bool Whether any instance changed.
	 */
	private static function _migrate_global_module_instances( array &$flat_objects ): bool {
		$changes_made = false;

		foreach ( $flat_objects as $module_id => $module ) {
			if ( 'divi/global-layout' !== ( $module['name'] ?? '' ) ) {
				continue;
			}

			$instance_attrs = $module['props']['attrs'] ?? [];
			if ( ! is_array( $instance_attrs ) ) {
				continue;
			}

			if ( ! self::_is_below_release_version( $instance_attrs['builderVersion'] ?? null ) ) {
				continue;
			}

			if ( array_key_exists( 'localAttrOverridePaths', $instance_attrs ) ) {
				$flat_objects[ $module_id ]['props']['attrs']['builderVersion'] = self::$_release_version;
				$changes_made = true;
				continue;
			}

			$global_module_id = $module['props']['attrs']['globalModule'] ?? null;
			if ( ! is_numeric( $global_module_id ) ) {
				continue;
			}

			$template_root = self::_get_global_template_root_block( (int) $global_module_id );
			if ( null === $template_root ) {
				continue;
			}

			$template_attrs = is_array( $template_root['attrs'] ?? null ) ? $template_root['attrs'] : [];

			$local_attrs = is_array( $instance_attrs['localAttrs'] ?? null )
				? $instance_attrs['localAttrs']
				: [];

			$flat_objects[ $module_id ]['props']['attrs']['localAttrOverridePaths'] =
				ComponentLocalAttrsUtils::get_local_attr_override_paths(
					$local_attrs,
					$template_attrs['localAttrsMap'] ?? []
				);
			$flat_objects[ $module_id ]['props']['attrs']['builderVersion']         = self::$_release_version;
			$changes_made = true;

			/*
			 * Intentionally stop at the wrapper. Released legacy content had no nested
			 * selective-sync ownership to recover from its complete child snapshot.
			 */
		}

		return $changes_made;
	}

	/**
	 * Get the root module block for a global module template.
	 *
	 * @since ??
	 *
	 * @param int $post_id Global module template post ID.
	 *
	 * @return array|null Root module block.
	 */
	private static function _get_global_template_root_block( int $post_id ): ?array {
		static $template_roots = [];

		$post = get_post( $post_id );
		if (
			! $post
			|| ! isset( $post->post_content )
			|| ! is_string( $post->post_content )
			|| ET_BUILDER_LAYOUT_POST_TYPE !== $post->post_type
			|| ! GlobalLayout::is_global_layout_template( $post_id )
		) {
			return null;
		}

		$content_hash = md5( $post->post_content );
		$cached_root  = $template_roots[ $post_id ] ?? null;

		if (
			is_array( $cached_root )
			&& ( $cached_root['contentHash'] ?? null ) === $content_hash
		) {
			return is_array( $cached_root['root'] ?? null ) ? $cached_root['root'] : null;
		}

		$migrated_content = self::migrate_global_template_content( $post->post_content, $post_id );
		$blocks           = self::_filter_module_blocks(
			parse_blocks( MigrationUtils::ensure_placeholder_wrapper( $migrated_content ) )
		);
		$root             = self::_find_template_root_block( $blocks );

		$template_roots[ $post_id ] = [
			'contentHash' => $content_hash,
			'root'        => is_array( $root ) ? $root : null,
		];

		return $template_roots[ $post_id ]['root'];
	}

	/**
	 * Find the first concrete module beneath optional placeholder blocks.
	 *
	 * @since ??
	 *
	 * @param array $blocks Parsed blocks.
	 *
	 * @return array|null Concrete template root block.
	 */
	private static function _find_template_root_block( array $blocks ): ?array {
		foreach ( self::_filter_module_blocks( $blocks ) as $block ) {
			if ( 'divi/placeholder' !== ( $block['blockName'] ?? '' ) ) {
				return $block;
			}

			$root = self::_find_template_root_block( $block['innerBlocks'] ?? [] );
			if ( null !== $root ) {
				return $root;
			}
		}

		return null;
	}

	/**
	 * Remove whitespace and freeform entries from parsed block children.
	 *
	 * @since ??
	 *
	 * @param array $blocks Parsed blocks.
	 *
	 * @return array Module blocks.
	 */
	private static function _filter_module_blocks( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static function ( $block ): bool {
					return is_array( $block ) && is_string( $block['blockName'] ?? null );
				}
			)
		);
	}

	/**
	 * Recursively add identities to one synchronized template subtree.
	 *
	 * Recursion is required because current-format nested selective sync addresses
	 * template nodes by `syncNodeId`. It does not imply that legacy instance children
	 * had local attributes.
	 *
	 * @since ??
	 *
	 * @param array  $flat_objects         Flat module objects.
	 * @param string $module_id            Current module ID.
	 * @param bool   $is_template_root     Whether this is the global template root.
	 * @param int    $post_id              Global module template post ID.
	 * @param string $module_path          Stable structural path before migration.
	 * @param array  $seen_sync_node_ids   Previously used persistent identities.
	 * @param array  $visited_module_ids   Previously visited flat module IDs.
	 *
	 * @return bool Whether any identity was added.
	 */
	private static function _migrate_module(
		array &$flat_objects,
		string $module_id,
		bool $is_template_root,
		int $post_id,
		string $module_path,
		array &$seen_sync_node_ids,
		array &$visited_module_ids
	): bool {
		if ( isset( $visited_module_ids[ $module_id ] ) || ! isset( $flat_objects[ $module_id ] ) ) {
			return false;
		}

		$visited_module_ids[ $module_id ] = true;
		$module_name                      = $flat_objects[ $module_id ]['name'] ?? '';

		if ( 'divi/placeholder' === $module_name ) {
			$is_template_root = true;
		} elseif ( 'divi/global-slot' === $module_name ) {
			return false;
		}

		$changes_made           = false;
		$module_attrs           = is_array( $flat_objects[ $module_id ]['props']['attrs'] ?? null )
			? $flat_objects[ $module_id ]['props']['attrs']
			: [];
		$module_needs_migration = 'divi/placeholder' !== $module_name
			&& self::_is_below_release_version( $module_attrs['builderVersion'] ?? null );

		if (
			'divi/placeholder' !== $module_name
			&& $module_needs_migration
		) {
			$flat_objects[ $module_id ]['props']['attrs']['builderVersion'] = self::$_release_version;
			$changes_made = true;
		}

		if ( ! $is_template_root ) {
			$sync_node_id = $flat_objects[ $module_id ]['props']['attrs']['syncNodeId'] ?? '';

			if (
				$module_needs_migration
				&& (
					! is_string( $sync_node_id )
					|| '' === $sync_node_id
					|| isset( $seen_sync_node_ids[ $sync_node_id ] )
				)
			) {
				$sync_node_id = self::_generate_sync_node_id( $post_id, $module_path );
				$flat_objects[ $module_id ]['props']['attrs']['syncNodeId'] = $sync_node_id;
				$changes_made = true;
			}

			if ( is_string( $sync_node_id ) && '' !== $sync_node_id ) {
				$seen_sync_node_ids[ $sync_node_id ] = true;
			}
		}

		$local_children = $flat_objects[ $module_id ]['props']['attrs']['localChildren'] ?? false;
		if ( true === $local_children ) {
			return $changes_made;
		}

		$children = $flat_objects[ $module_id ]['children'] ?? [];
		if ( ! is_array( $children ) ) {
			return $changes_made;
		}

		foreach ( $children as $child_index => $child_id ) {
			if ( ! is_string( $child_id ) ) {
				continue;
			}

			$changes_made = self::_migrate_module(
				$flat_objects,
				$child_id,
				'divi/placeholder' === $module_name ? true : false,
				$post_id,
				$module_path . '.' . $child_index,
				$seen_sync_node_ids,
				$visited_module_ids
			) || $changes_made;
		}

		return $changes_made;
	}

	/**
	 * Generate a deterministic identity for an existing synchronized node.
	 *
	 * Deterministic migration IDs remain stable until migrated content is saved.
	 *
	 * @since ??
	 *
	 * @param int    $post_id     Global module template post ID.
	 * @param string $module_path Stable structural path before migration.
	 *
	 * @return string
	 */
	private static function _generate_sync_node_id( int $post_id, string $module_path ): string {
		$hash = hash( 'sha256', 'divi-global-sync-node:' . $post_id . ':' . $module_path );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 12, 4 ),
			substr( $hash, 16, 4 ),
			substr( $hash, 20, 12 )
		);
	}
}
