<?php
/**
 * REST endpoints for the Tools screen.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Plugin;
use FreeformFields\Tools\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 *  GET  wpff/v1/tools/export?keys[]=…&format=json|php   → { json } or { php }
 *  POST wpff/v1/tools/import { groups }                 → { imported, errors }
 *  GET  wpff/v1/tools/acf                               → groups ACF stored in the database
 *  POST wpff/v1/tools/acf                               → import them
 */
final class ToolsController {

	public function __construct( private Plugin $plugin ) {}

	public function register_routes(): void {
		$permission = static fn() => current_user_can( Plugin::admin_capability() );
		$routes     = array(
			'/tools/export'    => array( \WP_REST_Server::READABLE, 'export' ),
			'/tools/import'    => array( \WP_REST_Server::CREATABLE, 'import' ),
			'/tools/acf-check' => array( \WP_REST_Server::READABLE, 'acf_check' ),
		);
		foreach ( $routes as $route => [ $method, $callback ] ) {
			register_rest_route(
				FieldGroupsController::NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $permission,
				)
			);
		}
		register_rest_route(
			FieldGroupsController::NAMESPACE,
			'/tools/acf',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'acf_preview' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'acf_import' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	private function tools(): ImportExport {
		return new ImportExport( $this->plugin );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$keys = array_values( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'keys' ) ) ) );
		if ( 'php' === $request->get_param( 'format' ) ) {
			return rest_ensure_response( array( 'php' => $this->tools()->export_php( $keys ) ) );
		}
		return rest_ensure_response( array( 'json' => $this->tools()->export( $keys ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->tools()->import( $request->get_param( 'groups' ) ) );
	}

	public function acf_preview(): \WP_REST_Response {
		$types = $this->tools()->acf_db_content_types();
		$out   = array();
		foreach ( $types as $kind => $items ) {
			foreach ( $items as $item ) {
				$out[] = array(
					'key'    => $item['key'],
					/* translators: %s: post type or taxonomy label. */
					'title'  => sprintf( 'post_type' === $kind ? __( 'Post type: %s', 'freeform-fields' ) : __( 'Taxonomy: %s', 'freeform-fields' ), $item['plural'] ),
					'fields' => null,
					'exists' => null !== $this->plugin->content_types()->get( $kind, $item['key'] ),
				);
			}
		}
		foreach ( $this->tools()->acf_db_options_pages() as $page ) {
			$slug  = sanitize_title( $page['menu_slug'] );
			$out[] = array(
				'key'    => $page['menu_slug'],
				/* translators: %s: options page title. */
				'title'  => sprintf( __( 'Options page: %s', 'freeform-fields' ), $page['page_title'] ),
				'fields' => null,
				'exists' => null !== $this->plugin->options_store()->find_post( $slug ) || $this->plugin->options_pages()->is_local( $slug ),
			);
		}
		foreach ( $this->tools()->acf_db_groups() as $group ) {
			$out[] = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => count( $group['fields'] ),
				// Groups read straight from ACF's tables (not migrated) do not count.
				'exists' => $this->imported( $group['key'] ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Whether a group with this key is WP Freeform Fields' own (not just read from ACF's tables).
	 *
	 * @param string $key Group key.
	 */
	private function imported( string $key ): bool {
		$group = $this->plugin->fields()->get_group( $key );
		return null !== $group && 'acf' !== ( $group['local'] ?? '' );
	}

	public function acf_check(): \WP_REST_Response {
		return rest_ensure_response( ( new \FreeformFields\Tools\ThemeCheck() )->run() );
	}

	public function acf_import(): \WP_REST_Response {
		return rest_ensure_response( $this->tools()->import_acf_all() );
	}
}
