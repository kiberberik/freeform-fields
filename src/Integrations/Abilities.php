<?php
/**
 * WordPress Abilities API integration.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Integrations;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Registers read-only abilities (WordPress 6.9+) so AI agents and other Abilities API
 * clients can discover field groups and read field values with the user's permissions.
 */
final class Abilities {

	public const CATEGORY = 'custom-fields';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => __( 'Custom fields', 'freeform-fields' ),
					'description' => __( 'Field groups and field values from WP Freeform Fields.', 'freeform-fields' ),
				)
			);
		}
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$readonly = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'wpff/list-field-groups',
			array(
				'label'               => __( 'List field groups', 'freeform-fields' ),
				'description'         => __( 'Lists field groups with their fields (key, name, label, type) and location rules.', 'freeform-fields' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => array( $this, 'list_groups' ),
				'permission_callback' => fn() => current_user_can( Plugin::admin_capability() ),
				'meta'                => $readonly,
			)
		);

		wp_register_ability(
			'wpff/get-field-values',
			array(
				'label'               => __( 'Get field values', 'freeform-fields' ),
				'description'         => __( 'Returns the raw field values of a post, term (term_5), user (user_3) or options page (option).', 'freeform-fields' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'object_id' => array(
							'type'        => array( 'string', 'integer' ),
							'description' => __( 'Post ID, term_{id}, user_{id}, comment_{id} or option.', 'freeform-fields' ),
						),
					),
					'required'             => array( 'object_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_values' ),
				'permission_callback' => array( $this, 'can_read_values' ),
				'meta'                => $readonly,
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_groups(): array {
		$walk = static function ( array $fields ) use ( &$walk ): array {
			return array_map(
				static fn( $f ) => array_filter(
					array(
						'key'        => $f['key'],
						'name'       => $f['name'],
						'label'      => $f['label'],
						'type'       => $f['type'],
						'required'   => (bool) $f['required'],
						'sub_fields' => isset( $f['sub_fields'] ) && is_array( $f['sub_fields'] ) ? $walk( $f['sub_fields'] ) : null,
					),
					static fn( $v ) => null !== $v
				),
				$fields
			);
		};
		$out  = array();
		foreach ( $this->plugin->fields()->get_groups() as $group ) {
			$out[] = array(
				'key'      => $group['key'],
				'title'    => $group['title'],
				'active'   => (bool) ( $group['active'] ?? true ),
				'location' => $group['location'],
				'fields'   => $walk( $group['fields'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_values( $input ) {
		$ref = ObjectRef::parse( $input['object_id'] ?? null );
		if ( ! $ref ) {
			return new \WP_Error( 'wpff_invalid_object', __( 'Unknown object.', 'freeform-fields' ) );
		}
		$values = wpff_get_fields( $ref->to_string(), false );
		return is_array( $values ) ? $values : array();
	}

	/**
	 * Same rule as editing the object: values may hold data editors see but visitors do not.
	 *
	 * @param array<string, mixed> $input Input.
	 */
	public function can_read_values( $input ): bool {
		$ref = ObjectRef::parse( is_array( $input ) ? ( $input['object_id'] ?? null ) : null );
		if ( ! $ref ) {
			return false;
		}
		switch ( $ref->type ) {
			case 'post':
				return current_user_can( 'edit_post', (int) $ref->id );
			case 'term':
				return current_user_can( 'edit_term', (int) $ref->id );
			case 'user':
				return current_user_can( 'edit_user', (int) $ref->id );
			case 'comment':
				return current_user_can( 'edit_comment', (int) $ref->id );
			default:
				return current_user_can( 'manage_options' );
		}
	}
}
