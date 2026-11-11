<?php
/**
 * Multilingual sites: options per language, translation preferences, WPML and Polylang hooks.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Multilingual\Multilingual;
use FreeformFields\Multilingual\Preferences;
use FreeformFields\Multilingual\Provider;
use FreeformFields\Storage\ObjectRef;

/**
 * Stand-in for WPML / Polylang: languages, translation groups and string translations set by the test.
 */
final class FakeProvider implements Provider {

	public ?string $current = 'en';

	/** @var array<int, array<string, int>> Post ID => language => post ID. */
	public array $posts = array();

	/** @var array<int, array<string, int>> */
	public array $terms = array();

	/** @var array<string, string> "context|name" => translation. */
	public array $strings = array();

	/** @var array<string, string> "context|name" => original. */
	public array $registered = array();

	public function __construct( private string $name = 'polylang' ) {}

	public function name(): string {
		return $this->name;
	}

	public function current_language(): ?string {
		return $this->current;
	}

	public function default_language(): ?string {
		return 'en';
	}

	public function languages(): array {
		return array(
			'en' => 'English',
			'ru' => 'Русский',
		);
	}

	public function post_language( int $post_id ): ?string {
		foreach ( $this->post_translations( $post_id ) as $lang => $id ) {
			if ( $id === $post_id ) {
				return $lang;
			}
		}
		return null;
	}

	public function term_language( int $term_id ): ?string {
		foreach ( $this->term_translations( $term_id ) as $lang => $id ) {
			if ( $id === $term_id ) {
				return $lang;
			}
		}
		return null;
	}

	public function post_translations( int $post_id ): array {
		return $this->posts[ $post_id ] ?? array();
	}

	public function term_translations( int $term_id ): array {
		return $this->terms[ $term_id ] ?? array();
	}

	public function post_source( int $post_id ): ?int {
		$en = $this->posts[ $post_id ]['en'] ?? null;
		return $en && $en !== $post_id ? $en : null;
	}

	public function register_string( string $context, string $name, string $value ): void {
		$this->registered[ $context . '|' . $name ] = $value;
	}

	public function translate_string( string $context, string $name, string $value ): string {
		return $this->strings[ $context . '|' . $name ] ?? $value;
	}

	/**
	 * Links posts as translations of each other.
	 *
	 * @param array<string, int> $group Language => post ID.
	 */
	public function link_posts( array $group ): void {
		foreach ( $group as $id ) {
			$this->posts[ $id ] = $group;
		}
	}
}

final class MultilingualTest extends TestCase {

	private FakeProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->provider = new FakeProvider();
		Multilingual::set_provider( $this->provider );
		$this->plugin()->multilingual()->preferences()->flush();
	}

	protected function tearDown(): void {
		Multilingual::set_provider( null );
		$this->plugin()->multilingual()->preferences()->flush();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'options\\_%' OR option_name LIKE '\\_options\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		\FreeformFields\Storage\MetaStore::reset_primed();
		parent::tearDown();
	}

	private function text_field( string $name = 'headline' ): array {
		return array(
			'key'   => 'field_ml_' . $name,
			'name'  => $name,
			'label' => ucfirst( $name ),
			'type'  => 'text',
		);
	}

	/* ---- Options ------------------------------------------------------- */

	public function test_options_are_stored_per_language_like_acf(): void {
		$this->make_group( array( $this->text_field() ) );

		update_field( 'headline', 'Hello', 'option' );
		$this->provider->current = 'ru';
		update_field( 'headline', 'Привет', 'option' );

		$this->assertSame( 'Hello', get_option( 'options_headline' ) );
		$this->assertSame( 'Привет', get_option( 'options_ru_headline' ) );
		$this->assertSame( 'field_ml_headline', get_option( '_options_ru_headline' ) );
		$this->assertSame( 'Привет', get_field( 'headline', 'option' ) );

		$this->provider->current = 'en';
		$this->assertSame( 'Hello', get_field( 'headline', 'options' ) );
	}

	public function test_language_without_its_own_option_value_shows_the_default_one(): void {
		$this->make_group( array( $this->text_field() ) );
		update_field( 'headline', 'Hello', 'option' );

		$this->provider->current = 'ru';
		$this->assertSame( 'Hello', get_field( 'headline', 'option' ) );
		$this->assertSame( array( 'headline' => 'Hello' ), get_fields( 'option' ) );

		add_filter( 'wpff/multilingual/options_fallback', '__return_false' );
		$this->assertNull( get_field( 'headline', 'option' ) );
		remove_filter( 'wpff/multilingual/options_fallback', '__return_false' );
	}

	public function test_all_languages_in_the_admin_edits_the_default_language(): void {
		$this->provider->current = null;
		$ref                     = ObjectRef::parse( 'options' );
		$this->assertSame( 'options', $ref->id );
		$this->assertNull( $ref->fallback );
	}

	public function test_custom_option_prefixes_are_not_localized(): void {
		$this->provider->current = 'ru';
		$this->assertSame( 'theme_settings', ObjectRef::parse( 'theme_settings' )->id );
		$this->assertSame( 'options_ru', ObjectRef::parse( 'options' )->id );
	}

	public function test_without_multilingual_plugin_options_are_shared(): void {
		Multilingual::set_provider( null );
		add_filter( 'wpff/multilingual/provider', '__return_null' );
		$this->assertSame( 'options', ObjectRef::parse( 'options' )->id );
		remove_filter( 'wpff/multilingual/provider', '__return_null' );
	}

	/* ---- Preferences --------------------------------------------------- */

	public function test_preferences_follow_the_group_mode(): void {
		$text  = array( 'type' => 'text' );
		$image = array( 'type' => 'image' );

		$this->assertSame( Preferences::TRANSLATE, Preferences::effective( $text, 'translation' ) );
		$this->assertSame( Preferences::COPY, Preferences::effective( $image, 'translation' ) );
		$this->assertSame( Preferences::TRANSLATE, Preferences::effective( $text, 'localization' ) );
		$this->assertSame( Preferences::COPY_ONCE, Preferences::effective( $image, 'localization' ) );

		// The expert mode uses the field's own preference; other modes ignore it.
		$image['wpml_cf_preferences'] = '0';
		$this->assertSame( Preferences::IGNORE, Preferences::effective( $image, 'advanced' ) );
		$this->assertSame( Preferences::COPY, Preferences::effective( $image, 'translation' ) );
	}

	public function test_group_mode_defaults(): void {
		$this->assertSame( 'localization', Preferences::mode( array( 'acfml_field_group_mode' => 'localization' ) ) );
		// Groups from before multilingual support: nothing is kept in sync until an option is chosen.
		$this->assertSame( 'localization', Preferences::mode( array( 'fields' => array( array( 'type' => 'text' ) ) ) ) );
		// Groups from before modes existed carry per-field preferences: expert mode.
		$this->assertSame(
			'advanced',
			Preferences::mode(
				array(
					'fields' => array(
						array(
							'type'       => 'repeater',
							'sub_fields' => array(
								array(
									'type'                => 'text',
									'wpml_cf_preferences' => 1,
								),
							),
						),
					),
				)
			)
		);
	}

	public function test_builder_keeps_mode_and_field_preferences(): void {
		$saved = $this->plugin()->groups()->save(
			array(
				'title'                  => 'ML group',
				'acfml_field_group_mode' => 'advanced',
				'fields'                 => array(
					array(
						'label'               => 'Title',
						'name'                => 'title',
						'type'                => 'text',
						'wpml_cf_preferences' => '3',
					),
					array(
						'label'               => 'Bad',
						'name'                => 'bad',
						'type'                => 'text',
						'wpml_cf_preferences' => 'nope',
					),
				),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertSame( 'advanced', $saved['acfml_field_group_mode'] );
		$this->assertSame( 3, $saved['fields'][0]['wpml_cf_preferences'] );
		$this->assertArrayNotHasKey( 'wpml_cf_preferences', $saved['fields'][1] );

		$bad = $this->plugin()->groups()->sanitize(
			array(
				'title'                  => 'X',
				'acfml_field_group_mode' => 'something',
			)
		);
		$this->assertArrayNotHasKey( 'acfml_field_group_mode', $bad );
	}

	/* ---- Polylang ------------------------------------------------------ */

	private function translated_post_group(): array {
		$this->make_group(
			array(
				$this->text_field( 'title_text' ),
				array(
					'key'   => 'field_ml_photo',
					'name'  => 'photo',
					'label' => 'Photo',
					'type'  => 'image',
				),
				array(
					'key'        => 'field_ml_cards',
					'name'       => 'cards',
					'label'      => 'Cards',
					'type'       => 'repeater',
					'sub_fields' => array( $this->text_field( 'card_title' ) ),
				),
			),
			array( 'acfml_field_group_mode' => 'translation' )
		);
		$en = $this->make_post();
		$ru = $this->make_post();
		$this->provider->link_posts(
			array(
				'en' => $en,
				'ru' => $ru,
			)
		);
		return array( $en, $ru );
	}

	public function test_polylang_synchronizes_only_copied_values(): void {
		[ $en, $ru ] = $this->translated_post_group();
		$image       = $this->make_image();
		update_field( 'title_text', 'Hello', $en );
		update_field( 'photo', $image, $en );
		update_field( 'cards', array( array( 'card_title' => 'One' ) ), $en );

		// Polylang Pro lists every public custom field when syncing custom fields is on.
		$sync = apply_filters( 'pll_copy_post_metas', array( 'title_text', 'other_meta' ), true, $en, $ru, 'ru' );
		$this->assertContains( 'other_meta', $sync );
		$this->assertContains( 'photo', $sync );
		$this->assertContains( '_photo', $sync );
		$this->assertContains( 'cards', $sync );
		$this->assertNotContains( 'title_text', $sync );
		$this->assertNotContains( 'cards_0_card_title', $sync );

		// A new translation starts with everything but "don't translate" values.
		$copy = apply_filters( 'pll_copy_post_metas', array(), false, $en, $ru, 'ru' );
		$this->assertContains( 'title_text', $copy );
		$this->assertContains( '_title_text', $copy );
		$this->assertContains( 'cards_0_card_title', $copy );
	}

	public function test_group_without_option_keeps_translations_apart(): void {
		// A group from before multilingual support (no option chosen yet).
		$this->make_group(
			array(
				array(
					'key'   => 'field_ml_legacy_photo',
					'name'  => 'legacy_photo',
					'label' => 'Photo',
					'type'  => 'image',
				),
			)
		);
		$en = $this->make_post();
		$ru = $this->make_post();
		$this->provider->link_posts(
			array(
				'en' => $en,
				'ru' => $ru,
			)
		);
		update_field( 'legacy_photo', $this->make_image(), $en );

		// Saving a post never overwrites its translations' values...
		$this->assertNotContains( 'legacy_photo', apply_filters( 'pll_copy_post_metas', array( 'legacy_photo' ), true, $en, $ru, 'ru' ) );
		// ...but a new translation still starts as a copy.
		$this->assertContains( 'legacy_photo', apply_filters( 'pll_copy_post_metas', array(), false, $en, $ru, 'ru' ) );
	}

	public function test_polylang_translates_ids_in_copied_values(): void {
		[ $en, $ru ] = $this->translated_post_group();
		$image_en    = $this->make_image();
		$image_ru    = $this->make_image();
		$this->provider->link_posts(
			array(
				'en' => $image_en,
				'ru' => $image_ru,
			)
		);
		update_field( 'photo', $image_en, $en );

		$this->assertSame( $image_ru, apply_filters( 'pll_translate_post_meta', $image_en, 'photo', 'ru', $en, $ru ) );
		// Not one of our fields: untouched.
		$this->assertSame( $image_en, apply_filters( 'pll_translate_post_meta', $image_en, 'unrelated', 'ru', $en, $ru ) );
	}

	/* ---- WPML ---------------------------------------------------------- */

	public function test_wpml_copied_ids_point_at_translations(): void {
		$this->provider = new FakeProvider( 'wpml' );
		Multilingual::set_provider( $this->provider );
		[ $en, $ru ] = $this->translated_post_group();
		$image_en    = $this->make_image();
		$image_ru    = $this->make_image();
		$this->provider->link_posts(
			array(
				'en' => $image_en,
				'ru' => $image_ru,
			)
		);
		update_field( 'photo', $image_en, $en );

		$this->assertSame( $image_ru, apply_filters( 'wpml_sync_custom_field_copied_value', $image_en, $en, $ru, 'photo', array() ) );
	}

	public function test_wpml_learns_preferences_of_saved_meta_keys(): void {
		if ( ! function_exists( 'wpml_load_core_tm' ) ) {
			require_once __DIR__ . '/stubs/wpml-tm.php';
		}
		$this->provider = new FakeProvider( 'wpml' );
		Multilingual::set_provider( $this->provider );
		[ $en ] = $this->translated_post_group();
		update_field( 'title_text', 'Hello', $en );
		update_field( 'photo', 5, $en );
		update_field( 'cards', array( array( 'card_title' => 'One' ) ), $en );

		do_action( 'wpff/save_post', (string) $en, array() );

		$settings = wpml_load_core_tm()->settings['custom_fields_translation'];
		$this->assertSame( Preferences::TRANSLATE, $settings['title_text'] );
		$this->assertSame( Preferences::COPY, $settings['_title_text'] );
		$this->assertSame( Preferences::COPY, $settings['photo'] );
		$this->assertSame( Preferences::COPY, $settings['cards'] );
		$this->assertSame( Preferences::TRANSLATE, $settings['cards_0_card_title'] );
	}

	/* ---- Display, location, strings ------------------------------------ */

	public function test_picked_posts_show_the_current_language_translation(): void {
		$this->make_group(
			array(
				array(
					'key'           => 'field_ml_related',
					'name'          => 'related',
					'label'         => 'Related',
					'type'          => 'post_object',
					'return_format' => 'id',
				),
			)
		);
		$page    = $this->make_post();
		$target  = $this->make_post( array( 'post_title' => 'EN target' ) );
		$target2 = $this->make_post( array( 'post_title' => 'RU target' ) );
		$this->provider->link_posts(
			array(
				'en' => $target,
				'ru' => $target2,
			)
		);
		update_field( 'related', $target, $page );

		$this->assertSame( $target, get_field( 'related', $page ) );
		$this->provider->current = 'ru';
		$this->assertSame( $target2, get_field( 'related', $page ) );
		// Raw values stay as stored.
		$this->assertSame( $target, (int) get_field( 'related', $page, false ) );
	}

	public function test_location_rule_on_a_page_matches_its_translations(): void {
		$en = $this->make_post( array( 'post_type' => 'page' ) );
		$ru = $this->make_post( array( 'post_type' => 'page' ) );
		$this->provider->link_posts(
			array(
				'en' => $en,
				'ru' => $ru,
			)
		);
		$location = array(
			array(
				array(
					'param'    => 'page',
					'operator' => '==',
					'value'    => (string) $en,
				),
			),
		);
		$this->assertTrue( $this->plugin()->locations()->matches( $location, array( 'post_id' => $ru ) ) );
		$other = $this->make_post( array( 'post_type' => 'page' ) );
		$this->assertFalse( $this->plugin()->locations()->matches( $location, array( 'post_id' => $other ) ) );
	}

	public function test_field_labels_and_choices_are_translatable_strings(): void {
		$key = $this->make_group(
			array(
				array(
					'key'          => 'field_ml_color',
					'name'         => 'color',
					'label'        => 'Color',
					'instructions' => 'Pick one',
					'type'         => 'select',
					'choices'      => array(
						'red'  => 'Red',
						'blue' => 'Blue',
					),
				),
			)
		);
		$this->plugin()->multilingual()->group_saved( array( 'key' => $key ) );
		$context = 'WP Freeform Fields: ' . $key;
		$this->assertSame( 'Color', $this->provider->registered[ $context . '|field_ml_color:label' ] );
		$this->assertSame( 'Blue', $this->provider->registered[ $context . '|field_ml_color:choices:blue' ] );

		$this->provider->strings[ $context . '|field_ml_color:label' ]        = 'Цвет';
		$this->provider->strings[ $context . '|field_ml_color:choices:blue' ] = 'Синий';
		$field = get_field_object( 'field_ml_color' );
		$this->assertSame( 'Цвет', $field['label'] );
		$this->assertSame( 'Синий', $field['choices']['blue'] );
		$this->assertSame( 'Red', $field['choices']['red'] );
	}
}
