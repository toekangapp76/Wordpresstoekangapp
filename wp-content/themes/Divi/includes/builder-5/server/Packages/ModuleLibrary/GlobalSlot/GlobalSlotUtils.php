<?php
/**
 * Module Library: Global Slot Utilities.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\ModuleLibrary\GlobalSlot;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Utilities for matching instance Slot payloads to template Slot shells.
 *
 * @since ??
 */
class GlobalSlotUtils {

	/**
	 * Collect instance Slot payloads by stable Slot ID.
	 *
	 * @since ??
	 *
	 * @param array $blocks Parsed instance blocks.
	 *
	 * @return array
	 */
	public static function collect_payloads( array $blocks ): array {
		$payloads = [];

		foreach ( $blocks as $block ) {
			if ( 'divi/global-slot' === ( $block['blockName'] ?? '' ) ) {
				$slot_id = $block['attrs']['slotId'] ?? '';

				if ( $slot_id ) {
					$payloads[ $slot_id ] = $block;
				}

				continue;
			}

			if ( 'divi/global-layout' === ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$inner_blocks = $block['innerBlocks'] ?? [];

			if ( is_array( $inner_blocks ) && $inner_blocks ) {
				$payloads = array_merge( $payloads, self::collect_payloads( $inner_blocks ) );
			}
		}

		return $payloads;
	}

	/**
	 * Graft instance Slot payloads into synchronized template Slot shells.
	 *
	 * @since ??
	 *
	 * @param array $blocks        Parsed template blocks.
	 * @param array $slot_payloads Slot payload blocks keyed by stable Slot ID.
	 *
	 * @return array
	 */
	public static function apply_payloads( array $blocks, array $slot_payloads ): array {
		foreach ( $blocks as &$block ) {
			if ( 'divi/global-slot' === ( $block['blockName'] ?? '' ) ) {
				$slot_id          = $block['attrs']['slotId'] ?? '';
				$payload          = $slot_id ? ( $slot_payloads[ $slot_id ] ?? [] ) : [];
				$payload_children = is_array( $payload['innerBlocks'] ?? null ) ? $payload['innerBlocks'] : [];

				$block['innerBlocks']  = $payload_children;
				$block['innerContent'] = array_fill( 0, count( $payload_children ), null );
				$block['innerHTML']    = '';
				continue;
			}

			if ( 'divi/global-layout' === ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::apply_payloads( $block['innerBlocks'], $slot_payloads );
			}
		}
		unset( $block );

		return $blocks;
	}
}
