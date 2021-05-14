<?php

namespace CoAuthors\API;

// Load required classes from core.
require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';

use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Endpoint.
 */
class Endpoints {

	/**
	 * Namespace for our endpoints.
	 */
	public const NAMESPACE = 'coauthors/v1';

	/**
	 * Route for authors search endpoint.
	 */
	public const SEARCH_ROUTE  = 'search';
	public const AUTHORS_ROUTE = 'authors';

	/**
	 * Regex to capture the query in a request.
	 */
	protected const ENDPOINT_POST_ID_REGEX = '/(?P<post_id>[\d]+)';

	/**
	 * An instance of the Co_Authors_Plus class.
	 */
	public $coauthors;

	/**
	 * WP_REST_API constructor.
	 */
	public function __construct( $coauthors_instance ) {
		$this->coauthors = $coauthors_instance;

		add_action( 'rest_api_init', array( $this, 'add_endpoints' ) );
	}

	/**
	 * Register endpoints.
	 */
	public function add_endpoints(): void {

		register_rest_route(
			static::NAMESPACE,
			static::SEARCH_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_coauthors_search_results' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'q'                => array(
							'description' => __( 'Text to search.' ),
							'required'    => false,
							'type'        => 'string',
						),
						'existing_authors' => array(
							'description' => __( 'Names of existing coauthors to exclude from search results.' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			static::AUTHORS_ROUTE . static::ENDPOINT_POST_ID_REGEX,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_coauthors' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'post_id' => array(
							'required'          => false,
							'type'              => 'number',
							'validate_callback' => array( $this, 'validate_numeric' ),
						),
					),
				),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			static::AUTHORS_ROUTE . static::ENDPOINT_POST_ID_REGEX,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_coauthors' ),
					// 'permission_callback' => [ $this, 'can_edit_coauthors', $post ],
					'permission_callback' => '__return_true',
					'args'                => array(
						'post_id'     => array(
							'required'          => false,
							'type'              => 'number',
							'validate_callback' => array( $this, 'validate_numeric' ),
						),
						'new_authors' => array(
							'description' => __( 'Names of coauthors to save.' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Search and return authors based on a text query.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_coauthors_search_results( WP_REST_Request $request ): WP_REST_Response {
		$response = array();

		$search  = sanitize_text_field( strtolower( $request['q'] ) );
		$ignore  = array_map( 'sanitize_text_field', explode( ',', $request['existing_authors'] ) );
		$authors = $this->coauthors->search_authors( $search, $ignore );

		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author ) {
				$response[] = $this->_format_author_data( $author );
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Return a single author.
	 */
	public function get_coauthors( WP_REST_Request $request ): WP_REST_Response {
		$response = array();

		$this->_build_authors_response( $response, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Update coauthors.
	 */
	public function update_coauthors( WP_REST_Request $request ): WP_REST_Response {

		$response = array();

		if ( isset( $request['new_authors'] ) ) {
			$author_names = explode( ',', $request['new_authors'] );
			$coauthors    = array_map( 'sanitize_title', (array) $author_names );

			// Replace all existing authors
			$this->coauthors->add_coauthors( $request->get_param( 'post_id' ), $coauthors );

			$this->_build_authors_response( $response, $request );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Validate input arguments.
	 *
	 * @param mixed $param Value to validate.
	 * @return bool
	 */
	public function validate_numeric( $param ): bool {
		return is_numeric( $param );
	}

	/**
	 * Permissions for updating coauthors.
	 */
	public function can_edit_coauthors( WP_Post $post ): bool {
		if ( ! $this->is_post_type_enabled( $post->post_type ) ) {
			return false;
		}

		return $this->current_user_can_set_authors( $post );
	}

	/**
	 * Helper function to consistently format the author data for
	 * the response.
	 *
	 * @param object $author The result from coauthors methods.
	 * @return array
	 */
	public function _format_author_data( object $author ): array {

		return array(
			'id'            => esc_html( $author->ID ),
			'user_nicename' => esc_html( rawurldecode( $author->user_nicename ) ),
			'login'         => esc_html( $author->user_login ),
			'email'         => $author->user_email,
			'display_name'  => esc_html( str_replace( '∣', '|', $author->display_name ) ),
			'avatar'        => esc_url( get_avatar_url( $author->ID ) ),
		);
	}

	/**
	 * Get authors' data and add it to the response.
	 *
	 * @param array The response array.
	 * @param int   Thet post ID from the request.
	 */
	public function _build_authors_response( &$response, $request ): void {
		$authors = get_coauthors( $request->get_param( 'post_id' ) );

		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author ) {
				$response[] = $this->_format_author_data( $author );
			}
		}
	}
}
