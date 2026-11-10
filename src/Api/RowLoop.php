<?php
/**
 * State for have_rows() / the_row() loops.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Api;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Storage\ValueManager;

defined( 'ABSPATH' ) || exit;

/**
 * A stack of row loops, so loops can be nested like in ACF:
 *
 *     while ( have_rows( 'sections' ) ) { the_row();
 *         if ( 'gallery' === get_row_layout() ) {
 *             while ( have_rows( 'slides' ) ) { the_row(); echo get_sub_field( 'caption' ); }
 *         }
 *     }
 *
 * Inside a row, have_rows() with a sub field name loops over that sub field of the current row.
 * Repeater and flexible content give their rows; a group gives one row.
 */
final class RowLoop {

	/**
	 * Active loops, innermost last.
	 *
	 * Each: selector, post_id, parent (index of parent loop or -1), parent_row, field (with full
	 * storage name), kind, rows, i (current row index, -1 before the first the_row()), ref.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $stack = array();

	/**
	 * Whether there is another row; ends (and forgets) the loop when there is none.
	 *
	 * @param string $selector Field name or key (a sub field name inside a row).
	 * @param mixed  $post_id  Object id.
	 */
	public static function have_rows( string $selector, $post_id = false ): bool {
		$index = self::find_or_start( $selector, $post_id );
		if ( null === $index ) {
			return false;
		}
		$loop = self::$stack[ $index ];
		if ( $loop['i'] + 1 < count( $loop['rows'] ) ) {
			return true;
		}
		array_splice( self::$stack, $index );
		return false;
	}

	/**
	 * Moves to the next row and returns it (unformatted values by sub name).
	 *
	 * @return array<string, mixed>|false
	 */
	public static function the_row() {
		$top = array_key_last( self::$stack );
		if ( null === $top ) {
			return false;
		}
		++self::$stack[ $top ]['i'];
		return self::current_row();
	}

	/**
	 * Value of a sub field in the current row.
	 *
	 * @param string $name   Sub field name or key.
	 * @param bool   $format Format the value.
	 * @return mixed Null when not in a row or the sub field does not exist.
	 */
	public static function get_sub_field( string $name, bool $format = true ) {
		$object = self::get_sub_field_object( $name, $format );
		return $object ? $object['value'] : null;
	}

	/**
	 * Sub field settings plus `value`.
	 *
	 * @param string $name   Sub field name or key.
	 * @param bool   $format Format the value.
	 * @return array<string, mixed>|false
	 */
	public static function get_sub_field_object( string $name, bool $format = true ) {
		$loop = self::top();
		$row  = self::current_row();
		if ( ! $loop || ! is_array( $row ) ) {
			return false;
		}
		foreach ( self::row_subs( $loop, $row ) as $sub ) {
			if ( $sub['name'] !== $name && $sub['key'] !== $name ) {
				continue;
			}
			$child = ValueManager::child( self::row_parent( $loop ), $sub );
			$value = $row[ $sub['name'] ] ?? null;
			if ( $format ) {
				$value = Plugin::instance()->values()->format( $value, $child, $loop['ref'] );
			}
			$sub['value'] = $value;
			return $sub;
		}
		return false;
	}

	/**
	 * Saves a sub field of the current row (update_sub_field() inside a loop).
	 *
	 * @param string $name  Sub field name or key.
	 * @param mixed  $value Value; null deletes it.
	 */
	public static function update_sub_field( string $name, $value ): bool {
		$top = array_key_last( self::$stack );
		$row = self::current_row();
		if ( null === $top || ! is_array( $row ) ) {
			return false;
		}
		$loop = self::$stack[ $top ];
		foreach ( self::row_subs( $loop, $row ) as $sub ) {
			if ( $sub['name'] !== $name && $sub['key'] !== $name ) {
				continue;
			}
			$child  = ValueManager::child( self::row_parent( $loop ), $sub );
			$values = Plugin::instance()->values();
			if ( null === $value ) {
				$values->store()->delete( $loop['ref'], $child['name'] );
				unset( self::$stack[ $top ]['rows'][ $loop['i'] ][ $sub['name'] ] );
				return true;
			}
			$saved = $values->save( $child, $value, $loop['ref'] );
			self::$stack[ $top ]['rows'][ $loop['i'] ][ $sub['name'] ] = $value;
			return $saved;
		}
		return false;
	}

	/**
	 * Current row with formatted (or raw) values.
	 *
	 * @param bool $format Format values.
	 * @return array<string, mixed>|false
	 */
	public static function get_row( bool $format = false ) {
		$loop = self::top();
		$row  = self::current_row();
		if ( ! $loop || ! is_array( $row ) || ! $format ) {
			return $row ?? false;
		}
		foreach ( self::row_subs( $loop, $row ) as $sub ) {
			if ( array_key_exists( $sub['name'], $row ) ) {
				$row[ $sub['name'] ] = self::get_sub_field( $sub['name'] );
			}
		}
		return $row;
	}

	/**
	 * Layout name of the current flexible content row.
	 *
	 * @return string|false
	 */
	public static function get_row_layout() {
		$row = self::current_row();
		return is_array( $row ) && isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : false;
	}

	/**
	 * 1-based index of the current row, or 0 outside a loop.
	 */
	public static function get_row_index(): int {
		$loop = self::top();
		return $loop ? (int) $loop['i'] + 1 : 0;
	}

	/**
	 * Forgets the innermost loop (to break out of it and loop again later).
	 */
	public static function reset_rows(): void {
		array_pop( self::$stack );
	}

	/**
	 * Forgets all loops. For tests.
	 *
	 * @internal
	 */
	public static function reset_all(): void {
		self::$stack = array();
	}

	/**
	 * Index of the loop for this call, starting a new one when needed. Null when there is nothing to loop.
	 *
	 * @param string $selector Selector.
	 * @param mixed  $post_id  Object id.
	 */
	private static function find_or_start( string $selector, $post_id ): ?int {
		$top = array_key_last( self::$stack );

		// The innermost loop is this one: continue it.
		if ( null !== $top ) {
			$loop = self::$stack[ $top ];
			if ( $loop['selector'] === $selector ) {
				$same_parent = -1 === $loop['parent']
					? self::same_object( $loop['post_id'], $post_id )
					: ( isset( self::$stack[ $loop['parent'] ] ) && self::$stack[ $loop['parent'] ]['i'] === $loop['parent_row'] );
				if ( $same_parent ) {
					return $top;
				}
			}
		}

		// A sub field of the current row: start a nested loop.
		if ( null !== $top && false === $post_id && self::$stack[ $top ]['i'] >= 0 ) {
			$parent = self::$stack[ $top ];
			$row    = self::current_row();
			foreach ( is_array( $row ) ? self::row_subs( $parent, $row ) : array() as $sub ) {
				if ( $sub['name'] !== $selector && $sub['key'] !== $selector ) {
					continue;
				}
				$field = ValueManager::child( self::row_parent( $parent ), $sub );
				return self::push( $selector, $post_id, $top, $parent['i'], $field, $row[ $sub['name'] ] ?? null, $parent['ref'] );
			}
		}

		// A top-level field.
		$ref = ObjectRef::parse( $post_id );
		if ( ! $ref ) {
			return null;
		}
		$values = Plugin::instance()->values();
		$field  = $values->resolve_field( $selector, $ref );
		if ( ! $field ) {
			return null;
		}
		return self::push( $selector, $post_id, -1, -1, $field, $values->load( $field, $ref ), $ref );
	}

	/**
	 * @param string               $selector   Selector.
	 * @param mixed                $post_id    Object id.
	 * @param int                  $parent     Parent loop index or -1.
	 * @param int                  $parent_row Parent row index.
	 * @param array<string, mixed> $field      Field with full storage name.
	 * @param mixed                $value      Loaded value.
	 * @param ObjectRef            $ref        Object.
	 */
	private static function push( string $selector, $post_id, int $parent, int $parent_row, array $field, $value, ObjectRef $ref ): ?int {
		$kind = Plugin::instance()->values()->kind_of( $field );
		if ( 'group' === $kind ) {
			$rows = is_array( $value ) ? array( $value ) : array();
		} elseif ( 'rows' === $kind || 'layouts' === $kind ) {
			$rows = is_array( $value ) ? array_values( $value ) : array();
		} else {
			return null;
		}
		self::$stack[] = array(
			'selector'   => $selector,
			'post_id'    => $post_id,
			'parent'     => $parent,
			'parent_row' => $parent_row,
			'field'      => $field,
			'kind'       => $kind,
			'rows'       => $rows,
			'i'          => -1,
			'ref'        => $ref,
		);
		return (int) array_key_last( self::$stack );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function top(): ?array {
		$top = array_key_last( self::$stack );
		return null === $top ? null : self::$stack[ $top ];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function current_row(): ?array {
		$loop = self::top();
		if ( ! $loop || $loop['i'] < 0 ) {
			return null;
		}
		$row = $loop['rows'][ $loop['i'] ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Sub fields that apply to a row (the row's layout for flexible content).
	 *
	 * @param array<string, mixed> $loop Loop.
	 * @param array<string, mixed> $row  Row.
	 * @return array<int, array<string, mixed>>
	 */
	private static function row_subs( array $loop, array $row ): array {
		if ( 'layouts' === $loop['kind'] ) {
			$layout = ValueManager::find_layout( $loop['field'], (string) ( $row['acf_fc_layout'] ?? '' ) );
			return $layout ? $layout['sub_fields'] : array();
		}
		return $loop['field']['sub_fields'] ?? array();
	}

	/**
	 * Field whose name prefixes the current row's sub fields: `{name}_{i}`, or `{name}` for a group.
	 *
	 * @param array<string, mixed> $loop Loop.
	 * @return array<string, mixed>
	 */
	private static function row_parent( array $loop ): array {
		return 'group' === $loop['kind'] ? $loop['field'] : ValueManager::row_parent( $loop['field'], (int) $loop['i'] );
	}

	/**
	 * Whether two post_id arguments mean the same object.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 */
	private static function same_object( $a, $b ): bool {
		$ra = ObjectRef::parse( $a );
		$rb = ObjectRef::parse( $b );
		return $ra && $rb && $ra->type === $rb->type && (string) $ra->id === (string) $rb->id;
	}
}
