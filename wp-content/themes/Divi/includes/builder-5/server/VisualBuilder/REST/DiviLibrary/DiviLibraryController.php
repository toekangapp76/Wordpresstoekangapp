<?php
/**
 * REST: DiviLibraryController class.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\REST\DiviLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Controllers\RESTController;
use ET\Builder\Framework\Portability\PortabilityPost;
use ET\Builder\Framework\Utility\Conditions;
use ET\Builder\FrontEnd\Assets\DetectFeature;
use ET\Builder\Library\LibraryUtility;
use ET\Builder\Framework\Utility\Shortcode;
use ET\Builder\Packages\Conversion\Conversion as BuilderConversion;
use ET\Builder\Packages\GlobalData\GlobalData;
use ET\Builder\Packages\GlobalData\GlobalPreset;
use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use ET\Builder\VisualBuilder\AiAgent\AiAgentApprovalTokens;
use ET\Builder\VisualBuilder\DiviLibrary\DiviLibraryUtility;
use ET\Builder\VisualBuilder\REST\Portability\PortabilityController;
use ET\Builder\VisualBuilder\Saving\SavingUtility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * DiviLibraryController class.
 *
 * The DiviLibraryController class extends the RESTController class and provides functionality to handle CRUD operations for the Divi Library.
 *
 * @since ??
 */
class DiviLibraryController extends RESTController {

		/**
		 * Export function for Divi Library.
		 *
		 * This function is used to handle the export of a Divi Builder layout based on the provided request parameter.
		 * It exports the layout as a portability post, which can be used for various purposes like importing/exporting, cloning, etc.
		 *
		 * @since ??
		 *
		 * @param WP_REST_Request $request The request object containing the necessary parameters.
		 * @param int             $id      The post ID.
		 * @param string          $content The post content.
		 *
		 * @return WP_REST_Response|WP_Error Returns a success `WP_REST_Response` object containing the exported portability post.
		 *                          If the context parameter is invalid, it returns an `WP_Error` object.
		 *
		 * @example:
		 * ```php
		 * // Example usage in a class where the trait is used
		 *   $request = new \WP_REST_Request( 'GET' );
		 *   // Set necessary parameters in the request as needed
		 *   $response = self::export( $request );
		 *
		 *   // Do something with the response
		 * ```
		 */
	public static function export( WP_REST_Request $request, $id, $content ) {
		$context = 'et_builder';

		$portability_registered = et_core_cache_get( $context, 'et_core_portability' );

		if ( ! $portability_registered ) {
			et_core_portability_register(
				$context,
				[
					'name' => esc_html__( 'Divi Builder Layout', 'et_builder_5' ),
					'type' => 'post',
					'view' => true,
				]
			);
		}

		$portability_post = new PortabilityPost( $context );

		if ( $request->has_param( 'timestamp' ) ) {
			$portability_post->set_param( 'timestamp', $request->get_param( 'timestamp' ) );
		}

		if ( $request->has_param( 'page' ) ) {
			$portability_post->set_param( 'page', $request->get_param( 'page' ) );
		}

		$portability_post->set_param( 'post', $id );
		$portability_post->set_param( 'content', $content );
		$portability_post->set_param( 'return_content', true );

		return $portability_post->export();
	}

		/**
		 * Get a single library layout.
		 *
		 * This function is used to handle the display of a Divi Builder layout based on the provided request parameter.
		 * It retrieves the post or layout content and returns it as a response.
		 * If the content is too large, it also includes a custom HTTP header 'X-Content-Length' as a fallback for the Content-Length header.
		 *
		 * @since ??
		 *
		 * @param WP_REST_Request $request The request object containing the necessary parameters.
		 *
		 * @return WP_REST_Response|WP_Error Returns a success `WP_REST_Response` object containing the retrieved content.
		 *                                   If the post or layout is not found, it returns a `WP_Error` object with an error message.
		 *
		 * @example:
		 * ```php
		 * $request = new WP_REST_Request( 'GET' );
		 * // Set necessary parameters in the request as needed
		 * $response = DiviLibrary::show( $request );
		 *
		 * // Do something with the response
		 * ```
		 */
	public static function show( WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$post = \get_post( $id );

		if ( ! $post || 'trash' === $post->post_status ) {
			return self::response_error( 'no_entry_found', esc_html__( 'No entry found.', 'et_builder_5' ) );
		}

		$result = [
			'content' => '',
		];

		switch ( $post->post_type ) {
			case \ET_BUILDER_LAYOUT_POST_TYPE:
				// Directly retrieve library item metadata from WordPress post object instead of using et_pb_retrieve_templates().
				// This ensures all metadata is available regardless of where the library item was originally saved,
				// allowing global elements to maintain their global status across all contexts.
				$library_type = $request->get_param( 'libraryType' );

				// Retrieve global status from the 'scope' taxonomy term.
				// Global elements have a 'global' term, while regular elements have 'non_global'.
				$scope        = \wp_get_post_terms( $post->ID, 'scope' );
				$global_scope = ( ! \is_wp_error( $scope ) && isset( $scope[0] ) ) ? $scope[0]->slug : 'non_global';

				// Retrieve layout type from taxonomy (e.g., 'section', 'row', 'module').
				// Falls back to the requested library type if no taxonomy term exists.
				$layout_type_terms = \wp_get_post_terms( $post->ID, 'layout_type' );
				$layout_type       = ! empty( $layout_type_terms ) ? $layout_type_terms[0]->slug : $library_type;

				// Retrieve all assigned categories for the library item.
				$categories           = \wp_get_post_terms( $post->ID, 'layout_category' );
				$categories_processed = [];
				$category_ids         = [];
				if ( ! \is_wp_error( $categories ) && ! empty( $categories ) ) {
					foreach ( $categories as $category_data ) {
						$categories_processed[] = \esc_html( $category_data->slug );
						$category_ids[]         = (int) $category_data->term_id;
					}
				}

				$tags           = \wp_get_post_terms( $post->ID, 'layout_tag' );
				$tags_processed = [];
				$tag_ids        = [];
				if ( ! \is_wp_error( $tags ) && ! empty( $tags ) ) {
					foreach ( $tags as $tag_data ) {
						$tags_processed[] = \esc_html( $tag_data->slug );
						$tag_ids[]        = (int) $tag_data->term_id;
					}
				}

				$module_type        = \get_post_meta( $post->ID, '_et_pb_module_type', true );
				$row_layout         = 'row' === $layout_type ? \get_post_meta( $post->ID, '_et_pb_row_layout', true ) : '';
				$module_width_terms = \wp_get_post_terms( $post->ID, 'module_width', [ 'fields' => 'slugs' ] );
				$module_width       = ! \is_wp_error( $module_width_terms ) && ! empty( $module_width_terms )
					? $module_width_terms[0]
					: 'regular';

				// Retrieve unsynced options for global modules.
				// Global modules can have certain options excluded from syncing across instances.
				$unsynced_options = [];
				if ( 'module' === $library_type && 'non_global' !== $global_scope ) {
					$unsynced_meta = \get_post_meta( $post->ID, '_et_pb_excluded_global_options', true );
					if ( ! empty( $unsynced_meta ) ) {
						$decoded = json_decode( $unsynced_meta, true );
						if ( is_array( $decoded ) ) {
							$unsynced_options = $decoded;
						}
					}
				}

				// Decode entities for UI text while stripping untrusted markup at the REST boundary.
				// Consumers must still escape the title for their output context.
				$title = \wp_strip_all_tags( \html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) );

				// Construct the complete result array with all required metadata.
				$result = [
					'ID'               => (int) $post->ID,
					'title'            => $title,
					'content'          => $post->post_content,
					'is_global'        => \esc_html( $global_scope ),
					'layout_type'      => \esc_html( $layout_type ),
					'applicability'    => '',
					'layouts_type'     => '',
					'module_type'      => \esc_html( $module_type ),
					'categories'       => $categories_processed,
					'category_ids'     => $category_ids,
					'tags'             => $tags_processed,
					'tag_ids'          => $tag_ids,
					'module_width'     => \esc_html( $module_width ),
					'row_layout'       => \esc_html( $row_layout ),
					'unsynced_options' => $unsynced_options,
				];

				break;

			default:
				// For non-library post types, only return the post content.
				// These posts don't have the same metadata structure as library items.
				$result['content'] = $post->post_content;
				break;
		}

		// Apply server-side D4-to-D5 conversion and D5 migrations for local library content.
		if ( ! empty( $result['content'] ) ) {
			$content_type                      = $request->get_param( 'contentType' );
			$is_portability_export             = 'exported' === $content_type;
			$convert_global_as_regular_modules = $is_portability_export;

			// Check if content needs D4-to-D5 conversion.
			if ( Shortcode::has_builder_shortcode( $result['content'] ) ) {
				// Initialize shortcode framework and prepare for conversion.
				BuilderConversion::initialize_shortcode_framework();

				// Prepare for D4 to D5 conversion by ensuring module definitions are available.
				do_action( 'divi_visual_builder_before_d4_conversion' );

				// Apply full conversion (includes migration + format conversion).
				// Pass post ID for global module template selective sync conversion.
				// Portability export deglobalizes instances; on-site library fetch preserves global placeholders (#44056).
				$result['content'] = BuilderConversion::maybeConvertContent( $result['content'], true, $post->ID, $convert_global_as_regular_modules );
			}

			// Apply post-scoped Visual Builder migrations after optional D4 conversion.
			$result['content'] = apply_filters( 'et_fb_load_raw_post_content', $result['content'], (int) $post->ID );

			// Portability export: deglobalize D5 global-layout placeholders (#44056).
			if ( $is_portability_export && str_contains( $result['content'], 'divi/global-layout' ) ) {
				if ( ! et_core_cache_get( 'et_builder', 'et_core_portability' ) ) {
					et_core_portability_register(
						'et_builder',
						[
							'name' => esc_html__( 'Divi Builder Layout', 'et_builder_5' ),
							'type' => 'post',
							'view' => true,
						]
					);
				}

				$portability_instance = new PortabilityPost( 'et_builder' );
				$result['content']    = $portability_instance->maybe_materialize_global_layout_blocks_for_portability( $result['content'] );
				$result['content']    = $portability_instance->maybe_deglobalize_nested_global_modules( $result['content'] );
			}

			// Portability export needs the full catalog; processed fetches only ship presets referenced in content (#29827).
			if ( $is_portability_export ) {
				$all_presets = GlobalPreset::get_data();

				if ( ! empty( $all_presets ) ) {
					$result['presets'] = $all_presets;
				}

				$all_global_colors = GlobalData::get_global_colors();

				if ( ! empty( $all_global_colors ) ) {
					$result['globalColors'] = $all_global_colors;
				}

				$all_global_variables = GlobalData::get_global_variables();
				if ( ! empty( $all_global_variables ) ) {
					$result['globalVariables'] = $all_global_variables;
				}
			} else {
				$used_presets = GlobalPreset::get_data_used_in_content( $result['content'] );

				if ( ! empty( $used_presets ) ) {
					$result['presets'] = $used_presets;
				}

				$color_ids = array_unique(
					array_merge(
						DetectFeature::get_global_color_ids( $result['content'] ),
						DetectFeature::get_preset_global_color_ids( $result['content'] )
					)
				);

				if ( ! empty( $color_ids ) ) {
					$all_global_colors = GlobalData::get_global_colors();
					$filtered_colors   = [];

					foreach ( $color_ids as $color_id ) {
						if ( isset( $all_global_colors[ $color_id ] ) ) {
							$filtered_colors[ $color_id ] = $all_global_colors[ $color_id ];
						}
					}

					if ( ! empty( $filtered_colors ) ) {
						$result['globalColors'] = $filtered_colors;
					}
				}

				$variable_ids = array_unique(
					array_merge(
						DetectFeature::get_global_variable_ids( $result['content'] ),
						DetectFeature::get_preset_global_variable_ids( $result['content'] )
					)
				);

				if ( ! empty( $variable_ids ) ) {
					$all_global_variables = GlobalData::get_global_variables();
					$filtered_variables   = [];

					foreach ( $all_global_variables as $group => $variables ) {
						$variables_array = (array) $variables;

						foreach ( $variable_ids as $variable_id ) {
							if ( isset( $variables_array[ $variable_id ] ) ) {
								if ( ! isset( $filtered_variables[ $group ] ) ) {
									$filtered_variables[ $group ] = [];
								}
								$filtered_variables[ $group ][ $variable_id ] = $variables_array[ $variable_id ];
							}
						}
					}

					if ( ! empty( $filtered_variables ) ) {
						$result['globalVariables'] = $filtered_variables;
					}
				}
			}
		}

		if ( 'exported' === $request->get_param( 'contentType' ) ) {
			$result['content'] = self::export( $request, $id, $result['content'] );
		}

		if ( function_exists( 'mb_strlen' ) ) {
			$x_content_length = mb_strlen( wp_json_encode( $result, JSON_NUMERIC_CHECK ), '8bit' );
		} else {
			$x_content_length = strlen( wp_json_encode( $result, JSON_NUMERIC_CHECK ) );
		}

		return self::response_success(
			$result,
			[
				// Custom HTTP header that will be used as fallback of the Content-Length header.
				// Mostly when the JSON data size is quite big, server will send response header Transfer-Encoding as chunked
				// and not send the Content-Length header.
				'X-Content-Length' => $x_content_length,
			]
		);
	}

	/**
	 * Get arguments for show action.
	 *
	 * Defines an array of arguments for the show action used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array An array of arguments for show action.
	 */
	public static function show_args(): array {
		return [
			'id'          => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'libraryType' => [
				'default'           => 'layout',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'builtFor'    => [
				'default'           => 'page',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'contentType' => [
				'default'           => 'processed',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Check if user has permission for show action.
	 *
	 * Checks if the current user has the permission to edit posts, used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has permission, or `WP_Error` if the user does not have permission.
	 *
	 * @example:
	 * ```php
	 *     // Check if the user has permission to edit posts
	 *     $permission = DiviLibrary::show_permission();
	 *     if ( $permission instanceof WP_Error ) {
	 *         echo $permission->get_error_message();
	 *     } else {
	 *         echo "Permission granted!";
	 *     }
	 * ```
	 */
	public static function show_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		$has_divi_library_access = et_pb_is_allowed( 'divi_library' );

		if ( ! $has_divi_library_access ) {
			return self::response_error_permission();
		}

		$has_load_permission = et_pb_is_allowed( 'add_library' );

		if ( ! $has_load_permission ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Updates library item terms.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Update library item terms.
	 *
	 * This function takes a `WP_REST_Request` object as a parameter and updates library item terms based on the `data` parameter of the request.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request {
	 *     The REST request object.
	 *
	 *     @type array $data {
	 *         An array of term data.
	 *
	 *         @type string $filterType The type of filter ('tags' or 'categories').
	 *         @type string $updateType The type of update ('add', 'rename', or 'remove').
	 *         @type int    $id         The ID of the term.
	 *         @type string $newName    The new name of the term (only for 'rename' update type).
	 *         @type string $term_name  The name of the term (only for 'add' update type).
	 *     }
	 * }
	 *
	 * @return array Returns an array of data including the updated filters, the filter type, and the local library terms.
	 *
	 * @example:
	 * ```php
	 * $request = new \WP_REST_Request( 'POST', '/v1/update-terms' );
	 * $request->set_param( 'data', [
	 *     [
	 *         'filterType' => 'tags',
	 *         'updateType' => 'add',
	 *         'id' => 1,
	 *         'term_name' => 'New Tag',
	 *     ],
	 *     [
	 *         'filterType' => 'categories',
	 *         'updateType' => 'rename',
	 *         'id' => 2,
	 *         'newName' => 'New Category Name',
	 *     ],
	 *     [
	 *         'filterType' => 'tags',
	 *         'updateType' => 'remove',
	 *         'id' => 3,
	 *     ],
	 * ] );
	 *
	 * $response = DiviLibrary::update( $request );
	 *
	 * print_r( $response );
	 * ```
	 *
	 * @output:
	 * ```php
	 *  Array(
	 *      'newFilters' => Array(
	 *          Array(
	 *              'name' => 'New Tag',
	 *              'id' => 1,
	 *              'location' => 'local',
	 *          ),
	 *      ),
	 *      'filterType' => 'tags',
	 *      'localLibraryTerms' => Array(
	 *          'layout_category' => ...,
	 *          'layout_tag' => ...,
	 *      ),
	 *  )
	 * ```
	 */
	public static function update( $request ) {
		$filter_type = '';
		$new_terms   = [];
		$data        = $request->get_param( 'data' );

		if ( empty( $data ) ) {
			return self::response_error( 'terms_data_empty', esc_html__( 'terms data cannot be empty.', 'et_builder_5' ) );
		}

		foreach ( $data as $single_item ) {
			if ( ! $filter_type ) {
				$filter_type = $single_item['filterType'];
			}

			$taxonomy = 'tags' === $single_item['filterType'] ? 'layout_tag' : 'layout_category';

			switch ( $single_item['updateType'] ) {
				case 'remove':
					$term_id = (int) $single_item['id'];
					wp_delete_term( $term_id, $taxonomy );
					break;
				case 'rename':
					$term_id  = (int) $single_item['id'];
					$new_name = (string) $single_item['newName'];

					if ( '' !== $new_name ) {
						$updated_term_data = wp_update_term( $term_id, $taxonomy, [ 'name' => $new_name ] );

						if ( ! is_wp_error( $updated_term_data ) ) {
							$new_terms[] = [
								'name'     => $new_name,
								'id'       => $updated_term_data['term_id'],
								'location' => 'local',
							];
						}
					}
					break;
				case 'add':
					$term_name     = (string) $single_item['id'];
					$new_term_data = wp_insert_term( $term_name, $taxonomy );

					if ( ! is_wp_error( $new_term_data ) ) {
						$new_terms[] = [
							'name'     => $term_name,
							'id'       => $new_term_data['term_id'],
							'location' => 'local',
						];
					}
					break;
			}
		}

		return self::response_success(
			[
				'newFilters'        => $new_terms,
				'filterType'        => $filter_type,
				'localLibraryTerms' => [
					'layout_category' => LibraryUtility::prepare_library_terms(),
					'layout_tag'      => LibraryUtility::prepare_library_terms( 'layout_tag' ),
				],
			]
		);
	}

	/**
	 * Retrieves the arguments array for update action.
	 *
	 * This function returns an array containing the arguments for update action, used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array The array containing the arguments for update action.
	 */
	public static function update_args(): array {
		return [
			'data' => [
				'required'          => true,
				'sanitize_callback' => function ( $data ) {
					$sanitized = [];

					foreach ( $data as $item ) {
						if ( ! $item || ! is_array( $item ) ) {
							continue;
						}

						$sanitized_item = [];

						foreach ( $item as $key => $value ) {
							$sanitized_item[ $key ] = sanitize_text_field( $value );
						}

						$sanitized[] = $sanitized_item;
					}

					return $sanitized;
				},
			],
		];
	}

	/**
	 * Check if user has permission for update action.
	 *
	 * This function checks if the current user has the capability to edit posts, used in `register_rest_route()`.
	 * If the user does not have the necessary permissions, an error response is returned.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the required permissions, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * $result = DiviLibrary::update_permission();
	 *
	 * if ( is_wp_error( $result ) ) {
	 *     echo $result->get_error_message();
	 * } else {
	 *     echo "Permission granted.";
	 * }
	 * ```
	 */
	public static function update_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}

		/**
		 * Create a new item in the WordPress REST API.
		 *
		 * This function receives a `WP_REST_Request` object and creates a new item based on the provided parameters.
		 * It saves the layout to the database and returns the layout data, updated terms, and save verification result.
		 *
		 * @since ??
		 *
		 * @param WP_REST_Request $request {
		 *     The REST request object.
		 *
		 *     @type string $layout_name          The name of the new layout.
		 *     @type string $layout_type          The type of the new layout.
		 *     @type string $post_type            The post type of the new layout.
		 *     @type string $layout_selected_cats The selected categories of the new layout.
		 *     @type string $layout_selected_tags The selected tags of the new layout.
		 *     @type string $layout_new_cat       The new category of the new layout.
		 *     @type string $layout_new_tag       The new tag of the new layout.
		 *     @type string $columns_layout       The columns layout of the new layout.
		 *     @type string $layout_content       The content of the new layout.
		 *     @type string $module_type          The module type of the new layout.
		 *     @type string $module_width         The module width of the new layout.
		 *     @type string $layout_scope         The scope of the new layout.
		 * }
		 *
		 * @return array|WP_Error {
		 *     The layout data, updated terms, and save verification result, or a `WP_Error` when the
		 *     submitted global variables could not be saved, in which case nothing is imported.
		 *
		 *     @type array $layoutData       The meta data of the new layout, including post ID, name, type, and scope.
		 *     @type array $updatedTerms     An array containing updated terms for layout categories and tags.
		 *     @type bool  $saveVerification The result of the save verification.
		 *     @type array $globalVariables  The persisted global variables. Only present when the request
		 *                                   carried global variables.
		 * }
		 *
		 * @example:
		 * ```php
		 *     // Creating a new item with layout content and custom parameters
		 *     $request = new \WP_REST_Request('POST');
		 *     $request->set_param('post_type', 'layout');
		 *     $request->set_param('layout_content', 'Lorem ipsum dolor sit amet');
		 *     $request->set_param('layout_name', 'My Layout');
		 *     // ... provide other parameters as needed
		 *
		 *     $response = DiviLibrary::create_item($request);
		 * ```

		 * @example:
		 * ```php
		 *     // Creating a new item with default parameters
		 *     $request = new \WP_REST_Request('POST');
		 *
		 *     $response = DiviLibrary::create_item($request);
		 * ```

		 * @example:
		 * ```php
		 *     // Creating a new item in a specific post type and with specific layout content
		 *     $request = new \WP_REST_Request('POST');
		 *     $request->set_param('post_type', 'page');
		 *     $request->set_param('layout_content', 'Sed ut perspiciatis unde omnis iste natus error');
		 *
		 *     $response = DiviLibrary::create_item($request);
		 * ```

		 * @example:
		 * ```php
		 *     // Creating a new item with empty layout content
		 *     $request = new \WP_REST_Request('POST');
		 *     $request->set_param('layout_content', '');
		 *
		 *     $response = DiviLibrary::create_item($request);
		 * ```
		 */
	public static function create_item( WP_REST_Request $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( et_theme_builder_is_layout_post_type( $post_type ) ) {
			// Treat TB layouts as normal posts when storing layouts from the library.
			$post_type = 'page';
		}

		$layout_content = $request->get_param( 'layout_content' );

		// Apply server-side D4-to-D5 conversion and D5 migrations before saving to library.
		if ( ! empty( $layout_content ) ) {
			if ( Conditions::has_shortcode( '', $layout_content ) ) {
				BuilderConversion::initialize_shortcode_framework();
				do_action( 'divi_visual_builder_before_d4_conversion' );
				$layout_content = BuilderConversion::maybeConvertContent( $layout_content, true, null, true );
			}

			$layout_content = apply_filters( 'divi_framework_portability_import_migrated_post_content', $layout_content );
		}

		// Prepare args for saving layout to the database.
		$args = [
			'layout_name'          => $request->get_param( 'layout_name' ),
			'template_type'        => $request->get_param( 'layout_type' ),
			'layout_type'          => $request->get_param( 'layout_type' ),
			'built_for_post_type'  => $post_type,
			'layout_scope'         => $request->get_param( 'layout_scope' ),
			'layout_selected_cats' => $request->get_param( 'layout_selected_cats' ),
			'layout_selected_tags' => $request->get_param( 'layout_selected_tags' ),
			'layout_new_cat'       => $request->get_param( 'layout_new_cat' ),
			'layout_new_tag'       => $request->get_param( 'layout_new_tag' ),
			'columns_layout'       => $request->get_param( 'columns_layout' ),
			'module_type'          => $request->get_param( 'module_type' ),
			'module_width'         => $request->get_param( 'module_width' ),
			'layout_content'       => wp_slash( $layout_content ),
			'post_date'            => $request->get_param( 'post_date' ),
		];

		$presets = json_decode( $request->get_param( 'presets' ) ?? '[]', true );
		$images  = json_decode( $request->get_param( 'images' ) ?? '[]', true );

		// Global variables must be saved before anything else is written, so a rejected save cannot leave
		// the imported layout, presets or colors referencing variables that do not exist. Paginated image
		// uploads resend the same request for every page, so the variables are only saved by the first one.
		$global_variables_param = $request->get_param( 'global_variables' );
		$import_page            = (int) ( $request->get_param( 'page' ) ?? 1 );
		if ( ! empty( $global_variables_param ) && 1 >= $import_page ) {
			$portability_post      = new PortabilityPost( 'et_builder' );
			$global_variables_data = is_string( $global_variables_param )
				? json_decode( $global_variables_param, true )
				: $global_variables_param;

			if ( ! empty( $global_variables_data ) && is_array( $global_variables_data ) ) {
				// Flatten array of arrays into single array of variable objects.
				// Client sends [[variables from file 1], [variables from file 2], ...].
				$flattened_variables = [];
				foreach ( $global_variables_data as $variables_from_file ) {
					if ( is_array( $variables_from_file ) ) {
						$flattened_variables = array_merge( $flattened_variables, $variables_from_file );
					}
				}

				if ( ! empty( $flattened_variables ) ) {
					$global_variables_import_error = null;
					$portability_post->import_global_variables( $flattened_variables, $global_variables_import_error );

					if ( is_wp_error( $global_variables_import_error ) ) {
						return self::response_error(
							'global_variables_import_failed',
							esc_html__( 'The global variables in this file could not be saved, so nothing was imported. Please reload the builder and try again.', 'et_builder_5' ),
							[],
							409
						);
					}
				}
			}
		}

		if ( ! empty( $presets ) ) {
			do_action( 'et_pb_before_library_preset_import' );

			GlobalPreset::process_presets_for_import( $presets );
		}

		// Import global colors BEFORE processing layout content.
		// This ensures global colors are available when layout items are saved.
		$global_colors_param = $request->get_param( 'global_colors' );
		if ( ! empty( $global_colors_param ) ) {
			$portability_post   = new PortabilityPost( 'et_builder' );
			$global_colors_data = is_string( $global_colors_param )
				? json_decode( $global_colors_param, true )
				: $global_colors_param;

			if ( ! empty( $global_colors_data ) && is_array( $global_colors_data ) ) {
				// Flatten array of arrays into single array of color data.
				// Client sends [[colors from file 1], [colors from file 2], ...].
				$flattened_colors = [];
				foreach ( $global_colors_data as $colors_from_file ) {
					if ( is_array( $colors_from_file ) ) {
						$flattened_colors = array_merge( $flattened_colors, $colors_from_file );
					}
				}

				if ( ! empty( $flattened_colors ) ) {
					$global_colors_imported = $portability_post->import_global_colors( $flattened_colors );
					if ( ! empty( $global_colors_imported ) ) {
						GlobalData::set_global_colors( $global_colors_imported, true );
					}
				}
			}
		}

		// Update images in content if present (may return pagination response).
		$updated_layout_content = $args['layout_content'];
		if ( ! empty( $images ) ) {
			$updated_layout_content = self::_update_images( $images, $args['layout_content'], $request );
			$args['layout_content'] = $updated_layout_content;
		}

		// Save layout to the database.
		$new_layout_meta = json_decode( et_pb_submit_layout( $args ), true );

		// Mark imported layout as D5 format.
		if ( ! empty( $new_layout_meta['post_id'] ) ) {
			update_post_meta( (int) $new_layout_meta['post_id'], '_et_pb_use_divi_5', 'on' );
		}

		foreach ( [ 'layout_category', 'layout_tag' ] as $taxonomy ) {
			// phpcs:ignore WordPress.WP.DeprecatedParameters.Get_termsParam2Found -- Using legacy format for compatibility. hide_empty is needed to get all terms.
			$raw_terms_defaults = get_terms( $taxonomy, [ 'hide_empty' => false ] );

			/**
			 * Filter to modify the default terms for the layout_category and layout_tag taxonomies.
			 *
			 * @since ??
			 * @deprecated 5.0.0 Use the {@see 'divi_visual_builder_rest_divi_library_new_layout_taxonomy_terms'} filter instead.
			 *
			 * @param array $raw_terms_defaults Array of default terms.
			 */
			$raw_terms_defaults = apply_filters(
				'et_pb_new_layout_cats_array',
				$raw_terms_defaults
			);

			/**
			 * Filter to modify the default terms for the layout_category and layout_tag taxonomies.
			 *
			 * @since ??
			 *
			 * @param array $raw_terms_defaults Array of default terms.
			 */
			$raw_terms_array = apply_filters( 'divi_visual_builder_rest_divi_library_new_layout_taxonomy_terms', $raw_terms_defaults );

			$clean_terms_array = [];

			if ( is_array( $raw_terms_array ) && ! empty( $raw_terms_array ) ) {
				foreach ( $raw_terms_array as $term ) {
					$clean_terms_array[] = [
						'name' => html_entity_decode( $term->name ),
						'id'   => $term->term_id,
						'slug' => $term->slug,
					];
				}
			}

			$updated_terms[ $taxonomy ] = $clean_terms_array;
		}

		$saved_layout_content = get_post_field( 'post_content', $new_layout_meta['post_id'], 'raw' );
		$verification         = $layout_content === $saved_layout_content;

		/**
		 * Filter to modify the save verification result.
		 *
		 * @since ??
		 * @deprecated 5.0.0 Use the {@see 'divi_visual_builder_rest_divi_library_save_verification'} filter instead.
		 *
		 * @param bool $verification Whether to save the verification result.
		 */
		$verification = apply_filters(
			'et_fb_ajax_save_verification_result',
			$verification
		);

		/**
		 * Filter to modify the save verification result.
		 *
		 * @since ??
		 *
		 * @param bool $verification Whether to save the verification result.
		 */
		$save_verification = apply_filters( 'divi_visual_builder_rest_divi_library_save_verification', $verification );

		$response = [
			'layoutData'       => $new_layout_meta,
			'updatedTerms'     => $updated_terms,
			'saveVerification' => $save_verification,
		];

		// The imported variables are already persisted, so the client must confirm them in its saved snapshot or
		// its next variable save is rejected as stale. Later pagination pages return the same persisted catalog.
		if ( ! empty( $global_variables_param ) ) {
			$response['globalVariables'] = GlobalData::get_global_variables();
		}

		return $response;
	}


	/**
	 * Update images data.
	 *
	 * This function updates the images data in the layout content.
	 *
	 * @since ??
	 *
	 * @param array           $images  The images data to be updated.
	 * @param string          $content The layout content.
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return string The updated layout content.
	 */
	protected static function _update_images( $images, $content, WP_REST_Request $request ) {
		$context                = 'et_builder_layouts';
		$portability_registered = et_core_cache_get( $context, 'et_core_portability' );

		if ( ! $portability_registered ) {
			et_core_portability_register(
				$context,
				[
					'name' => esc_html__( 'Divi Builder Layout', 'et_builder_5' ),
					'type' => 'post_type',
					'view' => true,
				]
			);
		}

		$portability_post = new PortabilityPost( $context );

		// Get or generate timestamp for pagination.
		$timestamp = $request->has_param( 'timestamp' ) ? $request->get_param( 'timestamp' ) : (string) microtime( true );

		// Set pagination parameters from request if present.
		$portability_post->set_param( 'timestamp', $timestamp );

		if ( $request->has_param( 'page' ) ) {
			$portability_post->set_param( 'page', $request->get_param( 'page' ) );
		}

		$temp_id = wp_rand();
		$images  = $portability_post->maybe_paginate_images( $images, 'upload_images', $timestamp );
		$content = $portability_post->replace_images_urls( $images, [ $temp_id => $content ] );

		return $content[ $temp_id ];
	}

	/**
	 * Retrieves the arguments array for create item action.
	 *
	 * This function returns an array containing the arguments for create item action used, in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array The array containing the arguments for create item action.
	 */
	public static function create_item_args(): array {
		return [
			'layout_name'          => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'layout_type'          => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'layout',
			],
			'post_type'            => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'page',
			],
			'layout_selected_cats' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
			'layout_selected_tags' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
			'layout_new_cat'       => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
			'layout_new_tag'       => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
			'columns_layout'       => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			],
			'module_type'          => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'divi/shortcode-module',
			],
			'module_width'         => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'regular',
			],
			'layout_content'       => [
				'required'          => false,
				'sanitize_callback' => [
					__CLASS__,
					'sanitize_layout_content',
				],
				'default'           => '',
			],
			'presets'              => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
			'global_colors'        => [
				'required'          => false,
				'sanitize_callback' => [ PortabilityController::class, 'sanitize_json_param' ],
				'default'           => '',
			],
			'global_variables'     => [
				'required'          => false,
				'sanitize_callback' => [ PortabilityController::class, 'sanitize_json_param' ],
				'default'           => '',
			],
			'post_date'            => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
		];
	}


	/**
	 * Check if user has permission to create an item.
	 *
	 * This function checks if the current user has the capability to edit posts and if they are allowed
	 * to save items to the Divi library.
	 * If the user does not have the necessary permissions, an error response is returned.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the required permissions, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * $result = DiviLibrary::create_item_permission();
	 *
	 * if ( is_wp_error( $result ) ) {
	 *     echo $result->get_error_message();
	 * } else {
	 *     echo "Permission granted.";
	 * }
	 * ```
	 */
	public static function create_item_permission() {
		$is_allowed_save_to_library = et_pb_is_allowed( 'divi_library' ) && et_pb_is_allowed( 'save_library' );

		if ( ! current_user_can( 'edit_posts' ) || ! $is_allowed_save_to_library ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Sanitizes the layout content, preparing it for database storage.
	 *
	 * This function sanitizes the layout content using `SavingUtility::prepare_content_for_db()`.
	 * It ensures that the content is safe to be stored in the database.
	 *
	 * @since ??
	 *
	 * @param string $layout_content The layout content to be sanitized.
	 *
	 * @return string The sanitized layout content.
	 */
	public static function sanitize_layout_content( string $layout_content ): string {
		return SavingUtility::prepare_content_for_db( $layout_content );
	}

	/**
	 * Get cloud access token using a `WP_REST_Request` object.
	 *
	 * This method retrieves the token and returns it as a response.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response object.
	 *
	 * @example:
	 * ```php
	 * // Example usage when loading a post with valid post ID.
	 * $request = new WP_REST_Request( 'GET', '/my-api/v1/cloud-token' );
	 *
	 * $response = DiviLibraryController::load( $request );
	 * ```
	 */
	public static function get_token( WP_REST_Request $request ) {
		$token = [
			'cloudToken' => get_transient( 'et_cloud_access_token' ),
		];

		return self::response_success( $token );
	}

	/**
	 * Load the arguments required for the token.
	 * This function returns an array of arguments required for a token used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array Empty array.
	 */
	public static function get_token_args(): array {
		return [];
	}

	/**
	 * Check permission.
	 *
	 * @return bool|WP_Error
	 * @since ??
	 */
	/**
	 * Check edit posts permission for current user.
	 *
	 * Checks if the current user has the capability to edit posts.
	 * If the user does not have the capability, it returns a permission error response.
	 * Otherwise, it returns `true`.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the capability to edit posts, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * // Check if the current user has the capability to edit posts.
	 * $result = DiviLibraryController::get_token_permission();
	 *
	 * if ( $result instanceof \WP_Error ) {
	 *     // Handle error response.
	 * } else {
	 *     // Continue with execution.
	 * }
	 * ```
	 */
	public static function get_token_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Get a list of library layouts.
	 *
	 * Retrieves a list of library layouts and returns a `WP_REST_Response` object with the options.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request     The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error  The `WP_REST_Response` object with the options, or a `WP_Error` object if the request fails.
	 *
	 * @example:
	 * ```php
	 * $request = new \WP_REST_Request();
	 * $request->set_param( 'postId', '123' );
	 *
	 * $response = DiviLibrary::index( $request );
	 * ```
	 *
	 * @example:
	 * ```php
	 * $request = new \WP_REST_Request();
	 * $request->set_param( 'postId', '456' );
	 *
	 * $response = DiviLibrary::index( $request );
	 * ```
	 */
	public static function index( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );

		if ( $type ) {
			$data  = [];
			$types = explode( ',', $type );

			foreach ( $types as $data_type ) {

				switch ( $data_type ) {
					case 'page':
						$exclude = [];

						if ( $request->get_param( 'exclude' ) ) {
							$exclude = array_map( 'intval', explode( ',', $request->get_param( 'exclude' ) ) );
						}

						$data[ $data_type ] = \ET_Builder_Library::instance()->builder_library_modal_custom_tabs_existing_pages( $exclude );
						break;

					default:
						$saved_data = \ET_Builder_Library::instance()->builder_library_layouts_data( $data_type );

						if ( is_wp_error( $saved_data ) ) {
							$error_data = $saved_data->get_error_data( $saved_data->get_error_code() );
							$status     = is_array( $error_data ) && ! empty( $error_data['status'] )
								? absint( $error_data['status'] )
								: 503;

							if ( is_array( $error_data ) ) {
								$error_data['failed_type'] = $data_type;
							} else {
								$error_data = [
									'failed_type' => $data_type,
								];
							}

							return self::response_error(
								$saved_data->get_error_code(),
								$saved_data->get_error_message(),
								$error_data,
								$status
							);
						}

						if ( isset( $saved_data['layouts_data'] ) ) {
							$component_orders = get_option( '_et_pb_component_manager_order', [] );
							$component_order  = is_array( $component_orders ) && isset( $component_orders[ $data_type ] )
								? $component_orders[ $data_type ]
								: [];

							$saved_data['layouts_data']['componentOrder'] = $component_order;
							$data[ $data_type ]                           = $saved_data['layouts_data'];
						}
				}
			}

			if ( $data ) {
				return self::response_success( $data );
			}
		}

		return self::response_error( 'no_entries_found', esc_html__( 'No entries found.', 'et_builder_5' ) );
	}

	/**
	 * Updates the saved order of Components within a module type.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response object.
	 */
	public static function update_component_order( WP_REST_Request $request ) {
		$library_type  = $request->get_param( 'library_type' );
		$module_name   = $request->get_param( 'module_name' );
		$component_ids = $request->get_param( 'component_ids' );

		if ( ! in_array( $library_type, [ 'module', 'row', 'section' ], true ) ) {
			return self::response_error( 'invalid_library_type', esc_html__( 'Invalid library type.', 'et_builder_5' ) );
		}

		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return self::response_error( 'missing_module_name', esc_html__( 'Module name is required.', 'et_builder_5' ) );
		}

		if ( ! is_array( $component_ids ) || empty( $component_ids ) ) {
			return self::response_error( 'missing_component_ids', esc_html__( 'Component IDs array is required.', 'et_builder_5' ) );
		}

		if ( self::_has_too_many_component_order_ids( $component_ids ) ) {
			return self::response_error( 'too_many_component_ids', esc_html__( 'Too many Component IDs were provided.', 'et_builder_5' ) );
		}

		$sanitized_component_ids = array_values( array_unique( array_map( 'absint', $component_ids ) ) );

		foreach ( $sanitized_component_ids as $component_id ) {
			if ( 0 >= $component_id || ET_BUILDER_LAYOUT_POST_TYPE !== get_post_type( $component_id ) ) {
				return self::response_error( 'invalid_component', esc_html__( 'One or more Components are invalid.', 'et_builder_5' ) );
			}

			if ( ! current_user_can( 'edit_post', $component_id ) ) {
				return self::response_error_permission();
			}

			$scope_terms = wp_get_post_terms( $component_id, 'scope', [ 'fields' => 'slugs' ] );
			if ( is_wp_error( $scope_terms ) || ! in_array( 'global', $scope_terms, true ) ) {
				return self::response_error( 'invalid_component_scope', esc_html__( 'One or more items are not Components.', 'et_builder_5' ) );
			}

			$layout_types = wp_get_post_terms( $component_id, 'layout_type', [ 'fields' => 'slugs' ] );
			if ( is_wp_error( $layout_types ) || ! in_array( $library_type, $layout_types, true ) ) {
				return self::response_error( 'component_type_mismatch', esc_html__( 'Component type mismatch.', 'et_builder_5' ) );
			}

			if ( 'module' === $library_type ) {
				$component_module_type = (string) get_post_meta( $component_id, '_et_pb_module_type', true );
				$resolved_module_name  = self::_resolve_component_module_name( $component_module_type );

				if ( $module_name !== $resolved_module_name ) {
					return self::response_error( 'component_module_mismatch', esc_html__( 'Components must use the same module type.', 'et_builder_5' ) );
				}
			}
		}

		if ( 'module' !== $library_type && "divi/{$library_type}" !== $module_name ) {
			return self::response_error( 'component_module_mismatch', esc_html__( 'Component module name does not match its library type.', 'et_builder_5' ) );
		}

		$component_orders = get_option( '_et_pb_component_manager_order', [] );
		$component_orders = is_array( $component_orders ) ? $component_orders : [];

		if ( ! isset( $component_orders[ $library_type ] ) || ! is_array( $component_orders[ $library_type ] ) ) {
			$component_orders[ $library_type ] = [];
		}

		$component_orders[ $library_type ][ $module_name ] = $sanitized_component_ids;
		update_option( '_et_pb_component_manager_order', $component_orders );

		return self::response_success( [] );
	}

	/**
	 * Resolves a stored library module type to its registered Divi 5 block name.
	 *
	 * @since ??
	 *
	 * @param string $module_type Stored module type.
	 *
	 * @return string Resolved module name, or an empty string when it cannot be resolved.
	 */
	private static function _resolve_component_module_name( string $module_type ): string {
		$normalized_module_type = strtolower( trim( $module_type ) );

		if ( str_starts_with( $normalized_module_type, 'divi/' ) ) {
			return $normalized_module_type;
		}

		$normalized_shortcode = preg_replace( '/^et_pb_/', '', $normalized_module_type );

		foreach ( BuilderConversion::getModuleCollections() as $module ) {
			$d4_shortcode = strtolower( $module['d4Shortcode'] ?? '' );

			if (
				$d4_shortcode === $normalized_module_type
				|| $d4_shortcode === $normalized_shortcode
				|| "et_pb_{$d4_shortcode}" === $normalized_module_type
			) {
				return (string) ( $module['name'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Whether an order request contains more IDs than the library can contain.
	 *
	 * The published layout count is a cheap upper bound that avoids rejecting
	 * legitimate Component groups while stopping oversized per-ID lookup loops.
	 *
	 * @since ??
	 *
	 * @param array $component_ids Component IDs.
	 *
	 * @return bool Whether the request exceeds the upper bound.
	 */
	private static function _has_too_many_component_order_ids( array $component_ids ): bool {
		$post_counts          = wp_count_posts( ET_BUILDER_LAYOUT_POST_TYPE );
		$published_count      = isset( $post_counts->publish ) ? absint( $post_counts->publish ) : 0;
		$unique_component_ids = array_unique( array_map( 'absint', $component_ids ) );

		return max( 1, $published_count ) < count( $unique_component_ids );
	}

	/**
	 * Get arguments for updating Component order.
	 *
	 * @since ??
	 *
	 * @return array The route arguments.
	 */
	public static function update_component_order_args(): array {
		return [
			'library_type'  => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			],
			'module_name'   => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'component_ids' => [
				'required'          => true,
				'validate_callback' => function ( $value ) {
					return is_array( $value ) && ! self::_has_too_many_component_order_ids( $value );
				},
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? array_map( 'absint', $value ) : [];
				},
			],
		];
	}

	/**
	 * Check permission to update Component order.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return bool|WP_Error Whether the request is allowed.
	 */
	public static function update_component_order_permission( WP_REST_Request $request ) {
		$is_allowed = current_user_can( 'edit_posts' )
			&& et_pb_is_allowed( 'divi_library' )
			&& et_pb_is_allowed( 'save_library' )
			&& et_pb_is_allowed( 'edit_global_library' );

		if ( ! $is_allowed ) {
			return self::response_error_permission();
		}

		$component_ids = $request->get_param( 'component_ids' );

		if ( is_array( $component_ids ) && self::_has_too_many_component_order_ids( $component_ids ) ) {
			return self::response_error( 'too_many_component_ids', esc_html__( 'Too many Component IDs were provided.', 'et_builder_5' ) );
		}

		if ( is_array( $component_ids ) ) {
			foreach ( array_unique( array_map( 'absint', $component_ids ) ) as $component_id ) {
				if ( 0 >= $component_id || ( get_post( $component_id ) && ! current_user_can( 'edit_post', $component_id ) ) ) {
					return self::response_error_permission();
				}
			}
		}

		return true;
	}

	/**
	 * Retrieves the arguments array for index action.
	 *
	 * This function returns an array containing the arguments for index action, used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array The array containing the arguments for index action.
	 */
	public static function index_args(): array {
		return [
			'type'    => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'exclude' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Check if user has permission for index action.
	 *
	 * This function checks if the current user has the capability to edit posts, used in `register_rest_route()`.
	 * If the user does not have the necessary permissions, an error response is returned.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the required permissions, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * $result = DiviLibrary::index_permission();
	 *
	 * if ( is_wp_error( $result ) ) {
	 *     echo $result->get_error_message();
	 * } else {
	 *     echo "Permission granted.";
	 * }
	 * ```
	 */
	public static function index_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		if ( ! et_pb_is_allowed( 'divi_library' ) ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Update the location of a Divi Builder layout.
	 *
	 * This function is used to handle the item location action in the Divi Library.
	 * It retrieves the post or layout content and returns it as a response.
	 * If the content is too large, it also includes a custom HTTP header 'X-Content-Length' as a fallback for the Content-Length header.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The request object containing the necessary parameters.
	 *
	 * @return WP_REST_Response|WP_Error Returns a success `WP_REST_Response` object containing the retrieved content.
	 *                                   If the post or layout is not found, it returns a `WP_Error` object with an error message.
	 *
	 * @example:
	 * ```php
	 * $request = new WP_REST_Request( 'GET' );
	 * // Set necessary parameters in the request as needed
	 * $response = DiviLibrary::item_location( $request );
	 *
	 * // Do something with the response
	 * ```
	 */
	public static function item_location( WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$unsupported_post_types = [
			ET_BUILDER_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_TEMPLATE_POST_TYPE,
			ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_THEME_BUILDER_POST_TYPE,
		];

		if ( ! in_array( get_post_type( $id ), $unsupported_post_types, true ) ) {
			return self::response_error( 'unsupported_post_type', esc_html__( 'Unsupported post type.', 'et_builder_5' ) );
		}

		wp_delete_post( $id, true );

		return self::response_success(
			[
				'localLibraryTerms' => [
					'layout_category' => LibraryUtility::prepare_library_terms(),
					'layout_tag'      => LibraryUtility::prepare_library_terms( 'layout_tag' ),
				],
			]
		);
	}

	/**
	 * Get arguments for show action.
	 *
	 * Defines an array of arguments for the show action used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array An array of arguments for show action.
	 */
	public static function item_location_args(): array {
		return [
			'id' => [
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Check if user has permission for show action.
	 *
	 * Checks if the current user has the permission to edit posts, used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has permission, or `WP_Error` if the user does not have permission.
	 *
	 * @example:
	 * ```php
	 *     // Check if the user has permission to edit posts
	 *     $permission = DiviLibrary::show_permission();
	 *     if ( $permission instanceof WP_Error ) {
	 *         echo $permission->get_error_message();
	 *     } else {
	 *         echo "Permission granted!";
	 *     }
	 * ```
	 */
	public static function item_location_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Load a library item by ID using a `WP_REST_Request` object.
	 *
	 * This method retrieves a post by the provided post ID and returns it as a response.
	 * If the post ID is invalid or the post is not found, an `WP_Error` object response is returned.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response object.
	 *
	 * @example:
	 * ```php
	 * // Example usage when loading a post with valid post ID.
	 * $request = new WP_REST_Request( 'GET', '/my-api/v1/load-post' );
	 * $request->set_param( 'post_id', 123 );
	 *
	 * $response = DiviLibraryController::load( $request );
	 * ```
	 *
	 * @example:
	 * ```php
	 * // Example usage when loading a post with invalid post ID.
	 * $request = new WP_REST_Request( 'GET', '/my-api/v1/load-post' );
	 * $request->set_param( 'post_id', 'abc' );
	 *
	 * $response = DiviLibraryController::load( $request );
	 * ```
	 */
	public static function load( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return self::response_error( 'invalid_post_id', esc_html__( 'Invalid Post ID.', 'et_builder_5' ) );
		}

		$post = DiviLibraryUtility::get_post(
			$post_id,
			[
				'post_type'   => ET_BUILDER_LAYOUT_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		if ( ! $post ) {
			return self::response_error( 'post_not_found', esc_html__( 'Post not found.', 'et_builder_5' ) );
		}

		return self::response_success( DiviLibraryUtility::prepare_post_for_load( $post ) );
	}

	/**
	 * Load the arguments required for a post.
	 *
	 * This function returns an array of arguments required for a post used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array {
	 *     An array of arguments required for a specific post ID.
	 *
	 *     @type array $post_id The arguments for the post.
	 * }
	 */
	public static function load_args(): array {
		return [
			'post_id' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Check permission.
	 *
	 * @return bool|WP_Error
	 * @since ??
	 */
	/**
	 * Check edit posts permission for current user.
	 *
	 * Checks if the current user has the capability to edit posts.
	 * If the user does not have the capability, it returns a permission error response.
	 * Otherwise, it returns `true`.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the capability to edit posts, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * // Check if the current user has the capability to edit posts.
	 * $result = DiviLibraryController::load_permission();
	 *
	 * if ( $result instanceof \WP_Error ) {
	 *     // Handle error response.
	 * } else {
	 *     // Continue with execution.
	 * }
	 * ```
	 */
	public static function load_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Save library item to the database.
	 *
	 * This function receives a `WP_REST_Request` object that contains the layout ID and content parameters.
	 * It then creates an array with the necessary information to update the post's content in the database.
	 * The post_content parameter is fetched from the request using the `content`'key, and the ID is fetched
	 * using the ``layout_id` key. Once the array is created, it is passed to the `wp_update_post()` function to
	 * update the post's content in the database.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The request received with the layout ID and content parameters.
	 *
	 * @return int|WP_Error The ID of the updated post or a `WP_Error` object if the post was not updated.
	 *
	 * @example:
	 * ```php
	 * // Update layout with ID 123 and set its content to 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'
	 * $request = new WP_REST_Request( 'POST' );
	 * $request->set_param( 'layout_id', 123 );
	 * $request->set_param( 'content', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.' );
	 *
	 * $result = DiviLibrary::save( $request );
	 *
	 * if ( is_wp_error( $result ) ) {
	 *     // Handle error.
	 * } else {
	 *     // Post was updated successfully.
	 *     echo 'Layout updated with ID: ' . $result;
	 * }
	 * ```
	 */
	public static function save( WP_REST_Request $request ) {
		$layout_id = absint( $request->get_param( 'layout_id' ) );
		$content   = (string) $request->get_param( 'content' );

		if ( GlobalLayout::has_component_reference_cycle( $layout_id, $content ) ) {
			return new WP_Error(
				'divi_component_reference_cycle',
				esc_html__( 'A Component cannot be placed inside itself.', 'et_builder_5' ),
				[ 'status' => 400 ]
			);
		}

		// Apply wp_slash, so the content can be properly processed by wp_update_post and all escaped characters decoded.
		$save = [
			'ID'           => $layout_id,
			'post_content' => wp_slash( $content ),
		];

		return wp_update_post( $save );
	}

	/**
	 * Get arguments for save action.
	 *
	 * Defines an array of arguments for the save action used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array An array of arguments for save action.
	 */
	public static function save_args(): array {
		return [
			'layout_id'     => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'content'       => [
				'required'          => true,
				'sanitize_callback' => [
					__CLASS__,
					'sanitize_layout_content',
				],
			],
			'ai_agent_tool' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
		];
	}

	/**
	 * Check if user has permission for save action.
	 *
	 * Checks if the current user can edit posts and global library items, and that
	 * `layout_id` is an existing `et_pb_layout` the user can edit.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The request containing the layout ID.
	 *
	 * @return bool|WP_Error Returns `true` if the user has permission, or `WP_Error` if the user does not have permission.
	 *
	 * @example:
	 * ```php
	 * $request = new WP_REST_Request( 'POST' );
	 * $request->set_param( 'layout_id', 123 );
	 *
	 * $permission = DiviLibraryController::save_permission( $request );
	 *
	 * if ( $permission instanceof WP_Error ) {
	 *     echo $permission->get_error_message();
	 * } else {
	 *     echo 'Permission granted.';
	 * }
	 * ```
	 */
	public static function save_permission( WP_REST_Request $request ) {
		$is_allowed_edit_global_items = et_pb_is_allowed( 'edit_global_library' );

		if ( ! current_user_can( 'edit_posts' ) || ! $is_allowed_edit_global_items ) {
			return self::response_error_permission();
		}

		$layout_id = absint( $request->get_param( 'layout_id' ) );

		if ( 'et_pb_layout' !== get_post_type( $layout_id ) || ! current_user_can( 'edit_post', $layout_id ) ) {
			return self::response_error_permission();
		}

		$tool_name     = (string) $request->get_param( 'ai_agent_tool' );
		$allowed_tools = [ 'edit_component_attributes', 'update_component' ];

		if ( ! in_array( $tool_name, $allowed_tools, true ) ) {
			$tool_name = '';
		}

		return AiAgentApprovalTokens::authorize_after_caps(
			$request,
			$tool_name,
			[ 'layout_id' => $layout_id ],
			true
		);
	}

	/**
	 * Upload library featured image using a `WP_REST_Request` object.
	 *
	 * This method retrieves the token and returns it as a response.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response object.
	 *
	 * @example:
	 * ```php
	 * // Example usage when loading a post with valid post ID.
	 * $request = new WP_REST_Request( 'GET', '/my-api/v1/upload_image' );
	 *
	 * $response = DiviLibraryController::upload_image( $request );
	 * ```
	 */
	public static function upload_image( WP_REST_Request $request ) {
		// Require necessary files for media_handle_sideload to work.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post_id       = $request->get_param( 'postId' );
		$image_url_raw = $request->get_param( 'imageURL' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::response_error_permission();
		}

		$upload         = media_sideload_image( $image_url_raw, $post_id, $post_id, 'id' );
		$attachment_id  = is_wp_error( $upload ) ? 0 : $upload;
		$image_url      = get_attached_file( $attachment_id );
		$image_metadata = wp_generate_attachment_metadata( $attachment_id, $image_url );

		wp_update_attachment_metadata( $attachment_id, $image_metadata );

		set_post_thumbnail( $post_id, $attachment_id );

		return self::response_success();
	}

	/**
	 * Load the arguments required for the token.
	 * This function returns an array of arguments required for a token used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array Empty array.
	 */
	public static function upload_image_args(): array {
		return [
			'postId'   => [
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'imageURL' => [
				'required'          => true,
				'sanitize_callback' => 'esc_url_raw',
			],
		];
	}

	/**
	 * Check edit posts and library permissions for current user.
	 *
	 * Checks if the current user has the capability to edit posts.
	 * If the user does not have the capability, it returns a permission error response.
	 * Otherwise, it returns `true`.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the capability to edit posts, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * // Check if the current user has the capability to edit posts.
	 * $result = DiviLibraryController::upload_image_permission();
	 *
	 * if ( $result instanceof \WP_Error ) {
	 *     // Handle error response.
	 * } else {
	 *     // Continue with execution.
	 * }
	 * ```
	 */
	public static function upload_image_permission() {
		$is_allowed_save_to_library = et_pb_is_allowed( 'divi_library' ) && et_pb_is_allowed( 'save_library' );

		if ( ! current_user_can( 'edit_posts' ) || ! $is_allowed_save_to_library ) {
			return self::response_error_permission();
		}

		return true;
	}

	/**
	 * Update library item.
	 *
	 * This function takes a `WP_REST_Request` object as a parameter and updates library item terms based on the `data` parameter of the request.
	 *
	 * @since ??
	 *
	 * @param WP_REST_Request $request {
	 *     The REST request object.
	 *
	 *     @type array $data {
	 *         Item update payload.
	 *
	 *         @type array $clickedItem {
	 *            Clicked item details.
	 *         }

	 *         @type string $isCloudItem Is item coming from cloud or local item.
	 *         @type array $itemPayload {
	 *             Update details.
	 *
	 *             @type array $itemTags       List of tags.
	 *             @type array $itemCategories List of categories.
	 *             @type string $updateType    Update type.
	 *             @type string $cloud         Is it a cloud item update.
	 *             @type string $global        Is it a global item update.
	 *             @type string $itemName      New name for item.
	 *         }
	 *     }
	 * }
	 *
	 * @return array Returns an array of data including the updated item and update details.
	 *
	 * @example:
	 * ```php
	 * $request = new \WP_REST_Request( 'POST', '/v1/update-item' );
	 * $request->set_param( 'data', [
	 *     clickedItem => [
	 *       'id' => 1,
	 *       ....
	 *     ],
	 *     isCloudItem => 'no',
	 *     itemPayload => [
	 *         'itemTags' => [1,2,3],
	 *         'itemCategories' => [1,2,3],
	 *         'updateType' => 'delete',
	 *         'cloud' => 'no',
	 *         'global' => 'no',
	 *         'itemName' => 'New item name',
	 *     ],
	 * ] );
	 *
	 * $response = DiviLibrary::update_item( $request );
	 *
	 * print_r( $response );
	 * ```
	 *
	 * @output:
	 * ```php
	 *  Array(
	 *      'updatedItem'  => '123',
	 *      'newItem'  => '',
	 *      'updateType'  => 'delete',
	 *      'categories'  => [1,2,3],
	 *      'tags'  => [1,2,3],
	 *      'updatedTerms' => Array(
	 *           'categories' => Array(),
	 *           'tags' => Array(),
	 *       ),
	 *  )
	 * ```
	 */
	public static function update_item( $request ) {
		$filter_type = '';
		$new_terms   = [];
		$data        = $request->get_param( 'data' );

		$update_details = $data['itemPayload'];
		$update_type    = $update_details['updateType'];
		$item_id        = intval( $data['clickedItem']['id'] );
		$new_id         = '';
		$categories     = empty( $update_details['itemCategories'] ) ? [] : array_unique( array_map( 'intval', $update_details['itemCategories'] ) );
		$tags           = empty( $update_details['itemTags'] ) ? [] : array_unique( array_map( 'intval', $update_details['itemTags'] ) );
		$new_categories = [];
		$new_tags       = [];

		$item_update = [
			'ID' => $item_id,
		];

		$is_library_post_type  = 'et_pb_layout' === get_post_type( $item_id );
		$can_edit_item_terms   = 'edit_cats' === $update_type
			&& current_user_can( 'manage_categories' )
			&& current_user_can( 'edit_post', $item_id );
		$can_create_item_terms = current_user_can( 'manage_categories' )
			&& ( 'duplicate' === $update_type || $can_edit_item_terms );

		if ( ! empty( $update_details['newCategoryName'] ) && $can_create_item_terms ) {
			$new_names_array = explode( ',', $update_details['newCategoryName'] );
			foreach ( $new_names_array as $new_name ) {
				if ( '' !== $new_name ) {
					$new_term = wp_insert_term( $new_name, 'layout_category' );

					if ( ! is_wp_error( $new_term ) ) {
						$categories[] = $new_term['term_id'];

						$new_categories[] = [
							'name'  => $new_name,
							'id'    => $new_term['term_id'],
							'count' => 1,
						];
					} elseif ( ! empty( $new_term->error_data ) && ! empty( $new_term->error_data['term_exists'] ) ) {
						$categories[] = $new_term->error_data['term_exists'];
					}
				}
			}
		}

		if ( ! empty( $update_details['newTagName'] ) && $can_create_item_terms ) {
			$new_names_array = explode( ',', $update_details['newTagName'] );

			foreach ( $new_names_array as $new_name ) {
				if ( '' !== $new_name ) {
					$new_term = wp_insert_term( $new_name, 'layout_tag' );

					if ( ! is_wp_error( $new_term ) ) {
						$tags[] = $new_term['term_id'];

						$new_tags[] = [
							'name'  => $new_name,
							'id'    => $new_term['term_id'],
							'count' => 1,
						];
					} elseif ( ! empty( $new_term->error_data ) && ! empty( $new_term->error_data['term_exists'] ) ) {
						$tags[] = $new_term->error_data['term_exists'];
					}
				}
			}
		}

		switch ( $update_type ) {
			case 'duplicate':
				$is_item_from_cloud = isset( $update_details['shortcode'] );
				$title              = sanitize_text_field( $update_details['itemName'] );
				$meta_input         = [];
				$item_thumbnail     = false;

				if ( $is_item_from_cloud ) {
					$content         = $update_details['shortcode'];
					$built_for       = 'page';
					$scope           = ! empty( $update_details['global'] ) && 'on' === $update_details['global'] ? 'global' : 'non_global';
					$layout_type     = isset( $update_details['layoutType'] ) ? sanitize_text_field( $update_details['layoutType'] ) : 'layout';
					$module_width    = isset( $update_details['moduleWidth'] ) ? sanitize_text_field( $update_details['moduleWidth'] ) : 'regular';
					$favorite_status = isset( $update_details['favoriteStatus'] ) && 'on' === sanitize_text_field( $update_details['favoriteStatus'] ) ? 'favorite' : '';

					if ( 'row' === $layout_type ) {
						$meta_input['_et_pb_row_layout'] = $update_details['rowLayout'];
					}

					if ( 'module' === $layout_type ) {
						$meta_input['_et_pb_module_type'] = $update_details['moduleType'];
					}

					if ( '' !== $favorite_status ) {
						$meta_input['favorite_status'] = $favorite_status;
					}
				} else {
					$content        = get_the_content( null, false, $item_id );
					$built_for      = get_post_meta( $item_id, '_et_pb_built_for_post_type', true );
					$module_width   = wp_get_post_terms( $item_id, 'module_width', [ 'fields' => 'names' ] );
					$module_width   = is_wp_error( $module_width ) ? 'regular' : sanitize_text_field( $module_width[0] );
					$layout_type    = wp_get_post_terms( $item_id, 'layout_type', [ 'fields' => 'names' ] );
					$layout_type    = is_wp_error( $layout_type ) || '' === $layout_type ? 'layout' : sanitize_text_field( $layout_type[0] );
					$item_thumbnail = get_post_thumbnail_id( $item_id );

					if ( ! empty( $update_details['global'] ) ) {
						$scope = 'on' === $update_details['global'] ? 'global' : 'non_global';
					} else {
						$scope = wp_get_post_terms( $item_id, 'scope', [ 'fields' => 'names' ] );
						$scope = is_wp_error( $scope ) ? 'non_global' : sanitize_text_field( $scope[0] );
					}

					if ( 'row' === $layout_type ) {
						$row_layout = get_post_meta( $item_id, '_et_pb_row_layout', true );

						$meta_input['_et_pb_row_layout'] = $row_layout;
					}

					if ( 'module' === $layout_type ) {
						$module_type = get_post_meta( $item_id, '_et_pb_module_type', true );

						$meta_input['_et_pb_module_type'] = $module_type;
					}
				}

				$meta_input['_et_pb_built_for_post_type'] = $built_for;

				$new_item = [
					'post_title'   => $title,
					'post_content' => wp_slash( $content ),
					'post_status'  => 'publish',
					'post_type'    => 'et_pb_layout',
					'tax_input'    => [
						'layout_category' => $categories,
						'layout_tag'      => $tags,
						'layout_type'     => $layout_type,
						'scope'           => $scope,
						'module_width'    => $module_width,
					],
					'meta_input'   => $meta_input,
				];

				$new_id = wp_insert_post( $new_item );

				if ( $item_thumbnail ) {
					set_post_thumbnail( $new_id, $item_thumbnail );
				}

				break;
			case 'edit_cats':
				if ( ! $can_edit_item_terms ) {
					return self::response_error_permission();
				}

				wp_set_object_terms( $item_id, $categories, 'layout_category' );
				wp_set_object_terms( $item_id, $tags, 'layout_tag' );
				break;
			case 'rename':
				if ( ! current_user_can( 'edit_post', $item_id ) ) {
					return self::response_error_permission();
				}

				$item_update['post_title'] = sanitize_text_field( $update_details['itemName'] );
				wp_update_post( $item_update );
				break;
			case 'toggle_fav':
				if ( ! current_user_can( 'edit_post', $item_id ) ) {
					return self::response_error_permission();
				}

				$favorite_status = 'on' === sanitize_text_field( $update_details['favoriteStatus'] ) ? 'favorite' : '';
				update_post_meta( $item_id, 'favorite_status', $favorite_status );

				break;
			case 'delete':
				if ( ! current_user_can( 'delete_post', $item_id ) || ! $is_library_post_type ) {
					return self::response_error_permission();
				}

				wp_trash_post( $item_id );
				break;
			case 'delete_permanently':
				if ( ! current_user_can( 'delete_post', $item_id ) || ! $is_library_post_type ) {
					return self::response_error_permission();
				}

				wp_delete_post( $item_id, true );
				break;
			case 'restore':
				if ( ! current_user_can( 'edit_post', $item_id ) || ! $is_library_post_type ) {
					return self::response_error_permission();
				}

				// wp_untrash_post() restores the post to `draft` by default, we have to set `publish` status via filter.
				add_filter(
					'wp_untrash_post_status',
					function () {
						return 'publish';
					}
				);
				wp_untrash_post( $item_id );
				break;
		}

		return self::response_success(
			[
				'updatedItem'  => $item_id,
				'newItem'      => $new_id,
				'updateType'   => $update_type,
				'categories'   => $categories,
				'tags'         => $tags,
				'updatedTerms' => [
					'categories' => LibraryUtility::prepare_library_terms(),
					'tags'       => LibraryUtility::prepare_library_terms( 'layout_tag' ),
				],
			]
		);
	}

	/**
	 * Retrieves the arguments array for update action.
	 *
	 * This function returns an array containing the arguments for update action, used in `register_rest_route()`.
	 *
	 * @since ??
	 *
	 * @return array The array containing the arguments for update action.
	 */
	public static function update_item_args(): array {
		return [
			'data'          => [
				'clickedItem' => [
					'required'          => true,
					'sanitize_callback' => function ( $data ) {
						$sanitized = [];

						foreach ( $data as $item ) {
							if ( ! $item || ! is_array( $item ) ) {
								continue;
							}

							$sanitized_item = [];

							foreach ( $item as $key => $value ) {
								$sanitized_item[ $key ] = sanitize_text_field( $value );
							}

							$sanitized[] = $sanitized_item;
						}

						return $sanitized;
					},
				],
				'isCloudItem' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'itemPayload' => [
					'itemTags'       => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'itemCategories' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'updateType'     => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'cloud'          => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'global'         => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'itemName'       => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			'ai_agent_tool' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => function ( $value ) {
					return 'update_component' === $value;
				},
			],
		];
	}

	/**
	 * Check if user has permission for update action.
	 *
	 * This function checks if the current user has the capability to edit posts, used in `register_rest_route()`.
	 * If the user does not have the necessary permissions, an error response is returned.
	 *
	 * @since ??
	 *
	 * @return bool|WP_Error Returns `true` if the user has the required permissions, `WP_Error` object otherwise.
	 *
	 * @example:
	 * ```php
	 * $result = DiviLibrary::update_permission();
	 *
	 * if ( is_wp_error( $result ) ) {
	 *     echo $result->get_error_message();
	 * } else {
	 *     echo "Permission granted.";
	 * }
	 * ```
	 * @param WP_REST_Request $request The request object.
	 */
	public static function update_item_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		$is_allowed_manage_library = et_pb_is_allowed( 'divi_library' ) && et_pb_is_allowed( 'save_library' );

		if ( ! $is_allowed_manage_library ) {
			return self::response_error_permission();
		}

		$data        = $request->get_param( 'data' );
		$item_id     = null;
		$update_type = null;
		$tool_name   = $request->get_param( 'ai_agent_tool' );

		if ( is_array( $data ) && isset( $data['itemPayload'] ) && is_array( $data['itemPayload'] ) ) {
			$update_type = $data['itemPayload']['updateType'] ?? null;
		}

		if ( is_array( $data ) && isset( $data['clickedItem'] ) && is_array( $data['clickedItem'] ) ) {
			$item_id = $data['clickedItem']['id'] ?? null;
		}

		$is_component_metadata_update = in_array( $update_type, [ 'edit_cats', 'rename' ], true )
			&& self::_is_component_library_item( absint( $item_id ) );
		$is_component_tool_request    = 'update_component' === $tool_name;

		if ( 'delete' === $update_type ) {
			return AiAgentApprovalTokens::authorize_after_caps(
				$request,
				'delete_library_item',
				$request->get_params(),
				true
			);
		}

		if ( $is_component_tool_request && ! $is_component_metadata_update ) {
			return self::response_error_permission();
		}

		if ( $is_component_metadata_update ) {
			if ( ! et_pb_is_allowed( 'edit_global_library' ) || ! current_user_can( 'edit_post', absint( $item_id ) ) ) {
				return self::response_error_permission();
			}

			return AiAgentApprovalTokens::authorize_after_caps(
				$request,
				'update_component',
				[ 'componentId' => $item_id ],
				true
			);
		}

		return true;
	}

	/**
	 * Whether a library item is a Component.
	 *
	 * @since ??
	 *
	 * @param int $item_id Library item post ID.
	 *
	 * @return bool
	 */
	private static function _is_component_library_item( int $item_id ): bool {
		if ( 0 >= $item_id || ET_BUILDER_LAYOUT_POST_TYPE !== get_post_type( $item_id ) ) {
			return false;
		}

		$scope_terms  = wp_get_post_terms( $item_id, 'scope', [ 'fields' => 'slugs' ] );
		$layout_types = wp_get_post_terms( $item_id, 'layout_type', [ 'fields' => 'slugs' ] );

		return ! is_wp_error( $scope_terms )
			&& in_array( 'global', $scope_terms, true )
			&& ! is_wp_error( $layout_types )
			&& (bool) array_intersect( [ 'module', 'row', 'section' ], $layout_types );
	}


	/**
	 * Splits an item based on the provided request parameters.
	 *
	 * @param WP_REST_Request $request - The WordPress REST request object.
	 * @return WP_REST_Response - The function does not return a value; it sends a JSON response.
	 *
	 * @example
	 * // Assuming a valid WP_REST_Request object named $request
	 * SplitItemTrait::split_item($request);
	 */
	public static function split_item( WP_REST_Request $request ) {

		$id         = $request->get_param( 'id' ) ? absint( $request->get_param( 'id' ) ) : false;
		$prefix     = $request->get_param( 'itemName' ) ? sanitize_text_field( $request->get_param( 'itemName' ) ) : false;
		$to_cloud   = $request->get_param( 'cloud' ) ? sanitize_text_field( $request->get_param( 'cloud' ) ) : 'off';
		$split_type = $request->get_param( 'updateType' ) ? sanitize_text_field( $request->get_param( 'updateType' ) ) : false;
		$origin     = $request->get_param( 'actionOrigin' ) ? sanitize_text_field( $request->get_param( 'actionOrigin' ) ) : '';

		if ( ! $id || ! $split_type || ! $prefix ) {
			wp_send_json_error();
		}

		if ( ! in_array( $split_type, [ 'split_layout', 'split_section', 'split_row' ], true ) ) {
			wp_send_json_error();
		}

		$cloud_content = $request->get_param( 'content' ) ?? '';

		if ( $cloud_content ) {
			$item_content = wp_unslash( reset( $cloud_content['data'] ) );
		} else {
			$item_content = get_the_content( null, false, $id );
		}

		switch ( $split_type ) {
			case 'split_layout':
				$pattern     = '/<!-- wp:divi\/section.*?<!-- \/wp:divi\/section -->/s';
				$layout_type = 'section';
				break;

			case 'split_section':
				$pattern     = '/<!-- wp:divi\/row(?:-inner)? .*?<!-- \/wp:divi\/row(?:-inner)? -->/s';
				$layout_type = 'row';
				break;

			case 'split_row':
				$pattern     = '/<!-- wp:divi\/(?!row|section|column|row-inner|column-inner)[^ ]*.*?-->/s';
				$layout_type = 'module';
				break;
		}

		// Get the intented content array based on split type pattern.
		preg_match_all( $pattern, $item_content, $matches );

		$args = [
			'split_type'           => $split_type,
			'layout_type'          => $layout_type,
			'layout_selected_cats' => $request->get_param( 'itemCategories' ) ? array_map( 'sanitize_text_field', $request->get_param( 'itemCategories' ) ) : [],
			'layout_selected_tags' => $request->get_param( 'itemTags' ) ? array_map( 'sanitize_text_field', $request->get_param( 'itemTags' ) ) : [],
			'built_for_post_type'  => 'page',
			'layout_new_cat'       => $request->get_param( 'newCategoryName' ) ? sanitize_text_field( $request->get_param( 'newCategoryName' ) ) : '',
			'layout_new_tag'       => $request->get_param( 'newTagName' ) ? sanitize_text_field( $request->get_param( 'newTagName' ) ) : '',
			'columns_layout'       => '0',
			'module_type'          => 'et_pb_unknown',
			'layout_scope'         => $request->get_param( 'global' ) && ( 'on' === $request->get_param( 'global' ) ) ? 'global' : 'not_global',
			'module_width'         => 'regular',
		];

		$layouts   = [];
		$processed = false;

		foreach ( $matches[0] as $key => $content ) {
			$title = $prefix;

			if ( 'split_row' === $split_type && 'save_modal' === $origin ) {
				$module_name = explode( ' ', $content )[0];
				$module_name = str_replace( '[et_pb_', '', $module_name );
				$module_name = ucfirst( str_replace( '_', ' ', $module_name ) );
				$title       = str_replace( '%module_type%', $module_name, $prefix );
			}

			$args['layout_name'] = $title . ' ' . ( ++$key );

			$content = self::_get_content_with_type( $content, $layout_type );

			if ( 'on' === $to_cloud ) {
				if ( $cloud_content ) {
					/* From cloud to cloud */
					$layouts[] = self::_get_cloud_to_cloud_formatted_data( $cloud_content, $content, $args );
				} else {
					/* From local to cloud */
					$layouts[] = self::_get_local_to_cloud_formatted_data( $content, $args );
				}
			} elseif ( $cloud_content ) {
					/* From cloud to local */
					$cloud_content['data']['1'] = $content;

					$layouts[] = self::_get_cloud_to_local_formatted_data( $cloud_content, $content, $args );

					// We only need to insert these data once into the database.
					unset( $cloud_content['presets'] );
					unset( $cloud_content['global_colors'] );
					unset( $cloud_content['images'] );
					unset( $cloud_content['thumbnails'] );
			} else {
				/* From local to Local */
				$args['layout_content']       = wp_slash( $content );
				$args['layout_selected_cats'] = is_array( $args['layout_selected_cats'] ) ? implode( ',', $args['layout_selected_cats'] ) : '';
				$args['layout_selected_tags'] = is_array( $args['layout_selected_tags'] ) ? implode( ',', $args['layout_selected_tags'] ) : '';

				$new_saved = json_decode( et_pb_submit_layout( $args ) );

				// Only need to process once because all the split item's taxonomies are the same.
				if ( ! $processed ) {
					$layouts[] = [
						'newId'         => $new_saved->post_id,
						'categories'    => $args['layout_selected_cats'],
						'tags'          => $args['layout_selected_tags'],
						'updated_terms' => self::_get_all_updated_terms(),
					];
					$processed = true;
				}
			}
		}

		return self::response_success( $layouts );
	}

	/**
	 * Returns an array of arguments for the split_item function.
	 *
	 * @return array - An array of arguments with their requirements and sanitize callbacks.
	 *
	 * @example
	 * // Get the arguments for the split_item function
	 * $args = SplitItemTrait::split_item_args();
	 */
	public static function split_item_args() {
		return [
			'id'              => [
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'itemName'        => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'cloud'           => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'updateType'      => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'actionOrigin'    => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'global'          => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'content'         => [
				'required' => false,
			],
			'itemCategories'  => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'itemTags'        => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'newCategoryName' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'newTagName'      => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Checks if the current user has the permission to split items.
	 *
	 * This method checks if the current user has the 'edit_posts' capability.
	 * If the user does not have the capability, it returns an error response.
	 * Otherwise, it returns true.
	 *
	 * @return bool|WP_REST_Response True if the user has the 'edit_posts' capability, or an error response otherwise.
	 *
	 * @example
	 * ```php
	 * // Example usage for checking the user's permission to split items.
	 * $permission = SplitItemTrait::split_item_permission();
	 * if ( is_wp_error( $permission ) ) {
	 *     // Handle error.
	 * }
	 * ```
	 */
	public static function split_item_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}


	/**
	 * Get content with type.
	 *
	 * @since 4.20.3
	 *
	 * @param string $content Content to be processed.
	 * @param string $type    Type of the content.
	 */
	public static function _get_content_with_type( $content, $type ) {
		$pattern = '/^(\[\w+\s)/';
		$replace = '$0template_type="' . $type . '" '; // e.g. [et_pb_row template_type="row" ...].

		return preg_replace( $pattern, $replace, $content );
	}

		/**
		 * Get formatted data for cloud item split to cloud.
		 *
		 * @since 4.20.3
		 *
		 * @param array  $cloud_content Cloud Item data.
		 * @param string $content       Shortcode after split cloud item.
		 * @param array  $assoc_data    Related data after split cloud item.
		 */
	public static function _get_cloud_to_cloud_formatted_data( $cloud_content, $content, $assoc_data ) {
		$data = self::_get_common_cloud_formatted_data( $content, $assoc_data );

		$images        = [];
		$presets       = [];
		$global_colors = [];

		if ( ! empty( $cloud_content['images'] ) ) {
			foreach ( $cloud_content['images'] as $url => $img ) {
				// Use strpos because str_contains() is not present in PHP version 7.4 or earlier.
				if ( str_contains( $content, $url ) ) {
					$images[ $url ] = $img;
				}
			}
		}

		if ( ! empty( $cloud_content['presets'] ) ) {
			foreach ( $cloud_content['presets'] as $module => $preset ) {
				// Use strpos because str_contains() is not present in PHP version 7.4 or earlier.
				if ( str_contains( $content, $module ) ) {
					$presets[ $module ] = $preset;
				}
			}
		}

		if ( ! empty( $cloud_content['global_colors'] ) ) {
			foreach ( $cloud_content['global_colors'] as $key => $global_color ) {
				// Use strpos because str_contains() is not present in PHP version 7.4 or earlier.
				if ( str_contains( $content, $global_color[0] ) ) {
					$global_colors[ $key ] = $global_color;
				}
			}
		}

		$data['images']        = $images;
		$data['presets']       = $presets;
		$data['global_colors'] = $global_colors;

		return $data;
	}

		/**
		 * Get formatted data for cloud item split to local.
		 *
		 * @since 4.20.3
		 *
		 * @param array  $cloud_content Cloud Item data.
		 * @param string $content       Shortcode after split cloud item.
		 * @param array  $assoc_data    Related data after split cloud item.
		 *
		 * @return array
		 */
	public static function _get_cloud_to_local_formatted_data( $cloud_content, $content, $assoc_data ) {
		return [
			'itemName'        => $assoc_data['layout_name'],
			'itemCategories'  => $assoc_data['layout_selected_cats'],
			'itemTags'        => $assoc_data['layout_selected_tags'],
			'newCategoryName' => $assoc_data['layout_new_cat'],
			'newTagName'      => $assoc_data['layout_new_tag'],
			'cloud'           => 'off',
			'global'          => $assoc_data['layout_scope'],
			'layoutType'      => $assoc_data['layout_type'],
			'updateType'      => $assoc_data['split_type'],
			'content'         => $cloud_content,
			'shortcode'       => wp_json_encode( $content ),
		];
	}

	/**
	 * Get common formatted data for cloud item.
	 *
	 * @since 4.20.3
	 *
	 * @param string $content    Shortcode after split cloud item.
	 * @param array  $assoc_data Related data after split cloud item.
	 *
	 * @return array
	 */
	public static function _get_common_cloud_formatted_data( $content, $assoc_data ) {
		$data = [
			'post_title'   => $assoc_data['layout_name'],
			'post_content' => $content,
			'terms'        => [
				[
					'name'     => $assoc_data['layout_type'],
					'slug'     => $assoc_data['layout_type'],
					'taxonomy' => 'layout_type',
				],
			],
		];

		foreach ( $assoc_data['layout_selected_cats'] as $category ) {
			$data['terms'][] = [
				'name'     => $category,
				'slug'     => $category,
				'taxonomy' => 'layout_category',
			];
		}

		foreach ( $assoc_data['layout_selected_tags'] as $tag ) {
			$data['terms'][] = [
				'name'     => $tag,
				'slug'     => $tag,
				'taxonomy' => 'layout_tag',
			];
		}

		return $data;
	}

		/**
		 * Get all the updated terms.
		 *
		 * @since 4.20.3
		 *
		 * @return array
		 */
	public static function _get_all_updated_terms() {
		$updated_terms = [];

		foreach ( [ 'layout_category', 'layout_tag' ] as $taxonomy ) {
			// phpcs:ignore WordPress.WP.DeprecatedParameters.Get_termsParam2Found -- Using legacy format for compatibility. hide_empty is needed to get all terms.
			$raw_terms_array   = get_terms( $taxonomy, [ 'hide_empty' => false ] );
			$clean_terms_array = [];

			if ( is_array( $raw_terms_array ) && ! empty( $raw_terms_array ) ) {
				foreach ( $raw_terms_array as $term ) {
					$clean_terms_array[] = [
						'name' => html_entity_decode( $term->name ),
						'id'   => $term->term_id,
						'slug' => $term->slug,
					];
				}
			}

			$updated_terms[ $taxonomy ] = $clean_terms_array;
		}

		return $updated_terms;
	}


	/**
	 * Get formatted data for local item split to cloud.
	 *
	 * @since 4.20.3
	 *
	 * @param string $content    Shortcode after split cloud item.
	 * @param array  $assoc_data Related data after split cloud item.
	 *
	 * @return array
	 */
	public static function _get_local_to_cloud_formatted_data( $content, $assoc_data ) {
		return self::_get_common_cloud_formatted_data( $content, $assoc_data );
	}

		/**
		 * Converts a Divi Builder item from one type to another.
		 *
		 * This method is used to convert a Divi Builder item (like a row or a section) from one type to another.
		 * The type of conversion is determined by the 'action' parameter in the WP_REST_Request object.
		 *
		 * @param WP_REST_Request $request The REST request object containing the 'action' and 'id' parameters.
		 *
		 * @example
		 * ```php
		 * // Example usage for converting a row to a section.
		 * $request = new WP_REST_Request( 'GET', '/my-api/v1/convert-item' );
		 * $request->set_param( 'action', 'convert_row_to_section' );
		 * $request->set_param( 'id', '123' );
		 *
		 * ConvertItemTrait::convert_item( $request );
		 * ```
		 */
	public static function convert_item( WP_REST_Request $request ) {
		$action  = $request->get_param( 'action' );
		$id      = $request->get_param( 'id' );
		$content = $request->get_param( 'content' );

		$placeholder_start = '<!-- wp:divi/placeholder -->';
		$placeholder_end   = '<!-- /wp:divi/placeholder -->';
		$section_start     = '<!-- wp:divi/section -->';
		$row_start         = '<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}}} -->';
		$column_start      = '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}}} -->';
		$column_end        = '<!-- /wp:divi/column -->';
		$row_end           = '<!-- /wp:divi/row -->';
		$section_end       = '<!-- /wp:divi/section -->';
		$fullwidth_wrapper = str_replace( 'fullwidth="off"', 'fullwidth="on" template_type="section"', $section_start );

		switch ( $action ) {
			case 'convert_row_to_section':
				$wrapper_start = $section_start;
				$wrapper_end   = $section_end;
				$from_type     = 'row';
				$to_type       = 'section';
				break;

			case 'convert_module_to_row':
				$wrapper_start = $row_start . $column_start;
				$wrapper_end   = $column_end . $row_end;
				$from_type     = 'module';
				$to_type       = 'row';
				break;

			case 'convert_module_to_section':
				$wrapper_start = $section_start . $row_start . $column_start;
				$wrapper_end   = $column_end . $row_end . $section_end;
				$from_type     = 'module';
				$to_type       = 'section';
				break;
		}

		/**
		 * For cloud item.
		 */
		if ( ! empty( $content ) ) {
			$post_content = $placeholder_start . $wrapper_start . $content . $wrapper_end . $placeholder_end;

			$response = [
				'data' => [ '1' => $post_content ],
			];

			return self::response_success( $response );
		}

		/**
		 * For local item.
		 */
		$post_id = isset( $id ) ? absint( $id ) : 0;
		$item    = get_post( $post_id );

		if ( ! $item ) {
			return self::response_error( 'post_not_found', esc_html__( 'Post not found.', 'et_builder_5' ) );
		}

		if ( 'convert_module_to_section' === $action ) {
			$module_type = get_post_meta( $post_id, '_et_pb_module_type', true );

			if ( str_contains( $module_type, 'et_pb_fullwidth' ) ) {
				// For fullwidth module, there is no row and column.
				$wrapper_start = $fullwidth_wrapper;
				$wrapper_end   = $section_end;
			}
		}

		$pattern      = '/<!-- wp:divi\/placeholder -->([\s\S]*?)<!-- \/wp:divi\/placeholder -->/s';
		$post_content = $item->post_content;
		preg_match( $pattern, $post_content, $matches );
		if ( isset( $matches[1] ) ) {
			$post_content = $matches[1];
		}

		$scope_terms  = get_the_terms( $post_id, 'scope' );
		$scope_terms  = wp_list_pluck( $scope_terms, 'slug' );
		$is_global    = in_array( 'global', $scope_terms, true );
		$post_content = $placeholder_start . $wrapper_start . $post_content . $wrapper_end . $placeholder_end;

		$new_id = wp_insert_post(
			[
				'ID'           => $is_global ? 0 : $post_id, // If global item, create a new post.
				'post_content' => wp_slash( $post_content ),
				'post_date'    => $item->post_date,
				'post_title'   => $item->post_title,
				'post_type'    => $item->post_type,
				'post_status'  => 'publish',
			]
		);

		if ( is_wp_error( $new_id ) ) {
			return self::response_error( $new_id->get_error_code(), $new_id->get_error_message() );
		}

		if ( ! $is_global ) {
			wp_remove_object_terms( $post_id, $from_type, 'layout_type' );
		}

		add_post_meta( $new_id, '_et_pb_built_for_post_type', 'page' );
		delete_post_meta( $new_id, '_et_pb_module_type' );
		wp_set_object_terms( $new_id, $to_type, 'layout_type' );

		return self::response_success( [ $post_content ] );
	}

	/**
	 * Defines the arguments for the `convert_item` method.
	 *
	 * This method returns an array of arguments that are required for the `convert_item` method.
	 * Each argument has a 'required' flag and a 'sanitize_callback' function for validation and sanitization.
	 *
	 * @return array The array of arguments for the `convert_item` method.
	 *
	 * @example
	 * ```php
	 * // Example usage for getting the arguments for the `convert_item` method.
	 * $args = ConvertItemTrait::convert_item_args();
	 * ```
	 */
	public static function convert_item_args(): array {
		return [
			'id'      => [
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'content' => [
				'required' => false,
			],
			'action'  => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Checks if the current user has the permission to convert items.
	 *
	 * This method checks if the current user has the 'edit_posts' capability.
	 * If the user does not have the capability, it returns an error response.
	 * Otherwise, it returns true.
	 *
	 * @return bool|WP_REST_Response True if the user has the 'edit_posts' capability, or an error response otherwise.
	 *
	 * @example
	 * ```php
	 * // Example usage for checking the user's permission to convert items.
	 * $permission = ConvertItemTrait::convert_item_permission();
	 * if ( is_wp_error( $permission ) ) {
	 *     // Handle error.
	 * }
	 * ```
	 */
	public static function convert_item_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::response_error_permission();
		}

		return true;
	}
}
