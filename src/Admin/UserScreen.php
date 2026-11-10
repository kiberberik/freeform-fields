<?php
/**
 * Field groups on user screens.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Renders fields on "Profile", "Edit User" and "Add New User" and saves them as user meta.
 *
 * Location rules: `user_form` (all, add, edit) and `user_role` (role of the edited user).
 * Invalid values block the save through `user_profile_update_errors`, like core fields.
 */
final class UserScreen {

	private FormSupport $form;

	public function __construct( private Plugin $plugin ) {
		$this->form = new FormSupport( $plugin );
	}

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render_edit' ) );
		add_action( 'edit_user_profile', array( $this, 'render_edit' ) );
		add_action( 'user_new_form', array( $this, 'render_add' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate' ), 10, 3 );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'user_register', array( $this, 'save_new' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Location screen for a user form.
	 *
	 * @param int    $user_id User ID (0 on the add form).
	 * @param string $form    `add` or `edit`.
	 * @return array<string, mixed>
	 */
	public static function screen( int $user_id, string $form ): array {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return array(
			'user_id'    => $user_id,
			'user_form'  => $form,
			'user_roles' => $user ? (array) $user->roles : array(),
		);
	}

	/**
	 * @param \WP_User $user User being edited.
	 */
	public function render_edit( $user ): void {
		$this->render( self::screen( $user->ID, 'edit' ), new ObjectRef( 'user', $user->ID ) );
	}

	public function render_add(): void {
		$this->render( self::screen( 0, 'add' ), null );
	}

	/**
	 * @param array<string, mixed> $screen Screen.
	 * @param ObjectRef|null       $ref    User, null on the add form.
	 */
	private function render( array $screen, ?ObjectRef $ref ): void {
		$groups = $this->form->groups_for( $screen );
		if ( array() === $groups ) {
			return;
		}
		FormSupport::nonce_field();
		$renderer = new FieldRenderer( $this->plugin->types(), $this->plugin->values() );
		foreach ( $groups as $group ) {
			printf( '<h2 class="wpff-user-group-title">%s</h2>', esc_html( $group['title'] ) );
			echo '<table class="form-table wpff-user-fields" role="presentation"><tbody>';
			$renderer->render_group( $group, $ref, 'table' );
			echo '</tbody></table>';
		}
	}

	/**
	 * Adds rejected values to the profile errors, which stops WordPress from saving.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param bool      $update Whether an existing user is updated.
	 * @param \stdClass $user   User data.
	 */
	public function validate( $errors, $update, $user ): void {
		$submitted = FormSupport::submitted();
		if ( null === $submitted ) {
			return;
		}
		$id     = $update ? (int) $user->ID : 0;
		$result = $this->form->validate( self::screen( $id, $update ? 'edit' : 'add' ), $submitted, $id ? 'user_' . $id : '' );
		foreach ( $result['errors'] as $key => $message ) {
			$errors->add( 'wpff_' . $key, $message );
		}
	}

	/**
	 * @param int $user_id User ID.
	 */
	public function save( $user_id ): void {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			$this->form->save_request( self::screen( (int) $user_id, 'edit' ), new ObjectRef( 'user', (int) $user_id ) );
		}
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public function save_new( $user_id ): void {
		if ( is_admin() && current_user_can( 'create_users' ) ) {
			$this->form->save_request( self::screen( 0, 'add' ), new ObjectRef( 'user', (int) $user_id ) );
		}
	}

	/**
	 * @param string $hook Admin page.
	 */
	public function enqueue( $hook ): void {
		$forms = array(
			'profile.php'   => 'edit',
			'user-edit.php' => 'edit',
			'user-new.php'  => 'add',
		);
		if ( ! isset( $forms[ $hook ] ) ) {
			return;
		}
		$user_id = 0;
		if ( 'profile.php' === $hook ) {
			$user_id = get_current_user_id();
		} elseif ( 'user-edit.php' === $hook ) {
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		}
		$groups = $this->form->groups_for( self::screen( $user_id, $forms[ $hook ] ) );
		if ( array() === $groups ) {
			return;
		}
		Assets::enqueue_form(
			$this->plugin,
			FormSupport::types_of( $groups ),
			array(
				'object'       => $user_id ? 'user_' . $user_id : 'newuser',
				'formSelector' => $user_id ? '#your-profile' : '#createuser',
			)
		);
	}
}
