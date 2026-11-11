<?php
/**
 * High-level reading and writing of field values.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Storage;

use FreeformFields\Hooks;

use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Registry\FieldRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves fields by name or key and runs values through the type handlers and filters.
 */
final class ValueManager {

	private MetaStore $store;

	/**
	 * Nesting depth of save() calls; the outermost one starts a write batch.
	 *
	 * @var int
	 */
	private int $depth = 0;

	public function __construct(
		private FieldRegistry $fields,
		private FieldTypeRegistry $types
	) {
		$this->store = new MetaStore();
	}

	public function store(): MetaStore {
		return $this->store;
	}

	/**
	 * Compound kind of a field (`group`, `rows`, `layouts`) or null.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public function kind_of( array $field ): ?string {
		$handler = $this->types->get( $field['type'] );
		return $handler ? $handler->compound() : null;
	}

	/**
	 * Finds the field definition for a selector on an object.
	 *
	 * Order: field key -> key referenced in storage (`_{name}`) -> first registered field with that name.
	 *
	 * @param string         $selector Field name or key.
	 * @param ObjectRef|null $ref      Object, used to read the stored reference.
	 * @return array<string, mixed>|null
	 */
	public function resolve_field( string $selector, ?ObjectRef $ref = null ): ?array {
		if ( str_starts_with( $selector, 'field_' ) ) {
			$field = $this->fields->get_field( $selector );
			if ( $field ) {
				return $field;
			}
		}
		if ( $ref ) {
			$key = $this->store->get_reference( $ref, $selector );
			if ( $key ) {
				$field = $this->fields->get_field( $key );
				if ( $field ) {
					// A sub field read directly (e.g. `hero_title`) is stored under the full name.
					$field['name'] = $selector;
					return $field;
				}
			}
		}
		return $this->fields->find_field_by_name( $selector );
	}

	/**
	 * Value of a field.
	 *
	 * @param string $selector Field name or key.
	 * @param mixed  $post_id  ACF-style object id.
	 * @param bool   $format   Apply format_value (return format, new lines, ...).
	 * @return mixed Null when the object cannot be resolved or nothing is stored and there is no default.
	 */
	public function get( string $selector, $post_id = false, bool $format = true ) {
		$ref = ObjectRef::parse( $post_id );
		if ( ! $ref ) {
			return null;
		}
		$field = $this->resolve_field( $selector, $ref );
		if ( ! $field ) {
			// Unknown field: behave like get_metadata() so plain meta is still readable.
			return $this->store->get( $ref, $selector );
		}
		$value = $this->load( $field, $ref );
		return $format ? $this->format( $value, $field, $ref ) : $this->acf_raw( $value, $field );
	}

	/**
	 * Unformatted value in ACF's shape: rows and groups keyed by field key, no rows = false.
	 *
	 * Internally rows are keyed by sub field name; public "raw" reads (get_field( $name, $id, false ))
	 * return what ACF returns so code written for it keeps working.
	 *
	 * @param mixed                $value Loaded value.
	 * @param array<string, mixed> $field Field.
	 * @return mixed
	 */
	public function acf_raw( $value, array $field ) {
		$kind = $this->kind_of( $field );
		if ( null === $kind ) {
			return $value;
		}
		if ( 'group' === $kind ) {
			return is_array( $value ) ? $this->acf_raw_row( $value, $field['sub_fields'] ?? array() ) : $value;
		}
		if ( ! is_array( $value ) || array() === $value ) {
			return false;
		}
		$rows = array();
		foreach ( array_values( $value ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$subs = $field['sub_fields'] ?? array();
			if ( 'layouts' === $kind ) {
				$layout = self::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
				$subs   = $layout ? $layout['sub_fields'] : array();
			}
			$out = $this->acf_raw_row( $row, $subs );
			if ( 'layouts' === $kind ) {
				$out = array( 'acf_fc_layout' => $row['acf_fc_layout'] ?? '' ) + $out;
			}
			$rows[] = $out;
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed>             $row  Values by sub field name.
	 * @param array<int, array<string, mixed>> $subs Sub fields.
	 * @return array<string, mixed> Values by sub field key.
	 */
	private function acf_raw_row( array $row, array $subs ): array {
		$out = array();
		foreach ( $subs as $sub ) {
			if ( array_key_exists( $sub['name'], $row ) ) {
				$out[ $sub['key'] ] = $this->acf_raw( $row[ $sub['name'] ], $sub );
			}
		}
		return $out;
	}

	/**
	 * Field settings plus `value` for one field.
	 *
	 * @param string $selector Field name or key.
	 * @param mixed  $post_id  ACF-style object id.
	 * @param bool   $format   Format the value.
	 * @return array<string, mixed>|null
	 */
	public function get_object( string $selector, $post_id = false, bool $format = true ): ?array {
		$ref   = ObjectRef::parse( $post_id );
		$field = $this->resolve_field( $selector, $ref );
		if ( ! $field ) {
			return null;
		}
		// Like ACF, the settings returned have been through load_field (translated labels, filled choices…).
		$loaded = Hooks::load_field( $field );
		if ( $ref ) {
			$value           = $this->load( $field, $ref );
			$loaded['value'] = $format ? $this->format( $value, $field, $ref ) : $this->acf_raw( $value, $field );
		} else {
			$loaded['value'] = null;
		}
		return $loaded;
	}

	/**
	 * All values stored with a field reference on the object, keyed by name.
	 *
	 * @param mixed $post_id ACF-style object id.
	 * @param bool  $format  Format the values.
	 * @return array<string, mixed>|false False when nothing is stored.
	 */
	public function get_all( $post_id = false, bool $format = true ) {
		$ref = ObjectRef::parse( $post_id );
		if ( ! $ref ) {
			return false;
		}
		$values = array();
		foreach ( $this->store->references( $ref ) as $name => $key ) {
			$field = $this->fields->get_field( $key );
			// Like ACF: only values whose reference points to an existing top-level field of the same
			// name. Others are leftovers of renamed or deleted fields, or sub field rows.
			if ( ! $field || str_starts_with( (string) $field['parent'], 'field_' ) || (string) $field['name'] !== (string) $name ) {
				continue;
			}
			$value           = $this->load( $field, $ref );
			$values[ $name ] = $format ? $this->format( $value, $field, $ref ) : $this->acf_raw( $value, $field );
		}
		return array() === $values ? false : $values;
	}

	/**
	 * Saves a value. Runs update_value but not sanitize/validate: callers pass a trusted value.
	 *
	 * @param string $selector Field name or key.
	 * @param mixed  $value    Value to store.
	 * @param mixed  $post_id  ACF-style object id.
	 */
	public function update( string $selector, $value, $post_id = false ): bool {
		$ref = ObjectRef::parse( $post_id );
		if ( ! $ref ) {
			return false;
		}
		$field = $this->resolve_field( $selector, $ref );
		if ( ! $field ) {
			if ( str_starts_with( $selector, 'field_' ) ) {
				return false;
			}
			return $this->store->update( $ref, $selector, $value, null );
		}
		return $this->save( $field, $value, $ref );
	}

	/**
	 * Writes a value for a known field.
	 *
	 * Compound values: a group takes [sub key or name => value]; repeater and flexible content
	 * take a list of such rows (flexible rows also carry `acf_fc_layout`).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param ObjectRef            $ref   Object.
	 */
	public function save( array $field, $value, ObjectRef $ref ): bool {
		if ( 0 === $this->depth ) {
			$this->store->begin_batch();
		}
		++$this->depth;
		try {
			return $this->save_value( $field, $value, $ref );
		} finally {
			--$this->depth;
		}
	}

	/**
	 * save() without batch handling.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param ObjectRef            $ref   Object.
	 */
	private function save_value( array $field, $value, ObjectRef $ref ): bool {
		$handler = $this->types->get( $field['type'] );
		$id      = $ref->to_string();
		if ( $handler && ! $handler->has_value() ) {
			return false;
		}
		$kind = $handler ? $handler->compound() : null;
		if ( 'group' === $kind ) {
			$this->save_subs( $field, $field['sub_fields'], is_array( $value ) ? $value : array(), $ref );
			$value = '';
		} elseif ( 'rows' === $kind || 'layouts' === $kind ) {
			$value = $this->save_rows( $field, $kind, $value, $ref );
		}
		if ( $handler ) {
			$value = $handler->update_value( $value, $id, $field );
		}
		$value = self::apply_field_filters( 'wpff/update_value', $value, $field, $id );

		/**
		 * Return false to skip saving this value.
		 *
		 * @param bool  $save  Whether to save.
		 * @param mixed $value Value.
		 * @param array $field Field.
		 * @param string $id   Object id.
		 */
		if ( ! Hooks::filter( 'pre_update_value', true, $value, $field, $id ) ) {
			return false;
		}
		return $this->store->update( $ref, $field['name'], $value, $field['key'] );
	}

	/**
	 * Saves sub values found in `$values` (by sub key, else by sub name) under `$parent`'s name.
	 *
	 * @param array<string, mixed>             $parent Field whose name prefixes the subs.
	 * @param array<int, array<string, mixed>> $subs   Sub fields.
	 * @param array<string, mixed>             $values Values.
	 * @param ObjectRef                        $ref    Object.
	 */
	private function save_subs( array $parent, array $subs, array $values, ObjectRef $ref ): void {
		foreach ( $subs as $sub ) {
			if ( array_key_exists( $sub['key'], $values ) ) {
				$sub_value = $values[ $sub['key'] ];
			} elseif ( '' !== $sub['name'] && array_key_exists( $sub['name'], $values ) ) {
				$sub_value = $values[ $sub['name'] ];
			} else {
				continue;
			}
			$this->save( self::child( $parent, $sub ), $sub_value, $ref );
		}
	}

	/**
	 * Saves repeater / flexible rows and removes rows that no longer exist.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $kind  `rows` or `layouts`.
	 * @param mixed                $value List of rows.
	 * @param ObjectRef            $ref   Object.
	 * @return int|string[] Value stored under the field name: row count, or layout names.
	 */
	private function save_rows( array $field, string $kind, $value, ObjectRef $ref ) {
		$old_layouts = $this->stored_layouts( $field, $kind, $ref );

		$rows    = array();
		$layouts = array();
		foreach ( is_array( $value ) ? $value : array() as $row_key => $row ) {
			if ( ! is_array( $row ) || str_starts_with( (string) $row_key, '__' ) ) {
				continue;
			}
			if ( 'layouts' === $kind ) {
				$layout = self::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
				if ( ! $layout ) {
					continue;
				}
				$layouts[] = $layout['name'];
			}
			$rows[] = $row;
		}

		foreach ( $rows as $i => $row ) {
			$subs = 'layouts' === $kind ? self::find_layout( $field, $layouts[ $i ] )['sub_fields'] : $field['sub_fields'];
			// A row that switched layout leaves the old layout's values behind; remove them.
			if ( 'layouts' === $kind && isset( $old_layouts[ $i ] ) && $old_layouts[ $i ] !== $layouts[ $i ] ) {
				$this->delete_row( $field, $kind, $i, $old_layouts[ $i ], $ref );
			}
			$this->save_subs( self::row_parent( $field, $i ), $subs, $row, $ref );
		}
		for ( $i = count( $rows ), $old = count( $old_layouts ); $i < $old; $i++ ) {
			$this->delete_row( $field, $kind, $i, $old_layouts[ $i ], $ref );
		}

		// No rows where ACF stored an empty string: keep it, nothing changed.
		if ( array() === $rows && '' === $this->store->get( $ref, $field['name'] ) ) {
			return '';
		}
		return 'layouts' === $kind ? $layouts : count( $rows );
	}

	/**
	 * What is stored for existing rows: one entry per row (layout name, or '' for repeaters).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $kind  `rows` or `layouts`.
	 * @param ObjectRef            $ref   Object.
	 * @return string[]
	 */
	private function stored_layouts( array $field, string $kind, ObjectRef $ref ): array {
		$stored = $this->store->get( $ref, $field['name'] );
		if ( 'layouts' === $kind ) {
			return array_values( array_map( 'strval', is_array( $stored ) ? $stored : array() ) );
		}
		return array_fill( 0, max( 0, (int) $stored ), '' );
	}

	/**
	 * Deletes the stored values of one row.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param string               $kind   `rows` or `layouts`.
	 * @param int                  $i      Row index.
	 * @param string               $layout Layout name of the row (flexible content).
	 * @param ObjectRef            $ref    Object.
	 */
	private function delete_row( array $field, string $kind, int $i, string $layout, ObjectRef $ref ): void {
		$subs = $field['sub_fields'] ?? array();
		if ( 'layouts' === $kind ) {
			$found = self::find_layout( $field, $layout );
			$subs  = $found ? $found['sub_fields'] : array();
		}
		$parent = self::row_parent( $field, $i );
		foreach ( $subs as $sub ) {
			$child = self::child( $parent, $sub );
			$this->delete_children( $child, $ref );
			$this->store->delete( $ref, $child['name'] );
		}
	}

	/**
	 * Layout of a flexible content field by name.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $name  Layout name.
	 * @return array<string, mixed>|null
	 */
	public static function find_layout( array $field, string $name ): ?array {
		foreach ( $field['layouts'] ?? array() as $layout ) {
			if ( $layout['name'] === $name ) {
				return $layout;
			}
		}
		return null;
	}

	/**
	 * The field renamed to `{name}_{i}`, used as the parent of row `$i`'s sub fields.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param int                  $i     Row index.
	 * @return array<string, mixed>
	 */
	public static function row_parent( array $field, int $i ): array {
		$field['name'] .= '_' . $i;
		return $field;
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $selector Field name or key.
	 * @param mixed  $post_id  ACF-style object id.
	 */
	public function delete( string $selector, $post_id = false ): bool {
		$ref = ObjectRef::parse( $post_id );
		if ( ! $ref ) {
			return false;
		}
		$field = $this->resolve_field( $selector, $ref );
		if ( ! $field ) {
			return $this->store->delete( $ref, $selector );
		}
		$this->delete_children( $field, $ref );
		return $this->store->delete( $ref, $field['name'] );
	}

	/**
	 * Deletes stored sub values of a compound field (any depth).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param ObjectRef            $ref   Object.
	 */
	private function delete_children( array $field, ObjectRef $ref ): void {
		$handler = $this->types->get( $field['type'] );
		$kind    = $handler ? $handler->compound() : null;
		if ( 'rows' === $kind || 'layouts' === $kind ) {
			foreach ( $this->stored_layouts( $field, $kind, $ref ) as $i => $layout ) {
				$this->delete_row( $field, $kind, $i, $layout, $ref );
			}
			return;
		}
		if ( 'group' === $kind ) {
			foreach ( $field['sub_fields'] as $sub ) {
				$child = self::child( $field, $sub );
				$this->delete_children( $child, $ref );
				$this->store->delete( $ref, $child['name'] );
			}
		}
	}

	/**
	 * A sub field with its full storage name, `{parent}_{sub}`.
	 *
	 * @param array<string, mixed> $parent Parent field (with its own full name).
	 * @param array<string, mixed> $sub    Sub field.
	 * @return array<string, mixed>
	 */
	public static function child( array $parent, array $sub ): array {
		$sub['name'] = $parent['name'] . '_' . $sub['name'];
		return $sub;
	}

	/**
	 * Reads and loads a value (default value applied when nothing is stored).
	 *
	 * Groups load as [sub name => value], repeaters as a list of such rows, flexible content
	 * as a list of rows that also carry `acf_fc_layout`.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param ObjectRef            $ref   Object.
	 * @return mixed
	 */
	public function load( array $field, ObjectRef $ref ) {
		$handler = $this->types->get( $field['type'] );
		$id      = $ref->to_string();
		if ( $handler && ! $handler->has_value() ) {
			return null;
		}
		$kind = $handler ? $handler->compound() : null;
		if ( 'group' === $kind ) {
			$value = $this->load_subs( $field, $field['sub_fields'], $ref );
			return self::apply_field_filters( 'wpff/load_value', $value, $field, $id );
		}
		if ( 'rows' === $kind || 'layouts' === $kind ) {
			$value = array();
			foreach ( $this->stored_layouts( $field, $kind, $ref ) as $i => $layout_name ) {
				$parent = self::row_parent( $field, $i );
				if ( 'layouts' === $kind ) {
					$layout = self::find_layout( $field, $layout_name );
					if ( ! $layout ) {
						continue;
					}
					$value[] = array( 'acf_fc_layout' => $layout_name ) + $this->load_subs( $parent, $layout['sub_fields'], $ref );
				} else {
					$value[] = $this->load_subs( $parent, $field['sub_fields'], $ref );
				}
			}
			return self::apply_field_filters( 'wpff/load_value', $value, $field, $id );
		}

		$value = $this->store->get( $ref, $field['name'] );
		if ( null === $value && '' !== $field['default_value'] && null !== $field['default_value'] ) {
			$value = $field['default_value'];
		}
		if ( $handler ) {
			$value = $handler->load_value( $value, $id, $field );
		}
		return self::apply_field_filters( 'wpff/load_value', $value, $field, $id );
	}

	/**
	 * Loads sub values stored under `$parent`'s name, keyed by sub name. Layout fields are skipped.
	 *
	 * @param array<string, mixed>             $parent Parent (full name).
	 * @param array<int, array<string, mixed>> $subs   Sub fields.
	 * @param ObjectRef                        $ref    Object.
	 * @return array<string, mixed>
	 */
	private function load_subs( array $parent, array $subs, ObjectRef $ref ): array {
		$values = array();
		foreach ( $subs as $sub ) {
			$handler = $this->types->get( $sub['type'] );
			if ( $handler && ! $handler->has_value() ) {
				continue;
			}
			$values[ $sub['name'] ] = $this->load( self::child( $parent, $sub ), $ref );
		}
		return $values;
	}

	/**
	 * Formats a loaded value for templates.
	 *
	 * @param mixed                $value Loaded value.
	 * @param array<string, mixed> $field Field.
	 * @param ObjectRef            $ref   Object.
	 * @return mixed
	 */
	public function format( $value, array $field, ObjectRef $ref ) {
		$field   = Hooks::load_field( $field );
		$id      = $ref->to_string();
		$handler = $this->types->get( $field['type'] );
		$kind    = $handler ? $handler->compound() : null;
		if ( 'group' === $kind && is_array( $value ) ) {
			$value = $this->format_subs( $value, $field, $field['sub_fields'], $ref );
		} elseif ( ( 'rows' === $kind || 'layouts' === $kind ) && is_array( $value ) ) {
			foreach ( array_values( $value ) as $i => $row ) {
				$subs = $field['sub_fields'] ?? array();
				if ( 'layouts' === $kind ) {
					$layout = self::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
					$subs   = $layout ? $layout['sub_fields'] : array();
				}
				$value[ $i ] = $this->format_subs( $row, self::row_parent( $field, $i ), $subs, $ref );
			}
		}
		if ( null === $kind ) {
			// Picked posts, terms and media show their translation in the current language.
			$value = \FreeformFields\Multilingual\Multilingual::localize( $value, $field );
		}
		if ( $handler ) {
			$value = $handler->format_value( $value, $id, $field );
		}
		return self::apply_field_filters( 'wpff/format_value', $value, $field, $id );
	}

	/**
	 * Formats sub values of one group / row.
	 *
	 * @param array<string, mixed>             $values Values by sub name.
	 * @param array<string, mixed>             $parent Parent (full name).
	 * @param array<int, array<string, mixed>> $subs   Sub fields.
	 * @param ObjectRef                        $ref    Object.
	 * @return array<string, mixed>
	 */
	private function format_subs( array $values, array $parent, array $subs, ObjectRef $ref ): array {
		foreach ( $subs as $sub ) {
			if ( array_key_exists( $sub['name'], $values ) ) {
				$values[ $sub['name'] ] = $this->format( $values[ $sub['name'] ], self::child( $parent, $sub ), $ref );
			}
		}
		return $values;
	}

	/**
	 * Applies `{hook}`, then `{hook}/type=`, `{hook}/name=` and `{hook}/key=` variants.
	 *
	 * @param string               $hook  Base hook name.
	 * @param mixed                $value Value to filter.
	 * @param array<string, mixed> $field Field.
	 * @param string               $id    Object id.
	 * @return mixed
	 */
	public static function apply_field_filters( string $hook, $value, array $field, string $id ) {
		// `wpff/load_value` → suffix `load_value`; the ACF-named twin runs in compatibility mode.
		return Hooks::field_filter( substr( $hook, strlen( 'wpff/' ) ), $value, $field, $id, $field );
	}
}
