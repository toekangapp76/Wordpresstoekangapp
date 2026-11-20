<?php
/**
 * Theme Builder filename utilities.
 *
 * @package Divi\Builder
 * @since   4.0
 */

/**
 * Normalize export filename for Theme Builder download.
 *
 * @since 4.0
 *
 * @param mixed $filename Raw filename value from request.
 *
 * @return string
 */
function et_theme_builder_api_normalize_export_filename( $filename ) {
	$filename = is_string( $filename ) ? rawurldecode( $filename ) : '';
	$filename = sanitize_text_field( $filename );
	$filename = '' !== $filename ? $filename : 'Divi Theme Builder Templates';

	return sanitize_file_name( $filename );
}
