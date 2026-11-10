<?php
/**
 * Shared logic for fields with predefined choices.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Base for Select, Checkbox and Radio.
 */
abstract class ChoiceField extends AbstractField {

	public string $category = 'choice';

	/**
	 * Whether the stored value is a list.
	 *
	 * @param array<string, mixed> $field Field settings.
	 */
	abstract protected function is_multiple( array $field ): bool;

	public function defaults(): array {
		return array(
			'choices'       => array(),
			'allow_null'    => 0,
			// 'value', 'label' or 'array'.
			'return_format' => 'value',
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'choices',
				__( 'Choices', 'freeform-fields' ),
				'choices',
				array( 'help' => __( 'One choice per line. Use "value : Label" to set the stored value and the label separately.', 'freeform-fields' ) )
			),
			self::setting( 'allow_null', __( 'Allow Null', 'freeform-fields' ), 'toggle' ),
			self::setting(
				'return_format',
				__( 'Return Format', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'value' => __( 'Value', 'freeform-fields' ),
						'label' => __( 'Label', 'freeform-fields' ),
						'array' => __( 'Both (Array)', 'freeform-fields' ),
					),
				)
			),
		);
	}

	/**
	 * Layout setting shared by checkbox and radio.
	 *
	 * @return array<string, mixed>
	 */
	protected static function layout_setting(): array {
		return self::setting(
			'layout',
			__( 'Layout', 'freeform-fields' ),
			'select',
			array(
				'options' => array(
					'vertical'   => __( 'Vertical', 'freeform-fields' ),
					'horizontal' => __( 'Horizontal', 'freeform-fields' ),
				),
			)
		);
	}

	/**
	 * Normalizes choices from an array or from `value : Label` lines.
	 *
	 * @param mixed $choices Raw choices.
	 * @return array<string, string>
	 */
	public static function normalize_choices( $choices ): array {
		if ( is_string( $choices ) ) {
			$lines   = preg_split( '/\r\n|\r|\n/', $choices );
			$choices = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( str_contains( $line, ' : ' ) ) {
					[ $value, $label ] = array_map( 'trim', explode( ' : ', $line, 2 ) );
					$choices[ $value ] = $label;
				} else {
					$choices[ $line ] = $line;
				}
			}
			return $choices;
		}
		if ( ! is_array( $choices ) ) {
			return array();
		}
		$normalized = array();
		foreach ( $choices as $value => $label ) {
			$normalized[ (string) $value ] = (string) $label;
		}
		return $normalized;
	}

	/**
	 * Values currently selected, always as a list of strings.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	protected static function selected( $value ): array {
		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}
		return array_map( 'strval', (array) $value );
	}

	public function sanitize( $value, array $field ) {
		$choices = self::normalize_choices( $field['choices'] );
		$values  = array();
		foreach ( (array) $value as $item ) {
			if ( is_scalar( $item ) && array_key_exists( (string) $item, $choices ) ) {
				$values[] = (string) $item;
			}
		}
		if ( $this->is_multiple( $field ) ) {
			return array_values( array_unique( $values ) );
		}
		return $values[0] ?? '';
	}

	public function format_value( $value, $object_id, array $field ) {
		$choices = self::normalize_choices( $field['choices'] );
		$format  = static function ( string $item ) use ( $choices, $field ) {
			$label = $choices[ $item ] ?? $item;
			switch ( $field['return_format'] ) {
				case 'label':
					return $label;
				case 'array':
					return array(
						'value' => $item,
						'label' => $label,
					);
				default:
					return $item;
			}
		};

		if ( $this->is_multiple( $field ) ) {
			return array_map( $format, self::selected( $value ) );
		}
		$selected = self::selected( $value );
		if ( array() === $selected ) {
			return null === $value ? null : '';
		}
		return $format( $selected[0] );
	}
}
