<?php
/**
 * Gallery field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * An ordered list of images. Stored as a list of attachment IDs, like ACF.
 */
class Gallery extends AbstractField {

	public string $name = 'gallery';

	public string $category = 'content';

	public function label(): string {
		return __( 'Gallery', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'array', 'url' or 'id'.
			'return_format' => 'array',
			'preview_size'  => 'thumbnail',
			'library'       => 'all',
			'min'           => '',
			'max'           => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'return_format',
				__( 'Return Format', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'array' => __( 'Image Array', 'freeform-fields' ),
						'url'   => __( 'Image URL', 'freeform-fields' ),
						'id'    => __( 'Image ID', 'freeform-fields' ),
					),
				)
			),
			self::setting(
				'library',
				__( 'Library', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'all'        => __( 'All', 'freeform-fields' ),
						'uploadedTo' => __( 'Uploaded to post', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'min', __( 'Minimum Selection', 'freeform-fields' ), 'number' ),
			self::setting( 'max', __( 'Maximum Selection', 'freeform-fields' ), 'number' ),
		);
	}

	public function enqueue_assets(): void {
		wp_enqueue_media();
	}

	/**
	 * @param mixed $value Stored or submitted value.
	 * @return int[]
	 */
	private static function ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? absint( $v ) : 0, $value ) ) ) );
	}

	public function render_input( array $field ): void {
		printf(
			'<div class="wpff-gallery" data-input-name="%s" data-max="%d" data-library="%s">',
			esc_attr( $field['input_name'] . '[]' ),
			(int) $field['max'],
			esc_attr( $field['library'] )
		);
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<ul class="wpff-gallery__items">';
		foreach ( self::ids( $field['value'] ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $src ) {
				continue;
			}
			printf(
				'<li class="wpff-gallery__item" draggable="true"><img src="%s" alt="%s" /><input type="hidden" name="%s" value="%d" /><button type="button" class="wpff-gallery__remove" aria-label="%s">&times;</button></li>',
				esc_url( $src ),
				esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				esc_attr( $field['input_name'] . '[]' ),
				(int) $id,
				esc_attr__( 'Remove image', 'freeform-fields' )
			);
		}
		echo '</ul>';
		printf( '<button type="button" class="button wpff-gallery__add">%s</button>', esc_html__( 'Add to gallery', 'freeform-fields' ) );
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		return self::ids( is_array( $value ) ? $value : array() );
	}

	public function is_empty( $value ): bool {
		return array() === self::ids( $value );
	}

	public function validate_value( $value, array $field ) {
		foreach ( self::ids( $value ) as $id ) {
			if ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'read_post', $id ) ) {
				return __( 'Please select only valid images.', 'freeform-fields' );
			}
		}
		$count = count( self::ids( $value ) );
		$min   = (int) $field['min'];
		$max   = (int) $field['max'];
		if ( $min > 0 && $count < $min ) {
			/* translators: %d: minimum number of images. */
			return sprintf( _n( 'Select at least %d image.', 'Select at least %d images.', $min, 'freeform-fields' ), $min );
		}
		if ( $max > 0 && $count > $max ) {
			/* translators: %d: maximum number of images. */
			return sprintf( _n( 'Select at most %d image.', 'Select at most %d images.', $max, 'freeform-fields' ), $max );
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		$out = array();
		$ids = self::ids( $value );
		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, true ); // One query for all images instead of two per image.
		}
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			switch ( $field['return_format'] ) {
				case 'id':
					$out[] = $id;
					break;
				case 'url':
					$out[] = wp_get_attachment_url( $id );
					break;
				default:
					$out[] = Image::attachment_array( $id );
			}
		}
		// Like ACF: an empty gallery formats to false.
		return array() !== $out ? $out : false;
	}
}
