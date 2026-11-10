<?php
/**
 * Admin AJAX endpoints.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `wpff_validate`: validates a form before it is submitted.
 *
 * Request: `object` (`post_5`, `term_3`, `newterm_category`, `user_2`, `newuser`), `nonce`, `wpff[...]`.
 */
final class Ajax {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'wp_ajax_wpff_validate', array( $this, 'validate' ) );
	}

	public function validate(): void {
		check_ajax_referer( FormSupport::NONCE_ACTION, 'nonce' );

		$object  = isset( $_POST['object'] ) ? sanitize_text_field( wp_unslash( $_POST['object'] ) ) : '';
		$context = FormSupport::screen_for_object( $object );
		if ( ! $context ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.', 'freeform-fields' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field type sanitizes its own value.
		$submitted = isset( $_POST[ FieldRenderer::INPUT_NAME ] ) ? wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ) : array();
		$result    = ( new FormSupport( $this->plugin ) )->validate(
			$context['screen'],
			is_array( $submitted ) ? $submitted : array(),
			$context['object_id']
		);

		wp_send_json_success(
			array(
				'valid'  => array() === $result['errors'],
				'errors' => (object) $result['errors'],
			)
		);
	}
}
