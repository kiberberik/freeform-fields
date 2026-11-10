<?php
/**
 * File field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Any Media Library attachment. Stores the attachment ID.
 */
class File extends AbstractField {

	public string $name = 'file';

	public string $category = 'content';

	public function label(): string {
		return __( 'File', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'array', 'url' or 'id'.
			'return_format' => 'array',
			'library'       => 'all',
			// Comma-separated extensions, e.g. "pdf, docx". Empty: any.
			'mime_types'    => '',
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
						'array' => __( 'File Array', 'freeform-fields' ),
						'url'   => __( 'File URL', 'freeform-fields' ),
						'id'    => __( 'File ID', 'freeform-fields' ),
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
			self::setting( 'mime_types', __( 'Allowed File Types', 'freeform-fields' ), 'text', array( 'help' => __( 'Comma separated list, e.g. pdf, docx. Empty for all types.', 'freeform-fields' ) ) ),
		);
	}

	public function enqueue_assets(): void {
		wp_enqueue_media();
	}

	public function render_input( array $field ): void {
		$id   = absint( is_scalar( $field['value'] ) ? $field['value'] : 0 );
		$file = $id ? get_post( $id ) : null;
		$url  = $file ? (string) wp_get_attachment_url( $id ) : '';

		printf(
			'<div class="wpff-file%s" data-library="%s" data-mime="%s">',
			$file ? ' has-value' : '',
			esc_attr( $field['library'] ),
			esc_attr( $field['mime_types'] )
		);
		printf(
			'<input type="hidden" id="%s" name="%s" value="%s" class="wpff-file__value" />',
			esc_attr( $field['input_id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $file ? (string) $id : '' )
		);
		printf(
			'<div class="wpff-file__preview"><span class="dashicons dashicons-media-default" aria-hidden="true"></span> <a class="wpff-file__name" href="%s" target="_blank" rel="noopener">%s</a> <button type="button" class="button-link wpff-file__remove">%s</button></div>',
			esc_url( $url ),
			esc_html( $url ? wp_basename( $url ) : '' ),
			esc_html__( 'Remove', 'freeform-fields' )
		);
		printf(
			'<p class="wpff-file__empty">%s <button type="button" class="button wpff-file__select">%s</button></p>',
			esc_html__( 'No file selected.', 'freeform-fields' ),
			esc_html__( 'Add File', 'freeform-fields' )
		);
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		$id = is_scalar( $value ) ? absint( $value ) : 0;
		return $id ? $id : '';
	}

	public function validate_value( $value, array $field ) {
		if ( 'attachment' !== get_post_type( (int) $value ) || ! current_user_can( 'read_post', (int) $value ) ) {
			return __( 'Please select a valid file.', 'freeform-fields' );
		}
		$allowed = self::extensions( (string) $field['mime_types'] );
		if ( array() !== $allowed ) {
			$ext = strtolower( pathinfo( (string) get_attached_file( (int) $value ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed, true ) ) {
				/* translators: %s: allowed extensions. */
				return sprintf( __( 'File type must be one of: %s.', 'freeform-fields' ), implode( ', ', $allowed ) );
			}
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		$id = absint( is_scalar( $value ) ? $value : 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		switch ( $field['return_format'] ) {
			case 'id':
				return $id;
			case 'url':
				return wp_get_attachment_url( $id );
			default:
				$data             = Image::attachment_array( $id );
				$data['filesize'] = (int) ( wp_get_attachment_metadata( $id )['filesize'] ?? @filesize( (string) get_attached_file( $id ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$data['icon']     = wp_mime_type_icon( $id );
				return $data;
		}
	}

	/**
	 * @param string $list Comma separated extensions.
	 * @return string[]
	 */
	private static function extensions( string $list ): array {
		return array_values( array_filter( array_map( static fn( $e ) => strtolower( trim( $e, " .\t\n" ) ), explode( ',', $list ) ) ) );
	}
}
