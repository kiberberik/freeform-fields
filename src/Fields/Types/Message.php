<?php
/**
 * Message field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Static text for editors.
 */
class Message extends LayoutField {

	public string $name = 'message';

	public function label(): string {
		return __( 'Message', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'message'   => '',
			'new_lines' => 'wpautop',
			'esc_html'  => 0,
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'message', __( 'Message', 'freeform-fields' ), 'textarea' ),
			self::setting(
				'new_lines',
				__( 'New Lines', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'wpautop' => __( 'Automatically add paragraphs', 'freeform-fields' ),
						'br'      => __( 'Automatically add <br>', 'freeform-fields' ),
						''        => __( 'No formatting', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'esc_html', __( 'Escape HTML', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Show HTML tags as text.', 'freeform-fields' ) ) ),
		);
	}

	public function render_input( array $field ): void {
		$text = (string) $field['message'];
		$text = ! empty( $field['esc_html'] ) ? esc_html( $text ) : wp_kses_post( $text );
		if ( 'wpautop' === $field['new_lines'] ) {
			$text = wpautop( $text );
		} elseif ( 'br' === $field['new_lines'] ) {
			$text = nl2br( $text );
		}
		echo '<div class="wpff-message">' . $text . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
}
