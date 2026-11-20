<?php
/**
 * Shared CSS property keys for routing label font, fill, and stroke styles.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\ModuleLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Label font property keys for FormFieldStyle propertySelectors.
 *
 * Includes text-decoration longhands, capitalization, fill, and stroke properties
 * that must target label text instead of the input or custom icon.
 *
 * @since ??
 */
class LabelTextDecorationFontProperties {

	/**
	 * CSS properties that must target option/field label text, not the input or custom icon.
	 *
	 * @var string[]
	 */
	public const CSS_PROPERTIES = [
		'text-decoration-line',
		'text-decoration-color',
		'text-decoration-style',
		'text-decoration-thickness',
		'text-underline-offset',
		'font-variant',
		'font-variant-caps',
		'background-image',
		'background-size',
		'background-position',
		'background-repeat',
		'background-blend-mode',
		'-webkit-background-clip',
		'background-clip',
		'-webkit-text-fill-color',
		'-webkit-text-stroke-width',
		'-webkit-text-stroke-color',
		'paint-order',
	];
}
