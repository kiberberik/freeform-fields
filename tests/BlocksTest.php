<?php
/**
 * Field blocks.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

final class BlocksTest extends TestCase {

	private string $dir = '';

	/** @var string[] */
	private array $registered = array();

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/wpff-blocks-' . wp_generate_password( 6, false );
		wp_mkdir_p( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( $this->registered as $name ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_type( $name );
			}
		}
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() );
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		parent::tearDown();
	}

	/**
	 * Writes a template that prints values through the public API.
	 *
	 * @param string $body PHP code.
	 */
	private function template( string $body ): string {
		$file = $this->dir . '/t-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( $file, "<?php\n" . $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	private function block_group( string $block ): void {
		$this->make_group(
			array(
				array(
					'key'      => 'field_b_title',
					'name'     => 'title',
					'required' => 1,
				),
				array(
					'key'        => 'field_b_items',
					'name'       => 'items',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'  => 'field_b_item',
							'name' => 'item',
						),
					),
				),
			),
			array(
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => $block,
						),
					),
				),
			)
		);
	}

	private function register( array $args ): array {
		$config             = acf_register_block_type( $args );
		$this->registered[] = $config['name'];
		return $config;
	}

	public function test_php_registration_and_render_with_acf_data(): void {
		$name = 'acf/hero-' . wp_generate_password( 4, false, false );
		$name = strtolower( $name );
		$this->register(
			array(
				'name'            => substr( $name, 4 ),
				'title'           => 'Hero',
				'category'        => 'formatting',
				'render_template' => $this->template( 'echo esc_html( get_field( "title" ) ) . "|" . implode( ",", array_column( (array) get_field( "items" ), "item" ) ) . "|" . get_row_index() . "|" . ( $is_preview ? "preview" : "front" ) . "|" . $block["name"];' ),
			)
		);
		$this->block_group( $name );

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$this->assertNotNull( $type );
		$this->assertSame( 'text', $type->category, 'ACF category names are mapped.' );
		$this->assertArrayHasKey( 'data', $type->attributes );

		// Exactly what ACF saves in the block comment.
		$data = array(
			'title'         => 'Big <b>idea</b>',
			'_title'        => 'field_b_title',
			'items'         => 2,
			'_items'        => 'field_b_items',
			'items_0_item'  => 'one',
			'_items_0_item' => 'field_b_item',
			'items_1_item'  => 'two',
			'_items_1_item' => 'field_b_item',
		);
		$html = do_blocks( '<!-- wp:' . $name . ' ' . wp_json_encode( array( 'data' => $data ) ) . ' /-->' );
		$this->assertSame( 'Big &lt;b&gt;idea&lt;/b&gt;|one,two|0|front|' . $name, $html );

		// Outside the template, get_field() reads the post again.
		$post = $this->make_post();
		$this->make_group( array( array( 'name' => 'title' ) ) );
		update_post_meta( $post, 'title', 'Post title' );
		$GLOBALS['post'] = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$this->assertSame( 'Post title', get_field( 'title' ) );
		unset( $GLOBALS['post'] );
	}

	public function test_block_json_with_acf_key(): void {
		$name = 'acf/quote-' . strtolower( wp_generate_password( 4, false, false ) );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->dir . '/block.json',
			(string) wp_json_encode(
				array(
					'apiVersion' => 3,
					'name'       => $name,
					'title'      => 'Quote',
					'acf'        => array(
						'mode'           => 'preview',
						'renderTemplate' => 'render.php',
					),
				)
			)
		);
		file_put_contents( $this->dir . '/render.php', '<?php echo "Q:" . esc_html( get_field( "title" ) );' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		register_block_type( $this->dir );
		$this->registered[] = $name;
		$this->block_group( $name );

		$this->assertSame( 'preview', $this->plugin()->blocks()->get( $name )['mode'] );
		$html = do_blocks( '<!-- wp:' . $name . ' {"data":{"title":"Hi","_title":"field_b_title"}} /-->' );
		$this->assertSame( 'Q:Hi', $html );
	}

	public function test_form_data_round_trip(): void {
		$name = 'acf/form-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->register(
			array(
				'name'  => $name,
				'title' => 'Form',
			)
		);
		$this->block_group( $name );
		$blocks = $this->plugin()->blocks();

		$result = $blocks->data_from_form(
			$name,
			array(
				'field_b_title' => 'Saved <b>x</b>',
				'field_b_items' => array(
					'__present' => '1',
					'r1'        => array( 'field_b_item' => 'a' ),
				),
			)
		);
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'Saved <b>x</b>', $result['data']['title'], 'Sanitized like any other form value.' );
		$this->assertSame( 'field_b_title', $result['data']['_title'] );
		$this->assertSame( 1, $result['data']['items'] );
		$this->assertSame( 'a', $result['data']['items_0_item'] );

		$html = $blocks->form_html( $name, $result['data'] );
		$this->assertStringContainsString( 'name="wpff[field_b_title]" value="Saved &lt;b&gt;x&lt;/b&gt;"', $html );
		$this->assertStringContainsString( 'name="wpff[field_b_items][row-0][field_b_item]" value="a"', $html );

		$invalid = $blocks->data_from_form( $name, array( 'field_b_title' => '' ) );
		$this->assertArrayHasKey( 'field_b_title', $invalid['errors'] );
	}

	public function test_rest_endpoints(): void {
		$name = 'acf/rest-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->register(
			array(
				'name'  => $name,
				'title' => 'Rest',
			)
		);
		$this->block_group( $name );

		$request = new \WP_REST_Request( 'POST', '/wpff/v1/blocks/data' );
		$request->set_body_params(
			array(
				'name'   => $name,
				'values' => array( 'field_b_title' => 'Via REST' ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Via REST', ( (array) $response->get_data()['data'] )['title'] );

		$missing = new \WP_REST_Request( 'POST', '/wpff/v1/blocks/form' );
		$missing->set_body_params( array( 'name' => 'acf/nope' ) );
		$this->assertSame( 404, rest_get_server()->dispatch( $missing )->get_status() );

		$subscriber = wp_insert_user(
			array(
				'user_login' => 'sb' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		wp_set_current_user( 1 );
		wp_delete_user( $subscriber );
	}

	public function test_location_rule_and_catalog(): void {
		$name = 'acf/cat-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->register(
			array(
				'name'  => $name,
				'title' => 'Catalog block',
			)
		);
		$rules = $this->plugin()->locations();
		$rule  = array(
			array(
				array(
					'param'    => 'block',
					'operator' => '==',
					'value'    => $name,
				),
			),
		);
		$this->assertTrue( $rules->matches( $rule, array( 'block' => $name ) ) );
		$this->assertFalse( $rules->matches( $rule, array( 'post_id' => $this->make_post() ) ), 'Block rules never match posts.' );

		$params = array_column( ( new \FreeformFields\Admin\BuilderData( $this->plugin() ) )->location_params(), null, 'name' );
		$this->assertSame( 'Catalog block', ( (array) $params['block']['values'] )[ $name ] );
	}

	public function test_namespaces(): void {
		$suffix             = strtolower( wp_generate_password( 4, false, false ) );
		$own                = wpff_register_block_type( array( 'name' => 'own-' . $suffix ) );
		$acf                = acf_register_block_type( array( 'name' => 'compat-' . $suffix ) );
		$this->registered[] = $own['name'];
		$this->registered[] = $acf['name'];
		$this->assertSame( 'wpff/own-' . $suffix, $own['name'] );
		$this->assertSame( 'acf/compat-' . $suffix, $acf['name'], 'ACF functions keep the acf/ namespace of existing content.' );
	}

	public function test_inner_blocks(): void {
		$name = 'acf/inner-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->register(
			array(
				'name'            => $name,
				'supports'        => array( 'jsx' => true ),
				'render_template' => $this->template( 'echo "<div class=\"box\"><InnerBlocks allowedBlocks=\"" . esc_attr( wp_json_encode( array( "core/paragraph" ) ) ) . "\" templateLock=\"all\" /></div>";' ),
			)
		);
		$html = do_blocks( '<!-- wp:' . $name . ' --><!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph --><!-- /wp:' . $name . ' -->' );
		$this->assertSame( '<div class="box"><p class="wp-block-paragraph">Inside</p></div>', trim( preg_replace( '/\s+/', ' ', $html ) ) );

		$preview = \FreeformFields\Blocks\BlockRegistry::place_inner_blocks( '<InnerBlocks allowedBlocks="[&quot;core/paragraph&quot;]" templateLock="false"></InnerBlocks>', 'x', true );
		$this->assertStringContainsString( 'data-wpff-inner-blocks="{&quot;allowedBlocks&quot;:[&quot;core\/paragraph&quot;],&quot;templateLock&quot;:false}"', $preview );

		// Without supports.jsx the tag is left alone.
		$plain = 'acf/plain-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->register(
			array(
				'name'            => $plain,
				'render_template' => $this->template( 'echo "<InnerBlocks />";' ),
			)
		);
		$this->assertSame( '<InnerBlocks />', do_blocks( '<!-- wp:' . $plain . ' /-->' ) );
	}

	public function test_bindings_unwrap_wysiwyg_paragraphs(): void {
		$this->assertSame( 'One<br><br>Two', \FreeformFields\Blocks\Bindings::to_attribute( "<p>One</p>\n<p>Two</p>\n", 'content' ) );
		$this->assertSame( 'Plain <b>text</b>', \FreeformFields\Blocks\Bindings::to_attribute( 'Plain <b>text</b>', 'content' ) );
		$this->assertSame( '<p>Kept</p>', \FreeformFields\Blocks\Bindings::to_attribute( '<p>Kept</p>', 'alt' ), 'Only rich text content is unwrapped.' );
	}

	public function test_block_bindings_source(): void {
		if ( ! function_exists( 'get_block_bindings_source' ) ) {
			$this->markTestSkipped( 'Block Bindings need WordPress 6.5.' );
		}
		$this->assertNotNull( get_block_bindings_source( 'wpff/field' ) );
		$image = $this->make_image();
		$this->make_group(
			array(
				array(
					'name'              => 'tagline',
					'allow_in_bindings' => 1,
				),
				array( 'name' => 'secret' ),
				array(
					'name'              => 'photo',
					'type'              => 'image',
					'allow_in_bindings' => 1,
				),
			)
		);
		$post = $this->make_post( array( 'post_status' => 'publish' ) );
		wpff_update_field( 'tagline', 'Hello <script>x</script><b>bold</b>', $post );
		wpff_update_field( 'secret', 'internal', $post );
		wpff_update_field( 'photo', $image, $post );

		$bind = static function ( string $block, string $attr, string $key, string $inner ) use ( $post ): string {
			$attrs  = array(
				'metadata' => array(
					'bindings' => array(
						$attr => array(
							'source' => 'wpff/field',
							'args'   => array( 'key' => $key ),
						),
					),
				),
			);
			$parsed = parse_blocks( '<!-- wp:' . $block . ' ' . wp_json_encode( $attrs ) . ' -->' . $inner . '<!-- /wp:' . $block . ' -->' );
			return ( new \WP_Block( $parsed[0], array( 'postId' => $post ) ) )->render();
		};

		$this->assertStringContainsString( '<b>bold</b></p>', $bind( 'paragraph', 'content', 'tagline', '<p>Old</p>' ) );
		$this->assertStringContainsString( '>Old</p>', $bind( 'paragraph', 'content', 'secret', '<p>Old</p>' ), 'Fields need "Allow in Block Bindings".' );
		$this->assertStringContainsString( wp_get_attachment_url( $image ), $bind( 'image', 'url', 'photo', '<figure class="wp-block-image"><img src="old.png" alt=""/></figure>' ) );

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'private',
			)
		);
		wp_set_current_user( 0 );
		$this->assertStringContainsString( '>Old</p>', $bind( 'paragraph', 'content', 'tagline', '<p>Old</p>' ), 'Private posts are not exposed to visitors.' );
		wp_set_current_user( 1 );
	}
}
