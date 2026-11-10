<?php
/**
 * Google Map field: ACF value layout, validation and API key sources.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Fields\Types\GoogleMap;
use FreeformFields\Validation\Validator;

final class GoogleMapTest extends TestCase {

	private function check( $value ): array {
		$this->make_group(
			array(
				array(
					'key'   => 'field_map',
					'name'  => 'map',
					'label' => 'Map',
					'type'  => 'google_map',
				),
			)
		);
		$field = $this->plugin()->fields()->get_field( 'field_map' );
		return ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_map' => $value ), array( 'field_map' => $field ) );
	}

	public function test_form_value_is_stored_like_acf(): void {
		$result = $this->check(
			array(
				'address' => 'Red Square, Moscow',
				'lat'     => '55.7535908',
				'lng'     => '37.6215013',
				'zoom'    => '15',
				'place'   => wp_slash(
					wp_json_encode(
						array(
							'city'          => 'Moscow',
							'country_short' => 'RU',
							'evil'          => 'x',
							'name'          => '<b>Red</b> Square',
						)
					)
				),
			)
		);
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'address'       => 'Red Square, Moscow',
				'lat'           => 55.7535908,
				'lng'           => 37.6215013,
				'zoom'          => 15,
				'name'          => 'Red Square',
				'city'          => 'Moscow',
				'country_short' => 'RU',
			),
			$result['values']['field_map']
		);
	}

	public function test_empty_and_invalid_values(): void {
		$this->assertSame(
			'',
			$this->check(
				array(
					'address' => '',
					'lat'     => '',
					'lng'     => '',
				)
			)['values']['field_map']
		);
		$this->assertArrayHasKey(
			'field_map',
			$this->check(
				array(
					'lat' => '55',
					'lng' => '',
				)
			)['errors']
		);
		$this->assertArrayHasKey(
			'field_map',
			$this->check(
				array(
					'lat' => '95',
					'lng' => '10',
				)
			)['errors']
		);
	}

	public function test_reads_values_saved_by_acf(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_6512ab0c1d2f0',
					'name' => 'office',
					'type' => 'google_map',
				),
			)
		);
		$post = $this->make_post();
		// ACF stores strings for lat/lng.
		add_post_meta(
			$post,
			'office',
			array(
				'address' => 'Somewhere',
				'lat'     => '-37.8',
				'lng'     => '144.9',
				'zoom'    => 12,
				'city'    => 'Melbourne',
			)
		);
		add_post_meta( $post, '_office', 'field_6512ab0c1d2f0' );

		$value = wpff_get_field( 'office', $post );
		$this->assertSame( -37.8, $value['lat'] );
		$this->assertSame( 'Melbourne', $value['city'] );

		$empty = $this->make_post();
		$this->assertFalse( wpff_get_field( 'office', $empty ) );
	}

	public function test_api_key_sources(): void {
		$this->assertSame( '', GoogleMap::api_key(), 'No key: OpenStreetMap.' );

		$acf = static fn() => array( 'key' => 'from-acf-filter' );
		add_filter( 'acf/fields/google_map/api', $acf );
		$this->assertSame( 'from-acf-filter', GoogleMap::api_key() );

		wpff_update_setting( 'google_api_key', 'from-setting' );
		$this->assertSame( 'from-setting', GoogleMap::api_key(), 'The setting wins over the ACF filter.' );
		$this->assertSame( 'from-setting', acf_get_setting( 'google_api_key' ) );

		remove_filter( 'acf/fields/google_map/api', $acf );
		wpff_update_setting( 'google_api_key', '' );
	}

	public function test_renders_inputs(): void {
		$this->make_group(
			array(
				array(
					'key'    => 'field_map',
					'name'   => 'map',
					'type'   => 'google_map',
					'height' => 250,
				),
			)
		);
		$field               = $this->plugin()->fields()->get_field( 'field_map' );
		$field['value']      = array(
			'address' => 'A "quoted" place',
			'lat'     => 1.5,
			'lng'     => 2.5,
			'zoom'    => 9,
		);
		$field['input_id']   = 'wpff-field_map';
		$field['input_name'] = 'wpff[field_map]';
		ob_start();
		$this->plugin()->types()->get( 'google_map' )->render_input( $field );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="wpff[field_map][lat]" value="1.5"', $html );
		$this->assertStringContainsString( 'value="A &quot;quoted&quot; place"', $html );
		$this->assertStringContainsString( 'height:250px', $html );
	}
}
