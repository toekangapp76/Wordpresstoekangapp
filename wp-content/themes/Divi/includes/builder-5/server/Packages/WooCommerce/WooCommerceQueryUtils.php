<?php
/**
 * WooCommerce query utilities.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Provides side-effect-free WooCommerce query argument transformations.
 *
 * @since ??
 */
class WooCommerceQueryUtils {

	/**
	 * Named meta-query clause used for standalone product Price ordering.
	 *
	 * @var string
	 */
	private const PRODUCT_PRICE_ORDER_CLAUSE = 'divi_product_price_order';

	/**
	 * Named meta-query clause used for standalone product Popularity ordering.
	 *
	 * @var string
	 */
	private const PRODUCT_POPULARITY_ORDER_CLAUSE = 'divi_product_popularity_order';

	/**
	 * Named meta-query clause used for standalone product Rating ordering.
	 *
	 * @var string
	 */
	private const PRODUCT_RATING_ORDER_CLAUSE = 'divi_product_rating_order';

	/**
	 * Apply numeric price ordering to an explicit product query.
	 *
	 * Standalone Price must order by this named `_price` clause. Using
	 * `meta_key` plus `orderby=meta_value_num` would bind to the first
	 * `meta_query` clause instead (stock status or another filter).
	 *
	 * Variable and grouped products may store several `_price` rows.
	 * `WP_Query` still groups by `{$wpdb->posts}.ID` whenever `meta_query`
	 * is present, so those rows do not duplicate loop results. Do not add
	 * persistent `posts_clauses` callbacks to deduplicate this path.
	 *
	 * @since ??
	 *
	 * @param array<string,mixed> $query_args          Query arguments.
	 * @param mixed               $post_type_for_order Resolved post type.
	 *
	 * @return array<string,mixed>
	 */
	public static function apply_product_price_ordering( array $query_args, $post_type_for_order ): array {
		$price_clause = self::get_product_price_order_clause( $post_type_for_order );

		if ( null === $price_clause ) {
			return $query_args;
		}

		$meta_query = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] )
			? $query_args['meta_query']
			: [];

		if ( ! isset( $meta_query[ self::PRODUCT_PRICE_ORDER_CLAUSE ] ) ) {
			$meta_query = empty( $meta_query )
				? [ self::PRODUCT_PRICE_ORDER_CLAUSE => $price_clause ]
				: [
					'relation'                         => 'AND',
					$meta_query,
					self::PRODUCT_PRICE_ORDER_CLAUSE => $price_clause,
				];
		}

		$query_args['meta_query'] = $meta_query;
		$query_args['orderby']    = self::PRODUCT_PRICE_ORDER_CLAUSE;

		unset( $query_args['meta_key'] );

		return $query_args;
	}

	/**
	 * Return a named meta-query clause for numeric product price ordering.
	 *
	 * @since ??
	 *
	 * @param mixed $post_type_for_order Resolved post type.
	 *
	 * @return array<string,string>|null
	 */
	public static function get_product_price_order_clause( $post_type_for_order ): ?array {
		if ( ! self::is_product_query( $post_type_for_order ) ) {
			return null;
		}

		return [
			'key'     => '_price',
			'compare' => 'EXISTS',
			'type'    => 'DECIMAL(26,8)',
		];
	}

	/**
	 * Apply numeric sales ordering to an explicit product-only query.
	 *
	 * @since ??
	 *
	 * @param array<string,mixed> $query_args          Query arguments.
	 * @param mixed               $post_type_for_order Resolved post type.
	 *
	 * @return array<string,mixed>
	 */
	public static function apply_product_popularity_ordering( array $query_args, $post_type_for_order ): array {
		$popularity_clause = self::get_product_popularity_order_clause( $post_type_for_order );

		if ( null === $popularity_clause ) {
			return $query_args;
		}

		$meta_query = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] )
			? $query_args['meta_query']
			: [];

		if ( ! isset( $meta_query[ self::PRODUCT_POPULARITY_ORDER_CLAUSE ] ) ) {
			$meta_query = empty( $meta_query )
				? [ self::PRODUCT_POPULARITY_ORDER_CLAUSE => $popularity_clause ]
				: [
					'relation'                              => 'AND',
					$meta_query,
					self::PRODUCT_POPULARITY_ORDER_CLAUSE => $popularity_clause,
				];
		}

		$query_args['meta_query'] = $meta_query;
		$query_args['orderby']    = self::PRODUCT_POPULARITY_ORDER_CLAUSE;

		unset( $query_args['meta_key'] );

		return $query_args;
	}

	/**
	 * Return a named meta-query clause for numeric product sales ordering.
	 *
	 * @since ??
	 *
	 * @param mixed $post_type_for_order Resolved post type.
	 *
	 * @return array<string,string>|null
	 */
	public static function get_product_popularity_order_clause( $post_type_for_order ): ?array {
		if ( ! self::is_product_only_query( $post_type_for_order ) ) {
			return null;
		}

		return [
			'key'     => 'total_sales',
			'compare' => 'EXISTS',
			'type'    => 'NUMERIC',
		];
	}

	/**
	 * Apply numeric rating ordering to an explicit product query.
	 *
	 * Standalone Rating must order by this named `_wc_average_rating` clause. Using
	 * `meta_key` plus `orderby=meta_value_num` would bind to the first
	 * `meta_query` clause instead (stock status or another filter).
	 *
	 * @since ??
	 *
	 * @param array<string,mixed> $query_args          Query arguments.
	 * @param mixed               $post_type_for_order Resolved post type.
	 *
	 * @return array<string,mixed>
	 */
	public static function apply_product_rating_ordering( array $query_args, $post_type_for_order ): array {
		$rating_clause = self::get_product_rating_order_clause( $post_type_for_order );

		if ( null === $rating_clause ) {
			return $query_args;
		}

		$meta_query = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] )
			? $query_args['meta_query']
			: [];

		if ( ! isset( $meta_query[ self::PRODUCT_RATING_ORDER_CLAUSE ] ) ) {
			$meta_query = empty( $meta_query )
				? [ self::PRODUCT_RATING_ORDER_CLAUSE => $rating_clause ]
				: [
					'relation'                        => 'AND',
					$meta_query,
					self::PRODUCT_RATING_ORDER_CLAUSE => $rating_clause,
				];
		}

		$query_args['meta_query'] = $meta_query;
		$query_args['orderby']    = self::PRODUCT_RATING_ORDER_CLAUSE;

		unset( $query_args['meta_key'] );

		return $query_args;
	}

	/**
	 * Return a named meta-query clause for numeric product rating ordering.
	 *
	 * @since ??
	 *
	 * @param mixed $post_type_for_order Resolved post type.
	 *
	 * @return array<string,string>|null
	 */
	public static function get_product_rating_order_clause( $post_type_for_order ): ?array {
		if ( ! self::is_product_query( $post_type_for_order ) ) {
			return null;
		}

		return [
			'key'     => '_wc_average_rating',
			'compare' => 'EXISTS',
			'type'    => 'DECIMAL(10,2)',
		];
	}

	/**
	 * Determine whether the resolved post type explicitly includes products.
	 *
	 * @since ??
	 *
	 * @param mixed $post_type Resolved post type.
	 *
	 * @return bool
	 */
	private static function is_product_query( $post_type ): bool {
		$post_types = is_array( $post_type ) ? $post_type : [ $post_type ];

		return function_exists( 'WC' ) && in_array( 'product', $post_types, true );
	}

	/**
	 * Determine whether the resolved post type contains only products.
	 *
	 * @since ??
	 *
	 * @param mixed $post_type Resolved post type.
	 *
	 * @return bool
	 */
	private static function is_product_only_query( $post_type ): bool {
		$post_types = is_array( $post_type ) ? array_values( array_unique( $post_type ) ) : [ $post_type ];

		return function_exists( 'WC' ) && [ 'product' ] === $post_types;
	}
}
