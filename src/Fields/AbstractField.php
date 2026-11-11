<?php
/**
 * Base class for field types.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A field type knows how to render, sanitize, validate, store and format one kind of value.
 *
 * Lifecycle of a value:
 *  - saving:  raw $_POST value -> sanitize() -> validate_value() -> update_value() -> storage
 *  - reading: storage -> load_value() -> format_value() -> template
 */
abstract class AbstractField {

	/**
	 * Machine name, e.g. `text`. Stored in field settings as `type`.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Category for the field picker in the builder.
	 *
	 * @var string
	 */
	public string $category = 'basic';

	/**
	 * Human-readable name.
	 */
	abstract public function label(): string;

	/**
	 * Whether the field stores a value. Layout fields (tab, accordion, message) do not.
	 */
	public function has_value(): bool {
		return true;
	}

	/**
	 * How a field with sub fields stores its value, or null for plain fields:
	 *
	 *  - `group`:   one set of sub fields, stored as `{name}_{sub}`;
	 *  - `rows`:    repeated sub fields, `{name}` = row count, values in `{name}_{i}_{sub}`;
	 *  - `layouts`: rows of different layouts, `{name}` = list of layout names, values in `{name}_{i}_{sub}`.
	 *
	 * This is exactly ACF's layout, so existing ACF data reads as-is.
	 */
	public function compound(): ?string {
		return null;
	}

	/**
	 * Whether the field contains `sub_fields` (or layouts with sub fields).
	 */
	public function has_sub_fields(): bool {
		return null !== $this->compound();
	}

	/**
	 * Type-specific settings and their defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Type-specific settings shown in the field group builder and accepted on save.
	 *
	 * Each item: `name`, `label`, `control` and optionally `help`, `options` (for `select`)
	 * and `show_if` (map of other setting => required value).
	 *
	 * Controls: `text`, `textarea`, `number`, `toggle`, `select`, `multiselect` (list of option keys),
	 * `choices` (value : Label lines), `lines` (one value per line, stored as a list).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings(): array {
		return array();
	}

	/**
	 * Prints the input HTML. `$field['value']`, `$field['input_name']` and `$field['input_id']` are set.
	 *
	 * @param array<string, mixed> $field Field settings.
	 */
	abstract public function render_input( array $field ): void;

	/**
	 * Converts the raw submitted value into the value to store.
	 *
	 * @param mixed                $value Unslashed value from the request.
	 * @param array<string, mixed> $field Field settings.
	 * @return mixed
	 */
	public function sanitize( $value, array $field ) {
		return is_scalar( $value ) ? self::sanitize_html( (string) $value ) : '';
	}

	/**
	 * Type-specific validation. Required-ness is checked before this.
	 *
	 * @param mixed                $value Sanitized value.
	 * @param array<string, mixed> $field Field settings.
	 * @return true|string True when valid, otherwise an error message.
	 */
	public function validate_value( $value, array $field ) {
		return true;
	}

	/**
	 * Whether the value counts as empty for the `required` check.
	 *
	 * @param mixed $value Sanitized value.
	 */
	public function is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			return array() === array_filter( $value, static fn( $v ) => '' !== $v && null !== $v );
		}
		return null === $value || '' === $value || false === $value;
	}

	/**
	 * Adjusts a value right before it is written to storage.
	 *
	 * @param mixed                $value     Value to store.
	 * @param int|string           $object_id ACF-style object id.
	 * @param array<string, mixed> $field     Field settings.
	 * @return mixed
	 */
	public function update_value( $value, $object_id, array $field ) {
		return $value;
	}

	/**
	 * Adjusts a value right after it is read from storage.
	 *
	 * @param mixed                $value     Stored value.
	 * @param int|string           $object_id ACF-style object id.
	 * @param array<string, mixed> $field     Field settings.
	 * @return mixed
	 */
	public function load_value( $value, $object_id, array $field ) {
		return $value;
	}

	/**
	 * Converts a loaded value into what templates receive from get_field().
	 *
	 * @param mixed                $value     Loaded value.
	 * @param int|string           $object_id ACF-style object id.
	 * @param array<string, mixed> $field     Field settings.
	 * @return mixed
	 */
	public function format_value( $value, $object_id, array $field ) {
		return $value;
	}

	/**
	 * Enqueues scripts the input needs (called once per screen per type).
	 */
	public function enqueue_assets(): void {}

	/**
	 * Applies `wpff/fields/{type}/query` (and `acf/fields/{type}/query` in compatibility mode)
	 * with /name= and /key= variants to a relational field's search arguments.
	 *
	 * @param array<string, mixed> $args  Query arguments.
	 * @param array<string, mixed> $field Field.
	 * @return array<string, mixed>
	 */
	protected static function query_filter( array $args, array $field ): array {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- context for filters only.
		$suffix  = 'fields/' . $field['type'] . '/query';
		foreach ( \FreeformFields\Hooks::prefixes() as $prefix ) {
			$args = apply_filters( "{$prefix}/{$suffix}", $args, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			foreach ( array( 'name', 'key' ) as $prop ) {
				$args = apply_filters( "{$prefix}/{$suffix}/{$prop}={$field[ $prop ]}", $args, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			}
		}
		return is_array( $args ) ? $args : array();
	}

	/**
	 * Whether a picked post (or attachment) may be returned by format_value() to the current user:
	 * published ones always, others only when the user can read them. Without it, a contributor
	 * could put a private post's ID into a block's data and see its title in the preview.
	 * Filter `wpff/format/readable_post` (bool, $post, $field) to change the answer.
	 *
	 * @param \WP_Post             $post  Post or attachment.
	 * @param array<string, mixed> $field Field.
	 */
	protected static function can_show_post( \WP_Post $post, array $field ): bool {
		if ( 'attachment' === $post->post_type ) {
			// Media files are public anyway; only those of private posts are hidden. Files
			// uploaded to drafts or to posts deleted since keep showing, as before.
			$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
			$hidden = 'private' === $post->post_status || ( $parent && 'private' === $parent->post_status );
		} else {
			$hidden = 'publish' !== $post->post_status;
		}
		$readable = ! $hidden || current_user_can( 'read_post', $post->ID );
		return (bool) apply_filters( 'wpff/format/readable_post', $readable, $post, $field );
	}

	/**
	 * Attachment ID to show in an input, or 0 when it is not an attachment the user may see.
	 * Inputs can be rendered from unvalidated data (the block form endpoint), so the URL of
	 * a media file must not be printed for an ID the user could not have selected.
	 *
	 * @param mixed                $id    Value.
	 * @param array<string, mixed> $field Field.
	 */
	protected static function shown_attachment( $id, array $field ): int {
		$id   = absint( is_scalar( $id ) ? $id : 0 );
		$post = $id ? get_post( $id ) : null;
		return $post && 'attachment' === $post->post_type && self::can_show_post( $post, $field ) ? $id : 0;
	}

	/**
	 * Builds one settings() item.
	 *
	 * @param string               $name    Setting key.
	 * @param string               $label   Label.
	 * @param string               $control Control type.
	 * @param array<string, mixed> $extra   help, options, show_if.
	 * @return array<string, mixed>
	 */
	protected static function setting( string $name, string $label, string $control = 'text', array $extra = array() ): array {
		return array(
			'name'    => $name,
			'label'   => $label,
			'control' => $control,
		) + $extra;
	}

	/**
	 * Allows HTML only for users who may post unfiltered HTML.
	 *
	 * @param string $value Raw string.
	 */
	protected static function sanitize_html( string $value ): string {
		return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
	}

	/**
	 * Escapes a stored value for a `value="…"` attribute without losing anything on the way back.
	 *
	 * esc_attr() leaves existing entities alone, so a stored `&amp;` would come back from the
	 * form as `&`. Encoding every `&` makes "open and save without changes" change nothing.
	 *
	 * @param mixed $value Value.
	 */
	public static function value_attr( $value ): string {
		return htmlspecialchars( is_scalar( $value ) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	}

	/**
	 * Builds an HTML attribute string, skipping null/false values.
	 *
	 * @param array<string, scalar|null> $attrs Attributes.
	 */
	protected static function attrs( array $attrs ): string {
		$html = '';
		foreach ( $attrs as $name => $value ) {
			if ( null === $value || false === $value || '' === $value ) {
				continue;
			}
			if ( true === $value ) {
				$html .= ' ' . esc_attr( $name );
				continue;
			}
			$html .= sprintf( ' %s="%s"', esc_attr( $name ), 'value' === $name ? self::value_attr( $value ) : esc_attr( (string) $value ) );
		}
		return $html;
	}

	/**
	 * Prints optional prepend/append wrappers around a text-like input.
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @param string               $input Escaped input HTML.
	 */
	protected static function print_affixed( array $field, string $input ): void {
		$prepend = (string) ( $field['prepend'] ?? '' );
		$append  = (string) ( $field['append'] ?? '' );
		if ( '' === $prepend && '' === $append ) {
			echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			return;
		}
		echo '<div class="wpff-affixed">';
		if ( '' !== $prepend ) {
			echo '<span class="wpff-prepend">' . esc_html( $prepend ) . '</span>';
		}
		echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( '' !== $append ) {
			echo '<span class="wpff-append">' . esc_html( $append ) . '</span>';
		}
		echo '</div>';
	}
}
