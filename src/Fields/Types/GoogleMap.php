<?php
/**
 * Google Map field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * A location on a map, stored like ACF's Google Map field:
 * `array( 'address' => …, 'lat' => …, 'lng' => …, 'zoom' => …, 'place_id' => …, 'city' => …, … )`.
 *
 * With a Google Maps API key the editor uses Google Maps and its Geocoding service.
 * Without a key it uses OpenStreetMap tiles and Nominatim search, so the field works out of the box.
 * Coordinates can also be typed in by hand.
 */
class GoogleMap extends AbstractField {

	public string $name = 'google_map';

	public string $category = 'advanced';

	public const HANDLE = 'wpff-map';

	/**
	 * Address parts kept next to address/lat/lng/zoom, as ACF stores them.
	 */
	private const PLACE_KEYS = array(
		'place_id',
		'name',
		'street_number',
		'street_name',
		'street_name_short',
		'city',
		'state',
		'state_short',
		'post_code',
		'country',
		'country_short',
	);

	public function label(): string {
		return __( 'Map', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'center_lat' => '',
			'center_lng' => '',
			'zoom'       => '',
			'height'     => '',
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'center_lat', __( 'Center Latitude', 'freeform-fields' ), 'text', array( 'help' => __( 'Where the map opens when no location is set.', 'freeform-fields' ) ) ),
			self::setting( 'center_lng', __( 'Center Longitude', 'freeform-fields' ), 'text' ),
			self::setting( 'zoom', __( 'Zoom', 'freeform-fields' ), 'number', array( 'help' => __( 'From 1 (world) to 20 (building). Default 14.', 'freeform-fields' ) ) ),
			self::setting( 'height', __( 'Height', 'freeform-fields' ), 'number', array( 'help' => __( 'Map height in pixels. Default 400.', 'freeform-fields' ) ) ),
		);
	}

	/**
	 * Google Maps API key, or '' to use OpenStreetMap.
	 *
	 * Sources, first match wins: the `WPFF_GOOGLE_MAPS_API_KEY` constant, `wpff_update_setting( 'google_api_key', … )`
	 * (or ACF's `acf_update_setting()`), the ACF filter `acf/fields/google_map/api`. Final say: `wpff/fields/google_map/api_key`.
	 */
	public static function api_key(): string {
		$key = defined( 'WPFF_GOOGLE_MAPS_API_KEY' ) ? (string) WPFF_GOOGLE_MAPS_API_KEY : '';
		if ( '' === $key ) {
			$key = (string) wpff_get_setting( 'google_api_key', '' );
		}
		if ( '' === $key ) {
			$api = apply_filters( 'acf/fields/google_map/api', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ACF compatibility.
			$key = is_array( $api ) && isset( $api['key'] ) && is_string( $api['key'] ) ? $api['key'] : '';
		}
		/**
		 * Filters the Google Maps API key. Return '' to use OpenStreetMap.
		 *
		 * @param string $key API key.
		 */
		return (string) apply_filters( 'wpff/fields/google_map/api_key', $key );
	}

	public function enqueue_assets(): void {
		$asset_file = WPFF_PATH . 'build/map.asset.php';
		if ( ! file_exists( $asset_file ) || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_style( 'wpff-leaflet', WPFF_URL . 'build/map.css', array(), $asset['version'] );
		wp_enqueue_style( self::HANDLE, WPFF_URL . 'build/style-map.css', array( 'wpff-leaflet' ), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_enqueue_script( self::HANDLE, WPFF_URL . 'build/map.js', array_merge( $asset['dependencies'], array( 'jquery' ) ), $asset['version'], array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'freeform-fields', WPFF_PATH . 'languages' );

		$key = self::api_key();
		wp_localize_script(
			self::HANDLE,
			'wpffMap',
			array(
				'provider'    => '' === $key ? 'osm' : 'google',
				'googleKey'   => $key,
				'language'    => substr( get_user_locale(), 0, 2 ),
				/**
				 * Filters the OpenStreetMap tile server used without a Google key.
				 *
				 * @param array{url: string, attribution: string} $tiles Leaflet tile layer URL and attribution HTML.
				 */
				'tiles'       => apply_filters(
					'wpff/fields/google_map/tiles',
					array(
						'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
					)
				),
				/**
				 * Filters the Nominatim-compatible geocoder used without a Google key.
				 *
				 * @param string $url Base URL; `/search` and `/reverse` are appended.
				 */
				'geocoderUrl' => apply_filters( 'wpff/fields/google_map/geocoder_url', 'https://nominatim.openstreetmap.org' ),
			)
		);
	}

	public function render_input( array $field ): void {
		$value  = self::normalize( $field['value'] );
		$height = (int) $field['height'] > 0 ? (int) $field['height'] : 400;
		$name   = $field['input_name'];
		$place  = $value ? array_intersect_key( $value, array_flip( self::PLACE_KEYS ) ) : array();

		printf(
			'<div class="wpff-map" data-center-lat="%s" data-center-lng="%s" data-zoom="%d">',
			esc_attr( is_numeric( $field['center_lat'] ) ? (string) $field['center_lat'] : '-37.81411' ),
			esc_attr( is_numeric( $field['center_lng'] ) ? (string) $field['center_lng'] : '144.96328' ),
			(int) $field['zoom'] > 0 ? (int) $field['zoom'] : 14
		);

		echo '<div class="wpff-map__search">';
		printf(
			'<input type="search" id="%s" name="%s[address]" value="%s" class="wpff-control wpff-map__address" placeholder="%s" autocomplete="off" />',
			esc_attr( $field['input_id'] ),
			esc_attr( $name ),
			wpff_value_attr( $value['address'] ?? '' ),
			esc_attr__( 'Search for an address…', 'freeform-fields' )
		);
		printf( '<button type="button" class="button wpff-map__find">%s</button>', esc_html__( 'Find', 'freeform-fields' ) );
		printf(
			'<button type="button" class="button wpff-map__locate" aria-label="%1$s" title="%1$s"><span class="dashicons dashicons-location" aria-hidden="true"></span></button>',
			esc_attr__( 'Use my location', 'freeform-fields' )
		);
		printf( '<button type="button" class="button-link wpff-map__clear">%s</button>', esc_html__( 'Clear', 'freeform-fields' ) );
		echo '</div>';
		printf( '<ul class="wpff-map__results" role="listbox" aria-label="%s" hidden></ul>', esc_attr__( 'Search results', 'freeform-fields' ) );
		echo '<p class="wpff-map__status" aria-live="polite"></p>';
		printf( '<div class="wpff-map__canvas" style="height:%dpx"></div>', (int) $height );

		echo '<div class="wpff-map__coords">';
		foreach ( array(
			'lat' => __( 'Latitude', 'freeform-fields' ),
			'lng' => __( 'Longitude', 'freeform-fields' ),
		) as $key => $label ) {
			printf(
				'<label><span>%s</span><input type="number" step="any" name="%s[%s]" value="%s" class="wpff-control wpff-map__%s" /></label>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $key ),
				esc_attr( isset( $value[ $key ] ) ? (string) $value[ $key ] : '' ),
				esc_attr( $key )
			);
		}
		echo '</div>';
		printf( '<input type="hidden" name="%s[zoom]" value="%s" class="wpff-map__zoom" />', esc_attr( $name ), esc_attr( isset( $value['zoom'] ) ? (string) $value['zoom'] : '' ) );
		printf( '<input type="hidden" name="%s[place]" value="%s" class="wpff-map__place" />', esc_attr( $name ), esc_attr( $place ? (string) wp_json_encode( $place ) : '' ) );
		echo '</div>';
	}

	public function sanitize( $value, array $field ) {
		if ( is_array( $value ) && isset( $value['place'] ) && is_string( $value['place'] ) ) {
			$place = json_decode( wp_unslash( $value['place'] ), true );
			unset( $value['place'] );
			if ( is_array( $place ) ) {
				$value += $place;
			}
		}
		$location = self::normalize( $value );
		if ( ! $location ) {
			// Keep half-filled coordinates so validation can complain about them.
			return is_array( $value ) && ( '' !== trim( (string) ( $value['lat'] ?? '' ) ) || '' !== trim( (string) ( $value['lng'] ?? '' ) ) ) ? $value : '';
		}
		$clean = array(
			'address' => sanitize_text_field( (string) ( $location['address'] ?? '' ) ),
			'lat'     => $location['lat'],
			'lng'     => $location['lng'],
			'zoom'    => $location['zoom'],
		);
		foreach ( self::PLACE_KEYS as $key ) {
			if ( isset( $location[ $key ] ) && is_scalar( $location[ $key ] ) && '' !== (string) $location[ $key ] ) {
				$clean[ $key ] = sanitize_text_field( (string) $location[ $key ] );
			}
		}
		return $clean;
	}

	public function validate_value( $value, array $field ) {
		if ( is_array( $value ) && ! self::normalize( $value ) ) {
			return __( 'Enter both latitude (-90 to 90) and longitude (-180 to 180).', 'freeform-fields' );
		}
		return true;
	}

	public function format_value( $value, $object_id, array $field ) {
		$location = self::normalize( $value );
		return $location ?: false;
	}

	/**
	 * A valid location with float lat/lng and int zoom, or null.
	 *
	 * @param mixed $value Stored or submitted value (ACF also accepts a JSON string).
	 * @return array<string, mixed>|null
	 */
	public static function normalize( $value ): ?array {
		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$lat = $value['lat'] ?? '';
		$lng = $value['lng'] ?? '';
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		$value['lat']     = $lat;
		$value['lng']     = $lng;
		$value['zoom']    = isset( $value['zoom'] ) && is_numeric( $value['zoom'] ) ? max( 0, min( 22, (int) $value['zoom'] ) ) : 14;
		$value['address'] = is_scalar( $value['address'] ?? null ) ? (string) $value['address'] : '';
		return $value;
	}
}
