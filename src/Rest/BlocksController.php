<?php
/**
 * REST endpoints used by field blocks in the editor.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 *  POST wpff/v1/blocks/form  { name, data }   → { html }            fields form filled from block data
 *  POST wpff/v1/blocks/data  { name, values } → { data, errors }    form values → flat block data
 */
final class BlocksController {

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		$args = array(
			'name' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/blocks/form',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'form' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/blocks/data',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'data' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $args,
			)
		);
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function form( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		if ( ! $this->plugin->blocks()->get( $name ) ) {
			return $this->not_found();
		}
		$data = $request->get_param( 'data' );
		return rest_ensure_response( array( 'html' => $this->plugin->blocks()->form_html( $name, is_array( $data ) ? $data : array() ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function data( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		if ( ! $this->plugin->blocks()->get( $name ) ) {
			return $this->not_found();
		}
		$values = $request->get_param( 'values' );
		$result = $this->plugin->blocks()->data_from_form( $name, is_array( $values ) ? $values : array() );
		return rest_ensure_response(
			array(
				'data'   => (object) $result['data'],
				'errors' => (object) $result['errors'],
			)
		);
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'wpff_not_found', __( 'Block not found.', 'freeform-fields' ), array( 'status' => 404 ) );
	}
}
