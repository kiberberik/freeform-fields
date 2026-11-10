<?php
/**
 * Field groups on taxonomy term screens.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Renders fields on the "Add New" and "Edit" term forms and saves them as term meta.
 *
 * Location rule: `taxonomy` == {taxonomy} (or `all`).
 */
final class TermScreen {

	private FormSupport $form;

	public function __construct( private Plugin $plugin ) {
		$this->form = new FormSupport( $plugin );
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_taxonomy_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'created_term', array( $this, 'save' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save' ), 10, 3 );
		add_filter( 'pre_insert_term', array( $this, 'validate_new_term' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_saved_errors' ) );
	}

	public function add_taxonomy_hooks(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit' ), 10, 2 );
		}
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 */
	public function render_add( $taxonomy ): void {
		$groups = $this->form->groups_for( array( 'taxonomy' => $taxonomy ) );
		if ( array() === $groups ) {
			return;
		}
		FormSupport::nonce_field();
		echo '<div class="wpff-term-fields">';
		foreach ( $groups as $group ) {
			$this->renderer()->render_group( $group, null, 'form' );
		}
		echo '</div>';
	}

	/**
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public function render_edit( $term, $taxonomy ): void {
		$groups = $this->form->groups_for(
			array(
				'taxonomy' => $taxonomy,
				'term_id'  => $term->term_id,
			)
		);
		if ( array() === $groups ) {
			return;
		}
		FormSupport::nonce_field();
		$ref = new ObjectRef( 'term', $term->term_id );
		foreach ( $groups as $group ) {
			// The edit form is one table; a heading row separates groups.
			printf( '<tr class="wpff-term-group-title"><th colspan="2"><h2>%s</h2></th></tr>', esc_html( $group['title'] ) );
			$this->renderer()->render_group( $group, $ref, 'table' );
		}
	}

	/**
	 * @param string $hook Admin page.
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : 'category';
		$term_id  = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
		// phpcs:enable
		$screen = array( 'taxonomy' => $taxonomy );
		if ( $term_id ) {
			$screen['term_id'] = $term_id;
		}
		$groups = $this->form->groups_for( $screen );
		if ( array() === $groups ) {
			return;
		}
		Assets::enqueue_form(
			$this->plugin,
			FormSupport::types_of( $groups ),
			array(
				'object'       => $term_id ? 'term_' . $term_id : 'newterm_' . $taxonomy,
				// The add form is submitted over AJAX by WordPress; it is validated by pre_insert_term.
				'formSelector' => $term_id ? '#edittag' : '#wpff-none',
			)
		);
	}

	/**
	 * Rejects a new term when submitted fields are invalid, so the AJAX "Add" form shows the error.
	 *
	 * @param string|\WP_Error $term     Term name.
	 * @param string           $taxonomy Taxonomy.
	 * @return string|\WP_Error
	 */
	public function validate_new_term( $term, $taxonomy ) {
		$submitted = FormSupport::submitted();
		if ( null === $submitted || is_wp_error( $term ) || ! empty( $_POST['tag_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in submitted().
			return $term;
		}
		$result = $this->form->validate( array( 'taxonomy' => $taxonomy ), $submitted, '' );
		if ( array() !== $result['errors'] ) {
			return new \WP_Error( 'wpff_invalid', implode( ' ', $result['errors'] ) );
		}
		return $term;
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function save( $term_id, $tt_id, $taxonomy = '' ): void {
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$errors = $this->form->save_request(
			array(
				'taxonomy' => $taxonomy,
				'term_id'  => (int) $term_id,
			),
			new ObjectRef( 'term', (int) $term_id )
		);
		if ( $errors ) {
			set_transient( FormSupport::errors_transient( 'term_' . $term_id ), $errors, MINUTE_IN_SECONDS * 5 );
		}
	}

	public function show_saved_errors(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$term_id = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
		if ( $screen && 'term' === $screen->base && $term_id ) {
			PostScreen::print_saved_errors( 'term_' . $term_id );
		}
	}

	private function renderer(): FieldRenderer {
		return new FieldRenderer( $this->plugin->types(), $this->plugin->values() );
	}
}
