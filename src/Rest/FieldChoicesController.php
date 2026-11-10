<?php
/**
 * Search endpoint for relational field pickers.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Fields\Types\RelationalField;
use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `GET wpff/v1/fields/{key}/choices?s=&page=` returns `{ results: [{id, text}], more }`.
 *
 * The field decides which capability is needed (e.g. `list_users` for the User field).
 */
final class FieldChoicesController {

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/fields/(?P<key>field_[a-z0-9_]+)/choices',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'choices' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					's'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_search( \WP_REST_Request $request ) {
		[ $field, $handler ] = $this->resolve( (string) $request['key'] );
		if ( ! $field || ! $handler ) {
			// Same answer for unknown fields and missing rights, so keys cannot be probed.
			return current_user_can( 'edit_posts' ) ? new \WP_Error( 'wpff_not_found', __( 'Field not found.', 'freeform-fields' ), array( 'status' => 404 ) ) : false;
		}
		return current_user_can( $handler->search_capability() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function choices( \WP_REST_Request $request ): \WP_REST_Response {
		[ $field, $handler ] = $this->resolve( (string) $request['key'] );
		return rest_ensure_response( $handler->query( $field, (string) $request['s'], (int) $request['page'] ) );
	}

	/**
	 * @param string $key Field key.
	 * @return array{0: array<string, mixed>|null, 1: RelationalField|null}
	 */
	private function resolve( string $key ): array {
		$field   = $this->plugin->fields()->get_field( $key );
		$handler = $field ? $this->plugin->types()->get( $field['type'] ) : null;
		if ( ! $handler instanceof RelationalField ) {
			return array( null, null );
		}
		return array( $field, $handler );
	}
}
