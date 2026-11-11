<?php
/**
 * Support for multilingual sites (WPML and Polylang).
 *
 * @package FreeformFields
 */

namespace FreeformFields\Multilingual;

use FreeformFields\Hooks;
use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Makes fields work with WPML or Polylang the way ACF does with ACFML:
 *
 *  - options pages keep one set of values per language (`options_{lang}_…`, ACF's layout);
 *  - each field is copied, copied once, translated or left alone between translations of a post
 *    or term, following its group's translation mode (see Preferences);
 *  - IDs picked in relational, image and file fields point at the translation in the target language;
 *  - field labels, instructions and choices are translatable strings;
 *  - location rules on a page or post also match its translations.
 */
final class Multilingual {

	private static ?Provider $provider = null;

	private static bool $detected = false;

	private Preferences $preferences;

	/**
	 * Values of our fields copied by WPML into a term's translations, by term: fixed afterwards.
	 *
	 * @var array<int, true>
	 */
	private array $saved_terms = array();

	public function __construct( private Plugin $plugin ) {
		$this->preferences = new Preferences( $plugin->fields() );
	}

	/* ---- Provider ------------------------------------------------------ */

	/**
	 * The active multilingual plugin, or null.
	 */
	public static function provider(): ?Provider {
		if ( ! self::$detected && did_action( 'plugins_loaded' ) ) {
			self::$detected = true;
			$provider       = null;
			if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_current_language' ) ) {
				$provider = new PolylangProvider();
			} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_current_language' ) ) {
				$provider = new WpmlProvider();
			}
			/**
			 * Filters the multilingual plugin adapter. Return null to turn multilingual support off.
			 *
			 * @param Provider|null $provider Detected adapter.
			 */
			$provider       = apply_filters( 'wpff/multilingual/provider', $provider );
			self::$provider = $provider instanceof Provider ? $provider : null;
		}
		return self::$provider;
	}

	/**
	 * Sets the adapter (tests), or null to detect it again.
	 *
	 * @param Provider|null $provider Adapter.
	 */
	public static function set_provider( ?Provider $provider ): void {
		self::$provider = $provider;
		self::$detected = null !== $provider;
	}

	public static function active(): bool {
		return null !== self::provider();
	}

	/**
	 * Language of the request, falling back to the default language ("All languages" in the admin).
	 */
	public static function current_language(): ?string {
		$provider = self::provider();
		if ( ! $provider ) {
			return null;
		}
		return $provider->current_language() ?? $provider->default_language();
	}

	/* ---- Options ------------------------------------------------------- */

	/**
	 * Storage target of an options prefix in the current language.
	 *
	 * Like ACF, values of `options` are stored per language: the default language uses
	 * `options_{name}`, others `options_{lang}_{name}`. A language with no value of its own
	 * reads the default language's value.
	 *
	 * @param string $prefix Options prefix (post_id), e.g. `options`.
	 */
	public static function options_ref( string $prefix ): ObjectRef {
		$provider = self::provider();
		if ( ! $provider ) {
			return new ObjectRef( 'option', $prefix );
		}
		/**
		 * Filters whether values of an options prefix are stored per language. Default: only `options`, as ACF does.
		 *
		 * @param bool   $localize Whether to store per language.
		 * @param string $prefix   Options prefix.
		 */
		if ( ! apply_filters( 'wpff/multilingual/localize_options', 'options' === $prefix, $prefix ) ) {
			return new ObjectRef( 'option', $prefix );
		}
		$lang    = $provider->current_language();
		$default = $provider->default_language();
		if ( null === $lang || null === $default || $lang === $default ) {
			return new ObjectRef( 'option', $prefix );
		}
		/**
		 * Filters whether a language without its own option values shows the default language's values.
		 *
		 * @param bool   $fallback Default true.
		 * @param string $prefix   Options prefix.
		 * @param string $lang     Current language.
		 */
		$fallback = apply_filters( 'wpff/multilingual/options_fallback', true, $prefix, $lang )
			? new ObjectRef( 'option', $prefix )
			: null;
		return new ObjectRef( 'option', $prefix . '_' . $lang, $fallback );
	}

	/* ---- IDs ----------------------------------------------------------- */

	/**
	 * Field types whose values are IDs, with the kind of object they point at.
	 *
	 * @return array<string, string> Type => `post` or `term`.
	 */
	public static function id_types(): array {
		/**
		 * Filters the field types whose values are post or term IDs that follow translations.
		 *
		 * @param array<string, string> $types Type => `post` or `term`.
		 */
		return (array) apply_filters(
			'wpff/multilingual/id_types',
			array(
				'post_object'  => 'post',
				'relationship' => 'post',
				'page_link'    => 'post',
				'image'        => 'post',
				'file'         => 'post',
				'gallery'      => 'post',
				'taxonomy'     => 'term',
			)
		);
	}

	/**
	 * The translation of a post or term, or the ID itself when there is none.
	 *
	 * @param int    $id   Post or term ID.
	 * @param string $kind `post` or `term`.
	 * @param string $lang Target language.
	 */
	public static function translate_id( int $id, string $kind, string $lang ): int {
		$provider = self::provider();
		if ( ! $provider || $id <= 0 ) {
			return $id;
		}
		$translations = 'term' === $kind ? $provider->term_translations( $id ) : $provider->post_translations( $id );
		return (int) ( $translations[ $lang ] ?? $id );
	}

	/**
	 * A raw value of an ID field with each ID replaced by its translation in `$lang`.
	 * Values of other fields are returned unchanged.
	 *
	 * @param mixed                $value Raw value: an ID, a list of IDs or a URL (page link).
	 * @param array<string, mixed> $field Field.
	 * @param string               $lang  Target language.
	 * @return mixed
	 */
	public static function translate_value( $value, array $field, string $lang ) {
		$kind = self::id_types()[ (string) ( $field['type'] ?? '' ) ] ?? null;
		if ( null === $kind || ! self::active() ) {
			return $value;
		}
		$one = static function ( $item ) use ( $kind, $lang ) {
			if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				$tr = self::translate_id( (int) $item, $kind, $lang );
				return is_int( $item ) ? $tr : (string) $tr;
			}
			return $item;
		};
		return is_array( $value ) ? array_map( $one, $value ) : $one( $value );
	}

	/**
	 * A loaded ID value with IDs replaced by their translations in the current language, for display.
	 *
	 * @param mixed                $value Loaded value.
	 * @param array<string, mixed> $field Field.
	 * @return mixed
	 */
	public static function localize( $value, array $field ) {
		$provider = self::provider();
		if ( ! $provider || null === $value || '' === $value || ! isset( self::id_types()[ (string) ( $field['type'] ?? '' ) ] ) ) {
			return $value;
		}
		$lang = $provider->current_language();
		/**
		 * Filters whether IDs of posts, terms and media shown from a field are replaced by their
		 * translations in the current language.
		 *
		 * @param bool  $translate Default true.
		 * @param array $field     Field.
		 */
		if ( null === $lang || ! apply_filters( 'wpff/multilingual/translate_ids_on_display', true, $field ) ) {
			return $value;
		}
		return self::translate_value( $value, $field, $lang );
	}

	/**
	 * Whether two posts are the same post or translations of each other.
	 *
	 * @param int $a Post ID.
	 * @param int $b Post ID.
	 */
	public static function same_post( int $a, int $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$provider = self::provider();
		if ( ! $provider || $a <= 0 || $b <= 0 ) {
			return false;
		}
		return in_array( $b, $provider->post_translations( $a ), true );
	}

	/* ---- Hooks --------------------------------------------------------- */

	public function preferences(): Preferences {
		return $this->preferences;
	}

	/**
	 * Registers hooks. Each callback does nothing while no multilingual plugin is active.
	 */
	public function register(): void {
		add_action( 'wpff/save_field_group', array( $this, 'group_saved' ) );
		add_action( 'wpff/save_post', array( $this, 'object_saved' ), 20 );
		add_filter( 'wpff/load_field', array( $this, 'translate_field_strings' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_sync' ), 20 );

		// Polylang.
		add_filter( 'pll_copy_post_metas', array( $this, 'pll_post_metas' ), 20, 5 );
		add_filter( 'pll_copy_term_metas', array( $this, 'pll_term_metas' ), 20, 5 );
		add_filter( 'pll_translate_post_meta', array( $this, 'pll_translate_post_meta' ), 10, 5 );
		add_filter( 'pll_translate_term_meta', array( $this, 'pll_translate_term_meta' ), 10, 5 );
		add_filter( 'pll_get_post_types', array( $this, 'pll_exclude_post_types' ), 20, 2 );

		// WPML.
		add_filter( 'wpml_sync_custom_field_copied_value', array( $this, 'wpml_copied_value' ), 10, 4 );
		add_action( 'edited_term', array( $this, 'term_saved' ), 5, 3 );
		add_action( 'created_term', array( $this, 'term_saved' ), 5, 3 );
		add_action( 'shutdown', array( $this, 'fix_term_copies' ) );
	}

	/**
	 * A group was saved: preferences changed; register its strings and meta keys.
	 *
	 * @param array<string, mixed> $group Saved group.
	 */
	public function group_saved( $group ): void {
		$this->preferences->flush();
		if ( ! self::active() ) {
			return;
		}
		$group = $this->plugin->fields()->get_group( (string) ( $group['key'] ?? '' ) ) ?? (array) $group;
		$this->register_group_strings( $group );
		if ( 'wpml' === self::provider()->name() ) {
			$this->wpml_register_keys( $this->stored_keys( $this->field_keys( (array) ( $group['fields'] ?? array() ) ) ) );
		}
	}

	/**
	 * Values were saved from a form: tell WPML how to handle the meta keys they use.
	 *
	 * @param string $object_id Object id (`123`, `term_5`, `options`…).
	 */
	public function object_saved( $object_id ): void {
		$provider = self::provider();
		if ( ! $provider || 'wpml' !== $provider->name() ) {
			return;
		}
		$ref = ObjectRef::parse( $object_id );
		if ( ! $ref || ! in_array( $ref->type, array( 'post', 'term' ), true ) ) {
			return;
		}
		$keys = $this->preferences->for_references( $this->plugin->values()->store()->references( $ref ) );
		$this->wpml_register_keys( array( $ref->type => $keys ) );
	}

	/**
	 * Once after the field groups or the multilingual plugin change: registers strings of all
	 * groups and, with WPML, the translation preference of every meta key already stored.
	 */
	public function maybe_sync(): void {
		if ( ! self::active() || wp_doing_ajax() || ! current_user_can( Plugin::admin_capability() ) ) {
			return;
		}
		$groups = $this->plugin->fields()->get_all_groups();
		$stamp  = md5( self::provider()->name() . '|' . (string) wp_json_encode( $groups ) );
		if ( get_option( 'wpff_multilingual_synced' ) === $stamp ) {
			return;
		}
		foreach ( $groups as $group ) {
			$this->register_group_strings( $group );
		}
		if ( 'wpml' === self::provider()->name() ) {
			$keys = array();
			foreach ( $groups as $group ) {
				$keys = array_merge( $keys, $this->field_keys( (array) $group['fields'] ) );
			}
			$this->wpml_register_keys( $this->stored_keys( $keys ) );
		}
		update_option( 'wpff_multilingual_synced', $stamp, false );
	}

	/* ---- Strings ------------------------------------------------------- */

	/**
	 * Settings of a field that are text shown to editors.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return array<string, string> Setting => text. Choices appear as `choices:{value}`.
	 */
	private static function field_strings( array $field ): array {
		$strings = array();
		foreach ( array( 'label', 'instructions', 'placeholder', 'prepend', 'append', 'message', 'button_label', 'ui_on_text', 'ui_off_text' ) as $setting ) {
			if ( isset( $field[ $setting ] ) && is_string( $field[ $setting ] ) && '' !== trim( $field[ $setting ] ) ) {
				$strings[ $setting ] = $field[ $setting ];
			}
		}
		foreach ( (array) ( $field['choices'] ?? array() ) as $value => $label ) {
			if ( is_string( $label ) && '' !== trim( $label ) ) {
				$strings[ 'choices:' . $value ] = $label;
			}
		}
		return $strings;
	}

	/**
	 * String context of a group.
	 *
	 * @param string $group_key Group key.
	 */
	private static function context( string $group_key ): string {
		return 'WP Freeform Fields: ' . $group_key;
	}

	/**
	 * Registers the labels, instructions and choices of a group's fields for translation.
	 *
	 * @param array<string, mixed> $group Group.
	 */
	private function register_group_strings( array $group ): void {
		$provider = self::provider();
		if ( ! $provider || empty( $group['key'] ) ) {
			return;
		}
		$context = self::context( (string) $group['key'] );
		if ( '' !== (string) ( $group['title'] ?? '' ) ) {
			$provider->register_string( $context, 'title', (string) $group['title'] );
		}
		$walk = function ( array $fields ) use ( &$walk, $provider, $context ): void {
			foreach ( $fields as $field ) {
				foreach ( self::field_strings( $field ) as $setting => $text ) {
					$provider->register_string( $context, $field['key'] . ':' . $setting, $text );
				}
				$walk( (array) ( $field['sub_fields'] ?? array() ) );
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					if ( '' !== (string) ( $layout['label'] ?? '' ) ) {
						$provider->register_string( $context, $field['key'] . ':layout:' . $layout['name'], (string) $layout['label'] );
					}
					$walk( (array) ( $layout['sub_fields'] ?? array() ) );
				}
			}
		};
		$walk( (array) ( $group['fields'] ?? array() ) );
	}

	/**
	 * Translates a field's labels, instructions and choices (on `wpff/load_field`).
	 *
	 * @param array<string, mixed> $field Field.
	 * @return array<string, mixed>
	 */
	public function translate_field_strings( $field ) {
		$provider = self::provider();
		if ( ! $provider || ! is_array( $field ) || empty( $field['key'] ) ) {
			return $field;
		}
		$group = $this->group_of( $field );
		if ( null === $group ) {
			return $field;
		}
		$context = self::context( $group );
		foreach ( self::field_strings( $field ) as $setting => $text ) {
			$translated = $provider->translate_string( $context, $field['key'] . ':' . $setting, $text );
			if ( str_starts_with( $setting, 'choices:' ) ) {
				$field['choices'][ substr( $setting, 8 ) ] = $translated;
			} else {
				$field[ $setting ] = $translated;
			}
		}
		foreach ( (array) ( $field['layouts'] ?? array() ) as $i => $layout ) {
			if ( '' !== (string) ( $layout['label'] ?? '' ) ) {
				$field['layouts'][ $i ]['label'] = $provider->translate_string( $context, $field['key'] . ':layout:' . $layout['name'], (string) $layout['label'] );
			}
		}
		return $field;
	}

	/**
	 * A group title in the current language.
	 *
	 * @param array<string, mixed> $group Group.
	 */
	public static function group_title( array $group ): string {
		$title    = (string) ( $group['title'] ?? '' );
		$provider = self::provider();
		if ( ! $provider || '' === $title ) {
			return $title;
		}
		return $provider->translate_string( self::context( (string) $group['key'] ), 'title', $title );
	}

	/**
	 * Key of the group a field belongs to, following parents up.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private function group_of( array $field ): ?string {
		$parent = (string) ( $field['parent'] ?? '' );
		$depth  = 0;
		while ( $depth < 20 && str_starts_with( $parent, 'field_' ) ) {
			$up     = $this->plugin->fields()->get_field( $parent );
			$parent = $up ? (string) ( $up['parent'] ?? '' ) : '';
			++$depth;
		}
		return '' !== $parent ? $parent : null;
	}

	/* ---- Meta keys ----------------------------------------------------- */

	/**
	 * Keys of fields and their sub fields.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @return string[]
	 */
	private function field_keys( array $fields ): array {
		$keys = array();
		foreach ( $fields as $field ) {
			$keys[] = (string) $field['key'];
			$keys   = array_merge( $keys, $this->field_keys( (array) ( $field['sub_fields'] ?? array() ) ) );
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$keys = array_merge( $keys, $this->field_keys( (array) ( $layout['sub_fields'] ?? array() ) ) );
			}
		}
		return $keys;
	}

	/**
	 * Meta keys stored in the database for the given fields, with their preferences.
	 *
	 * @param string[] $field_keys Field keys.
	 * @return array{post: array<string, int>, term: array<string, int>}
	 */
	private function stored_keys( array $field_keys ): array {
		global $wpdb;
		$out        = array(
			'post' => array(),
			'term' => array(),
		);
		$field_keys = array_values( array_unique( array_filter( $field_keys ) ) );
		if ( array() === $field_keys ) {
			return $out;
		}
		foreach ( array_chunk( $field_keys, 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( array(
				'post' => $wpdb->postmeta,
				'term' => $wpdb->termmeta,
			) as $type => $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name and placeholders are built above.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT meta_key, meta_value FROM {$table} WHERE meta_key LIKE %s AND meta_value IN ({$in})", array_merge( array( $wpdb->esc_like( '_' ) . '%' ), $chunk ) ) );
				$refs = array();
				foreach ( $rows as $row ) {
					$refs[ substr( (string) $row->meta_key, 1 ) ] = (string) $row->meta_value;
				}
				$out[ $type ] += $this->preferences->for_references( $refs );
			}
		}
		return $out;
	}

	/**
	 * Saves translation preferences of meta keys in WPML's settings (only those that changed).
	 *
	 * @param array<string, array<string, int>> $keys `post` / `term` => meta key => preference.
	 */
	private function wpml_register_keys( array $keys ): void {
		if ( ! function_exists( 'wpml_load_core_tm' ) ) {
			return;
		}
		$tm = wpml_load_core_tm();
		if ( ! is_object( $tm ) || ! method_exists( $tm, 'get_settings' ) || ! method_exists( $tm, 'save_settings' ) ) {
			return;
		}
		$tm->get_settings();
		$changed = false;
		$indexes = array(
			'post' => 'custom_fields_translation',
			'term' => 'custom_term_fields_translation',
		);
		foreach ( $indexes as $type => $index ) {
			foreach ( $keys[ $type ] ?? array() as $meta_key => $pref ) {
				if ( ! isset( $tm->settings[ $index ][ $meta_key ] ) || (int) $tm->settings[ $index ][ $meta_key ] !== $pref ) {
					$tm->settings[ $index ][ $meta_key ] = $pref;
					$changed                             = true;
				}
			}
		}
		if ( $changed ) {
			$tm->save_settings();
		}
	}

	/**
	 * Preferences of our values stored on an object: meta key => preference.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return array<string, int>
	 */
	private function object_keys( string $type, int $id ): array {
		return $this->preferences->for_references( $this->plugin->values()->store()->references( new ObjectRef( $type, $id ) ) );
	}

	/**
	 * The field a stored meta key belongs to, from its `_{key}` reference.
	 *
	 * @param string $type     `post` or `term`.
	 * @param int    $id       Object ID.
	 * @param string $meta_key Meta key.
	 * @return array<string, mixed>|null
	 */
	private function field_of_meta( string $type, int $id, string $meta_key ): ?array {
		if ( str_starts_with( $meta_key, '_' ) ) {
			return null;
		}
		$key = get_metadata( $type, $id, '_' . $meta_key, true );
		if ( ! is_string( $key ) || ! str_starts_with( $key, 'field_' ) ) {
			return null;
		}
		return $this->plugin->fields()->get_field( $key );
	}

	/* ---- Polylang ------------------------------------------------------ */

	/**
	 * Meta keys Polylang copies (new translation) or keeps in sync (existing translations).
	 *
	 * Our values follow their field's preference: when a translation is created, everything
	 * except "don't translate" is copied (translatable text as a starting point); afterwards
	 * only "copy" values stay synchronized.
	 *
	 * @param string[] $keys Keys chosen by Polylang.
	 * @param bool     $sync True to synchronize, false to copy once.
	 * @param int      $from Source object.
	 * @param string   $type `post` or `term`.
	 * @return string[]
	 */
	private function pll_metas( $keys, $sync, $from, string $type ): array {
		$keys = (array) $keys;
		if ( ! self::active() || ! $from ) {
			return $keys;
		}
		$ours = $this->object_keys( $type, (int) $from );
		if ( array() === $ours ) {
			return $keys;
		}
		$keys = array_values( array_diff( $keys, array_keys( $ours ) ) );
		foreach ( $ours as $meta_key => $pref ) {
			$copy = $sync
				? Preferences::COPY === $pref
				: Preferences::IGNORE !== $pref;
			if ( $copy ) {
				$keys[] = (string) $meta_key;
			}
		}
		return $keys;
	}

	/**
	 * @param string[] $keys Keys.
	 * @param bool     $sync Synchronization or copy.
	 * @param int      $from Source post.
	 * @return string[]
	 */
	public function pll_post_metas( $keys, $sync = false, $from = 0 ): array {
		return $this->pll_metas( $keys, $sync, $from, 'post' );
	}

	/**
	 * @param string[] $keys Keys.
	 * @param bool     $sync Synchronization or copy.
	 * @param int      $from Source term.
	 * @return string[]
	 */
	public function pll_term_metas( $keys, $sync = false, $from = 0 ): array {
		return $this->pll_metas( $keys, $sync, $from, 'term' );
	}

	/**
	 * IDs in copied post values point at translations in the target language.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language.
	 * @param int    $from  Source post.
	 * @return mixed
	 */
	public function pll_translate_post_meta( $value, $key, $lang, $from = 0 ) {
		$field = $from ? $this->field_of_meta( 'post', (int) $from, (string) $key ) : null;
		return $field ? self::translate_value( $value, $field, (string) $lang ) : $value;
	}

	/**
	 * IDs in copied term values point at translations in the target language.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language.
	 * @param int    $from  Source term.
	 * @return mixed
	 */
	public function pll_translate_term_meta( $value, $key, $lang, $from = 0 ) {
		$field = $from ? $this->field_of_meta( 'term', (int) $from, (string) $key ) : null;
		return $field ? self::translate_value( $value, $field, (string) $lang ) : $value;
	}

	/**
	 * Keeps our internal post types out of Polylang's translatable types.
	 *
	 * @param string[] $types Post types.
	 * @return string[]
	 */
	public function pll_exclude_post_types( $types ): array {
		$types    = (array) $types;
		$internal = array_merge( array( Plugin::GROUP_POST_TYPE, \FreeformFields\Options\OptionsPages::POST_TYPE ), array_values( \FreeformFields\Content\ContentTypes::POST_TYPES ) );
		foreach ( $internal as $type ) {
			unset( $types[ $type ] );
		}
		return $types;
	}

	/* ---- WPML ---------------------------------------------------------- */

	/**
	 * IDs in values WPML copies to a translation point at translations in its language.
	 *
	 * @param mixed  $value    Copied value.
	 * @param int    $from     Source post.
	 * @param int    $to       Target post.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function wpml_copied_value( $value, $from, $to, $meta_key ) {
		$provider = self::provider();
		if ( ! $provider ) {
			return $value;
		}
		$field = $this->field_of_meta( 'post', (int) $from, (string) $meta_key );
		$lang  = $field ? $provider->post_language( (int) $to ) : null;
		return $field && $lang ? self::translate_value( $value, $field, $lang ) : $value;
	}

	/**
	 * Remembers a saved term: WPML copies its values to translations without translating IDs.
	 *
	 * @param int $term_id Term ID.
	 */
	public function term_saved( $term_id ): void {
		$provider = self::provider();
		if ( $provider && 'wpml' === $provider->name() ) {
			$this->saved_terms[ (int) $term_id ] = true;
		}
	}

	/**
	 * Points IDs in term values copied by WPML at the translations' languages.
	 */
	public function fix_term_copies(): void {
		$provider = self::provider();
		if ( ! $provider || array() === $this->saved_terms ) {
			return;
		}
		foreach ( array_keys( $this->saved_terms ) as $term_id ) {
			$translations = $provider->term_translations( $term_id );
			$keys         = $this->object_keys( 'term', $term_id );
			foreach ( $keys as $meta_key => $pref ) {
				if ( Preferences::COPY !== $pref && Preferences::COPY_ONCE !== $pref ) {
					continue;
				}
				$field = $this->field_of_meta( 'term', $term_id, (string) $meta_key );
				if ( ! $field || ! isset( self::id_types()[ $field['type'] ] ) ) {
					continue;
				}
				$source = get_term_meta( $term_id, (string) $meta_key, true );
				foreach ( $translations as $lang => $tr_id ) {
					if ( $tr_id === $term_id || get_term_meta( $tr_id, (string) $meta_key, true ) !== $source ) {
						continue;
					}
					$translated = self::translate_value( $source, $field, (string) $lang );
					if ( $translated !== $source ) {
						update_term_meta( $tr_id, (string) $meta_key, $translated );
					}
				}
			}
		}
		$this->saved_terms = array();
	}
}
