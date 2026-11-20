<?php
/**
 * The Events Calendar plugin compatibility for the Visual Builder Classic Editor flow.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\PluginCompat\TheEventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use WP_Post;

/**
 * TheEventsCalendar class.
 *
 * Handles saving The Events Calendar event meta when a post is prepared for the
 * Visual Builder via the Classic Editor "Use Divi Builder" button. TEC's own
 * save_post handler refuses to run during AJAX requests, so this compatibility
 * layer collects the event date/time fields from the request and calls TEC's API
 * directly.
 *
 * @since ??
 */
class TheEventsCalendar {
	/**
	 * Register plugin-compat hooks.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_action( 'divi_classic_editor_before_vb_redirect', [ __CLASS__, 'save_event_meta_from_request' ], 10, 2 );
	}

	/**
	 * Save TEC event meta from the current AJAX request.
	 *
	 * @since ??
	 *
	 * @param int     $post_id Post ID being prepared for the Visual Builder.
	 * @param WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public static function save_event_meta_from_request( int $post_id, WP_Post $post ): void {
		// This hook is generic; skip posts that are not Events (no TEC date fields).
		if ( 'tribe_events' !== $post->post_type ) {
			return;
		}

		if ( ! class_exists( 'Tribe__Events__API' ) ) {
			return;
		}

		$tec_data = self::_get_event_data_from_request();

		// Both dates are required to produce valid TEC event meta.
		if ( empty( $tec_data['EventStartDate'] ) || empty( $tec_data['EventEndDate'] ) ) {
			return;
		}

		\Tribe__Events__API::saveEventMeta( $post_id, $tec_data, $post );
	}

	/**
	 * Collect TEC event fields from the current request.
	 *
	 * @return array<string, mixed>
	 */
	private static function _get_event_data_from_request(): array {
		$tec_fields = [
			'EventStartDate',
			'EventStartTime',
			'EventStartHour',
			'EventStartMinute',
			'EventStartMeridian',
			'EventEndDate',
			'EventEndTime',
			'EventEndHour',
			'EventEndMinute',
			'EventEndMeridian',
			'EventTimezone',
			'EventAllDay',
		];

		$tec_data = [];

		foreach ( $tec_fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $field ] );

			if ( is_array( $value ) ) {
				$tec_data[ $field ] = array_map( 'sanitize_text_field', $value );
			} else {
				$tec_data[ $field ] = sanitize_text_field( $value );
			}
		}

		return $tec_data;
	}
}
