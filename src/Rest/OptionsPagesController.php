<?php
/**
 * REST endpoints for managing options pages.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under `wpff/v1`:
 *
 *  GET    /options-pages          list (database and PHP pages)
 *  POST   /options-pages          create
 *  GET    /options-pages/{slug}   read
 *  PUT    /options-pages/{slug}   update (the slug may change)
 *  DELETE /options-pages/{slug}   delete (stored values are kept)
 */
final class OptionsPagesController {

	private const SLUG_PATTERN = '(?P<slug>[a-z0-9_\-]+)';

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		$permission = static fn() => current_user_can( Plugin::admin_capability() );

		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/options-pages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/options-pages/' . self::SLUG_PATTERN,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	public function list_pages(): \WP_REST_Response {
		$out = array();
		foreach ( $this->plugin->options_pages()->all( true ) as $page ) {
			$out[] = $page + array( 'field_groups' => $this->group_titles( $page['menu_slug'] ) );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_page( \WP_REST_Request $request ) {
		$page = $this->plugin->options_pages()->get( (string) $request['slug'] );
		return $page ? rest_ensure_response( $page ) : $this->not_found();
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_page( \WP_REST_Request $request ) {
		$page = $this->plugin->options_store()->save( (array) $request->get_json_params() );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$response = rest_ensure_response( $page );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_page( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		if ( $this->plugin->options_pages()->is_local( $slug ) ) {
			return $this->local_error();
		}
		if ( ! $this->plugin->options_store()->find_post( $slug ) ) {
			return $this->not_found();
		}
		$page = $this->plugin->options_store()->save( (array) $request->get_json_params(), $slug );
		return is_wp_error( $page ) ? $page : rest_ensure_response( $page );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_page( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		if ( $this->plugin->options_pages()->is_local( $slug ) ) {
			return $this->local_error();
		}
		if ( ! $this->plugin->options_store()->delete( $slug ) ) {
			return $this->not_found();
		}
		return rest_ensure_response(
			array(
				'menu_slug' => $slug,
				'deleted'   => true,
			)
		);
	}

	/**
	 * Titles of field groups shown on a page.
	 *
	 * @param string $slug Menu slug.
	 * @return string[]
	 */
	private function group_titles( string $slug ): array {
		$titles = array();
		foreach ( $this->plugin->fields()->get_groups() as $group ) {
			if ( $this->plugin->locations()->matches( $group['location'], array( 'options_page' => $slug ) ) ) {
				$titles[] = $group['title'];
			}
		}
		return $titles;
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'wpff_not_found', __( 'Options page not found.', 'freeform-fields' ), array( 'status' => 404 ) );
	}

	private function local_error(): \WP_Error {
		return new \WP_Error( 'wpff_local_page', __( 'This options page is registered in code and cannot be edited here.', 'freeform-fields' ), array( 'status' => 409 ) );
	}
}
