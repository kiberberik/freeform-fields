<?php
/**
 * Link field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * URL, link text and target. Stored as an array {title, url, target} like ACF.
 */
class Link extends AbstractField {

	public string $name = 'link';

	public string $category = 'relational';

	public function label(): string {
		return __( 'Link', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'array' or 'url'.
			'return_format' => 'array',
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'return_format',
				__( 'Return Value', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'array' => __( 'Link Array', 'freeform-fields' ),
						'url'   => __( 'Link URL', 'freeform-fields' ),
					),
				)
			),
		);
	}

	public function render_input( array $field ): void {
		$link = self::normalize( $field['value'] );
		echo '<div class="wpff-link">';
		printf(
			'<label class="wpff-link__part"><span>%s</span><input type="url" id="%s" name="%s[url]" value="%s" class="wpff-control" placeholder="https://" /></label>',
			esc_html__( 'URL', 'freeform-fields' ),
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			wpff_value_attr( $link['url'] )
		);
		printf(
			'<label class="wpff-link__part"><span>%s</span><input type="text" name="%s[title]" value="%s" class="wpff-control" /></label>',
			esc_html__( 'Link Text', 'freeform-fields' ),
			esc_attr( $field['input_name'] ),
			wpff_value_attr( $link['title'] )
		);
		printf(
			'<label class="wpff-link__target"><input type="checkbox" name="%s[target]" value="_blank"%s /> %s</label>',
			esc_attr( $field['input_name'] ),
			checked( '_blank', $link['target'], false ),
			esc_html__( 'Open in a new tab', 'freeform-fields' )
		);
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		$link = self::normalize( $value );
		if ( '' === $link['url'] ) {
			return '';
		}
		return array(
			'title'  => sanitize_text_field( $link['title'] ),
			'url'    => trim( sanitize_text_field( $link['url'] ) ),
			'target' => '_blank' === $link['target'] ? '_blank' : '',
		);
	}

	public function validate_value( $value, array $field ) {
		return ( new Url() )->validate_value( $value['url'] ?? '', $field );
	}

	public function format_value( $value, $object_id, array $field ) {
		$link = self::normalize( $value );
		if ( '' === $link['url'] ) {
			return '' === $value || null === $value ? $value : '';
		}
		return 'url' === $field['return_format'] ? $link['url'] : $link;
	}

	/**
	 * @param mixed $value Stored or submitted value.
	 * @return array{title: string, url: string, target: string}
	 */
	private static function normalize( $value ): array {
		if ( is_string( $value ) ) {
			$value = array( 'url' => $value );
		}
		$value = is_array( $value ) ? $value : array();
		return array(
			'title'  => is_scalar( $value['title'] ?? null ) ? (string) $value['title'] : '',
			'url'    => is_scalar( $value['url'] ?? null ) ? (string) $value['url'] : '',
			'target' => is_scalar( $value['target'] ?? null ) ? (string) $value['target'] : '',
		);
	}
}
