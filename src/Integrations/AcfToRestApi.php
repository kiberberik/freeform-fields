<?php
/**
 * Keeps the REST output of the "ACF to REST API" plugin working without ACF.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Integrations;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Admin\UserScreen;
use FreeformFields\Plugin;
use FreeformFields\Rest\RestFields;

defined( 'ABSPATH' ) || exit;

/**
 * "ACF to REST API" (acf-to-rest-api) adds an `acf` property to wp/v2 responses and
 * `acf/v3/…` routes, but switches itself off when ACF is missing. While that plugin is active and
 * ACF is not, this class serves the same read-only output from WP Freeform Fields:
 *
 * - `acf` in wp/v2 posts, terms and users: formatted values (get_fields());
 * - GET `acf/v3/{rest_base}/{id}` → `{"acf": {...}}`, and `acf/v3/{rest_base}/{id}/{field}` → `{"field": value}`;
 * - GET `acf/v3/options/{id}` and `acf/v3/options/{id}/{field}`.
 * Comments have no field locations here, so `acf/v3/comments` is not served.
 *
 * Only fields of the groups shown on that object are served, never other meta or options, and
 * Password fields are left out. Unlike the original:
 * - posts, terms and users follow the visibility rules of the core REST API;
 * - options page fields need the page's capability, unless their group is shown in REST
 *   (filter `wpff/acf_to_rest_api/public_options` to make them all public, as the original did).
 * Filter `wpff/emulate_acf_to_rest_api` to turn it off.
 */
final class AcfToRestApi {

	public const PLUGIN = 'acf-to-rest-api/class-acf-to-rest-api.php';

	public const NAMESPACE = 'acf/v3';

	private ?RestFields $rest = null;

	public function __construct( private Plugin $plugin ) {}

	public static function active(): bool {
		if ( Plugin::acf_active() ) {
			return false;
		}
		$active = in_array( self::PLUGIN, (array) get_option( 'active_plugins', array() ), true );
		/**
		 * Filters whether WP Freeform Fields serves the "ACF to REST API" output.
		 *
		 * @param bool $active Default: true while acf-to-rest-api is active and ACF is not.
		 */
		return (bool) apply_filters( 'wpff/emulate_acf_to_rest_api', $active );
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 15 );
	}

	public function register_routes(): void {
		if ( ! self::active() ) {
			return;
		}
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			$this->add( $type->rest_base ? $type->rest_base : $type->name, $type->name, 'post' );
		}
		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $tax ) {
			$this->add( $tax->rest_base ? $tax->rest_base : $tax->name, $tax->name, 'term' );
		}
		$this->add( 'users', 'user', 'user' );

		register_rest_route(
			self::NAMESPACE,
			'/options/(?P<id>[\w\-\_]+)/?(?P<field>[\w\-\_]+)?',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => $this->response( (string) $r['id'], (string) ( $r['field'] ?? '' ), $this->options_fields( (string) $r['id'] ) ),
				'permission_callback' => '__return_true', // Only the allowed fields are served; none means `{"acf": []}`, as in the original.
			)
		);
	}

	/**
	 * Route and wp/v2 property for one object type.
	 *
	 * @param string $base   REST base, e.g. `posts`, `news`, `categories`.
	 * @param string $type   Post type, taxonomy or `user`.
	 * @param string $kind   post, term or user.
	 */
	private function add( string $base, string $type, string $kind ): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/(?P<id>[\d]+)/?(?P<field>[\w\-\_]+)?',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( \WP_REST_Request $r ) use ( $type, $kind ) {
					$id = (int) $r['id'];
					return $this->response( self::object_id( $kind, $type, $id ), (string) ( $r['field'] ?? '' ), $this->allowed( self::screen( $kind, $type, $id ) ) );
				},
				'permission_callback' => fn( \WP_REST_Request $r ) => self::can_read( $kind, $type, (int) $r['id'] ),
			)
		);
		register_rest_field(
			$type,
			'acf',
			array(
				'get_callback' => function ( $item ) use ( $type, $kind ) {
					$id = (int) ( $item['id'] ?? 0 );
					return self::can_read( $kind, $type, $id ) ? $this->fields( self::object_id( $kind, $type, $id ), $this->allowed( self::screen( $kind, $type, $id ) ) ) : array();
				},
				'schema'       => array(
					'description' => __( 'Custom field values.', 'freeform-fields' ),
					'type'        => 'object',
				),
			)
		);
	}

	/**
	 * @param string                                   $object_id ACF-style id.
	 * @param string                                   $field     Single field name, or ''.
	 * @param array<string, array<string, mixed>>|null $allowed   Fields that may be served, by name.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function response( string $object_id, string $field, ?array $allowed ) {
		$allowed = (array) $allowed;
		if ( '' !== $field ) {
			if ( ! isset( $allowed[ $field ] ) ) {
				return new \WP_Error( 'rest_not_found', __( 'No field with that name.', 'freeform-fields' ), array( 'status' => 404 ) );
			}
			return rest_ensure_response( array( $field => $this->safe( wpff_get_field( $field, $object_id ), $allowed[ $field ] ) ) );
		}
		return rest_ensure_response( array( 'acf' => $this->fields( $object_id, $allowed ) ) );
	}

	/**
	 * Formatted values of the allowed fields, in get_fields() order. Without stored values,
	 * every allowed field (as the original does).
	 *
	 * @param string                              $object_id ACF-style id.
	 * @param array<string, array<string, mixed>> $allowed   Fields by name.
	 * @return array<string, mixed>
	 */
	private function fields( string $object_id, array $allowed ): array {
		if ( array() === $allowed ) {
			return array();
		}
		$values = wpff_get_fields( $object_id );
		$values = is_array( $values ) ? array_intersect_key( $values, $allowed ) : array();
		if ( array() === $values ) {
			foreach ( array_keys( $allowed ) as $name ) {
				$values[ $name ] = wpff_get_field( $name, $object_id );
			}
		}
		foreach ( $values as $name => $value ) {
			$values[ $name ] = $this->safe( $value, $allowed[ $name ] );
		}
		return $values;
	}

	/**
	 * Drops linked posts the requester may not read and private user details, at any depth.
	 *
	 * @param mixed                $value Formatted value.
	 * @param array<string, mixed> $field Field.
	 * @return mixed
	 */
	private function safe( $value, array $field ) {
		$this->rest ??= new RestFields( $this->plugin );
		return $this->rest->rest_safe( $value, $field, true );
	}

	/**
	 * Top-level fields of the groups shown on a screen, by name. Password fields and
	 * fields without a value (tabs, messages) are left out.
	 *
	 * @param array<string, mixed> $screen    Location screen.
	 * @param bool                 $only_rest Only groups with "Show in REST API".
	 * @return array<string, array<string, mixed>>
	 */
	private function allowed( array $screen, bool $only_rest = false ): array {
		$fields = array();
		if ( array() === $screen ) {
			return $fields;
		}
		foreach ( ( new FormSupport( $this->plugin ) )->groups_for( $screen ) as $group ) {
			if ( $only_rest && empty( $group['show_in_rest'] ) ) {
				continue;
			}
			foreach ( $group['fields'] as $f ) {
				$handler = $this->plugin->types()->get( $f['type'] );
				if ( '' === $f['name'] || 'password' === $f['type'] || ( $handler && ! $handler->has_value() ) ) {
					continue;
				}
				$fields[ $f['name'] ] = $f;
			}
		}
		return $fields;
	}

	/**
	 * Fields of the options pages saved under an id that the requester may see, or null when none.
	 *
	 * @param string $id Options id from the route (`options` by default).
	 * @return array<string, array<string, mixed>>|null
	 */
	private function options_fields( string $id ): ?array {
		$found  = false;
		$fields = array();
		/**
		 * Filters whether every options page field is public in acf/v3, as in the original plugin.
		 *
		 * @param bool $public Default false: only groups shown in REST, or users with the page's capability.
		 */
		$public = (bool) apply_filters( 'wpff/acf_to_rest_api/public_options', false );
		foreach ( $this->plugin->options_pages()->all() as $slug => $page ) {
			if ( (string) $page['post_id'] !== $id ) {
				continue;
			}
			$found      = true;
			$capability = (string) ( $page['capability'] ?? '' );
			$full       = $public || ( '' !== $capability && current_user_can( $capability ) );
			$fields    += $this->allowed( array( 'options_page' => $slug ), ! $full );
		}
		return $found && array() !== $fields ? $fields : null;
	}

	private static function object_id( string $kind, string $type, int $id ): string {
		switch ( $kind ) {
			case 'term':
				return $type . '_' . $id;
			case 'user':
				return 'user_' . $id;
			default:
				return (string) $id;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function screen( string $kind, string $type, int $id ): array {
		switch ( $kind ) {
			case 'post':
				return array( 'post_id' => $id );
			case 'term':
				return array( 'taxonomy' => $type );
			case 'user':
				return UserScreen::screen( $id, 'edit' );
			default:
				return array();
		}
	}

	/**
	 * Same visibility as the core REST API for the object.
	 *
	 * @param string $kind post, term or user.
	 * @param string $type Post type or taxonomy.
	 * @param int    $id   Object id.
	 */
	private static function can_read( string $kind, string $type, int $id ): bool {
		switch ( $kind ) {
			case 'post':
				$post = get_post( $id );
				if ( ! $post || $post->post_type !== $type ) {
					return false;
				}
				if ( 'publish' === $post->post_status && '' === $post->post_password ) {
					return true;
				}
				return current_user_can( 'read_post', $id ) && ( '' === $post->post_password || current_user_can( 'edit_post', $id ) );
			case 'term':
				$term = get_term( $id, $type );
				$tax  = get_taxonomy( $type );
				return $term instanceof \WP_Term && $tax && ( $tax->public || current_user_can( $tax->cap->assign_terms ) );
			case 'user':
				// As in core: authors of public content, or anyone for those who may list users.
				if ( ! get_userdata( $id ) ) {
					return false;
				}
				return current_user_can( 'list_users' ) || count_user_posts(
					$id,
					get_post_types(
						array(
							'show_in_rest' => true,
							'public'       => true,
						)
					),
					true
				) > 0;
		}
		return false;
	}
}
