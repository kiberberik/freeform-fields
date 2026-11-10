<?php
/**
 * Database storage for field groups edited in the admin.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Registry;

use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Fields\Types\ChoiceField;
use FreeformFields\Location\LocationRules;
use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes field groups and saves them as `wpff-field-group` posts.
 *
 * Post layout: post_name = group key, post_title = title, menu_order = order,
 * post_status = publish (active) or wpff-disabled, post_content = JSON of everything else.
 */
final class GroupStore {

	public const DISABLED_STATUS = 'wpff-disabled';

	private const POSITIONS    = array( 'acf_after_title', 'normal', 'side' );
	private const STYLES       = array( 'default', 'seamless' );
	private const LABELS       = array( 'top', 'left' );
	private const INSTRUCTIONS = array( 'label', 'field' );

	public function __construct(
		private FieldRegistry $registry,
		private FieldTypeRegistry $types,
		private LocationRules $locations
	) {}

	/**
	 * Cleans a group submitted from the builder.
	 *
	 * @param array<string, mixed> $input Raw group.
	 * @return array<string, mixed>|\WP_Error Clean group, or an error whose data lists `errors` as {path, message}.
	 */
	public function sanitize( array $input ) {
		$errors = array();

		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( ! preg_match( '/^group_[a-z0-9_]+$/', $key ) ) {
			$key = 'group_' . self::uid();
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			$errors[] = array(
				'path'    => 'title',
				'message' => __( 'Field group title is required.', 'freeform-fields' ),
			);
		}

		$keys_seen = array();
		$list      = $this->sanitize_field_list( (array) ( $input['fields'] ?? array() ), 'fields', $keys_seen );
		$fields    = $list['fields'];
		$errors    = array_merge( $errors, $list['errors'] );
		$fields    = $this->sanitize_conditions( $fields, array_keys( $keys_seen ) );

		if ( array() !== $errors ) {
			return new \WP_Error(
				'wpff_invalid_group',
				$errors[0]['message'],
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return array(
			'key'                   => $key,
			'title'                 => $title,
			'fields'                => $fields,
			'location'              => $this->sanitize_location( $input['location'] ?? array() ),
			'menu_order'            => (int) ( $input['menu_order'] ?? 0 ),
			'position'              => self::one_of( $input['position'] ?? '', self::POSITIONS, 'normal' ),
			'style'                 => self::one_of( $input['style'] ?? '', self::STYLES, 'default' ),
			'label_placement'       => self::one_of( $input['label_placement'] ?? '', self::LABELS, 'top' ),
			'instruction_placement' => self::one_of( $input['instruction_placement'] ?? '', self::INSTRUCTIONS, 'label' ),
			'hide_on_screen'        => array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['hide_on_screen'] ?? array() ) ) ) ),
			'active'                => ! isset( $input['active'] ) || ! empty( $input['active'] ),
			'description'           => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'show_in_rest'          => empty( $input['show_in_rest'] ) ? 0 : 1,
			'modified'              => time(),
		);
	}

	/**
	 * Creates or updates a group.
	 *
	 * @param array<string, mixed> $input Raw group.
	 * @return array<string, mixed>|\WP_Error Normalized saved group.
	 */
	public function save( array $input ) {
		$group = $this->sanitize( $input );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		if ( $this->registry->is_local( $group['key'] ) ) {
			return new \WP_Error( 'wpff_local_group', __( 'This field group is registered in code and cannot be edited here.', 'freeform-fields' ), array( 'status' => 409 ) );
		}

		$content = $group;
		unset( $content['key'], $content['title'], $content['menu_order'], $content['active'] );

		$post    = $this->find_post( $group['key'] );
		$postarr = array(
			'post_type'    => Plugin::GROUP_POST_TYPE,
			'post_status'  => $group['active'] ? 'publish' : self::DISABLED_STATUS,
			'post_title'   => $group['title'],
			'post_name'    => $group['key'],
			'menu_order'   => $group['menu_order'],
			'post_content' => (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
		if ( $post ) {
			$postarr['ID'] = $post->ID;
		}

		// The JSON is already sanitized; post_content kses would corrupt it.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->registry->flush();

		/**
		 * Fires after a field group was saved in the admin.
		 *
		 * @param array $group Sanitized group.
		 */
		do_action( 'wpff/save_field_group', $group );

		return $this->registry->get_group( $group['key'] ) ?? $group;
	}

	/**
	 * Moves a group to the trash.
	 *
	 * @param string $key Group key.
	 */
	public function trash( string $key ): bool {
		$post = $this->find_post( $key );
		if ( ! $post ) {
			return false;
		}
		$ok = (bool) wp_trash_post( $post->ID );
		$this->registry->flush();
		if ( $ok ) {
			/**
			 * Fires after a field group was moved to the trash.
			 *
			 * @param string $key Group key.
			 */
			do_action( 'wpff/delete_field_group', $key );
		}
		return $ok;
	}

	/**
	 * Restores a trashed group with its previous status.
	 *
	 * @param string $key Group key.
	 */
	public function restore( string $key ): bool {
		$post = $this->find_post( $key, true );
		if ( ! $post || 'trash' !== $post->post_status ) {
			return false;
		}
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$ok = (bool) wp_untrash_post( $post->ID );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		$this->registry->flush();
		$group = $this->registry->get_group( $key );
		if ( $ok && $group ) {
			do_action( 'wpff/save_field_group', $group );
		}
		return $ok;
	}

	/**
	 * Imports a group's Local JSON file into the database.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>|\WP_Error Saved group.
	 */
	public function sync( string $key ) {
		$entry = Plugin::instance()->local_json()->files()[ $key ] ?? null;
		if ( ! $entry ) {
			return new \WP_Error( 'wpff_not_found', __( 'No JSON file for this field group.', 'freeform-fields' ), array( 'status' => 404 ) );
		}
		return $this->save( $entry['group'] );
	}

	/**
	 * Copies a group (database or PHP) into a new database group.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>|\WP_Error New group.
	 */
	public function duplicate( string $key ) {
		$group = $this->registry->get_group( $key );
		if ( ! $group ) {
			return new \WP_Error( 'wpff_not_found', __( 'Field group not found.', 'freeform-fields' ), array( 'status' => 404 ) );
		}
		$group['key'] = 'group_' . self::uid();
		/* translators: %s: field group title. */
		$group['title']  = sprintf( __( '%s (copy)', 'freeform-fields' ), $group['title'] );
		$map             = array();
		$group['fields'] = self::rekey( $group['fields'], $map );
		// Conditions must follow the new keys.
		$group['fields'] = self::remap_conditions( $group['fields'], $map );
		return $this->save( $group );
	}

	/**
	 * Gives fields (recursively) new keys, recording old => new.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, string>            $map    Key map (updated).
	 * @return array<int, array<string, mixed>>
	 */
	private static function rekey( array $fields, array &$map ): array {
		foreach ( $fields as &$field ) {
			$new                  = 'field_' . self::uid();
			$map[ $field['key'] ] = $new;
			$field['key']         = $new;
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::rekey( $field['sub_fields'], $map );
			}
			foreach ( $field['layouts'] ?? array() as $l => $layout ) {
				$field['layouts'][ $l ]['key']        = 'layout_' . self::uid();
				$field['layouts'][ $l ]['sub_fields'] = self::rekey( $layout['sub_fields'], $map );
			}
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Rewrites condition rule field keys after rekey().
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, string>            $map    Old => new keys.
	 * @return array<int, array<string, mixed>>
	 */
	private static function remap_conditions( array $fields, array $map ): array {
		foreach ( $fields as &$field ) {
			if ( is_array( $field['conditional_logic'] ?? null ) ) {
				foreach ( $field['conditional_logic'] as &$and_rules ) {
					foreach ( $and_rules as &$rule ) {
						$rule['field'] = $map[ $rule['field'] ] ?? $rule['field'];
					}
					unset( $rule );
				}
				unset( $and_rules );
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::remap_conditions( $field['sub_fields'], $map );
			}
			foreach ( $field['layouts'] ?? array() as $l => $layout ) {
				$field['layouts'][ $l ]['sub_fields'] = self::remap_conditions( $layout['sub_fields'], $map );
			}
			if ( ! empty( $field['collapsed'] ) && isset( $map[ $field['collapsed'] ] ) ) {
				$field['collapsed'] = $map[ $field['collapsed'] ];
			}
		}
		unset( $field );
		return $fields;
	}

	/**
	 * The post storing a group.
	 *
	 * @param string $key           Group key.
	 * @param bool   $include_trash Also look in the trash.
	 */
	public function find_post( string $key, bool $include_trash = false ): ?\WP_Post {
		$statuses = array( 'publish', self::DISABLED_STATUS );
		if ( $include_trash ) {
			$statuses[] = 'trash';
		}
		$posts = get_posts(
			array(
				'post_type'      => Plugin::GROUP_POST_TYPE,
				'post_status'    => $statuses,
				'name'           => $key,
				'posts_per_page' => 1,
			)
		);
		if ( ! $posts && $include_trash ) {
			// Trashed posts get a `__trashed` suffix on their slug.
			$posts = get_posts(
				array(
					'post_type'      => Plugin::GROUP_POST_TYPE,
					'post_status'    => 'trash',
					'name'           => $key . '__trashed',
					'posts_per_page' => 1,
				)
			);
		}
		return $posts[0] ?? null;
	}

	/**
	 * Cleans a list of sibling fields: names must be unique among siblings, keys across the group.
	 *
	 * @param array<int|string, mixed> $raw_list  Raw fields.
	 * @param string                   $path      Error path prefix, e.g. `fields` or `fields.2.sub_fields`.
	 * @param array<string, true>      $keys_seen Keys used so far in the group (updated).
	 * @return array{fields: array<int, array<string, mixed>>, errors: array<int, array{path: string, message: string}>}
	 */
	private function sanitize_field_list( array $raw_list, string $path, array &$keys_seen ): array {
		$fields     = array();
		$errors     = array();
		$names_seen = array();
		foreach ( array_values( $raw_list ) as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$result = $this->sanitize_field( $raw, "{$path}.{$index}", $keys_seen );
			$errors = array_merge( $errors, $result['errors'] );
			$field  = $result['field'];

			// Tabs, messages and accordions store nothing, so their names (ACF keeps one) never clash.
			$handler = $this->types->get( $field['type'] );
			if ( '' !== $field['name'] && ( ! $handler || $handler->has_value() ) ) {
				if ( isset( $names_seen[ $field['name'] ] ) ) {
					$errors[] = array(
						'path'    => "{$path}.{$index}.name",
						/* translators: %s: field name. */
						'message' => sprintf( __( 'Field name "%s" is used more than once here.', 'freeform-fields' ), $field['name'] ),
					);
				}
				$names_seen[ $field['name'] ] = true;
			}
			$fields[] = $field;
		}
		return array(
			'fields' => $fields,
			'errors' => $errors,
		);
	}

	/**
	 * Cleans one field.
	 *
	 * @param array<string, mixed> $raw       Raw field.
	 * @param string               $path      Error path prefix.
	 * @param array<string, true>  $keys_seen Keys used so far in the group (updated).
	 * @return array{field: array<string, mixed>, errors: array<int, array{path: string, message: string}>}
	 */
	private function sanitize_field( array $raw, string $path, array &$keys_seen ): array {
		$errors  = array();
		$type    = sanitize_key( (string) ( $raw['type'] ?? 'text' ) );
		$handler = $this->types->get( $type );
		if ( ! $handler ) {
			$errors[] = array(
				'path'    => "{$path}.type",
				/* translators: %s: field type. */
				'message' => sprintf( __( 'Unknown field type "%s".', 'freeform-fields' ), $type ),
			);
		}

		$label = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
		if ( '' === $label ) {
			$errors[] = array(
				'path'    => "{$path}.label",
				'message' => __( 'Field label is required.', 'freeform-fields' ),
			);
		}

		$name   = self::sanitize_name( (string) ( $raw['name'] ?? '' ) );
		$stores = ! $handler || $handler->has_value();
		if ( '' === $name && $stores ) {
			$errors[] = array(
				'path'    => "{$path}.name",
				'message' => __( 'Field name is required and may contain only a-z, 0-9, _ and -.', 'freeform-fields' ),
			);
		}

		$key = (string) ( $raw['key'] ?? '' );
		if ( ! preg_match( '/^field_[a-z0-9_]+$/', $key ) || isset( $keys_seen[ $key ] ) ) {
			$key = 'field_' . self::uid();
		}
		$keys_seen[ $key ] = true;

		$wrapper = (array) ( $raw['wrapper'] ?? array() );
		$width   = isset( $wrapper['width'] ) && '' !== $wrapper['width'] ? max( 0, min( 100, (int) $wrapper['width'] ) ) : '';

		$field = array(
			'key'               => $key,
			'label'             => $label,
			'name'              => $name,
			'type'              => $type,
			'instructions'      => wp_kses_post( (string) ( $raw['instructions'] ?? '' ) ),
			'required'          => empty( $raw['required'] ) ? 0 : 1,
			'allow_in_bindings' => empty( $raw['allow_in_bindings'] ) ? 0 : 1,
			// Checked against the group's field keys in sanitize_conditions().
			'conditional_logic' => self::sanitize_condition_rules( $raw['conditional_logic'] ?? 0 ),
			'wrapper'           => array(
				'width' => $width ? $width : '',
				'class' => implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) ( $wrapper['class'] ?? '' ) ) ) ) ),
				'id'    => sanitize_html_class( (string) ( $wrapper['id'] ?? '' ) ),
			),
			'default_value'     => '',
		);

		if ( $handler ) {
			$defaults = $handler->defaults();
			foreach ( $handler->settings() as $setting ) {
				$setting_name           = $setting['name'];
				$value                  = $raw[ $setting_name ] ?? ( $defaults[ $setting_name ] ?? '' );
				$field[ $setting_name ] = self::sanitize_setting( $value, $setting, $defaults[ $setting_name ] ?? '' );
			}
		}

		$kind = $handler ? $handler->compound() : null;
		if ( 'group' === $kind || 'rows' === $kind ) {
			$list                = $this->sanitize_field_list( (array) ( $raw['sub_fields'] ?? array() ), "{$path}.sub_fields", $keys_seen );
			$field['sub_fields'] = $list['fields'];
			$errors              = array_merge( $errors, $list['errors'] );
		} elseif ( 'layouts' === $kind ) {
			$result           = $this->sanitize_layouts( (array) ( $raw['layouts'] ?? array() ), $path, $keys_seen );
			$field['layouts'] = $result['layouts'];
			$errors           = array_merge( $errors, $result['errors'] );
		}

		// A `sub_field` setting (e.g. repeater "collapsed") must point at one of this field's sub fields.
		if ( $handler ) {
			foreach ( $handler->settings() as $setting ) {
				if ( 'sub_field' === $setting['control'] && ! in_array( $field[ $setting['name'] ], array_column( $field['sub_fields'] ?? array(), 'key' ), true ) ) {
					$field[ $setting['name'] ] = '';
				}
			}
		}

		return array(
			'field'  => $field,
			'errors' => $errors,
		);
	}

	/**
	 * Cleans flexible content layouts.
	 *
	 * @param array<int|string, mixed> $raw_layouts Raw layouts.
	 * @param string                   $path        Error path of the field.
	 * @param array<string, true>      $keys_seen   Field keys used so far (updated).
	 * @return array{layouts: array<int, array<string, mixed>>, errors: array<int, array{path: string, message: string}>}
	 */
	private function sanitize_layouts( array $raw_layouts, string $path, array &$keys_seen ): array {
		$layouts = array();
		$errors  = array();
		$names   = array();
		foreach ( array_values( $raw_layouts ) as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$lpath = "{$path}.layouts.{$i}";
			$label = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
			$name  = self::sanitize_name( (string) ( $raw['name'] ?? '' ) );
			if ( '' === $label ) {
				$errors[] = array(
					'path'    => "{$lpath}.label",
					'message' => __( 'Layout label is required.', 'freeform-fields' ),
				);
			}
			if ( '' === $name ) {
				$errors[] = array(
					'path'    => "{$lpath}.name",
					'message' => __( 'Layout name is required and may contain only a-z, 0-9, _ and -.', 'freeform-fields' ),
				);
			} elseif ( isset( $names[ $name ] ) ) {
				$errors[] = array(
					'path'    => "{$lpath}.name",
					/* translators: %s: layout name. */
					'message' => sprintf( __( 'Layout name "%s" is used more than once.', 'freeform-fields' ), $name ),
				);
			}
			$names[ $name ] = true;

			$key = (string) ( $raw['key'] ?? '' );
			if ( ! preg_match( '/^layout_[a-z0-9_]+$/', $key ) ) {
				$key = 'layout_' . self::uid();
			}
			$list      = $this->sanitize_field_list( (array) ( $raw['sub_fields'] ?? array() ), "{$lpath}.sub_fields", $keys_seen );
			$errors    = array_merge( $errors, $list['errors'] );
			$layouts[] = array(
				'key'        => $key,
				'name'       => $name,
				'label'      => $label,
				'display'    => self::one_of( $raw['display'] ?? '', array( 'block', 'table', 'row' ), 'block' ),
				'min'        => is_numeric( $raw['min'] ?? null ) ? (string) absint( $raw['min'] ) : '',
				'max'        => is_numeric( $raw['max'] ?? null ) ? (string) absint( $raw['max'] ) : '',
				'sub_fields' => $list['fields'],
			);
		}
		return array(
			'layouts' => $layouts,
			'errors'  => $errors,
		);
	}

	/**
	 * Operators accepted in conditional logic rules.
	 */
	public const CONDITION_OPERATORS = array( '==', '!=', '==empty', '!=empty', '==contains', '!=contains', '>', '<' );

	/**
	 * Cleans the structure of conditional logic: OR-list of AND-lists of {field, operator, value}.
	 *
	 * @param mixed $rules Raw rules.
	 * @return array<int, array<int, array<string, string>>>|int Rules, or 0 when there are none.
	 */
	private static function sanitize_condition_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return 0;
		}
		$clean = array();
		foreach ( $rules as $and_rules ) {
			$group = array();
			foreach ( (array) $and_rules as $rule ) {
				$rule     = (array) $rule;
				$field    = (string) ( $rule['field'] ?? '' );
				$operator = (string) ( $rule['operator'] ?? '' );
				if ( ! preg_match( '/^field_[a-z0-9_]+$/', $field ) || ! in_array( $operator, self::CONDITION_OPERATORS, true ) ) {
					continue;
				}
				$group[] = array(
					'field'    => $field,
					'operator' => $operator,
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}
			if ( array() !== $group ) {
				$clean[] = $group;
			}
		}
		return array() === $clean ? 0 : $clean;
	}

	/**
	 * Drops condition rules pointing at fields that are not in the group (or at the field itself).
	 *
	 * @param array<int, array<string, mixed>> $fields Fields (recursively).
	 * @param string[]                         $keys   All field keys in the group.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_conditions( array $fields, array $keys ): array {
		foreach ( $fields as &$field ) {
			if ( is_array( $field['conditional_logic'] ) ) {
				$self  = $field['key'];
				$rules = array();
				foreach ( $field['conditional_logic'] as $and_rules ) {
					$and_rules = array_values(
						array_filter( $and_rules, static fn( $rule ) => $rule['field'] !== $self && in_array( $rule['field'], $keys, true ) )
					);
					if ( array() !== $and_rules ) {
						$rules[] = $and_rules;
					}
				}
				$field['conditional_logic'] = array() === $rules ? 0 : $rules;
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = $this->sanitize_conditions( $field['sub_fields'], $keys );
			}
			foreach ( $field['layouts'] ?? array() as $l => $layout ) {
				$field['layouts'][ $l ]['sub_fields'] = $this->sanitize_conditions( $layout['sub_fields'], $keys );
			}
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Cleans one type setting according to its control.
	 *
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $setting Setting definition.
	 * @param mixed                $fallback Default value.
	 * @return mixed
	 */
	private static function sanitize_setting( $value, array $setting, $fallback ) {
		switch ( $setting['control'] ) {
			case 'toggle':
				return empty( $value ) ? 0 : 1;
			case 'number':
				return is_numeric( $value ) ? (string) ( $value + 0 ) : '';
			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			case 'select':
				$options = array_map( 'strval', array_keys( (array) ( $setting['options'] ?? array() ) ) );
				return is_scalar( $value ) && in_array( (string) $value, $options, true ) ? (string) $value : $fallback;
			case 'sub_field':
				// Checked against the field's sub fields after they are sanitized.
				return is_string( $value ) && preg_match( '/^field_[a-z0-9_]+$/', $value ) ? $value : '';
			case 'multiselect':
				$options = array_map( 'strval', array_keys( (array) ( $setting['options'] ?? array() ) ) );
				return array_values( array_intersect( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ), $options ) );
			case 'choices':
				$clean = array();
				foreach ( ChoiceField::normalize_choices( $value ) as $choice => $label ) {
					$choice = sanitize_text_field( $choice );
					if ( '' !== $choice ) {
						$clean[ $choice ] = sanitize_text_field( $label );
					}
				}
				return $clean;
			case 'lines':
				$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
				return array_values( array_filter( array_map( static fn( $line ) => sanitize_text_field( (string) $line ), $lines ), 'strlen' ) );
			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
	}

	/**
	 * Cleans location rules, dropping unknown params and empty AND groups.
	 *
	 * @param mixed $location Raw rules.
	 * @return array<int, array<int, array<string, string>>>
	 */
	private function sanitize_location( $location ): array {
		$params = $this->locations->params();
		$clean  = array();
		foreach ( (array) $location as $and_rules ) {
			$group = array();
			foreach ( (array) $and_rules as $rule ) {
				$rule  = (array) $rule;
				$param = (string) ( $rule['param'] ?? '' );
				if ( ! in_array( $param, $params, true ) ) {
					continue;
				}
				$group[] = array(
					'param'    => $param,
					'operator' => '!=' === ( $rule['operator'] ?? '' ) ? '!=' : '==',
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}
			if ( array() !== $group ) {
				$clean[] = $group;
			}
		}
		return $clean;
	}

	/**
	 * Field name: letters, digits, `_` and `-`. Case is kept: meta keys are case-sensitive and
	 * groups imported from ACF may use capitals.
	 *
	 * @param string $name Raw name.
	 */
	public static function sanitize_name( string $name ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $name ) );
	}

	/**
	 * Returns `$value` when allowed, else `$fallback`.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Default.
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Random 13-character id, like ACF's uniqid-based keys.
	 */
	private static function uid(): string {
		return substr( bin2hex( random_bytes( 8 ) ), 0, 13 );
	}
}
