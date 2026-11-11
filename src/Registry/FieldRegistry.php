<?php
/**
 * Registry of field groups and their fields.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Registry;

use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Collects field groups from PHP registration and the database into one normalized list.
 *
 * Local (PHP) groups win over database groups with the same key.
 */
final class FieldRegistry {

	/**
	 * Raw groups registered from PHP, keyed by group key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $local = array();

	/**
	 * Normalized groups, keyed by group key. Null until built.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $groups = null;

	/**
	 * The same groups as defined, before clones are expanded: what the builder edits and
	 * exports write. Null until built.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $definitions = null;

	/**
	 * Normalized fields, keyed by field key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

	public function __construct( private FieldTypeRegistry $types ) {}

	/**
	 * Registers a field group from PHP.
	 *
	 * @param array<string, mixed> $group Group settings with a `fields` list.
	 * @return string The group key.
	 */
	public function add_local_group( array $group ): string {
		if ( empty( $group['key'] ) ) {
			$group['key'] = 'group_' . substr( md5( (string) ( $group['title'] ?? wp_json_encode( $group ) ) ), 0, 13 );
		}
		$this->local[ $group['key'] ] = $group;
		$this->groups                 = null;
		return $group['key'];
	}

	/**
	 * Adds a field to a PHP-registered group (ACF's acf_add_local_field()). `parent` is the group key.
	 *
	 * @param array<string, mixed> $field Field settings with `parent`.
	 */
	public function add_local_field( array $field ): bool {
		$parent = (string) ( $field['parent'] ?? '' );
		if ( ! isset( $this->local[ $parent ] ) ) {
			return false;
		}
		$this->local[ $parent ]['fields']   = (array) ( $this->local[ $parent ]['fields'] ?? array() );
		$this->local[ $parent ]['fields'][] = $field;
		$this->groups                       = null;
		return true;
	}

	/**
	 * Removes a PHP-registered group.
	 *
	 * @param string $key Group key.
	 */
	public function remove_local_group( string $key ): void {
		unset( $this->local[ $key ] );
		$this->groups = null;
	}

	/**
	 * Whether a group with this key is registered from PHP (and therefore read-only in the admin).
	 *
	 * @param string $key Group key.
	 */
	public function is_local( string $key ): bool {
		return isset( $this->local[ $key ] );
	}

	/**
	 * Drops cached groups so the next read rebuilds them (e.g. after a DB group is saved).
	 */
	public function flush(): void {
		$this->groups      = null;
		$this->definitions = null;
	}

	/**
	 * All groups including inactive ones, sorted by menu_order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_groups(): array {
		$this->build();
		return $this->groups;
	}

	/**
	 * All active groups, sorted by menu_order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_groups(): array {
		$this->build();
		return array_filter( $this->groups, static fn( $g ) => ! empty( $g['active'] ) );
	}

	/**
	 * One group by key, active or not.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>|null
	 */
	public function get_group( string $key ): ?array {
		$this->build();
		return $this->groups[ $key ] ?? null;
	}

	/**
	 * One group as defined, with clone fields kept as clone fields (not expanded).
	 * Use it to edit, copy or export a group; use get_group() to read or render fields.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>|null
	 */
	public function get_group_definition( string $key ): ?array {
		$this->build();
		return $this->definitions[ $key ] ?? null;
	}

	/**
	 * All groups as defined (clones not expanded), sorted by menu_order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_group_definitions(): array {
		$this->build();
		return $this->definitions;
	}

	/**
	 * One field by key.
	 *
	 * @param string $key Field key.
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $key ): ?array {
		$this->build();
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * First field with the given name, optionally limited to some groups.
	 *
	 * @param string        $name       Field name.
	 * @param string[]|null $group_keys Groups to search, null for all active groups.
	 * @return array<string, mixed>|null
	 */
	public function find_field_by_name( string $name, ?array $group_keys = null ): ?array {
		$this->build();
		$groups = null === $group_keys
			? $this->get_groups()
			: array_intersect_key( $this->groups, array_flip( $group_keys ) );
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( $field['name'] === $name ) {
					return $field;
				}
			}
		}
		return null;
	}

	/**
	 * Builds the normalized list from local and database sources.
	 */
	private function build(): void {
		if ( null !== $this->groups ) {
			return;
		}

		$raw = $this->load_db_groups();
		foreach ( $this->local as $key => $group ) {
			$raw[ $key ] = array( 'local' => 'php' ) + $group;
		}
		// Not migrated yet: read ACF's own groups so the site keeps working without ACF.
		$fallback = self::acf_fallback_groups();
		$missing  = array_diff_key( $fallback['groups'], $raw );
		if ( '' !== $fallback['stamp'] && array() === $missing ) {
			// Every ACF group is migrated: skip reading them until ACF's groups change.
			update_option( 'wpff_acf_migrated', $fallback['stamp'], true );
		}
		foreach ( $missing as $key => $group ) {
			$raw[ $key ] = array( 'local' => 'acf' ) + $group;
		}

		/**
		 * Filters raw field groups before normalization.
		 *
		 * @param array<string, array> $raw Groups keyed by group key.
		 */
		$raw = (array) \FreeformFields\Hooks::filter( 'load_field_groups', $raw );

		$this->groups = array();
		$this->fields = array();
		foreach ( $raw as $key => $group ) {
			$group                         = $this->normalize_group( array( 'key' => (string) $key ) + $group );
			$this->groups[ $group['key'] ] = $group;
		}
		// Clones need every group normalized first, so they are expanded in a second pass.
		$normalized        = $this->groups;
		$this->definitions = $normalized;
		foreach ( $this->groups as $key => $group ) {
			$this->groups[ $key ]['fields'] = $this->expand_clones( $group['fields'], $normalized, array( $key => true ), 0 );
			$this->index_fields( $this->groups[ $key ]['fields'] );
		}

		uasort( $this->groups, static fn( $a, $b ) => $a['menu_order'] <=> $b['menu_order'] );
		uasort( $this->definitions, static fn( $a, $b ) => $a['menu_order'] <=> $b['menu_order'] );
	}

	/**
	 * Field groups ACF stored in the database, read-only, while ACF itself is inactive.
	 *
	 * Lets a site switched off ACF before migrating keep its fields. Cached until ACF's
	 * group or field posts change. Filter `wpff/read_acf_groups` to turn it off.
	 *
	 * @return array{groups: array<string, array<string, mixed>>, stamp: string} Groups keyed by group key,
	 *         and the fingerprint of ACF's groups when they were read.
	 */
	private static function acf_fallback_groups(): array {
		$none = array(
			'groups' => array(),
			'stamp'  => '',
		);
		if ( \FreeformFields\Plugin::acf_active() || ! apply_filters( 'wpff/read_acf_groups', true ) ) {
			return $none;
		}
		$hash = AcfStamp::get( 'groups' );
		if ( '' === $hash || get_option( 'wpff_acf_migrated' ) === $hash ) {
			return $none;
		}
		$cache_key = 'wpff_acf_groups_' . $hash;
		$groups    = get_transient( $cache_key );
		if ( ! is_array( $groups ) ) {
			$groups = array();
			foreach ( ( new \FreeformFields\Tools\ImportExport( \FreeformFields\Plugin::instance() ) )->acf_db_groups() as $group ) {
				$groups[ $group['key'] ] = \FreeformFields\Tools\ImportExport::from_acf_shape( $group );
			}
			set_transient( $cache_key, $groups, DAY_IN_SECONDS );
		}
		return array(
			'groups' => $groups,
			'stamp'  => $hash,
		);
	}

	/**
	 * Replaces clone fields by copies of the fields they clone (seamless), or fills their
	 * sub fields with those copies (group display). Works at any depth; cycles are cut.
	 *
	 * @param array<int, array<string, mixed>>    $fields  Fields.
	 * @param array<string, array<string, mixed>> $groups  All normalized groups.
	 * @param array<string, true>                 $visited Group and field keys being expanded.
	 * @param int                                 $depth   Nesting depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function expand_clones( array $fields, array $groups, array $visited, int $depth ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( 'clone' === $field['type'] ) {
				$copies = $depth < 5 ? $this->clone_copies( $field, $groups, $visited, $depth ) : array();
				if ( 'group' === $field['display'] ) {
					$field['sub_fields'] = $copies;
					$out[]               = $field;
				} else {
					foreach ( $copies as $copy ) {
						$copy['parent'] = $field['parent'];
						$out[]          = $copy;
					}
				}
				continue;
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = $this->expand_clones( $field['sub_fields'], $groups, $visited, $depth );
			}
			if ( ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $i => $layout ) {
					$field['layouts'][ $i ]['sub_fields'] = $this->expand_clones( $layout['sub_fields'], $groups, $visited, $depth );
				}
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Copies of the fields a clone points at, with keys `{clone key}_{original key}`.
	 *
	 * @param array<string, mixed>                $clone   Clone field.
	 * @param array<string, array<string, mixed>> $groups  All normalized groups.
	 * @param array<string, true>                 $visited Keys being expanded.
	 * @param int                                 $depth   Depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function clone_copies( array $clone, array $groups, array $visited, int $depth ): array {
		$sources = array();
		foreach ( (array) $clone['clone'] as $target ) {
			$target = (string) $target;
			if ( isset( $visited[ $target ] ) ) {
				continue;
			}
			if ( isset( $groups[ $target ] ) ) {
				foreach ( $groups[ $target ]['fields'] as $field ) {
					$sources[] = array( $field, $target );
				}
				continue;
			}
			foreach ( $groups as $group_key => $group ) {
				foreach ( $group['fields'] as $field ) {
					if ( $field['key'] === $target ) {
						$sources[] = array( $field, $group_key );
					}
				}
			}
		}

		$copies = array();
		$map    = array();
		foreach ( $sources as [ $field, $group_key ] ) {
			// Nested clones inside the cloned fields are expanded first.
			$expanded = $this->expand_clones(
				array( $field ),
				$groups,
				$visited + array(
					$group_key    => true,
					$field['key'] => true,
				),
				$depth + 1
			);
			foreach ( $expanded as $copy ) {
				$copy = self::prefix_keys( $copy, $clone['key'] . '_', $map );
				if ( ! empty( $clone['prefix_name'] ) && '' !== $copy['name'] ) {
					$copy['name'] = $clone['name'] . '_' . $copy['name'];
				}
				if ( ! empty( $clone['prefix_label'] ) ) {
					$copy['label'] = $clone['label'] . ' ' . $copy['label'];
				}
				$copy['parent'] = $clone['key'];
				$copies[]       = $copy;
			}
		}
		return self::remap_conditions( $copies, $map );
	}

	/**
	 * Prefixes the keys of a field and its sub fields, recording old => new.
	 *
	 * @param array<string, mixed>  $field  Field.
	 * @param string                $prefix Prefix.
	 * @param array<string, string> $map    Key map (updated).
	 * @return array<string, mixed>
	 */
	private static function prefix_keys( array $field, string $prefix, array &$map ): array {
		$new                  = $prefix . $field['key'];
		$map[ $field['key'] ] = $new;
		$field['key']         = $new;
		foreach ( $field['sub_fields'] ?? array() as $i => $sub ) {
			$field['sub_fields'][ $i ]           = self::prefix_keys( $sub, $prefix, $map );
			$field['sub_fields'][ $i ]['parent'] = $new;
		}
		foreach ( $field['layouts'] ?? array() as $l => $layout ) {
			foreach ( $layout['sub_fields'] as $i => $sub ) {
				$field['layouts'][ $l ]['sub_fields'][ $i ]           = self::prefix_keys( $sub, $prefix, $map );
				$field['layouts'][ $l ]['sub_fields'][ $i ]['parent'] = $new;
			}
		}
		return $field;
	}

	/**
	 * Points conditional logic of copied fields at the copies.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, string>            $map    Old => new keys.
	 * @return array<int, array<string, mixed>>
	 */
	private static function remap_conditions( array $fields, array $map ): array {
		foreach ( $fields as &$field ) {
			if ( is_array( $field['conditional_logic'] ?? null ) ) {
				foreach ( $field['conditional_logic'] as &$rules ) {
					foreach ( $rules as &$rule ) {
						$rule['field'] = $map[ $rule['field'] ?? '' ] ?? ( $rule['field'] ?? '' );
					}
					unset( $rule );
				}
				unset( $rules );
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::remap_conditions( $field['sub_fields'], $map );
			}
			foreach ( $field['layouts'] ?? array() as $l => $layout ) {
				$field['layouts'][ $l ]['sub_fields'] = self::remap_conditions( $layout['sub_fields'], $map );
			}
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Adds fields and their sub fields (any depth) to the key index.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 */
	private function index_fields( array $fields ): void {
		foreach ( $fields as $field ) {
			$this->fields[ $field['key'] ] = $field;
			if ( ! empty( $field['sub_fields'] ) ) {
				$this->index_fields( $field['sub_fields'] );
			}
			foreach ( $field['layouts'] ?? array() as $layout ) {
				$this->index_fields( $layout['sub_fields'] );
			}
		}
	}

	/**
	 * Reads groups saved in the admin. Each group is one post with its settings as JSON in post_content.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load_db_groups(): array {
		if ( ! did_action( 'init' ) || ! post_type_exists( Plugin::GROUP_POST_TYPE ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'              => Plugin::GROUP_POST_TYPE,
				'post_status'            => array( 'publish', 'wpff-disabled' ),
				'posts_per_page'         => -1,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				// Filters stay on for other plugins, but groups are never limited to a language.
				'suppress_filters'       => false,
				'lang'                   => '',
				'update_post_term_cache' => false,
			)
		);
		$groups = array();
		foreach ( $posts as $post ) {
			$data = json_decode( $post->post_content, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$data['key']                = $post->post_name;
			$data['title']              = $post->post_title;
			$data['menu_order']         = $post->menu_order;
			$data['active']             = 'publish' === $post->post_status;
			$data['ID']                 = $post->ID;
			$groups[ $post->post_name ] = $data;
		}
		return $groups;
	}

	/**
	 * Fills group defaults and normalizes its fields.
	 *
	 * @param array<string, mixed> $group Raw group.
	 * @return array<string, mixed>
	 */
	private function normalize_group( array $group ): array {
		$group = wp_parse_args(
			$group,
			array(
				'key'                   => '',
				'title'                 => '',
				'fields'                => array(),
				'location'              => array(),
				'menu_order'            => 0,
				// 'acf_after_title', 'normal' or 'side'.
				'position'              => 'normal',
				// 'default' (box) or 'seamless'.
				'style'                 => 'default',
				// 'top' or 'left'.
				'label_placement'       => 'top',
				// 'label' or 'field'.
				'instruction_placement' => 'label',
				'hide_on_screen'        => array(),
				'active'                => true,
				'description'           => '',
			)
		);

		$fields = array();
		foreach ( (array) $group['fields'] as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field = $this->normalize_field( $field, $group['key'], (int) $index );
			if ( null !== $field ) {
				$fields[] = $field;
			}
		}
		$group['fields']     = $fields;
		$group['menu_order'] = (int) $group['menu_order'];
		$group['active']     = (bool) $group['active'];
		$group['location']   = is_array( $group['location'] ) ? $group['location'] : array();

		return $group;
	}

	/**
	 * Fills common and type-specific defaults for a field.
	 *
	 * Layout fields (tab, accordion, message) store no value and may have no name.
	 *
	 * @param array<string, mixed> $field      Raw field.
	 * @param string               $parent_key Parent group or field key.
	 * @param int                  $index      Position among its siblings.
	 * @param string               $seed       Extra seed for generated keys (e.g. layout name).
	 * @return array<string, mixed>|null Null when a value field has no name.
	 */
	private function normalize_field( array $field, string $parent_key, int $index, string $seed = '' ): ?array {
		$name    = (string) ( $field['name'] ?? '' );
		$type    = (string) ( $field['type'] ?? 'text' );
		$handler = $this->types->get( $type );
		$stores  = ! $handler || $handler->has_value();
		if ( '' === $name && $stores ) {
			return null;
		}
		$seed .= '' !== $name ? $name : $type . '_' . $index . '_' . ( $field['label'] ?? '' );

		$field = array_merge(
			array(
				// A stable key keeps stored `_name` references valid when the key is omitted.
				'key'               => 'field_' . substr( md5( $parent_key . '/' . $seed ), 0, 13 ),
				'label'             => $name,
				'name'              => $name,
				'type'              => $type,
				'instructions'      => '',
				'required'          => 0,
				'allow_in_bindings' => 0,
				'default_value'     => '',
				'conditional_logic' => 0,
				'wrapper'           => array(
					'width' => '',
					'class' => '',
					'id'    => '',
				),
			),
			$handler ? $handler->defaults() : array(),
			$field
		);

		$field['parent']     = $parent_key;
		$field['menu_order'] = $index;
		$field['required']   = (int) ! empty( $field['required'] );
		$field['wrapper']    = wp_parse_args(
			(array) $field['wrapper'],
			array(
				'width' => '',
				'class' => '',
				'id'    => '',
			)
		);

		$kind = $handler ? $handler->compound() : null;
		if ( 'group' === $kind || 'rows' === $kind ) {
			$field['sub_fields'] = $this->normalize_list( (array) ( $field['sub_fields'] ?? array() ), $field['key'] );
		} elseif ( 'layouts' === $kind ) {
			$layouts = array();
			foreach ( array_values( (array) ( $field['layouts'] ?? array() ) ) as $layout ) {
				if ( ! is_array( $layout ) || '' === (string) ( $layout['name'] ?? '' ) ) {
					continue;
				}
				$layout               = wp_parse_args(
					$layout,
					array(
						'key'        => 'layout_' . substr( md5( $field['key'] . '/' . $layout['name'] ), 0, 13 ),
						'name'       => '',
						'label'      => $layout['name'],
						// 'block', 'table' or 'row'.
						'display'    => 'block',
						'sub_fields' => array(),
						'min'        => '',
						'max'        => '',
					)
				);
				$layout['sub_fields'] = $this->normalize_list( (array) $layout['sub_fields'], $field['key'], $layout['name'] . '/' );
				$layouts[]            = $layout;
			}
			$field['layouts'] = $layouts;
		}

		return $field;
	}

	/**
	 * Normalizes a list of sibling fields.
	 *
	 * @param array<int|string, mixed> $list       Raw fields.
	 * @param string                   $parent_key Parent field key.
	 * @param string                   $seed       Extra seed for generated keys.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_list( array $list, string $parent_key, string $seed = '' ): array {
		$fields = array();
		foreach ( array_values( $list ) as $i => $sub ) {
			$sub = is_array( $sub ) ? $this->normalize_field( $sub, $parent_key, $i, $seed ) : null;
			if ( null !== $sub ) {
				$fields[] = $sub;
			}
		}
		return $fields;
	}
}
