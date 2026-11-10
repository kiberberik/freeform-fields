<?php
/**
 * Front-end forms (ACF's acf_form() / acf_form_head()).
 *
 * @package FreeformFields
 */

namespace FreeformFields\Front;

use FreeformFields\Admin\Assets;
use FreeformFields\Admin\FieldRenderer;
use FreeformFields\Admin\FormSupport;
use FreeformFields\Hooks;
use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Validation\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves forms placed in theme templates.
 *
 * The form settings (target post, fields, redirect) never come from the visitor: they are sent
 * along as signed JSON and rejected if changed, so a visitor cannot point a form at another post.
 * Like ACF, the template decides who sees the form; there is no capability check of its own.
 */
final class Forms {

	public const NONCE_ACTION = 'wpff_form';

	private const ARGS_FIELD = '_wpff_form';
	private const HONEYPOT   = '_wpff_hp';
	private const POST_INPUT = 'wpff_post';

	private static ?Forms $instance = null;

	/**
	 * Result of the submission handled in this request, by form id.
	 *
	 * @var array<string, array{errors: array<string, string>, values: array<string, mixed>}>
	 */
	private array $results = array();

	private bool $handled = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		// Templates that forget acf_form_head() still get their submissions saved.
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * ACF's defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'id'                    => 'acf-form',
			'post_id'               => false,
			'new_post'              => false,
			'field_groups'          => false,
			'fields'                => false,
			'post_title'            => false,
			'post_content'          => false,
			'form'                  => true,
			'form_attributes'       => array(),
			'return'                => '',
			'html_before_fields'    => '',
			'html_after_fields'     => '',
			'submit_value'          => __( 'Update', 'freeform-fields' ),
			'updated_message'       => __( 'Post updated', 'freeform-fields' ),
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'honeypot'              => true,
			'html_updated_message'  => '<div id="message" class="updated"><p>%s</p></div>',
			'html_submit_button'    => '<input type="submit" class="acf-button button button-primary button-large" value="%s" />',
			'kses'                  => true,
		);
	}

	/**
	 * Prints a form.
	 *
	 * @param array<string, mixed> $args Settings as in ACF's acf_form().
	 */
	public function render( array $args ): void {
		$args   = $this->normalize( $args );
		$fields = $this->fields_for( $args );
		$ref    = $this->target( $args );
		$result = $this->results[ $args['id'] ] ?? null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( ! $result && isset( $_GET['updated'], $_GET['form'] ) && sanitize_key( wp_unslash( $_GET['form'] ) ) === $args['id'] && '' !== $args['updated_message'] ) {
			printf( wp_kses_post( $args['html_updated_message'] ), esc_html( $args['updated_message'] ) );
		}

		$this->enqueue( $args, $fields );

		if ( $args['form'] ) {
			$attrs = wp_parse_args(
				(array) $args['form_attributes'],
				array(
					'id'     => $args['id'],
					'class'  => 'acf-form wpff-form',
					'action' => '',
					'method' => 'post',
				)
			);
			echo '<form';
			foreach ( $attrs as $name => $value ) {
				printf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
			}
			echo '>';
		}

		wp_nonce_field( self::NONCE_ACTION, '_wpff_nonce', false );
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::ARGS_FIELD ), esc_attr( self::sign( $args ) ) );
		if ( $args['honeypot'] ) {
			printf(
				'<div class="wpff-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>%s <input type="text" name="%s" value="" tabindex="-1" autocomplete="off" /></label></div>',
				esc_html__( 'Leave this field empty', 'freeform-fields' ),
				esc_attr( self::HONEYPOT )
			);
		}

		if ( $result && array() !== $result['errors'] ) {
			echo '<div class="wpff-form__errors" role="alert"><p>' . esc_html__( 'Please correct the highlighted fields.', 'freeform-fields' ) . '</p></div>';
		}

		echo wp_kses_post( $args['html_before_fields'] );
		printf( '<div class="wpff-fields wpff-fields--label-%s">', esc_attr( 'left' === $args['label_placement'] ? 'left' : 'top' ) );
		$this->render_post_inputs( $args, $ref );

		$renderer = new FieldRenderer( Plugin::instance()->types(), Plugin::instance()->values() );
		foreach ( $fields as $field ) {
			if ( $result && array_key_exists( $field['key'], $result['values'] ) ) {
				$field['value'] = $result['values'][ $field['key'] ];
			} else {
				$field['value'] = $ref ? Plugin::instance()->values()->load( $field, $ref ) : $field['default_value'];
			}
			$renderer->render_field( $field, 'field' === $args['instruction_placement'] ? 'field' : 'label' );
		}
		echo '</div>';
		echo wp_kses_post( $args['html_after_fields'] );

		if ( $args['form'] ) {
			echo '<div class="acf-form-submit wpff-form__submit">';
			$button_html = array(
				'input'  => array_fill_keys( array( 'type', 'class', 'value', 'name', 'id' ), true ),
				'button' => array_fill_keys( array( 'type', 'class', 'value', 'name', 'id' ), true ),
				'span'   => array_fill_keys( array( 'class' ), true ),
			);
			echo wp_kses( sprintf( $args['html_submit_button'], esc_attr( $args['submit_value'] ) ), $button_html );
			echo '</div></form>';
		}
	}

	/**
	 * Saves a submitted form, then redirects. Safe to call more than once per request.
	 */
	public function handle(): void {
		if ( $this->handled || ! isset( $_POST[ self::ARGS_FIELD ] ) ) {
			return;
		}
		$this->handled = true;
		if ( ! isset( $_POST['_wpff_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpff_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The form has expired. Please reload the page and try again.', 'freeform-fields' ), '', array( 'response' => 403 ) );
		}
		$args = self::verify( (string) wp_unslash( $_POST[ self::ARGS_FIELD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified signature.
		if ( null === $args ) {
			wp_die( esc_html__( 'Invalid form.', 'freeform-fields' ), '', array( 'response' => 400 ) );
		}
		// Bots fill every input; people never see this one.
		if ( $args['honeypot'] && ! empty( $_POST[ self::HONEYPOT ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field type sanitizes its own value.
		$submitted = isset( $_POST[ FieldRenderer::INPUT_NAME ] ) && is_array( $_POST[ FieldRenderer::INPUT_NAME ] ) ? wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ) : array();
		$fields    = array_column( $this->fields_for( $args ), null, 'key' );
		$ref       = $this->target( $args );
		$result    = ( new Validator( Plugin::instance()->types() ) )->validate( $submitted, $fields, $ref ? $ref->to_string() : 'new_post' );

		if ( array() !== $result['errors'] ) {
			$this->results[ $args['id'] ] = $result;
			add_action( 'wp_footer', fn() => $this->print_errors( $result['errors'] ), 100 );
			return;
		}

		$post_id = $this->save_post( $args, $ref );
		if ( ! $post_id ) {
			return;
		}
		$ref = ObjectRef::parse( $post_id );
		foreach ( $result['values'] as $key => $value ) {
			if ( isset( $fields[ $key ] ) && $ref ) {
				Plugin::instance()->values()->save( $fields[ $key ], $value, $ref );
			}
		}

		$fake_acf = Plugin::compat_enabled() && ! isset( $_POST['acf'] );
		if ( $fake_acf ) {
			$_POST['acf'] = $submitted; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only copy for ACF callbacks.
		}
		Hooks::action( 'save_post', $post_id, array() );
		Hooks::action( 'submit_form', $args, $post_id );
		if ( $fake_acf ) {
			unset( $_POST['acf'] );
		}

		$return = '' !== $args['return'] ? (string) $args['return'] : add_query_arg(
			array(
				'updated' => 'true',
				'form'    => $args['id'],
			),
			self::current_url()
		);
		if ( is_numeric( $post_id ) ) {
			$return = str_replace( array( '%post_id%', '%post_url%' ), array( (string) $post_id, (string) get_permalink( (int) $post_id ) ), $return );
		}
		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * Creates the post for `new_post` forms and saves title/content; returns the object id.
	 *
	 * @param array<string, mixed> $args Settings.
	 * @param ObjectRef|null       $ref  Existing target.
	 * @return int|string|null
	 */
	private function save_post( array $args, ?ObjectRef $ref ) {
		$post_data = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		if ( $args['post_title'] && isset( $_POST[ self::POST_INPUT ]['post_title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( wp_unslash( $_POST[ self::POST_INPUT ]['post_title'] ) );
		}
		if ( $args['post_content'] && isset( $_POST[ self::POST_INPUT ]['post_content'] ) ) {
			$content                   = (string) wp_unslash( $_POST[ self::POST_INPUT ]['post_content'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- kses below.
			$post_data['post_content'] = $args['kses'] || ! current_user_can( 'unfiltered_html' ) ? wp_kses_post( $content ) : $content;
		}
		// phpcs:enable

		if ( 'new_post' === $args['post_id'] ) {
			$new = is_array( $args['new_post'] ) ? $args['new_post'] : array();
			$new = $post_data + $new + array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			);
			// ACF's hook for creating the object yourself: return its id.
			$filtered = Hooks::filter( 'pre_save_post', 'new_post', $args );
			if ( 'new_post' !== $filtered ) {
				return $filtered;
			}
			$id = wp_insert_post( wp_slash( $new ), true );
			return is_wp_error( $id ) ? null : (int) $id;
		}
		if ( ! $ref ) {
			return null;
		}
		$id = Hooks::filter( 'pre_save_post', $ref->to_string(), $args );
		if ( 'post' === $ref->type && array() !== $post_data ) {
			wp_update_post( wp_slash( array( 'ID' => (int) $ref->id ) + $post_data ) );
		}
		return $id;
	}

	/**
	 * @param array<string, mixed> $args Settings.
	 */
	private function render_post_inputs( array $args, ?ObjectRef $ref ): void {
		$post = $ref && 'post' === $ref->type ? get_post( (int) $ref->id ) : null;
		// After a rejected submission, show what the visitor typed.
		$sent = isset( $this->results[ $args['id'] ], $_POST[ self::POST_INPUT ] ) && is_array( $_POST[ self::POST_INPUT ] ) ? wp_unslash( $_POST[ self::POST_INPUT ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in handle(); escaped on output.
		$post = $sent ? (object) array(
			'post_title'   => (string) ( $sent['post_title'] ?? '' ),
			'post_content' => (string) ( $sent['post_content'] ?? '' ),
		) : $post;
		if ( $args['post_title'] ) {
			printf(
				'<div class="wpff-field"><div class="wpff-label"><label for="%1$s-title">%2$s</label></div><div class="wpff-input"><input type="text" id="%1$s-title" name="%3$s[post_title]" value="%4$s" class="wpff-control" required /></div></div>',
				esc_attr( $args['id'] ),
				esc_html__( 'Title', 'freeform-fields' ),
				esc_attr( self::POST_INPUT ),
				esc_attr( $post ? $post->post_title : '' )
			);
		}
		if ( $args['post_content'] ) {
			printf(
				'<div class="wpff-field"><div class="wpff-label"><label for="%1$s-content">%2$s</label></div><div class="wpff-input"><textarea id="%1$s-content" name="%3$s[post_content]" rows="8" class="wpff-control">%4$s</textarea></div></div>',
				esc_attr( $args['id'] ),
				esc_html__( 'Content', 'freeform-fields' ),
				esc_attr( self::POST_INPUT ),
				esc_textarea( $post ? $post->post_content : '' )
			);
		}
	}

	/**
	 * Fields shown by a form: `fields`, else `field_groups`, else the groups for the target.
	 *
	 * @param array<string, mixed> $args Settings.
	 * @return array<int, array<string, mixed>>
	 */
	private function fields_for( array $args ): array {
		$registry = Plugin::instance()->fields();
		if ( is_array( $args['fields'] ) ) {
			$out = array();
			foreach ( $args['fields'] as $selector ) {
				$field = wpff_get_field_settings( (string) $selector );
				if ( $field ) {
					$out[] = $field;
				}
			}
			return $out;
		}
		$groups = array();
		if ( is_array( $args['field_groups'] ) ) {
			foreach ( $args['field_groups'] as $key ) {
				$group = $registry->get_group( (string) $key );
				if ( $group ) {
					$groups[] = $group;
				}
			}
		} else {
			$ref    = $this->target( $args );
			$screen = $ref && 'post' === $ref->type ? array( 'post_id' => (int) $ref->id ) : array( 'post_type' => (string) ( $args['new_post']['post_type'] ?? 'post' ) );
			$groups = ( new FormSupport( Plugin::instance() ) )->groups_for( $screen );
		}
		$fields = array();
		foreach ( $groups as $group ) {
			$fields = array_merge( $fields, $group['fields'] );
		}
		return $fields;
	}

	/**
	 * @param array<string, mixed> $args Settings.
	 */
	private function target( array $args ): ?ObjectRef {
		return 'new_post' === $args['post_id'] ? null : ObjectRef::parse( $args['post_id'] );
	}

	/**
	 * @param array<string, mixed> $args Raw settings.
	 * @return array<string, mixed>
	 */
	private function normalize( array $args ): array {
		$args = wp_parse_args( $args, self::defaults() );
		if ( false === $args['post_id'] ) {
			$args['post_id'] = (int) get_the_ID();
		}
		$args['id'] = sanitize_key( (string) $args['id'] );
		return $args;
	}

	/**
	 * @param array<string, mixed> $args   Settings.
	 * @param array<int, array<string, mixed>> $fields Fields.
	 */
	private function enqueue( array $args, array $fields ): void {
		$types = array_values( array_unique( array_column( $fields, 'type' ) ) );
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		Assets::enqueue_form(
			Plugin::instance(),
			$types,
			array(
				'object'       => is_numeric( $args['post_id'] ) ? 'post_' . $args['post_id'] : '',
				'postId'       => is_numeric( $args['post_id'] ) ? (int) $args['post_id'] : 0,
				// Validation runs on submit on the server; no AJAX pre-check for visitors.
				'formSelector' => '#__wpff-no-ajax-validation',
			)
		);
	}

	/**
	 * Highlights rejected fields after a failed submission.
	 *
	 * @param array<string, string> $errors Errors by field path.
	 */
	private function print_errors( array $errors ): void {
		printf(
			'<script>window.addEventListener("load",function(){if(window.wpff&&window.wpff.showErrors){window.wpff.showErrors(%s);}});</script>',
			wp_json_encode( $errors, JSON_HEX_TAG | JSON_HEX_AMP )
		);
	}

	/**
	 * URL of the page the form was posted to (the form's action is the page itself).
	 */
	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url  = '' !== $host ? set_url_scheme( 'http://' . $host . $uri ) : home_url( '/' );
		return remove_query_arg( array( 'updated', 'form' ), $url );
	}

	/**
	 * Settings that decide what a submission may change, signed with the site's salt.
	 *
	 * @param array<string, mixed> $args Settings.
	 */
	private static function sign( array $args ): string {
		$keep = array_intersect_key( $args, array_flip( array( 'id', 'post_id', 'new_post', 'field_groups', 'fields', 'post_title', 'post_content', 'return', 'honeypot', 'kses' ) ) );
		$json = (string) wp_json_encode( $keep );
		return base64_encode( $json ) . '.' . hash_hmac( 'sha256', $json, wp_salt( 'nonce' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding.
	}

	/**
	 * @param string $signed Signed settings.
	 * @return array<string, mixed>|null
	 */
	private static function verify( string $signed ): ?array {
		$parts = explode( '.', $signed, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$json = base64_decode( $parts[0], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding.
		if ( false === $json || ! hash_equals( hash_hmac( 'sha256', $json, wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return null;
		}
		$args = json_decode( $json, true );
		return is_array( $args ) ? wp_parse_args( $args, self::defaults() ) : null;
	}
}
