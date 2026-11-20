<?php
/**
 * Module: Order preset attributes map.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\Module\Options\Order;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/** Provides the shared display-order preset attribute. */
class OrderPresetAttrsMap {
	/**
	 * Get the map for display-order preset attributes.
	 *
	 * @since ??
	 *
	 * @param string $attr_name The attribute name.
	 *
	 * @return array The preset attributes map.
	 */
	public static function get_map( string $attr_name ) {
		return [
			"{$attr_name}__order" => [
				'attrName' => $attr_name,
				'preset'   => [ 'style' ],
				'subName'  => 'order',
			],
		];
	}
}
