<?php
/**
 * Low-level value storage.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes raw values in the same layout ACF uses, so data is interchangeable:
 *
 *  - objects with meta (post, term, user, comment): meta `{name}` = value, meta `_{name}` = field key;
 *  - options: option `{prefix}_{name}` = value, option `_{prefix}_{name}` = field key.
 */
final class MetaStore {

	/**
	 * Current meta values of objects written this request, by "type:id" then name
	 * (unserialized; `null` marks a key with several rows). WordPress clears its meta cache after
	 * every write, so without this each write would reload all meta of the object.
	 * Forgotten as soon as anyone else changes the object's meta.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $known = array();

	/**
	 * The same objects' meta as WordPress caches it (name => list of stored strings), put back
	 * into the meta cache after each write so the next read needs no query.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private array $raw = array();

	/**
	 * Objects whose values are remembered at most; an import touching many objects starts over.
	 */
	private const KNOWN_OBJECTS = 50;

	/**
	 * True while this class writes meta itself, so its own writes do not clear $known.
	 *
	 * @var bool
	 */
	private bool $writing = false;

	public function __construct() {
		foreach ( array( 'post', 'term', 'user', 'comment' ) as $type ) {
			$forget = function ( $meta_ids, $object_id ) use ( $type ): void {
				if ( ! $this->writing ) {
					unset( $this->known[ $type . ':' . (int) $object_id ], $this->raw[ $type . ':' . (int) $object_id ] );
				}
			};
			foreach ( array( 'added', 'updated', 'deleted' ) as $action ) {
				add_action( "{$action}_{$type}_meta", $forget, 10, 2 );
			}
		}
	}

	/**
	 * Starts a new save. Remembered values stay valid: other writes to the meta clear them.
	 */
	public function begin_batch(): void {
		if ( count( $this->known ) > self::KNOWN_OBJECTS ) {
			$this->known = array();
			$this->raw   = array();
		}
	}

	/**
	 * In-memory data of blocks being rendered or edited, keyed by block id (`block_…`).
	 * Same flat layout as meta: `{name}` => value, `_{name}` => field key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $blocks = array();

	/**
	 * Option prefixes whose values are already in the cache this request.
	 *
	 * @var array<string, true>
	 */
	private static array $primed = array();

	/**
	 * Sets the data of a block.
	 *
	 * @param string               $id   Block id.
	 * @param array<string, mixed> $data Flat block data.
	 */
	public static function set_block_data( string $id, array $data ): void {
		self::$blocks[ $id ] = $data;
	}

	/**
	 * Current data of a block.
	 *
	 * @param string $id Block id.
	 * @return array<string, mixed>
	 */
	public static function block_data( string $id ): array {
		return self::$blocks[ $id ] ?? array();
	}

	/**
	 * Forgets a block's data.
	 *
	 * @param string $id Block id.
	 */
	public static function forget_block( string $id ): void {
		unset( self::$blocks[ $id ] );
	}

	/**
	 * Raw stored value, or null when nothing is stored.
	 *
	 * @param ObjectRef $ref  Target object.
	 * @param string    $name Field name.
	 * @return mixed
	 */
	public function get( ObjectRef $ref, string $name ) {
		if ( 'block' === $ref->type ) {
			return self::$blocks[ $ref->id ][ $name ] ?? null;
		}
		if ( 'option' === $ref->type ) {
			self::prime_options( (string) $ref->id );
			$value = get_option( $this->option_name( $ref, $name ), null );
			if ( null === $value && $ref->fallback ) {
				return $this->get( $ref->fallback, $name );
			}
			return $value;
		}
		if ( ! metadata_exists( $ref->type, (int) $ref->id, $name ) ) {
			return null;
		}
		return get_metadata( $ref->type, (int) $ref->id, $name, true );
	}

	/**
	 * Field key stored next to the value, or null.
	 *
	 * @param ObjectRef $ref  Target object.
	 * @param string    $name Field name.
	 */
	public function get_reference( ObjectRef $ref, string $name ): ?string {
		$key = $this->get( $ref, '_' . $name );
		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * Stores a value and, when given, its field key reference.
	 *
	 * @param ObjectRef   $ref   Target object.
	 * @param string      $name  Field name.
	 * @param mixed       $value Value.
	 * @param string|null $key   Field key.
	 */
	public function update( ObjectRef $ref, string $name, $value, ?string $key ): bool {
		$ok = $this->write( $ref, $name, $value );
		if ( null !== $key ) {
			$this->write( $ref, '_' . $name, $key );
		}
		return $ok;
	}

	/**
	 * Deletes a value and its reference.
	 *
	 * @param ObjectRef $ref  Target object.
	 * @param string    $name Field name.
	 */
	public function delete( ObjectRef $ref, string $name ): bool {
		if ( 'block' === $ref->type ) {
			unset( self::$blocks[ $ref->id ][ $name ], self::$blocks[ $ref->id ][ '_' . $name ] );
			return true;
		}
		if ( 'option' === $ref->type ) {
			delete_option( $this->option_name( $ref, '_' . $name ) );
			return delete_option( $this->option_name( $ref, $name ) );
		}
		return $this->delete_many( $ref, array( $name ) );
	}

	/**
	 * Deletes several values and their references. On objects with meta, all rows go in one
	 * DELETE instead of two queries per row; WordPress's delete meta hooks still run per key.
	 *
	 * @param ObjectRef $ref   Target object.
	 * @param string[]  $names Field names.
	 * @return bool Whether anything was deleted.
	 */
	public function delete_many( ObjectRef $ref, array $names ): bool {
		if ( array() === $names ) {
			return false;
		}
		if ( 'block' === $ref->type || 'option' === $ref->type ) {
			$ok = false;
			foreach ( $names as $name ) {
				if ( 'block' === $ref->type ) {
					$ok = $ok || array_key_exists( $name, self::$blocks[ $ref->id ] ?? array() );
					unset( self::$blocks[ $ref->id ][ $name ], self::$blocks[ $ref->id ][ '_' . $name ] );
					continue;
				}
				delete_option( $this->option_name( $ref, '_' . $name ) );
				$ok = delete_option( $this->option_name( $ref, $name ) ) || $ok;
			}
			return $ok;
		}

		global $wpdb;
		$type  = $ref->type;
		$id    = (int) $ref->id;
		$table = _get_meta_table( $type );
		if ( ! $table ) {
			return false;
		}
		$column    = sanitize_key( $type . '_id' );
		$id_column = 'user' === $type ? 'umeta_id' : 'meta_id';
		$keys      = array();
		foreach ( $names as $name ) {
			$keys[] = $name;
			$keys[] = '_' . $name;
		}

		$known = &$this->known_values( $type, $id );
		// Only names actually stored: removing rows of a long repeater usually hits all of them.
		$keys = array_values( array_filter( array_unique( $keys ), static fn( $k ) => array_key_exists( $k, $known ) ) );
		if ( array() === $keys ) {
			return false;
		}

		/** This filter is documented in wp-includes/meta.php */
		foreach ( $keys as $i => $meta_key ) {
			if ( null !== apply_filters( "delete_{$type}_metadata", null, $id, $meta_key, '', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				// Another plugin handles this key itself: let delete_metadata() run its filter path.
				unset( $keys[ $i ] );
				$this->writing = true;
				try {
					delete_metadata( $type, $id, $meta_key );
				} finally {
					$this->writing = false;
				}
				unset( $known[ $meta_key ] );
			}
		}
		$keys = array_values( $keys );
		if ( array() === $keys ) {
			return true;
		}

		$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_column} AS meta_id, meta_key, meta_value FROM {$table} WHERE {$column} = %d AND meta_key IN ({$in})", $id, ...$keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table and columns come from _get_meta_table(); $in is a list of placeholders.
		if ( ! $rows ) {
			foreach ( $keys as $meta_key ) {
				unset( $known[ $meta_key ] );
			}
			return false;
		}
		$by_key = array();
		foreach ( $rows as $row ) {
			$by_key[ $row->meta_key ][] = $row;
		}

		$this->writing = true;
		try {
			foreach ( $by_key as $meta_key => $key_rows ) {
				/** This action is documented in wp-includes/meta.php */
				do_action( "delete_{$type}_meta", wp_list_pluck( $key_rows, 'meta_id' ), $id, $meta_key, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				if ( 'post' === $type ) {
					do_action( 'delete_postmeta', wp_list_pluck( $key_rows, 'meta_id' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				}
			}
			$ids     = array_map( 'intval', wp_list_pluck( $rows, 'meta_id' ) );
			$ids_in  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$id_column} IN ({$ids_in})", ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- see above.
			foreach ( $keys as $meta_key ) {
				unset( $this->raw[ $type . ':' . $id ][ $meta_key ] );
			}
			$this->put_back_cache( $type, $id );
			foreach ( $by_key as $meta_key => $key_rows ) {
				/** This action is documented in wp-includes/meta.php */
				do_action( "deleted_{$type}_meta", wp_list_pluck( $key_rows, 'meta_id' ), $id, $meta_key, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				if ( 'post' === $type ) {
					do_action( 'deleted_postmeta', wp_list_pluck( $key_rows, 'meta_id' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				}
			}
		} finally {
			$this->writing = false;
		}
		foreach ( $keys as $meta_key ) {
			unset( $known[ $meta_key ] );
		}
		return (bool) $deleted;
	}

	/**
	 * Map of field name => field key for every referenced value on the object.
	 *
	 * @param ObjectRef $ref Target object.
	 * @return array<string, string>
	 */
	public function references( ObjectRef $ref ): array {
		$refs = array();
		if ( 'block' === $ref->type ) {
			$data = self::$blocks[ $ref->id ] ?? array();
			foreach ( $data as $key => $value ) {
				$key = (string) $key;
				if ( '_' === ( $key[0] ?? '' ) && is_string( $value ) && str_starts_with( $value, 'field_' ) && array_key_exists( substr( $key, 1 ), $data ) ) {
					$refs[ substr( $key, 1 ) ] = $value;
				}
			}
			return $refs;
		}
		if ( 'option' === $ref->type ) {
			global $wpdb;
			$prefix = '_' . $ref->id . '_';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no API lists options by prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( $rows as $row ) {
				if ( str_starts_with( (string) $row->option_value, 'field_' ) ) {
					$refs[ substr( $row->option_name, strlen( $prefix ) ) ] = $row->option_value;
				}
			}
			// Values this language has not set yet come from the fallback (default language).
			return $ref->fallback ? $refs + $this->references( $ref->fallback ) : $refs;
		}

		$all = get_metadata( $ref->type, (int) $ref->id );
		foreach ( (array) $all as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			$value    = $values[0] ?? '';
			if ( '_' === ( $meta_key[0] ?? '' ) && is_string( $value ) && str_starts_with( $value, 'field_' ) && isset( $all[ substr( $meta_key, 1 ) ] ) ) {
				$refs[ substr( $meta_key, 1 ) ] = $value;
			}
		}
		return $refs;
	}

	/**
	 * Writes one raw value.
	 *
	 * @param ObjectRef $ref   Target object.
	 * @param string    $name  Storage name.
	 * @param mixed     $value Value.
	 */
	private function write( ObjectRef $ref, string $name, $value ): bool {
		if ( 'block' === $ref->type ) {
			self::$blocks[ $ref->id ][ $name ] = $value;
			return true;
		}
		if ( 'option' === $ref->type ) {
			$option = $this->option_name( $ref, $name );
			/**
			 * Whether option values of this prefix are autoloaded. Options pages set this from their `autoload` setting.
			 *
			 * @param bool   $autoload Default false.
			 * @param string $prefix   Options prefix, e.g. `options`.
			 */
			$autoload = (bool) apply_filters( 'wpff/options_autoload', false, (string) $ref->id );
			return update_option( $option, $value, $autoload ) || get_option( $option ) === $value;
		}
		$type  = $ref->type;
		$id    = (int) $ref->id;
		$known = &$this->known_values( $type, $id );

		// Unchanged values cost no query and new values one INSERT; update_metadata() alone runs
		// a SELECT on every call, which adds up quickly for repeaters with many rows.
		if ( array_key_exists( $name, $known ) && null !== $known[ $name ] && self::same( $known[ $name ][0], $value ) ) {
			return true;
		}
		// update_metadata() / add_metadata() strip slashes, so add them back to store the value as given.
		$this->writing = true;
		try {
			if ( ! array_key_exists( $name, $known ) ) {
				$ok = false !== add_metadata( $type, $id, $name, wp_slash( $value ) );
			} else {
				$ok = false !== update_metadata( $type, $id, $name, wp_slash( $value ) );
			}
		} finally {
			$this->writing = false;
		}
		if ( $ok && $this->plain_meta( $type, $id, $name ) ) {
			$stored = maybe_serialize( $value );
			$stored = is_string( $stored ) ? $stored : (string) $stored;
			$count  = count( $this->raw[ $type . ':' . $id ][ $name ] ?? array() );
			// update_metadata() without a previous value sets every row of the key.
			$this->raw[ $type . ':' . $id ][ $name ] = array_fill( 0, max( 1, $count ), $stored );
			$this->put_back_cache( $type, $id );
		} else {
			unset( $this->raw[ $type . ':' . $id ] );
		}
		$known[ $name ] = array( $value );
		return $ok;
	}

	/**
	 * Whether WordPress stores this key's value unchanged (no meta sanitizing or short-circuit
	 * filters), so the meta cache can be updated without reading it back.
	 *
	 * @param string $type Meta type.
	 * @param int    $id   Object ID.
	 * @param string $name Meta key.
	 */
	private function plain_meta( string $type, int $id, string $name ): bool {
		if ( has_filter( "add_{$type}_metadata" ) || has_filter( "update_{$type}_metadata" ) || has_filter( "sanitize_{$type}_meta_{$name}" ) ) {
			return false;
		}
		$subtype = get_object_subtype( $type, $id );
		return '' === $subtype || ! has_filter( "sanitize_{$type}_meta_{$name}_for_{$subtype}" );
	}

	/**
	 * Puts an object's meta back into WordPress's cache after this class changed it.
	 *
	 * @param string $type Meta type.
	 * @param int    $id   Object ID.
	 */
	private function put_back_cache( string $type, int $id ): void {
		if ( isset( $this->raw[ $type . ':' . $id ] ) ) {
			wp_cache_set( $id, $this->raw[ $type . ':' . $id ], $type . '_meta' );
		} else {
			wp_cache_delete( $id, $type . '_meta' );
		}
	}

	/**
	 * Current values of an object's meta, loaded once per save.
	 *
	 * @param string $type Meta type.
	 * @param int    $id   Object ID.
	 * @return array<string, array{0: mixed}|null> Name => [ value ], or null for multi-row keys.
	 */
	private function &known_values( string $type, int $id ): array {
		$key = $type . ':' . $id;
		if ( ! isset( $this->known[ $key ] ) ) {
			$cache = wp_cache_get( $id, $type . '_meta' );
			if ( false === $cache ) {
				update_meta_cache( $type, array( $id ) );
				$cache = wp_cache_get( $id, $type . '_meta' );
			}
			$this->known[ $key ] = array();
			$this->raw[ $key ]   = is_array( $cache ) ? $cache : array();
			foreach ( is_array( $cache ) ? $cache : array() as $name => $rows ) {
				$this->known[ $key ][ (string) $name ] = 1 === count( $rows ) ? array( maybe_unserialize( $rows[0] ) ) : null;
			}
		}
		return $this->known[ $key ];
	}

	/**
	 * Whether a stored value equals a new one as WordPress would store it.
	 *
	 * @param mixed $stored Unserialized stored value.
	 * @param mixed $value  New value.
	 */
	private static function same( $stored, $value ): bool {
		if ( is_array( $stored ) || is_array( $value ) ) {
			return is_array( $stored ) && is_array( $value ) && maybe_serialize( self::stringify( $stored ) ) === maybe_serialize( self::stringify( $value ) );
		}
		if ( is_object( $stored ) || is_object( $value ) ) {
			return false;
		}
		// Meta is stored as text: 1, '1' and true are the same row; false and '' too.
		return (string) $stored === (string) $value;
	}

	/**
	 * Scalars of an array as strings, for comparison.
	 *
	 * @param array<mixed> $value Array.
	 * @return array<mixed>
	 */
	private static function stringify( array $value ): array {
		return array_map( static fn( $v ) => is_array( $v ) ? self::stringify( $v ) : ( is_scalar( $v ) || null === $v ? (string) $v : $v ), $value );
	}

	/**
	 * Option name for a field on an options prefix.
	 *
	 * @param ObjectRef $ref  Options target.
	 * @param string    $name Field name, may start with `_`.
	 */
	/**
	 * Loads every value and field reference of an options prefix with two queries, instead of
	 * two queries per field (options page values are not autoloaded by default).
	 *
	 * @param string $prefix Options id, e.g. `options`.
	 */
	private static function prime_options( string $prefix ): void {
		if ( isset( self::$primed[ $prefix ] ) || ! function_exists( 'wp_prime_option_caches' ) ) {
			return;
		}
		self::$primed[ $prefix ] = true;
		global $wpdb;
		$like  = $wpdb->esc_like( $prefix . '_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, '\\_' . $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $names ) {
			wp_prime_option_caches( $names );
		}
	}

	/**
	 * Forgets primed prefixes (tests, or after bulk changes made outside the options API).
	 */
	public static function reset_primed(): void {
		self::$primed = array();
	}

	private function option_name( ObjectRef $ref, string $name ): string {
		if ( '_' === ( $name[0] ?? '' ) ) {
			return '_' . $ref->id . '_' . substr( $name, 1 );
		}
		return $ref->id . '_' . $name;
	}
}
