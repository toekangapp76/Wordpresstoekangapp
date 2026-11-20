<?php
/**
 * Class BlockParserUtils
 *
 * @since ??
 *
 * @package Divi
 */

namespace ET\Builder\FrontEnd\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\FrontEnd\BlockParser\BlockParserStore;
use WP_Block_Parser;

/**
 * Class BlockParserUtils
 *
 * Utility methods for block parsing operations.
 *
 * @since ??
 */
class BlockParserUtils {

	/**
	 * Extract a brace-matched JSON object from serialized block content.
	 *
	 * @since ??
	 *
	 * @param string $content    Serialized block content.
	 * @param int    $json_start JSON object start offset.
	 *
	 * @return array|null JSON bounds and value, or null when no complete object exists.
	 */
	public static function extract_json_object_bounds( string $content, int $json_start ): ?array {
		$content_length = strlen( $content );

		if ( $json_start >= $content_length || '{' !== $content[ $json_start ] ) {
			return null;
		}

		$brace_count = 0;
		$in_string   = false;
		$escape_next = false;

		for ( $index = $json_start; $index < $content_length; $index++ ) {
			$char = $content[ $index ];

			if ( $escape_next ) {
				$escape_next = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escape_next = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				++$brace_count;
				continue;
			}

			if ( '}' !== $char ) {
				continue;
			}

			--$brace_count;

			if ( 0 === $brace_count ) {
				return [
					'start' => $json_start,
					'end'   => $index,
					'json'  => substr( $content, $json_start, $index - $json_start + 1 ),
				];
			}
		}

		return null;
	}

	/**
	 * Decode block attribute JSON, including legacy literal control characters in strings.
	 *
	 * @since ??
	 *
	 * @param string $json Block attribute JSON.
	 *
	 * @return array|null Decoded attributes, or null when the JSON cannot be repaired.
	 */
	public static function decode_block_attrs_json( string $json ): ?array {
		$attrs = json_decode( $json, true );

		if ( is_array( $attrs ) ) {
			return $attrs;
		}

		$normalized  = '';
		$in_string   = false;
		$escape_next = false;
		$json_length = strlen( $json );

		for ( $index = 0; $index < $json_length; $index++ ) {
			$char = $json[ $index ];

			if ( $escape_next ) {
				$normalized .= $char;
				$escape_next = false;
				continue;
			}

			if ( '\\' === $char ) {
				$normalized .= $char;
				$escape_next = true;
				continue;
			}

			if ( '"' === $char ) {
				$normalized .= $char;
				$in_string   = ! $in_string;
				continue;
			}

			if ( $in_string && ord( $char ) < 0x20 ) {
				$normalized .= sprintf( '\\u%04x', ord( $char ) );
				continue;
			}

			$normalized .= $char;
		}

		$attrs = json_decode( $normalized, true );

		return is_array( $attrs ) ? $attrs : null;
	}

	/**
	 * Normalize Component reference comment JSON before block parsing.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 *
	 * @return string Content with decodable reference attributes re-encoded.
	 */
	public static function normalize_global_layout_block_comments( string $content ): string {
		$normalized = $content;
		$offset     = 0;
		$marker     = '<!-- wp:divi/global-layout';

		while ( false !== ( $position = strpos( $normalized, $marker, $offset ) ) ) {
			$json_start = $position + strlen( $marker );

			if ( $json_start >= strlen( $normalized ) || ! ctype_space( $normalized[ $json_start ] ) ) {
				$offset = $json_start;
				continue;
			}

			while ( $json_start < strlen( $normalized ) && ctype_space( $normalized[ $json_start ] ) ) {
				++$json_start;
			}

			$json_bounds = self::extract_json_object_bounds( $normalized, $json_start );

			if ( null === $json_bounds ) {
				$offset = $json_start;
				continue;
			}

			$attrs = self::decode_block_attrs_json( $json_bounds['json'] );

			if ( ! is_array( $attrs ) || ! isset( $attrs['globalModule'] ) ) {
				$offset = $json_bounds['end'] + 1;
				continue;
			}

			$encoded_attrs = serialize_block_attributes( $attrs );

			if ( ! is_string( $encoded_attrs ) ) {
				$offset = $json_bounds['end'] + 1;
				continue;
			}

			$normalized = substr_replace(
				$normalized,
				$encoded_attrs,
				$json_start,
				$json_bounds['end'] - $json_start + 1
			);
			$offset     = $json_start + strlen( $encoded_attrs );
		}

		return $normalized;
	}

	/**
	 * Parse serialized content with normalized Component references and the core parser.
	 *
	 * Using the core parser prevents the Divi parser from recursively invoking Component
	 * expansion while inspecting or expanding reference blocks.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 *
	 * @return array Parsed WordPress blocks.
	 */
	public static function parse_global_layout_blocks( string $content ): array {
		return ( new WP_Block_Parser() )->parse(
			self::normalize_global_layout_block_comments( $content )
		);
	}

	/**
	 * Extract one complete Component reference block without reserializing its content.
	 *
	 * @since ??
	 *
	 * @param string $content Serialized block content.
	 * @param int    $start   Offset of the Component reference opening marker.
	 *
	 * @return array|null Raw block bounds and decoded attributes, or null for malformed content.
	 */
	public static function extract_global_layout_block( string $content, int $start ): ?array {
		$opening_marker = '<!-- wp:divi/global-layout';

		if ( $start < 0 || $start !== strpos( $content, $opening_marker, $start ) ) {
			return null;
		}

		$block_name_end = $start + strlen( $opening_marker );
		$content_length = strlen( $content );

		if ( $block_name_end >= $content_length || ! ctype_space( $content[ $block_name_end ] ) ) {
			return null;
		}

		$json_start = $block_name_end;

		while ( $json_start < $content_length && ctype_space( $content[ $json_start ] ) ) {
			++$json_start;
		}

		$json_bounds = self::extract_json_object_bounds( $content, $json_start );

		if ( null === $json_bounds ) {
			return null;
		}

		$attrs = self::decode_block_attrs_json( $json_bounds['json'] );

		if ( ! is_array( $attrs ) ) {
			return null;
		}

		$opening_end = strpos( $content, '-->', $json_bounds['end'] + 1 );

		if ( false === $opening_end ) {
			return null;
		}

		$opening_suffix = substr(
			$content,
			$json_bounds['end'] + 1,
			$opening_end - $json_bounds['end'] - 1
		);
		$opening_suffix = trim( $opening_suffix );

		if ( '' !== $opening_suffix && '/' !== $opening_suffix ) {
			return null;
		}

		$opening_end += 3;

		if ( '/' === $opening_suffix ) {
			return [
				'attrs'               => $attrs,
				'end'                 => $opening_end,
				'hasChildrenSnapshot' => false,
				'innerHTML'           => '',
				'start'               => $start,
			];
		}

		$closing_marker = '<!-- /wp:divi/global-layout -->';
		$cursor         = $opening_end;

		while ( $cursor < $content_length ) {
			$nested_start = strpos( $content, $opening_marker, $cursor );
			$closing_start = strpos( $content, $closing_marker, $cursor );

			if ( false === $closing_start ) {
				return null;
			}

			if ( false === $nested_start || $closing_start < $nested_start ) {
				return [
					'attrs'               => $attrs,
					'end'                 => $closing_start + strlen( $closing_marker ),
					'hasChildrenSnapshot' => true,
					'innerHTML'           => substr( $content, $opening_end, $closing_start - $opening_end ),
					'start'               => $start,
				];
			}

			$nested_block = self::extract_global_layout_block( $content, $nested_start );

			if ( null === $nested_block ) {
				$cursor = $nested_start + strlen( $opening_marker );
				continue;
			}

			$cursor = $nested_block['end'];
		}

		return null;
	}

	/**
	 * Checks if the content contains wrapper blocks that trigger double parsing.
	 *
	 * WordPress wrapper blocks like wp:post-content, wp:navigation, and wp:block
	 * can cause double parsing issues. This method detects their presence in the content.
	 *
	 * @since ??
	 *
	 * @param string $content The content to check for wrapper blocks.
	 *
	 * @return bool True if wrapper blocks are found, false otherwise.
	 *
	 * @see https://github.com/WordPress/WordPress/commit/c5f7803d64e5a35e84aa3b8abd315c0441aea463
	 */
	public static function is_parsing_wrapper_blocks( $content ) {
		// Bail early if content is empty.
		if ( empty( $content ) ) {
			return false;
		}

		// Check for WordPress wrapper blocks that trigger double parsing.
		$has_post_content_wrapper = str_contains( $content, '<!-- wp:post-content ' );
		$has_navigation_wrapper   = str_contains( $content, '<!-- wp:navigation ' );
		$has_block_wrapper        = str_contains( $content, '<!-- wp:block ' );

		return $has_post_content_wrapper || $has_navigation_wrapper || $has_block_wrapper;
	}

	/**
	 * Parses blocks with specific layout context.
	 *
	 * @since ??
	 *
	 * @param string $content The content to parse.
	 * @param string $layout_type The type of layout to parse.
	 * @param string $layout_id The ID of the layout to parse.
	 *
	 * @return array The parsed blocks.
	 */
	public static function parse_blocks_with_layout_context( string $content, string $layout_type, string $layout_id = '' ): array {
		BlockParserStore::set_layout(
			[
				'id'   => $layout_id,
				'type' => $layout_type,
			]
		);

		$blocks = parse_blocks( $content );

		// Reset the block parser store and order index to avoid conflicts with rendering.
		BlockParserBlock::reset_order_index();

		BlockParserStore::reset_layout();
		return $blocks;
	}
}
