<?php
/**
 * Gutenberg: Admin Asset Loading and Portability
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Gutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Utility\Conditions;
use ET\Builder\VisualBuilder\Assets\AssetsUtility;
use ET\Builder\VisualBuilder\Assets\PackageBuildManager;
use ET\Builder\VisualBuilder\ClassicEditor\ClassicEditor;

/**
 * Gutenberg Admin class.
 *
 * Handles asset loading and portability for Layout Block in the Gutenberg block editor.
 * Instantiated and initialized at the bottom of this file.
 *
 * @since ??
 */
class Admin {
	/**
	 * Resolve editor context post type and post ID.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return array{post_type:string,post_id:int}
	 */
	private function _get_editor_post_context( string $hook_suffix ): array {
		global $typenow, $post;

		$post_type = '';
		$post_id   = 0;

		// phpcs:disable WordPress.Security.NonceVerification -- Reading request params for context detection only.
		if ( ! empty( $typenow ) ) {
			$post_type = $typenow;
		} elseif ( isset( $_GET['post_type'] ) ) {
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		} elseif ( 'post-new.php' === $hook_suffix ) {
			$post_type = 'post';
		}

		if ( isset( $_GET['post'] ) ) {
			$post_id = (int) sanitize_text_field( wp_unslash( $_GET['post'] ) );
		} elseif ( ! empty( $post ) && isset( $post->ID ) ) {
			$post_id = (int) $post->ID;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( '' === $post_type && $post_id > 0 ) {
			$resolved_post_type = get_post_type( $post_id );
			$post_type          = is_string( $resolved_post_type ) ? $resolved_post_type : '';
		}

		return [
			'post_type' => $post_type,
			'post_id'   => $post_id,
		];
	}

	/**
	 * Initialize the class.
	 *
	 * Registers hooks for asset loading and portability.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'load_visual_builder_dependencies' ] );
		add_action( 'admin_init', [ $this, 'register_portability' ] );
		// TinyMCE needs its baseURL normalized before it initializes.
		add_action( 'admin_print_scripts', [ $this, 'normalize_tinymce_base_url_for_backend_editor' ], 1 );
	}

	/**
	 * Enqueue scripts and styles on Gutenberg block editor page.
	 *
	 * Uses PackageBuildManager to enqueue D5 assets (same as old D4 implementation).
	 *
	 * @since ??
	 *
	 * @param string|null $hook_suffix Current admin page hook. May be null in iframe sub-requests (e.g. `iframe_header()`).
	 *
	 * @return void
	 */
	public function load_visual_builder_dependencies( ?string $hook_suffix ): void {
		if ( null === $hook_suffix ) {
			return;
		}

		if ( ! Conditions::is_block_editor() ) {
			return;
		}

		// Restrict D5 Gutenberg dependencies to post editor screens only.
		// Block-editor-powered admin screens (e.g. widgets.php) should not load these assets.
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$editor_context               = $this->_get_editor_post_context( $hook_suffix );
		$post_type                    = $editor_context['post_type'];
		$post_id                      = $editor_context['post_id'];
		$is_builder_enabled_post_type = '' !== $post_type && et_builder_enabled_for_post_type( $post_type );

		if ( ! $is_builder_enabled_post_type ) {
			return;
		}

		// Check if this post actually uses the block editor (not Classic Editor).
		if ( ! empty( $post_id ) && function_exists( 'use_block_editor_for_post' ) ) {
			if ( ! use_block_editor_for_post( $post_id ) ) {
				return;
			}
		} elseif ( function_exists( 'use_block_editor_for_post_type' ) ) {
			if ( ! use_block_editor_for_post_type( $post_type ) ) {
				return;
			}
		}

		// Check if Classic Editor is enabled (either Divi's or WordPress Classic Editor plugin).
		$divi_classic_editor_enabled = ClassicEditor::is_enabled();
		$wp_classic_editor_enabled   = false;
		if ( class_exists( 'Classic_Editor' ) ) {
			$wp_classic_editor_replace_option = get_option( 'classic-editor-replace' );
			$wp_classic_editor_block_settings = [ 'block', 'no-replace' ];
			// If the option value is not set to 'block' or 'no-replace', then the Classic Editor is enabled.
			$wp_classic_editor_enabled = ! in_array( $wp_classic_editor_replace_option, $wp_classic_editor_block_settings, true );
		}
		$classic_editor_enabled = $divi_classic_editor_enabled || $wp_classic_editor_enabled;

		// Enqueue media library script so ETSelect is available in topWindow.
		et_builder_enqueue_assets_head();

		// Register vendor dependencies before PackageBuildManager enqueues scripts.
		AssetsUtility::enqueue_visual_builder_dependencies();

		// When Classic Editor is OFF, ACF Pro needs WordPress's TinyMCE, not Divi's.
		// Register an empty script to satisfy dependencies without loading Divi's TinyMCE.
		// This avoids wpautoresize conflicts on every builder-enabled post type, not only post/page.
		if ( ! $classic_editor_enabled ) {
			// Register empty script to satisfy dependency requirements without actually loading TinyMCE.
			wp_register_script(
				'react-tiny-mce',
				'',
				[],
				ET_BUILDER_VERSION,
				false
			);
		} else {
			// Register Divi's TinyMCE normally for other contexts.
			wp_register_script(
				'react-tiny-mce',
				ET_BUILDER_5_URI . '/visual-builder/assets/tinymce/tinymce.min.js',
				[],
				ET_BUILDER_VERSION,
				false
			);
		}

		// Use PackageBuildManager to enqueue D5 assets.
		PackageBuildManager::register_divi_package_builds();
		PackageBuildManager::enqueue_scripts();
		PackageBuildManager::enqueue_styles();
	}

	/**
	 * Determine whether we need to normalize TinyMCE base URL for the current backend editor request.
	 *
	 * @return bool
	 *
	 * @since ??
	 */
	private function _should_normalize_tinymce_base_url(): bool {
		if ( ! Conditions::is_block_editor() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// We only target the post edit screens (post.php / post-new.php).
		if ( ! $screen || 'post' !== ( $screen->base ?? '' ) ) {
			return false;
		}

		global $pagenow;

		$hook_suffix     = is_string( $pagenow ) ? $pagenow : 'post.php';
		$editor_context  = $this->_get_editor_post_context( $hook_suffix );
		$post_type       = $editor_context['post_type'];
		$post_id         = $editor_context['post_id'];
		$is_builder_type = '' !== $post_type && et_builder_enabled_for_post_type( $post_type );

		if ( ! $is_builder_type ) {
			return false;
		}

		// Check if this post actually uses the block editor (not Classic Editor).
		if ( ! empty( $post_id ) && function_exists( 'use_block_editor_for_post' ) ) {
			if ( ! use_block_editor_for_post( $post_id ) ) {
				return false;
			}
		} elseif ( function_exists( 'use_block_editor_for_post_type' ) ) {
			if ( ! use_block_editor_for_post_type( $post_type ) ) {
				return false;
			}
		}

		// Only apply fix when Classic Editor is disabled.
		$divi_classic_editor_enabled = ClassicEditor::is_enabled();
		$wp_classic_editor_enabled   = false;
		if ( class_exists( 'Classic_Editor' ) ) {
			$wp_classic_editor_replace_option = get_option( 'classic-editor-replace' );
			$wp_classic_editor_block_settings = [ 'block', 'no-replace' ];
			$wp_classic_editor_enabled        = ! in_array(
				$wp_classic_editor_replace_option,
				$wp_classic_editor_block_settings,
				true
			);
		}
		$classic_editor_enabled = $divi_classic_editor_enabled || $wp_classic_editor_enabled;

		return ! $classic_editor_enabled;
	}

	/**
	 * Normalize TinyMCE base URL to WordPress core so wpautoresize (and similar) resolve correctly.
	 *
	 * This must run before TinyMCE initializes, which is why it uses `admin_print_scripts` with early priority.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function normalize_tinymce_base_url_for_backend_editor(): void {
		if ( ! $this->_should_normalize_tinymce_base_url() ) {
			return;
		}

		$wp_tinymce_base = includes_url( 'js/tinymce' );

		?>
		<script>
		(function () {
			try {
				if (window.tinyMCEPreInit) {
					window.tinyMCEPreInit.baseURL = "<?php echo esc_url( $wp_tinymce_base ); ?>";
				}

				if (window.tinymce) {
					window.tinymce.baseURL = "<?php echo esc_url( $wp_tinymce_base ); ?>";
				}
			} catch (e) {}
		})();
		</script>
		<?php
	}

	/**
	 * Register portability functionality for Layout Block.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function register_portability(): void {
		// Register portability only if not already registered.
		if ( ! et_core_is_builder_used_on_current_request() ) {
			return;
		}

		et_core_portability_link(
			'et_builder',
			[
				'name' => esc_html__( 'Divi Builder', 'et_builder_5' ),
				'view' => ! is_customize_preview(),
			]
		);
	}
}

// Instantiate and initialize.
$admin = new Admin();
$admin->initialize();
