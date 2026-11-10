<?php
/**
 * Formatting and validation per field type.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Fields\Types\ChoiceField;
use FreeformFields\Validation\Validator;

final class FieldTypesTest extends TestCase {

	private const CHOICES = array(
		'red'   => 'Red',
		'green' => 'Green',
	);

	public function test_choice_return_formats(): void {
		$this->make_group(
			array(
				array(
					'name'    => 'c_value',
					'type'    => 'select',
					'choices' => self::CHOICES,
				),
				array(
					'name'          => 'c_label',
					'type'          => 'radio',
					'choices'       => self::CHOICES,
					'return_format' => 'label',
				),
				array(
					'name'          => 'c_array',
					'type'          => 'checkbox',
					'choices'       => self::CHOICES,
					'return_format' => 'array',
				),
				array(
					'name'          => 'c_multi',
					'type'          => 'select',
					'choices'       => self::CHOICES,
					'multiple'      => 1,
					'return_format' => 'label',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'c_value', 'red', $post );
		wpff_update_field( 'c_label', 'green', $post );
		wpff_update_field( 'c_array', array( 'red' ), $post );
		wpff_update_field( 'c_multi', array( 'red', 'green' ), $post );

		$this->assertSame( 'red', wpff_get_field( 'c_value', $post ) );
		$this->assertSame( 'Green', wpff_get_field( 'c_label', $post ) );
		$this->assertSame(
			array(
				array(
					'value' => 'red',
					'label' => 'Red',
				),
			),
			wpff_get_field( 'c_array', $post )
		);
		$this->assertSame( array( 'Red', 'Green' ), wpff_get_field( 'c_multi', $post ) );
	}

	public function test_choices_from_text_lines(): void {
		$this->assertSame(
			array(
				'red'   => 'Red',
				'Green' => 'Green',
			),
			ChoiceField::normalize_choices( "red : Red\n\nGreen\r\n" )
		);
	}

	public function test_textarea_new_lines_and_true_false(): void {
		$this->make_group(
			array(
				array(
					'name'      => 'bio',
					'type'      => 'textarea',
					'new_lines' => 'br',
				),
				array(
					'name' => 'flag',
					'type' => 'true_false',
				),
			)
		);
		$post = $this->make_post();
		wpff_update_field( 'bio', "a\nb", $post );
		wpff_update_field( 'flag', 0, $post );

		$this->assertSame( "a<br />\nb", wpff_get_field( 'bio', $post ) );
		$this->assertFalse( wpff_get_field( 'flag', $post ) );
	}

	public function test_image_return_formats(): void {
		$image = $this->make_image();
		$this->make_group(
			array(
				array(
					'name' => 'img_array',
					'type' => 'image',
				),
				array(
					'name'          => 'img_url',
					'type'          => 'image',
					'return_format' => 'url',
				),
				array(
					'name'          => 'img_id',
					'type'          => 'image',
					'return_format' => 'id',
				),
			)
		);
		$post = $this->make_post();
		foreach ( array( 'img_array', 'img_url', 'img_id' ) as $name ) {
			wpff_update_field( $name, $image, $post );
		}

		$array = wpff_get_field( 'img_array', $post );
		$this->assertSame( $image, $array['ID'] );
		$this->assertSame( 'Alt text', $array['alt'] );
		$this->assertSame( 'image/png', $array['mime_type'] );
		$this->assertSame( 1, $array['width'] );
		$this->assertSame( wp_get_attachment_url( $image ), wpff_get_field( 'img_url', $post ) );
		$this->assertSame( $image, wpff_get_field( 'img_id', $post ) );

		wpff_update_field( 'img_id', '', $post );
		$this->assertFalse( wpff_get_field( 'img_id', $post ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: bool}>
	 */
	public static function validation_cases(): array {
		return array(
			'required empty'          => array( array( 'required' => 1 ), '', false ),
			'required filled'         => array( array( 'required' => 1 ), 'x', true ),
			'maxlength'               => array( array( 'maxlength' => 3 ), 'abcd', false ),
			'number ok'               => array(
				array(
					'type' => 'number',
					'min'  => 1,
					'max'  => 10,
				),
				'5',
				true,
			),
			'number comma decimal'    => array( array( 'type' => 'number' ), '1,5', true ),
			'number not numeric'      => array( array( 'type' => 'number' ), 'abc', false ),
			'number below min'        => array(
				array(
					'type' => 'number',
					'min'  => 1,
				),
				'0',
				false,
			),
			'number above max'        => array(
				array(
					'type' => 'number',
					'max'  => 10,
				),
				'11',
				false,
			),
			'email ok'                => array( array( 'type' => 'email' ), 'a@b.co', true ),
			'email bad'               => array( array( 'type' => 'email' ), 'not-an-email', false ),
			'url ok'                  => array( array( 'type' => 'url' ), 'https://example.com/x', true ),
			'url relative'            => array( array( 'type' => 'url' ), '/about', true ),
			'url javascript'          => array( array( 'type' => 'url' ), 'javascript:alert(1)', false ),
			'select unknown required' => array(
				array(
					'type'     => 'select',
					'choices'  => self::CHOICES,
					'required' => 1,
				),
				'blue',
				false,
			),
			'true_false required off' => array(
				array(
					'type'     => 'true_false',
					'required' => 1,
				),
				'0',
				false,
			),
			'true_false required on'  => array(
				array(
					'type'     => 'true_false',
					'required' => 1,
				),
				'1',
				true,
			),
			'image not attachment'    => array( array( 'type' => 'image' ), '999999', false ),
		);
	}

	/**
	 * @dataProvider validation_cases
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param mixed                $value    Submitted value.
	 * @param bool                 $valid    Expected result.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'validation_cases' )]
	public function test_validation( array $settings, $value, bool $valid ): void {
		$this->make_group(
			array(
				$settings + array(
					'key'   => 'field_v',
					'name'  => 'v',
					'label' => 'V',
				),
			)
		);
		$field  = $this->plugin()->fields()->get_field( 'field_v' );
		$result = ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_v' => $value ), array( 'field_v' => $field ) );

		$this->assertSame( $valid, array() === $result['errors'], (string) wp_json_encode( $result['errors'] ) );
	}

	public function test_select_drops_unknown_choices(): void {
		$this->make_group(
			array(
				array(
					'key'      => 'field_s',
					'name'     => 's',
					'type'     => 'select',
					'choices'  => self::CHOICES,
					'multiple' => 1,
				),
			)
		);
		$field  = $this->plugin()->fields()->get_field( 'field_s' );
		$result = ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_s' => array( 'red', 'blue', 'red' ) ), array( 'field_s' => $field ) );

		$this->assertSame( array( 'red' ), $result['values']['field_s'] );
	}

	public function test_html_is_filtered_without_unfiltered_html(): void {
		$author = wp_insert_user(
			array(
				'user_login' => 'a' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			)
		);
		wp_set_current_user( $author );
		$this->make_group(
			array(
				array(
					'key'  => 'field_h',
					'name' => 'h',
				),
			)
		);
		$field  = $this->plugin()->fields()->get_field( 'field_h' );
		$result = ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_h' => '<b>ok</b><script>x</script>' ), array( 'field_h' => $field ) );

		$this->assertSame( '<b>ok</b>x', $result['values']['field_h'] );
		wp_delete_user( $author );
	}

	public function test_validate_filter_can_reject(): void {
		$this->make_group(
			array(
				array(
					'key'  => 'field_custom',
					'name' => 'custom',
				),
			)
		);
		$field = $this->plugin()->fields()->get_field( 'field_custom' );
		$cb    = static fn( $valid, $value ) => 'bad' === $value ? 'No bad values' : $valid;
		add_filter( 'wpff/validate_value/name=custom', $cb, 10, 2 );

		$result = ( new Validator( $this->plugin()->types() ) )->validate( array( 'field_custom' => 'bad' ), array( 'field_custom' => $field ) );
		remove_filter( 'wpff/validate_value/name=custom', $cb, 10 );

		$this->assertSame( array( 'field_custom' => 'No bad values' ), $result['errors'] );
	}
}
