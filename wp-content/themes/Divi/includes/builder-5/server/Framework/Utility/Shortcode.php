<?php
/**
 * Shortcode class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Framework\Utility;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Shortcode class.
 *
 * @since ??
 */
class Shortcode {

	/**
	 * Regex fragment matching a shortcode tag name.
	 *
	 * Excludes the characters `add_shortcode()` forbids (`<>&/[]=`, whitespace, and control characters),
	 * plus quotes so JSON string arrays such as `["a b"]` are never read as shortcodes.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	private const TAG_NAME_PATTERN = '[^"\'<>&\/\[\]=\x00-\x20]+';

	/**
	 * Regex pattern to match shortcode opening tags that have attributes.
	 *
	 * Mirrors the tag and attribute boundaries of `get_shortcode_regex()`: attribute text cannot contain `]`,
	 * and may contain `/` only when it is not followed by `]`. Closing tags and tags without attributes never match.
	 *
	 * Groups: 1 optional escape `[`, 2 tag name, 3 attribute text (with leading whitespace),
	 * 4 self-closing `/`, 5 optional escape `]`.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	public const OPENING_TAG_WITH_ATTRIBUTES_REGEX = '/(\[?)\[(' . self::TAG_NAME_PATTERN . ')(\s(?:[^\]\/]|\/(?!\]))*)(\/?)\](\]?)/';

	/**
	 * Regex pattern to match shortcode closing tags. Group 1 is the tag name.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	private const CLOSING_TAG_REGEX = '/\[\/(' . self::TAG_NAME_PATTERN . ')\]/';

		/**
		 * Check if content contains Divi Builder shortcodes.
		 *
		 * This method detects any registered Divi module shortcodes (including third-party modules),
		 * while avoiding non-Divi shortcodes to prevent false positives.
		 *
		 * If this function is called with `content=null`, and the current query is for an existing single post of any post
		 * type (`is_singular=true`), then the function will attempt to get the raw post content via `get_post_field` using
		 * `get_the_ID` to get the post ID.
		 *
		 * @since ??
		 *
		 * @param string $content Optional. Content to check. Default `null`.
		 *
		 * @return bool
		 */
	public static function has_builder_shortcode( $content = null ): bool {
		// Use raw post content so shortcodes are not altered by filters or formatting.
		if ( null === $content && is_singular() ) {
			$content = get_post_field( 'post_content', get_the_ID(), 'raw' );
		}

		if ( ! is_string( $content ) ) {
			return false;
		}

		if ( ! str_contains( $content, '[' ) ) {
			return false;
		}

		// Check if content contains D4 core module shortcode (et_pb_).
		if ( self::has_core_module_shortcode( '', $content ) ) {
			return true;
		}

		// Check if content contains third-party module shortcode.
		return self::_has_third_party_shortcode( $content );
	}

	/**
	 * Check if content contains D4 core module shortcode (et_pb_).
	 *
	 * If this function is called with `content=null`, and the current query is for an existing single post of any post
	 * type (`is_singular=true`), then the function will attempt to get the raw post content via `get_post_field` using
	 * `get_the_ID` to get the post ID.
	 *
	 * @since ??
	 *
	 * @see Conditions::has_shortcode() - Equivalent function for checking D4 core module shortcodes.
	 *
	 * @param string $shortcode_suffix Optional. Shortcode tag suffix to check. Default empty string.
	 * @param string $content          Optional. Content to check. Default `null`.
	 *
	 * @return bool
	 */
	public static function has_core_module_shortcode( $shortcode_suffix = '', $content = null ): bool {
		// If content is not provided, try to get it from the current post.
		if ( null === $content && is_singular() ) {
			$content = get_post_field( 'post_content', get_the_ID(), 'raw' );
		}

		if ( ! is_string( $content ) ) {
			return false;
		}

		/**
		 * Regex pattern to match paired and self-closing shortcodes with prefix `et_pb_`.
		 *
		 * Test regex https://regex101.com/r/XfqdEC/1
		 */
		$regex_pattern = '/\[et_pb_' . $shortcode_suffix . '[^\]]*\/?\]/';

		return (bool) ( preg_match( $regex_pattern, $content ) );
	}

	/**
	 * Check if content contains third-party module shortcodes.
	 *
	 * @since ??
	 *
	 * @param string $content Optional. Content to check. Default `null`.
	 *
	 * @return bool
	 */
	private static function _has_third_party_shortcode( $content = null ): bool {
		// If content is not provided, try to get it from the current post.
		if ( null === $content && is_singular() ) {
			$content = get_post_field( 'post_content', get_the_ID(), 'raw' );
		}

		if ( ! is_string( $content ) ) {
			return false;
		}

		// Use the persisted list to avoid loading the shortcode framework in detection paths.
		// This keeps the gate lightweight and avoids side effects from initializing extensions.
		$shortcode_slugs = array_filter( et_get_option( 'all_third_party_shortcode_slugs', [], '', true ) );

		if ( empty( $shortcode_slugs ) ) {
			return false;
		}

		$regex_pattern = get_shortcode_regex( $shortcode_slugs );

		/**
		 * Regex pattern to match third-party module shortcodes.
		 *
		 * Test regex https://regex101.com/r/OzPZpQ/1
		 */
		return (bool) preg_match( '/' . $regex_pattern . '/s', $content );
	}

	/**
	 * Replace the attribute text of unescaped shortcode opening tags.
	 *
	 * Escaped shortcodes (`[[tag ...]]`, `[[tag ... /]]`, and `[[tag ...]...[/tag]]`) are rendered by WordPress
	 * as literal text, so neither they nor any tag inside them are passed to the callback.
	 *
	 * @since ??
	 *
	 * @param string   $content  Content to process.
	 * @param callable $callback Receives the tag name and attribute text, and returns replacement attribute text.
	 *
	 * @return string
	 */
	public static function replace_opening_tags( string $content, callable $callback ): string {
		if ( ! str_contains( $content, '[' ) ) {
			return $content;
		}

		$result        = '';
		$cursor        = 0;
		$search_from   = 0;
		$closing_index = null;

		// Match one tag at a time so memory stays constant regardless of how many tags the content has.
		while ( preg_match( self::OPENING_TAG_WITH_ATTRIBUTES_REGEX, $content, $tag_match, PREG_OFFSET_CAPTURE, $search_from ) ) {
			$offset      = $tag_match[0][1];
			$tag         = array_column( $tag_match, 0 );
			$escaped_end = self::_get_escaped_shortcode_end( $content, $tag, $offset, $closing_index );

			if ( null !== $escaped_end ) {
				$search_from = $escaped_end;
				continue;
			}

			$result     .= substr( $content, $cursor, $offset - $cursor )
				. $tag[1] . '[' . $tag[2] . $callback( $tag[2], $tag[3] ) . $tag[4] . ']' . $tag[5];
			$cursor      = $offset + strlen( $tag[0] );
			$search_from = $cursor;
		}

		return 0 === $cursor ? $content : $result . substr( $content, $cursor );
	}

	/**
	 * Get the end offset of an escaped shortcode that starts with a matched opening tag.
	 *
	 * Must be called with increasing offsets: each tag name's closing-tag cursor only moves forward,
	 * so all lookups together scan every closing tag once.
	 *
	 * @since ??
	 *
	 * @param string     $content       Full content.
	 * @param array      $tag           Matched tag groups.
	 * @param int        $offset        Offset of the full match in the content.
	 * @param array|null $closing_index Closing-tag index, built on first use.
	 *
	 * @return int|null End offset of the escaped shortcode, or null when the tag is not escaped.
	 */
	private static function _get_escaped_shortcode_end( string $content, array $tag, int $offset, ?array &$closing_index ): ?int {
		if ( '[' !== $tag[1] ) {
			return null;
		}

		$tag_end = $offset + strlen( $tag[0] );

		if ( ']' === $tag[5] ) {
			return $tag_end;
		}

		if ( '/' === $tag[4] ) {
			return null;
		}

		if ( null === $closing_index ) {
			$closing_index = self::_index_closing_tags( $content );
		}

		$tag_name = $tag[2];

		if ( ! isset( $closing_index['nameIds'][ $tag_name ] ) ) {
			return null;
		}

		$name_id = $closing_index['nameIds'][ $tag_name ];
		$closing = $closing_index['cursors'][ $name_id ];

		while ( -1 !== $closing && $closing_index['offsets'][ $closing ] < $tag_end ) {
			$closing = $closing_index['next'][ $closing ];
		}

		$closing_index['cursors'][ $name_id ] = $closing;

		if ( -1 === $closing ) {
			return null;
		}

		$closing_end = $closing_index['offsets'][ $closing ] + strlen( '[/' . $tag_name . ']' );

		if ( ']' !== substr( $content, $closing_end, 1 ) ) {
			return null;
		}

		return $closing_end + 1;
	}

	/**
	 * Index every closing tag in the content.
	 *
	 * Uses flat integer lists rather than an array per tag name, so memory stays small
	 * even when malformed content has a large number of distinct tag names.
	 *
	 * @since ??
	 *
	 * @param string $content Content to index.
	 *
	 * @return array {
	 *     @type int[] $nameIds Map of tag name to name ID.
	 *     @type int[] $cursors Per name ID, the first closing tag not yet passed, or -1.
	 *     @type int[] $offsets Per closing tag, its offset in the content, in ascending order.
	 *     @type int[] $next    Per closing tag, the next closing tag with the same name, or -1.
	 * }
	 */
	private static function _index_closing_tags( string $content ): array {
		$name_ids    = [];
		$cursors     = [];
		$last        = [];
		$offsets     = [];
		$next        = [];
		$search_from = 0;

		while ( preg_match( self::CLOSING_TAG_REGEX, $content, $closing_match, PREG_OFFSET_CAPTURE, $search_from ) ) {
			$tag_name = $closing_match[1][0];
			$closing  = count( $offsets );

			if ( isset( $name_ids[ $tag_name ] ) ) {
				$name_id                   = $name_ids[ $tag_name ];
				$next[ $last[ $name_id ] ] = $closing;
			} else {
				$name_id               = count( $cursors );
				$name_ids[ $tag_name ] = $name_id;
				$cursors[]             = $closing;
			}

			$last[ $name_id ] = $closing;
			$offsets[]        = $closing_match[0][1];
			$next[]           = -1;
			$search_from      = $closing_match[0][1] + strlen( $closing_match[0][0] );
		}

		return [
			'nameIds' => $name_ids,
			'cursors' => $cursors,
			'offsets' => $offsets,
			'next'    => $next,
		];
	}
}
