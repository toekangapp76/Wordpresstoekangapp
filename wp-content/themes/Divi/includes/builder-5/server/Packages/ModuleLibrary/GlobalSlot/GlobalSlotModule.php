<?php
/**
 * Module Library: Global Slot Module.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\ModuleLibrary\GlobalSlot;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;
use ET\Builder\Packages\Module\Layout\Components\ModuleElements\ModuleElements;
use ET\Builder\Packages\ModuleLibrary\Group\GroupModule;
use ET\Builder\Packages\ModuleLibrary\ModuleRegistration;
use WP_Block;

/**
 * Registers the Global Slot module.
 *
 * @since ??
 */
class GlobalSlotModule implements DependencyInterface {

	/**
	 * Render instance-local child content with the same container as a Group.
	 *
	 * @since ??
	 *
	 * @param array          $attrs                  Block attributes.
	 * @param string         $child_modules_content Rendered child module content.
	 * @param WP_Block       $block                  Block instance.
	 * @param ModuleElements $elements               Module elements instance.
	 * @param array          $default_printed_style_attrs Default printed style attributes.
	 *
	 * @return string
	 */
	public static function render_callback(
		array $attrs,
		string $child_modules_content,
		WP_Block $block,
		ModuleElements $elements,
		array $default_printed_style_attrs
	): string {
		// A Slot is a placeholder for instance content. When an instance leaves it empty there is nothing
		// to contain, so it renders no wrapper and registers no styles on the front end.
		if ( '' === trim( $child_modules_content ) ) {
			return '';
		}

		return GroupModule::render_callback(
			$attrs,
			$child_modules_content,
			$block,
			$elements,
			$default_printed_style_attrs
		);
	}

	/**
	 * Register the Global Slot module.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load(): void {
		// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters.dirname_levelsFound -- PHP 7 is supported.
		$module_json_folder_path = dirname( __DIR__, 4 ) . '/visual-builder/packages/module-library/src/components/global-slot/';

		ModuleRegistration::register_module(
			$module_json_folder_path,
			[
				'render_callback' => [ self::class, 'render_callback' ],
			]
		);
	}
}
