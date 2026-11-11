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
	 * Current meta values of objects written during one save, by "type:id" then name
	 * (unserialized; `null` marks a key with several rows). WordPress clears its meta cache after
	 * every write, so without this each write would reload all meta of the object.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $known = array();

	/**
	 * Starts a new save: forgets values remembered from earlier writes.
	 */
	public function begin_batch(): void {
		$this->known = array();
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
		$key = $ref->type . ':' . $ref->id;
		unset( $this->known[ $key ][ $name ], $this->known[ $key ][ '_' . $name ] );
		delete_metadata( $ref->type, (int) $ref->id, '_' . $name );
		return delete_metadata( $ref->type, (int) $ref->id, $name );
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
		if ( ! array_key_exists( $name, $known ) ) {
			$ok = false !== add_metadata( $type, $id, $name, wp_slash( $value ) );
		} else {
			$ok = false !== update_metadata( $type, $id, $name, wp_slash( $value ) );
		}
		$known[ $name ] = array( $value );
		return $ok;
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
