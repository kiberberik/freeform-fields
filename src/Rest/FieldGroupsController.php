<?php
/**
 * REST endpoints for managing field groups.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Admin\BuilderData;
use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under `wpff/v1`:
 *
 *  GET    /field-groups                 list (database and PHP groups)
 *  POST   /field-groups                 create
 *  GET    /field-groups/{key}           read
 *  PUT    /field-groups/{key}           update
 *  DELETE /field-groups/{key}           move to trash
 *  POST   /field-groups/{key}/duplicate copy into a new database group
 *  POST   /field-groups/{key}/restore   restore from trash
 *  GET    /builder                      field types and location rule catalog
 */
final class FieldGroupsController {

	public const NAMESPACE = 'wpff/v1';

	private const KEY_PATTERN = '(?P<key>group_[a-z0-9_]+)';

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/field-groups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_group' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/' . self::KEY_PATTERN,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/' . self::KEY_PATTERN . '/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_group' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/' . self::KEY_PATTERN . '/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_group' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/' . self::KEY_PATTERN . '/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_group' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/builder',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'builder_data' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( Plugin::admin_capability() );
	}

	public function list_groups(): \WP_REST_Response {
		return rest_ensure_response( ( new BuilderData( $this->plugin ) )->group_summaries() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_group( \WP_REST_Request $request ) {
		$key   = (string) $request['key'];
		$group = $this->plugin->fields()->get_group( $key );
		if ( ! $group ) {
			return $this->not_found();
		}
		return rest_ensure_response( $this->prepare( $group ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_group( \WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		// A new group always gets a fresh key, even if the client sent one that is taken.
		if ( isset( $input['key'] ) && ( $this->plugin->fields()->get_group( (string) $input['key'] ) || $this->plugin->groups()->find_post( (string) $input['key'], true ) ) ) {
			unset( $input['key'] );
		}
		$group = $this->plugin->groups()->save( $input );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		$response = rest_ensure_response( $this->prepare( $group ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_group( \WP_REST_Request $request ) {
		$key       = (string) $request['key'];
		$json_only = 'json' === ( $this->plugin->fields()->get_group( $key )['local'] ?? '' );
		if ( ! $this->plugin->groups()->find_post( $key ) && ! $json_only ) {
			return $this->plugin->fields()->is_local( $key ) ? $this->local_error() : $this->not_found();
		}
		$input        = (array) $request->get_json_params();
		$input['key'] = $key;
		$group        = $this->plugin->groups()->save( $input );
		return is_wp_error( $group ) ? $group : rest_ensure_response( $this->prepare( $group ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_group( \WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( $this->plugin->fields()->is_local( $key ) ) {
			return $this->local_error();
		}
		if ( ! $this->plugin->groups()->trash( $key ) ) {
			return $this->not_found();
		}
		return rest_ensure_response(
			array(
				'key'     => $key,
				'trashed' => true,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate_group( \WP_REST_Request $request ) {
		$group = $this->plugin->groups()->duplicate( (string) $request['key'] );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		$response = rest_ensure_response( $this->prepare( $group ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_group( \WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( ! $this->plugin->groups()->restore( $key ) ) {
			return $this->not_found();
		}
		$group = $this->plugin->fields()->get_group( $key );
		return $group ? rest_ensure_response( $this->prepare( $group ) ) : $this->not_found();
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_group( \WP_REST_Request $request ) {
		$group = $this->plugin->groups()->sync( (string) $request['key'] );
		return is_wp_error( $group ) ? $group : rest_ensure_response( $this->prepare( $group ) );
	}

	public function builder_data(): \WP_REST_Response {
		$data = new BuilderData( $this->plugin );
		return rest_ensure_response(
			array(
				'fieldTypes' => $data->field_types(),
				'location'   => $data->location_params(),
			)
		);
	}

	/**
	 * Group as sent to the client: settings plus `source`, without internal props.
	 *
	 * @param array<string, mixed> $group Normalized group.
	 * @return array<string, mixed>
	 */
	private function prepare( array $group ): array {
		$source = $this->plugin->fields()->is_local( $group['key'] ) ? 'php' : ( in_array( $group['local'] ?? '', array( 'json', 'acf' ), true ) ? $group['local'] : 'db' );
		unset( $group['ID'], $group['local'], $group['local_file'], $group['json_modified'], $group['db_modified'], $group['has_db'] );
		foreach ( $group['fields'] as &$field ) {
			unset( $field['parent'], $field['menu_order'] );
		}
		unset( $field );
		$group['source'] = $source;
		return $group;
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'wpff_not_found', __( 'Field group not found.', 'freeform-fields' ), array( 'status' => 404 ) );
	}

	private function local_error(): \WP_Error {
		return new \WP_Error( 'wpff_local_group', __( 'This field group is registered in code and cannot be edited here.', 'freeform-fields' ), array( 'status' => 409 ) );
	}
}
