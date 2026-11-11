<?php
/**
 * Translation preferences of field groups and fields.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Multilingual;

use FreeformFields\Registry\FieldRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Decides how each field's value moves between translations.
 *
 * Stored in the same settings ACFML uses, so groups move between the plugins unchanged:
 *  - group `acfml_field_group_mode`: `translation` (same fields across languages),
 *    `localization` (different fields across languages) or `advanced` (expert: per field);
 *  - field `wpml_cf_preferences`: 0 don't translate, 1 copy, 2 translate, 3 copy once.
 */
final class Preferences {

	public const IGNORE    = 0;
	public const COPY      = 1;
	public const TRANSLATE = 2;
	public const COPY_ONCE = 3;

	public const MODE_TRANSLATION  = 'translation';
	public const MODE_LOCALIZATION = 'localization';
	public const MODE_ADVANCED     = 'advanced';

	/**
	 * Group setting holding the mode.
	 */
	public const GROUP_SETTING = 'acfml_field_group_mode';

	/**
	 * Field setting holding the preference.
	 */
	public const FIELD_SETTING = 'wpml_cf_preferences';

	public const MODES = array( self::MODE_TRANSLATION, self::MODE_LOCALIZATION, self::MODE_ADVANCED );

	/**
	 * Field key => preference, for every field of every group (clone copies included).
	 *
	 * @var array<string, int>|null
	 */
	private ?array $map = null;

	public function __construct( private FieldRegistry $registry ) {}

	/**
	 * Forgets the computed map (after a group is saved).
	 */
	public function flush(): void {
		$this->map = null;
	}

	/**
	 * Mode of a group. A group without one uses the expert setup when its fields carry
	 * preferences (groups made before modes existed), else "same fields across languages".
	 *
	 * @param array<string, mixed> $group Group.
	 */
	public static function mode( array $group ): string {
		$mode = (string) ( $group[ self::GROUP_SETTING ] ?? '' );
		if ( in_array( $mode, self::MODES, true ) ) {
			return $mode;
		}
		return self::has_stored_preferences( (array) ( $group['fields'] ?? array() ) ) ? self::MODE_ADVANCED : self::MODE_TRANSLATION;
	}

	/**
	 * Types whose values are text to translate.
	 *
	 * @return string[]
	 */
	public static function text_types(): array {
		/**
		 * Filters the field types translated as text (preference "Translate") in the
		 * "same fields" and "different fields" modes. Other types are copied.
		 *
		 * @param string[] $types Type names.
		 */
		return (array) apply_filters( 'wpff/multilingual/text_types', array( 'text', 'textarea', 'wysiwyg' ) );
	}

	/**
	 * Preference a field gets from its type in a mode.
	 *
	 * @param string $type Field type.
	 * @param string $mode Group mode.
	 */
	public static function default_for( string $type, string $mode ): int {
		if ( in_array( $type, self::text_types(), true ) ) {
			return self::TRANSLATE;
		}
		return self::MODE_LOCALIZATION === $mode ? self::COPY_ONCE : self::COPY;
	}

	/**
	 * A stored preference, or null when missing or invalid.
	 *
	 * @param mixed $value Stored setting.
	 */
	public static function sanitize( $value ): ?int {
		if ( is_bool( $value ) || ! is_scalar( $value ) || '' === (string) $value || ! ctype_digit( (string) $value ) ) {
			return null;
		}
		$value = (int) $value;
		return in_array( $value, array( self::IGNORE, self::COPY, self::TRANSLATE, self::COPY_ONCE ), true ) ? $value : null;
	}

	/**
	 * Preference that applies to a field in a group of the given mode.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $mode  Group mode.
	 */
	public static function effective( array $field, string $mode ): int {
		$stored = self::sanitize( $field[ self::FIELD_SETTING ] ?? null );
		$pref   = self::MODE_ADVANCED === $mode && null !== $stored ? $stored : self::default_for( (string) ( $field['type'] ?? 'text' ), $mode );
		/**
		 * Filters the translation preference of a field: 0 don't translate, 1 copy, 2 translate, 3 copy once.
		 *
		 * @param int    $pref  Preference.
		 * @param array  $field Field.
		 * @param string $mode  Group mode.
		 */
		$pref = self::sanitize( apply_filters( 'wpff/multilingual/field_preference', $pref, $field, $mode ) );
		return $pref ?? self::COPY;
	}

	/**
	 * Preference of a field by key, null for unknown keys.
	 *
	 * @param string $key Field key.
	 */
	public function for_key( string $key ): ?int {
		if ( null === $this->map ) {
			$this->map = array();
			foreach ( $this->registry->get_all_groups() as $group ) {
				$this->index( (array) $group['fields'], self::mode( $group ) );
			}
		}
		return $this->map[ $key ] ?? null;
	}

	/**
	 * Preference of every value stored on an object by our fields: meta key => preference.
	 * Field references (`_{name}`) are listed too, always as "copy".
	 *
	 * @param array<string, string> $references Stored name => field key (MetaStore::references()).
	 * @return array<string, int>
	 */
	public function for_references( array $references ): array {
		$keys = array();
		foreach ( $references as $name => $field_key ) {
			$pref = $this->for_key( (string) $field_key );
			if ( null === $pref ) {
				continue;
			}
			$keys[ (string) $name ] = $pref;
			$keys[ '_' . $name ]    = self::IGNORE === $pref ? self::IGNORE : self::COPY;
		}
		return $keys;
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param string                           $mode   Group mode.
	 */
	private function index( array $fields, string $mode ): void {
		foreach ( $fields as $field ) {
			$this->map[ (string) $field['key'] ] = self::effective( $field, $mode );
			$this->index( (array) ( $field['sub_fields'] ?? array() ), $mode );
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$this->index( (array) ( $layout['sub_fields'] ?? array() ), $mode );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Fields.
	 */
	private static function has_stored_preferences( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( null !== self::sanitize( $field[ self::FIELD_SETTING ] ?? null ) ) {
				return true;
			}
			if ( self::has_stored_preferences( (array) ( $field['sub_fields'] ?? array() ) ) ) {
				return true;
			}
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				if ( self::has_stored_preferences( (array) ( $layout['sub_fields'] ?? array() ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
