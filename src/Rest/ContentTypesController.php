<?php
/**
 * REST endpoints for post types and taxonomies created in the admin.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 *  GET    wpff/v1/content-types/{kind}          list (kind: post_type or taxonomy)
 *  POST   wpff/v1/content-types/{kind}          create
 *  GET    wpff/v1/content-types/{kind}/{key}    read
 *  PUT    wpff/v1/content-types/{kind}/{key}    update (the key may change)
 *  DELETE wpff/v1/content-types/{kind}/{key}    delete (content stays in the database)
 */
final class ContentTypesController {

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		$permission = static fn() => current_user_can( Plugin::admin_capability() );
		$kind       = '(?P<kind>post_type|taxonomy)';
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/content-types/' . $kind,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/content-types/' . $kind . '/(?P<key>[a-z0-9_\-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_items( \WP_REST_Request $request ): \WP_REST_Response {
		$kind  = (string) $request['kind'];
		$items = array();
		foreach ( $this->plugin->content_types()->all( $kind ) as $item ) {
			$items[] = $item + array( 'count' => self::count( $kind, $item['key'] ) );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * Published posts or terms of an item; 0 while it is not registered.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 */
	private static function count( string $kind, string $key ): int {
		if ( 'post_type' === $kind ) {
			return post_type_exists( $key ) ? (int) ( wp_count_posts( $key )->publish ?? 0 ) : 0;
		}
		if ( ! taxonomy_exists( $key ) ) {
			return 0;
		}
		$count = wp_count_terms(
			array(
				'taxonomy'   => $key,
				'hide_empty' => false,
			)
		);
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$item = $this->plugin->content_types()->get( (string) $request['kind'], (string) $request['key'] );
		return $item ? rest_ensure_response( $item ) : $this->not_found();
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$item = $this->plugin->content_types()->save( (string) $request['kind'], (array) $request->get_json_params() );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$response = rest_ensure_response( $item );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$kind = (string) $request['kind'];
		$key  = (string) $request['key'];
		if ( ! $this->plugin->content_types()->find_post( $kind, $key ) ) {
			return $this->not_found();
		}
		$item = $this->plugin->content_types()->save( $kind, (array) $request->get_json_params(), $key );
		return is_wp_error( $item ) ? $item : rest_ensure_response( $item );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		if ( ! $this->plugin->content_types()->delete( (string) $request['kind'], (string) $request['key'] ) ) {
			return $this->not_found();
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'wpff_not_found', __( 'Not found.', 'freeform-fields' ), array( 'status' => 404 ) );
	}
}
