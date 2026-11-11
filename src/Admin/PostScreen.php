<?php
/**
 * Field groups on the post edit screen.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a meta box per matching field group and saves submitted values.
 * Works in the classic editor and in the block editor's meta box area.
 */
final class PostScreen {

	public const NONCE_ACTION = FormSupport::NONCE_ACTION;
	public const NONCE_NAME   = FormSupport::NONCE_NAME;

	private const AFTER_TITLE_CONTEXT = 'wpff_after_title';

	/**
	 * Groups shown on this screen, for asset loading.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $groups_on_screen = array();

	private FormSupport $form;

	public function __construct( private Plugin $plugin ) {
		$this->form = new FormSupport( $plugin );
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'render_after_title' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_saved_errors' ) );
	}

	/**
	 * Groups whose location rules match the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, array<string, mixed>>
	 */
	public function groups_for_post( \WP_Post $post ): array {
		if ( Plugin::GROUP_POST_TYPE === $post->post_type ) {
			return array();
		}
		return $this->form->groups_for( array( 'post_id' => $post->ID ) );
	}

	/**
	 * Registers meta boxes.
	 *
	 * @param string        $post_type Post type.
	 * @param \WP_Post|null $post      Post.
	 */
	public function add_meta_boxes( $post_type, $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$block_editor = function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post );

		foreach ( $this->groups_for_post( $post ) as $group ) {
			$this->groups_on_screen[ $group['key'] ] = $group;

			$context  = 'side' === $group['position'] ? 'side' : 'normal';
			$priority = 'high';
			if ( 'acf_after_title' === $group['position'] && ! $block_editor ) {
				$context = self::AFTER_TITLE_CONTEXT;
			} elseif ( 'normal' === $group['position'] ) {
				$priority = 'default';
			}

			add_meta_box(
				'wpff-' . $group['key'],
				esc_html( \FreeformFields\Multilingual\Multilingual::group_title( $group ) ),
				array( $this, 'render_meta_box' ),
				$post_type,
				$context,
				$priority,
				array(
					'group'                              => $group,
					'__back_compat_meta_box'             => false,
					'__block_editor_compatible_meta_box' => true,
				)
			);
		}
	}

	/**
	 * Prints `acf_after_title` boxes in the classic editor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_after_title( $post ): void {
		do_meta_boxes( get_current_screen(), self::AFTER_TITLE_CONTEXT, $post );
	}

	/**
	 * Meta box callback.
	 *
	 * @param \WP_Post                         $post Post.
	 * @param array{args: array{group: array}} $box  Meta box.
	 */
	public function render_meta_box( $post, array $box ): void {
		$group = $box['args']['group'];
		FormSupport::nonce_field();
		if ( 'seamless' === $group['style'] ) {
			echo '<div class="wpff-seamless"></div>';
		}
		// A brand-new post has no stored values yet, so defaults are shown.
		$ref = 'auto-draft' === $post->post_status ? null : new ObjectRef( 'post', $post->ID );
		$this->renderer()->render_group( $group, $ref );
	}

	/**
	 * Enqueues assets when the screen has fields.
	 */
	public function enqueue(): void {
		if ( array() === $this->groups_on_screen ) {
			return;
		}
		$post = get_post();
		Assets::enqueue_form(
			$this->plugin,
			FormSupport::types_of( $this->groups_on_screen ),
			array(
				'object'       => $post ? 'post_' . $post->ID : '',
				'postId'       => $post ? $post->ID : 0,
				'formSelector' => '#post',
				'blockEditor'  => $post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ),
			)
		);
	}

	/**
	 * Saves submitted field values.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save_post( $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// Only the post that owns the form; other posts saved during the same request are left alone.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in FormSupport::submitted().
		if ( (int) ( $_POST['post_ID'] ?? 0 ) !== (int) $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$errors = $this->form->save_request( array( 'post_id' => (int) $post_id ), new ObjectRef( 'post', (int) $post_id ) );
		if ( null === $errors ) {
			return;
		}

		$transient = FormSupport::errors_transient( (string) $post_id );
		if ( array() !== $errors ) {
			set_transient( $transient, $errors, MINUTE_IN_SECONDS * 5 );
		} else {
			delete_transient( $transient );
		}
	}

	/**
	 * Validates submitted values against the fields shown for this post.
	 *
	 * @param \WP_Post             $post      Post.
	 * @param array<string, mixed> $submitted Values keyed by field key.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public function validate_for_post( \WP_Post $post, array $submitted ): array {
		return $this->form->validate( array( 'post_id' => $post->ID ), $submitted, (string) $post->ID );
	}

	/**
	 * Shows values rejected on the last save (classic editor reload).
	 */
	public function show_saved_errors(): void {
		$screen = get_current_screen();
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post ) {
			return;
		}
		self::print_saved_errors( (string) $post->ID );
	}

	/**
	 * Prints and clears rejected values stored for an object.
	 *
	 * @param string $object_id Object id.
	 */
	public static function print_saved_errors( string $object_id ): void {
		$transient = FormSupport::errors_transient( $object_id );
		$errors    = get_transient( $transient );
		if ( ! is_array( $errors ) || array() === $errors ) {
			return;
		}
		delete_transient( $transient );

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Some fields were not saved:', 'freeform-fields' ) . '</p><ul>';
		foreach ( $errors as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	private function renderer(): FieldRenderer {
		return new FieldRenderer( $this->plugin->types(), $this->plugin->values() );
	}
}
