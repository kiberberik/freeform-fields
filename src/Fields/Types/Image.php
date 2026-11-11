<?php
/**
 * Image field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Picks an image from the Media Library. Stores the attachment ID.
 */
class Image extends AbstractField {

	public string $name = 'image';

	public string $category = 'content';

	public function label(): string {
		return __( 'Image', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'array', 'url' or 'id'.
			'return_format' => 'array',
			'preview_size'  => 'medium',
			// 'all' or 'uploadedTo'.
			'library'       => 'all',
		);
	}

	public function settings(): array {
		$sizes = array( 'thumbnail' => 'thumbnail' );
		foreach ( get_intermediate_image_sizes() as $size ) {
			$sizes[ $size ] = $size;
		}
		$sizes['full'] = 'full';

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
			self::setting( 'preview_size', __( 'Preview Size', 'freeform-fields' ), 'select', array( 'options' => $sizes ) ),
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
		);
	}

	public function enqueue_assets(): void {
		wp_enqueue_media();
	}

	public function render_input( array $field ): void {
		$id      = absint( is_scalar( $field['value'] ) ? $field['value'] : 0 );
		$preview = $id ? wp_get_attachment_image_url( $id, $field['preview_size'] ) : '';

		printf(
			'<div class="wpff-image%s" data-library="%s" data-preview-size="%s">',
			$preview ? ' has-value' : '',
			esc_attr( $field['library'] ),
			esc_attr( $field['preview_size'] )
		);
		printf(
			'<input type="hidden" id="%s" name="%s" value="%s" class="wpff-image__value" />',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $id ? (string) $id : '' )
		);
		printf(
			'<div class="wpff-image__preview"><img src="%s" alt="" /><button type="button" class="button-link wpff-image__remove">%s</button></div>',
			esc_url( (string) $preview ),
			esc_html__( 'Remove', 'freeform-fields' )
		);
		printf(
			'<p class="wpff-image__empty">%s <button type="button" class="button wpff-image__select">%s</button></p>',
			esc_html__( 'No image selected.', 'freeform-fields' ),
			esc_html__( 'Add Image', 'freeform-fields' )
		);
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		$id = is_scalar( $value ) ? absint( $value ) : 0;
		return $id ? $id : '';
	}

	public function validate_value( $value, array $field ) {
		if ( '' === $value ) {
			return true;
		}
		if ( 'attachment' !== get_post_type( (int) $value ) || ! wp_attachment_is_image( (int) $value ) ) {
			return __( 'Please select a valid image.', 'freeform-fields' );
		}
		if ( ! current_user_can( 'read_post', (int) $value ) ) {
			return __( 'You are not allowed to use this image.', 'freeform-fields' );
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		$id   = absint( is_scalar( $value ) ? $value : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type || ! self::can_show_post( $post, $field ) ) {
			return false;
		}
		switch ( $field['return_format'] ) {
			case 'id':
				return $id;
			case 'url':
				return wp_get_attachment_url( $id );
			default:
				return self::attachment_array( $id );
		}
	}

	/**
	 * Attachment data in the same shape as ACF's image array.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function attachment_array( int $id ): array {
		$post = get_post( $id );
		$meta = wp_get_attachment_metadata( $id ) ?: array();
		$url  = (string) wp_get_attachment_url( $id );

		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( $src ) {
				$sizes[ $size ]             = $src[0];
				$sizes[ $size . '-width' ]  = $src[1];
				$sizes[ $size . '-height' ] = $src[2];
			}
		}

		return array(
			'ID'          => $id,
			'id'          => $id,
			'title'       => $post->post_title,
			'filename'    => wp_basename( $url ),
			'url'         => $url,
			'link'        => get_attachment_link( $id ),
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'author'      => $post->post_author,
			'description' => $post->post_content,
			'caption'     => $post->post_excerpt,
			'name'        => $post->post_name,
			'status'      => $post->post_status,
			'uploaded_to' => $post->post_parent,
			'date'        => $post->post_date_gmt,
			'modified'    => $post->post_modified_gmt,
			'mime_type'   => $post->post_mime_type,
			'type'        => strtok( (string) $post->post_mime_type, '/' ),
			'subtype'     => substr( (string) strstr( (string) $post->post_mime_type, '/' ), 1 ),
			'width'       => (int) ( $meta['width'] ?? 0 ),
			'height'      => (int) ( $meta['height'] ?? 0 ),
			'sizes'       => $sizes,
		);
	}
}
