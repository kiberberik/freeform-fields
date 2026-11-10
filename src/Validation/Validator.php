<?php
/**
 * Server-side validation of submitted values.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Validation;

use FreeformFields\Hooks;

use FreeformFields\Fields\FieldTypeRegistry;
use FreeformFields\Fields\Types\FlexibleContent;
use FreeformFields\Fields\Types\Repeater;
use FreeformFields\Storage\ValueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes and validates a submitted `wpff[field_key] => value` map against allowed fields.
 *
 * Errors are keyed by field path: `field_key` at the top level, `field_group.field_sub` inside a
 * group and `field_rep.{row id}.field_sub` inside repeater / flexible rows. The admin script
 * finds fields by the same path (`data-path`).
 */
final class Validator {

	public function __construct( private FieldTypeRegistry $types ) {}

	/**
	 * Sanitizes and validates submitted values.
	 *
	 * Fields not present in `$fields` are ignored, so a request cannot write fields that are not on the screen.
	 *
	 * @param array<string, mixed>                $submitted Unslashed submitted values keyed by field key.
	 * @param array<string, array<string, mixed>> $fields    Allowed fields keyed by field key.
	 * @param string                              $object_id ACF-style object id, passed to filters.
	 * @param string                              $path      Path prefix with trailing dot for nested calls.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public function validate( array $submitted, array $fields, string $object_id = '', string $path = '' ): array {
		$values = array();
		$errors = array();

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $submitted ) ) {
				continue;
			}
			// Runtime changes (e.g. choices filled by acf/load_field) apply to validation too.
			$field   = Hooks::load_field( $field );
			$handler = $this->types->get( $field['type'] );
			if ( ! $handler || ! $handler->has_value() ) {
				continue;
			}
			$field_path = $path . $key;
			$kind       = $handler->compound();

			if ( 'group' === $kind ) {
				$raw    = is_array( $submitted[ $key ] ) ? $submitted[ $key ] : array();
				$result = $this->validate( $raw, array_column( $field['sub_fields'], null, 'key' ), $object_id, $field_path . '.' );
				$errors = array_merge( $errors, $result['errors'] );
				// Valid sub values are saved even when a sibling is rejected.
				$values[ $key ] = $result['values'];
				continue;
			}

			if ( 'rows' === $kind || 'layouts' === $kind ) {
				$result = $this->validate_rows( $field, $kind, $submitted[ $key ], $object_id, $field_path );
				$errors = array_merge( $errors, $result['errors'] );
				// Rows are saved all or nothing: a partly saved row could keep a stale value
				// from the row that used to be at its position.
				if ( array() === $result['errors'] ) {
					$values[ $key ] = $result['rows'];
				}
				continue;
			}

			$value = $handler->sanitize( $submitted[ $key ], $field );
			$valid = true;

			if ( $field['required'] && $handler->is_empty( $value ) ) {
				/* translators: %s: field label. */
				$valid = sprintf( __( '%s is required.', 'freeform-fields' ), $field['label'] );
			} elseif ( ! $handler->is_empty( $value ) ) {
				$valid = $handler->validate_value( $value, $field );
			}

			$valid = $this->filter( $valid, $value, $field, $object_id );
			if ( true === $valid ) {
				$values[ $key ] = $value;
			} else {
				$errors[ $field_path ] = $valid;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Validates repeater or flexible content rows.
	 *
	 * Row-level problems (too few rows) are reported on the field's own path, sub field problems
	 * on the sub field's path. Any problem keeps the stored rows unchanged.
	 *
	 * @param array<string, mixed> $field     Field.
	 * @param string               $kind      `rows` or `layouts`.
	 * @param mixed                $raw       Submitted rows keyed by row id.
	 * @param string               $object_id Object id.
	 * @param string               $path      Field path.
	 * @return array{rows: array<int, array<string, mixed>>, errors: array<string, string>}
	 */
	private function validate_rows( array $field, string $kind, $raw, string $object_id, string $path ): array {
		$rows    = array();
		$errors  = array();
		$counts  = array();
		$handler = $this->types->get( $field['type'] );

		foreach ( is_array( $raw ) ? $raw : array() as $row_id => $row ) {
			if ( ! is_array( $row ) || str_starts_with( (string) $row_id, '__' ) ) {
				continue;
			}
			$subs  = $field['sub_fields'] ?? array();
			$clean = array();
			if ( 'layouts' === $kind ) {
				$layout = ValueManager::find_layout( $field, is_scalar( $row['acf_fc_layout'] ?? null ) ? (string) $row['acf_fc_layout'] : '' );
				if ( ! $layout ) {
					continue;
				}
				$subs                      = $layout['sub_fields'];
				$clean['acf_fc_layout']    = $layout['name'];
				$counts[ $layout['name'] ] = ( $counts[ $layout['name'] ] ?? 0 ) + 1;
			}
			$result = $this->validate( $row, array_column( $subs, null, 'key' ), $object_id, $path . '.' . $row_id . '.' );
			$errors = array_merge( $errors, $result['errors'] );
			$rows[] = $clean + $result['values'];
		}

		$valid = true;
		if ( $field['required'] && array() === $rows ) {
			/* translators: %s: field label. */
			$valid = sprintf( __( '%s is required.', 'freeform-fields' ), $field['label'] );
		} elseif ( $handler instanceof Repeater ) {
			$valid = $handler->validate_count( count( $rows ), $field );
			if ( true === $valid && $handler instanceof FlexibleContent ) {
				$valid = $handler->validate_layout_counts( $counts, $field );
			}
		}
		$valid = $this->filter( $valid, $rows, $field, $object_id );
		if ( true !== $valid ) {
			$errors[ $path ] = $valid;
		}

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Runs the validate_value filters.
	 *
	 * @param true|string          $valid     Result so far.
	 * @param mixed                $value     Value.
	 * @param array<string, mixed> $field     Field.
	 * @param string               $object_id Object id.
	 * @return true|string True or an error message.
	 */
	private function filter( $valid, $value, array $field, string $object_id ) {
		/**
		 * Filters a field's validation result. Return a string to reject the value with that message.
		 *
		 * Variants: wpff/validate_value/type={type}, /name={name}, /key={key}.
		 *
		 * @param true|string $valid     True or an error message.
		 * @param mixed       $value     Sanitized value.
		 * @param array       $field     Field.
		 * @param string      $object_id Object id.
		 */
		$valid = Hooks::field_filter( 'validate_value', $valid, $field, $value, $field, $object_id );
		if ( true === $valid ) {
			return true;
		}
		return is_string( $valid ) && '' !== $valid ? $valid : __( 'Validation failed.', 'freeform-fields' );
	}
}
