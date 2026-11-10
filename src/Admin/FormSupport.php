<?php
/**
 * Shared form logic for every screen that edits field values.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Validation\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the groups for a screen, validates and saves submitted values, and maps
 * client-side object ids (`post_5`, `term_3`, `newterm_category`, `user_2`, `newuser`)
 * to screens and permissions for AJAX validation.
 */
final class FormSupport {

	public const NONCE_ACTION = 'wpff_form';
	public const NONCE_NAME   = 'wpff_nonce';

	private static bool $nonce_printed = false;

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Active groups whose location rules match a screen.
	 *
	 * @param array<string, mixed> $screen Screen context.
	 * @return array<string, array<string, mixed>>
	 */
	public function groups_for( array $screen ): array {
		return array_filter(
			$this->plugin->fields()->get_groups(),
			fn( array $group ) => array() !== $group['fields']
				&& ! self::owned_by_acf( $group['key'] )
				&& $this->plugin->locations()->matches( $group['location'], $screen )
		);
	}

	/**
	 * Whether ACF is active and shows a group with this key itself (e.g. right after migration).
	 *
	 * Two forms for the same fields would save over each other, so ACF's form wins while it runs.
	 *
	 * @param string $key Group key.
	 */
	public static function owned_by_acf( string $key ): bool {
		if ( ! Plugin::acf_active() || ! function_exists( 'acf_get_field_group' ) ) {
			return false;
		}
		return (bool) acf_get_field_group( $key );
	}

	/**
	 * Field type names used by groups (including sub fields), for asset loading.
	 *
	 * @param array<string, array<string, mixed>> $groups Groups.
	 * @return string[]
	 */
	public static function types_of( array $groups ): array {
		$types   = array();
		$collect = static function ( array $fields ) use ( &$collect, &$types ): void {
			foreach ( $fields as $field ) {
				$types[ $field['type'] ] = true;
				if ( ! empty( $field['sub_fields'] ) ) {
					$collect( $field['sub_fields'] );
				}
			}
		};
		foreach ( $groups as $group ) {
			$collect( $group['fields'] );
		}
		return array_keys( $types );
	}

	/**
	 * Validates submitted values against the fields shown on a screen.
	 *
	 * @param array<string, mixed> $screen    Screen context.
	 * @param array<string, mixed> $submitted Values keyed by field key.
	 * @param string               $object_id ACF-style object id for filters.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public function validate( array $screen, array $submitted, string $object_id ): array {
		$allowed = array();
		foreach ( $this->groups_for( $screen ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				$allowed[ $field['key'] ] = $field;
			}
		}
		return ( new Validator( $this->plugin->types() ) )->validate( $submitted, $allowed, $object_id );
	}

	/**
	 * Values submitted with the current request, or null when the request has no valid WP Freeform Fields form.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function submitted(): ?array {
		if ( ! isset( $_POST[ self::NONCE_NAME ], $_POST[ FieldRenderer::INPUT_NAME ] ) ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field type sanitizes its own value.
		$submitted = wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] );
		return is_array( $submitted ) ? $submitted : null;
	}

	/**
	 * Validates and saves the current request's values for an object.
	 *
	 * @param array<string, mixed> $screen Screen context.
	 * @param ObjectRef            $ref    Object to save to.
	 * @return array<string, string>|null Rejected fields (key => message), or null when nothing was submitted.
	 */
	public function save_request( array $screen, ObjectRef $ref ): ?array {
		$submitted = self::submitted();
		if ( null === $submitted ) {
			return null;
		}
		$result = $this->validate( $screen, $submitted, $ref->to_string() );
		foreach ( $result['values'] as $key => $value ) {
			$field = $this->plugin->fields()->get_field( $key );
			if ( $field ) {
				$this->plugin->values()->save( $field, $value, $ref );
			}
		}

		/**
		 * Fires after field values of an object were saved from a form.
		 *
		 * @param string $object_id ACF-style object id (`5`, `term_3`, `user_2`).
		 * @param array  $errors    Fields that were rejected, keyed by field key.
		 */
		// ACF callbacks of acf/save_post often read $_POST['acf'] (values keyed by field key).
		$fake_acf = \FreeformFields\Plugin::compat_enabled() && ! isset( $_POST['acf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in submitted().
		if ( $fake_acf ) {
			$_POST['acf'] = $_POST[ FieldRenderer::INPUT_NAME ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- read-only copy for compatibility.
		}
		\FreeformFields\Hooks::action( 'save_post', $ref->to_string(), $result['errors'] );
		if ( $fake_acf ) {
			unset( $_POST['acf'] );
		}

		return $result['errors'];
	}

	/**
	 * Prints the nonce once per page.
	 */
	public static function nonce_field(): void {
		if ( ! self::$nonce_printed ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
			self::$nonce_printed = true;
		}
	}

	/**
	 * Screen context and permission for an object id sent by the browser.
	 *
	 * @param string $object `post_5`, `term_3`, `newterm_{taxonomy}`, `user_2` or `newuser`.
	 * @return array{screen: array<string, mixed>, object_id: string}|null Null when unknown or not allowed.
	 */
	public static function screen_for_object( string $object ): ?array {
		if ( preg_match( '/^post_(\d+)$/', $object, $m ) ) {
			$id = (int) $m[1];
			return get_post( $id ) && current_user_can( 'edit_post', $id )
				? array(
					'screen'    => array( 'post_id' => $id ),
					'object_id' => (string) $id,
				)
				: null;
		}
		if ( preg_match( '/^term_(\d+)$/', $object, $m ) ) {
			$term = get_term( (int) $m[1] );
			return $term instanceof \WP_Term && current_user_can( 'edit_term', $term->term_id )
				? array(
					'screen'    => array(
						'taxonomy' => $term->taxonomy,
						'term_id'  => $term->term_id,
					),
					'object_id' => 'term_' . $term->term_id,
				)
				: null;
		}
		if ( preg_match( '/^newterm_([a-z0-9_\-]+)$/', $object, $m ) ) {
			$taxonomy = get_taxonomy( $m[1] );
			return $taxonomy && current_user_can( $taxonomy->cap->edit_terms )
				? array(
					'screen'    => array( 'taxonomy' => $taxonomy->name ),
					'object_id' => '',
				)
				: null;
		}
		if ( preg_match( '/^user_(\d+)$/', $object, $m ) ) {
			$id = (int) $m[1];
			return get_userdata( $id ) && current_user_can( 'edit_user', $id )
				? array(
					'screen'    => UserScreen::screen( $id, 'edit' ),
					'object_id' => 'user_' . $id,
				)
				: null;
		}
		if ( preg_match( '/^options_([a-z0-9_\-]+)$/', $object, $m ) ) {
			$page = Plugin::instance()->options_pages()->get( $m[1] );
			return $page && current_user_can( $page['capability'] )
				? array(
					'screen'    => OptionsScreen::screen( $page['menu_slug'] ),
					'object_id' => (string) $page['post_id'],
				)
				: null;
		}
		if ( 'newuser' === $object ) {
			return current_user_can( 'create_users' )
				? array(
					'screen'    => UserScreen::screen( 0, 'add' ),
					'object_id' => '',
				)
				: null;
		}
		return null;
	}

	/**
	 * Transient key for errors shown after a redirect.
	 *
	 * @param string $object_id Object id.
	 */
	public static function errors_transient( string $object_id ): string {
		return 'wpff_errors_' . get_current_user_id() . '_' . $object_id;
	}
}
