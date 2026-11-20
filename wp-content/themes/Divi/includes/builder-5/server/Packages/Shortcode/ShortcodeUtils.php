<?php
/**
 * Shortcode: ShortcodeUtils class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\Shortcode;

use ET\Builder\Packages\Module\Layout\Components\DynamicContent\DynamicContentUtils;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * ShortcodeUtils class.
 *
 * This class provides utility methods for handling shortcodes.
 *
 * @since ??
 */
class ShortcodeUtils {

	/**
	 * Original callbacks for wrapped shortcode tags.
	 *
	 * @since ??
	 *
	 * @var array
	 */
	private static $_wrapped_shortcode_callbacks = [];

	/**
	 * Wrap depth counter for nested Theme Builder renders.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	private static $_wrap_depth = 0;

	/**
	 * Stack of post IDs set by shortcode_wrapper context overrides.
	 *
	 * Each entry is the $main_post_id that shortcode_wrapper switched $post to.
	 * Used to detect when a third-party loop (e.g. Toolset Views) changes $post
	 * to a loop-item post during a nested shortcode execution so the wrapper does
	 * not override that loop-item context with the main page post.
	 *
	 * @since ??
	 *
	 * @var array<int, int>
	 */
	private static $_active_context_post_id_stack = [];

	/**
	 * Wrap all registered shortcodes with Theme Builder context handling.
	 *
	 * Called lazily from Layout::render() when Theme Builder is actually rendering.
	 * Wraps third-party shortcodes to automatically fix the $post context during rendering.
	 *
	 * Excludes Divi's internal shortcodes (et_pb_*) to avoid regressions.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function wrap_shortcodes_for_theme_builder(): void {
		global $shortcode_tags;

		// Support nested renders: only perform the actual wrapping once.
		if ( self::$_wrap_depth > 0 ) {
			++self::$_wrap_depth;
			return;
		}

		self::$_wrap_depth = 1;

		// Wrap each third-party shortcode with our Theme Builder-aware wrapper.
		// Skip Divi's internal shortcodes (et_pb_*) to avoid regressions.
		foreach ( $shortcode_tags as $tag => $callback ) {
			// Skip Divi shortcodes - they may intentionally use layout post context.
			if ( str_starts_with( $tag, 'et_pb_' ) ) {
				continue;
			}

			// Skip lazy placeholders during initial wrap. They are wrapped later,
			// after shortcode manager resolves real callbacks at parse-time.
			if ( self::_is_empty_placeholder_callback( $callback ) ) {
				continue;
			}

			// Store original callback for this wrapped shortcode only.
			self::$_wrapped_shortcode_callbacks[ $tag ] = $callback;

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily wrapping shortcodes for Theme Builder context fix.
			$shortcode_tags[ $tag ] = [ self::class, 'shortcode_wrapper' ];
		}
	}

	/**
	 * Wrap a resolved shortcode callback while Theme Builder scope is active.
	 *
	 * Lazy placeholder shortcodes are skipped during initial wrap pass so shortcode
	 * manager can resolve their real callbacks first. This method is called from
	 * shortcode manager pre-do-shortcode boundary after resolution.
	 *
	 * @since ??
	 *
	 * @param string $tag Shortcode tag.
	 *
	 * @return void
	 */
	public static function maybe_wrap_resolved_shortcode_for_theme_builder( string $tag ): void {
		global $shortcode_tags;

		// Only wrap during active Theme Builder wrapping scope.
		if ( self::$_wrap_depth <= 0 ) {
			return;
		}

		if ( '' === $tag || str_starts_with( $tag, 'et_pb_' ) ) {
			return;
		}

		$current_callback = $shortcode_tags[ $tag ] ?? null;
		if ( ! $current_callback ) {
			return;
		}

		// Already wrapped.
		if ( [ self::class, 'shortcode_wrapper' ] === $current_callback ) {
			return;
		}

		// Keep placeholders untouched until they are resolved.
		if ( self::_is_empty_placeholder_callback( $current_callback ) ) {
			return;
		}

		// Store original callback and wrap.
		self::$_wrapped_shortcode_callbacks[ $tag ] = $current_callback;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily wrapping shortcodes for Theme Builder context fix.
		$shortcode_tags[ $tag ] = [ self::class, 'shortcode_wrapper' ];
	}

	/**
	 * Restore original shortcode callbacks after Theme Builder rendering.
	 *
	 * Called from Layout::render() after content is rendered to unwrap shortcodes.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public static function unwrap_shortcodes_for_theme_builder(): void {
		global $shortcode_tags;

		if ( self::$_wrap_depth <= 0 ) {
			return;
		}

		--self::$_wrap_depth;

		// If still nested, defer restoration to the outermost unwrap.
		if ( self::$_wrap_depth > 0 ) {
			return;
		}

		// Restore only the shortcodes we wrapped to avoid clobbering runtime updates.
		if ( ! empty( self::$_wrapped_shortcode_callbacks ) ) {
			foreach ( self::$_wrapped_shortcode_callbacks as $tag => $callback ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring wrapped shortcode callback.
				$shortcode_tags[ $tag ] = $callback;
			}

			self::$_wrapped_shortcode_callbacks = [];
		}

		// Safety reset: the stack should always be empty after all shortcode executions
		// complete, but clear it explicitly in case of unexpected code paths.
		self::$_active_context_post_id_stack = [];
	}

	/**
	 * Wrapper function for third-party shortcodes that fixes Theme Builder context.
	 *
	 * This wrapper is only registered for non-Divi shortcodes. It ensures they
	 * execute with the correct post context by temporarily replacing the global
	 * $post with the actual displayed post instead of the Theme Builder layout post.
	 *
	 * @since ??
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Shortcode content.
	 * @param string       $tag     Shortcode tag.
	 *
	 * @return string|mixed Shortcode output with correct Theme Builder context.
	 */
	public static function shortcode_wrapper( $atts, $content = null, $tag = '' ) {
		// Get original callback.
		$original_callback = self::$_wrapped_shortcode_callbacks[ $tag ] ?? null;

		if ( ! $original_callback || ! is_callable( $original_callback ) ) {
			return '';
		}

		// Get the actual displayed post ID.
		$main_post_id    = class_exists( '\ET_Post_Stack' ) ? \ET_Post_Stack::get_main_post_id() : 0;
		$current_post_id = get_the_ID();

		// Get the actual displayed post object.
		$main_post = 0 < $main_post_id ? get_post( $main_post_id ) : null;

		// Hard skips: context is already correct or managed by another mechanism.
		if (
			! $main_post
			|| DynamicContentUtils::has_active_loop_post_context()
			|| self::_has_active_woocommerce_product_loop_context()
			|| self::_has_active_third_party_loop_post_context()
		) {
			return call_user_func( $original_callback, $atts, $content, $tag );
		}

		// IDs already match (Post Content has already set $post to the displayed page).
		// Push the main post ID so nested loop-item shortcodes are detected as third-party
		// loop context instead of being rewritten back to the main page (#47171).
		if ( $current_post_id === $main_post_id ) {
			self::$_active_context_post_id_stack[] = $main_post_id;

			try {
				return self::_invoke_original_callback_capturing_echo( $original_callback, $atts, $content, $tag );
			} finally {
				array_pop( self::$_active_context_post_id_stack );
			}
		}

		global $post;

		$original_post = $post;

		self::$_active_context_post_id_stack[] = $main_post_id;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Necessary for Theme Builder shortcode context.
		$post = $main_post;

		setup_postdata( $main_post );

		try {
			$output = call_user_func( $original_callback, $atts, $content, $tag );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original state.
			$post = $original_post;

			wp_reset_postdata();

			array_pop( self::$_active_context_post_id_stack );
		}

		return $output;
	}

	/**
	 * Invoke a wrapped shortcode callback and capture echoed output.
	 *
	 * Used only on the IDs-match path (#47171). Toolset Views `[wpv-view]` echoes HTML
	 * instead of returning it. Post Content captures `the_content()` in an output buffer,
	 * so uncaptured echoes land outside the shortcode position.
	 *
	 * @since ??
	 *
	 * @param callable     $original_callback Original shortcode callback.
	 * @param array|string $atts              Shortcode attributes.
	 * @param string|null  $content           Shortcode content.
	 * @param string       $tag               Shortcode tag.
	 *
	 * @return string Combined echoed and returned shortcode output.
	 */
	private static function _invoke_original_callback_capturing_echo( $original_callback, $atts, $content, $tag ): string {
		$ob_opened   = ob_start();
		$ob_consumed = false;

		try {
			$returned = call_user_func( $original_callback, $atts, $content, $tag );
			$echoed   = '';

			if ( $ob_opened ) {
				$echoed      = ob_get_clean();
				$ob_consumed = true;
			}

			if ( false === $echoed ) {
				$echoed = '';
			}

			return $echoed . (string) $returned;
		} finally {
			if ( $ob_opened && ! $ob_consumed ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Whether the current $post was changed by a third-party loop during active wrapper scope.
	 *
	 * Returns true when an outer `shortcode_wrapper()` call has already set $post to the
	 * main displayed post (recorded in `$_active_context_post_id_stack`) and the current
	 * global $post now differs from that value. The discrepancy indicates a third-party
	 * loop (e.g. Toolset Views) has overridden $post to a loop-item post between the outer
	 * and inner shortcode executions. Trusting that loop context prevents the wrapper from
	 * overwriting it with the main page post and resolving custom fields against the wrong post.
	 *
	 * @since ??
	 *
	 * @return bool True when inside a third-party loop that changed $post from the outer wrapper context.
	 */
	private static function _has_active_third_party_loop_post_context(): bool {
		if ( empty( self::$_active_context_post_id_stack ) ) {
			return false;
		}

		return get_the_ID() !== end( self::$_active_context_post_id_stack );
	}

	/**
	 * Whether the current post is already an intentional WooCommerce product loop item.
	 *
	 * Used by `shortcode_wrapper()` to avoid overriding the loop product with
	 * `ET_Post_Stack::get_main_post()` when nested third-party shortcodes run mid-loop
	 * (e.g. wishlist/compare buttons inside `[products]`).
	 *
	 * Reads `$GLOBALS['woocommerce_loop']` directly instead of `wc_get_loop_prop()` so
	 * this check does not initialize a default loop via `wc_setup_loop()`.
	 *
	 * @since ??
	 *
	 * @return bool True when inside a WC shortcode/named product loop on a product post.
	 */
	private static function _has_active_woocommerce_product_loop_context(): bool {
		if ( empty( $GLOBALS['woocommerce_loop'] ) || ! is_array( $GLOBALS['woocommerce_loop'] ) ) {
			return false;
		}

		$is_shortcode_loop = ! empty( $GLOBALS['woocommerce_loop']['is_shortcode'] );
		$loop_name         = $GLOBALS['woocommerce_loop']['name'] ?? '';

		// Shortcode loops ([products]) and named loops (related, up-sells, cross-sells).
		// Singular product context (`is_product`) alone must not match — TB shortcodes on
		// single-product templates still need main-post correction (#41239).
		if ( ! $is_shortcode_loop && '' === $loop_name ) {
			return false;
		}

		$current_post_id = get_the_ID();

		return 0 < $current_post_id && 'product' === get_post_type( $current_post_id );
	}

	/**
	 * Determine whether callback is placeholder callback.
	 *
	 * @since ??
	 *
	 * @param mixed $callback Shortcode callback.
	 *
	 * @return bool
	 */
	private static function _is_empty_placeholder_callback( $callback ): bool {
		return '__return_empty_string' === $callback;
	}

	/**
	 * Get processed `embed` shortcode if the content has `embed` shortcode.
	 *
	 * This function checks if the provided content contains the `[embed][/embed]` shortcode and
	 * processes it using `$wp_embed->run_shortcode` from the global `$wp_embed` object.
	 *
	 * @since ??
	 *
	 * @param string $content Content to search for shortcodes.
	 *
	 * @return string Content with processed embed shortcode.
	 *
	 * @example:
	 * ```php
	 * $content = '[embed]http://www.wordpress.test/watch?v=embed-shortcode[/embed]';
	 * $processedContent = ShortcodeUtils::get_processed_embed_shortcode( $content );
	 * echo $processedContent;
	 *
	 * // Output: <a href="http://www.wordpress.test/watch?v=embed-shortcode">http://www.wordpress.test/watch?v=embed-shortcode</a>
	 * ```
	 */
	public static function get_processed_embed_shortcode( string $content ): string {
		if ( has_shortcode( $content, 'embed' ) ) {
			global $wp_embed;
			$content = $wp_embed->run_shortcode( $content );
		}

		return $content;
	}

	/**
	 * Get content with WordPress auto-embed applied.
	 *
	 * Converts bare oEmbed URLs that sit on their own line or as the sole text of a
	 * paragraph into embed HTML via `$wp_embed->autoembed()`. Sole-paragraph autolinks
	 * whose href is a known oEmbed provider are unwrapped first so TinyMCE Visual paste
	 * matches D4. Mid-sentence URLs, custom-text links, and URLs inside other HTML tags
	 * are left unchanged.
	 *
	 * @since ??
	 *
	 * @param string $content Content that may contain bare oEmbed URLs.
	 *
	 * @return string Content with auto-embedded URLs converted where applicable.
	 *
	 * @example:
	 * ```php
	 * $content = '<p>https://www.youtube.com/watch?v=l2QxKrw3dhE</p>';
	 * $processed_content = ShortcodeUtils::get_processed_autoembed( $content );
	 * ```
	 */
	public static function get_processed_autoembed( string $content ): string {
		global $wp_embed;

		if ( $wp_embed ) {
			// Unwrap TinyMCE autolinked own-line oEmbed URLs so autoembed can see them.
			$content = self::maybe_unwrap_sole_oembed_links( $content );
			$content = $wp_embed->autoembed( $content );
		}

		return $content;
	}

	/**
	 * Unwrap a paragraph that contains only an oEmbed URL link.
	 *
	 * TinyMCE `autolink` / HTML clipboard paste stores Visual-mode URLs as
	 * `<p><a href="URL">URL</a></p>`. `WP_Embed::autoembed()` skips URLs inside tags,
	 * so those would never convert. Only known oEmbed providers are unwrapped
	 * (`discover` is off, no HTTP). Custom anchor text, nested tags, and extra
	 * paragraph siblings are left alone. Paragraph attributes are kept.
	 *
	 * @since ??
	 *
	 * @param string $content Content that may contain autolinked oEmbed URLs.
	 *
	 * @return string Content with sole oEmbed links reduced to bare URLs.
	 */
	private static function maybe_unwrap_sole_oembed_links( string $content ): string {
		if ( false === stripos( $content, '<a ' ) ) {
			return $content;
		}

		// Href stops at the attribute quotes. Inner text cannot contain tags.
		// Regex101 link: https://regex101.com/r/V7ustO/1.
		$unwrapped = preg_replace_callback(
			'!<p(\s[^>]*)?>\s*<a\s[^>]*href=([\'"])(https?://[^\'"]+)\2[^>]*>\s*([^<]*?)\s*</a>\s*</p>!i',
			[ self::class, 'maybe_unwrap_sole_oembed_link_callback' ],
			$content
		);

		return is_string( $unwrapped ) ? $unwrapped : $content;
	}

	/**
	 * Callback for `maybe_unwrap_sole_oembed_links()`.
	 *
	 * @since ??
	 *
	 * @param array $matches Regex matches: 1 = p attrs, 3 = href, 4 = inner text.
	 *
	 * @return string Bare-URL paragraph when the href is an oEmbed provider, otherwise the original HTML.
	 */
	private static function maybe_unwrap_sole_oembed_link_callback( array $matches ): string {
		$decoded_href  = html_entity_decode( $matches[3], ENT_QUOTES, 'UTF-8' );
		$decoded_inner = html_entity_decode( trim( $matches[4] ), ENT_QUOTES, 'UTF-8' );
		$safe_url      = self::get_safe_oembed_href( $decoded_href );

		if ( '' === $safe_url || ( $decoded_inner !== $decoded_href && $decoded_inner !== $safe_url ) ) {
			return $matches[0];
		}

		$oembed   = _wp_oembed_get_object();
		$provider = $oembed->get_provider( $safe_url, [ 'discover' => false ] );

		if ( ! $provider ) {
			return $matches[0];
		}

		return '<p' . ( $matches[1] ?? '' ) . '>' . esc_html( $safe_url ) . '</p>';
	}

	/**
	 * Return an http(s) URL safe to match against oEmbed providers and emit as text.
	 *
	 * @since ??
	 *
	 * @param string $href Decoded href attribute value.
	 *
	 * @return string Sanitized URL, or empty string when the href is not a plain URL.
	 */
	private static function get_safe_oembed_href( string $href ): string {
		$href = trim( $href );

		if ( $href !== wp_strip_all_tags( $href ) ) {
			return '';
		}

		// Regex101 link: https://regex101.com/r/SpHVFH/1.
		if ( 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $href ) ) {
			return '';
		}

		return esc_url_raw( $href, [ 'http', 'https' ] );
	}
}
