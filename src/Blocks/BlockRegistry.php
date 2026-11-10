<?php
/**
 * Field-powered Gutenberg blocks.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Blocks;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Plugin;
use FreeformFields\Storage\MetaStore;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks rendered by PHP templates, with fields from groups whose location rule is
 * `block` == {block name}.
 *
 * Two ways to register, as in ACF:
 *  - `wpff_register_block_type( [ 'name' => 'hero', 'title' => 'Hero', 'render_template' => '…' ] )`;
 *  - `register_block_type( __DIR__ . '/blocks/hero' )` with `"acf": { "renderTemplate": "hero.php" }`
 *    (or `"wpff": {…}`) in block.json.
 *
 * Field values live in the block's `data` attribute in ACF's flat layout
 * (`{name}` => value, `_{name}` => field key), so blocks saved by ACF render here unchanged.
 * Inside a template, get_field( 'name' ) reads the current block.
 */
final class BlockRegistry {

	public const EDITOR_HANDLE = 'wpff-blocks';

	/**
	 * Block settings by block name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $blocks = array();

	/**
	 * Blocks waiting for `init` (registered too early).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $pending = array();

	private int $counter = 0;

	public function __construct( private Plugin $plugin ) {}

	public function register_hooks(): void {
		add_filter( 'block_type_metadata_settings', array( $this, 'from_metadata' ), 10, 2 );
		add_action( 'init', array( $this, 'register_pending' ), 20 );
		add_filter( 'wp_insert_post_data', array( $this, 'sanitize_saved_blocks' ), 20, 2 );
	}

	/**
	 * Filters HTML in field block data saved by users without `unfiltered_html`.
	 *
	 * WordPress filters post HTML with kses, but not block attributes in comments, so without
	 * this a user could put a script into a block's `data` and bypass field sanitizing.
	 *
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function sanitize_saved_blocks( $data, $postarr = array() ) {
		if ( current_user_can( 'unfiltered_html' ) || empty( $data['post_content'] ) || array() === $this->blocks ) {
			return $data;
		}
		$content = wp_unslash( (string) $data['post_content'] );
		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $data;
		}
		$changed = false;
		$blocks  = $this->sanitize_block_list( parse_blocks( $content ), $changed );
		if ( $changed ) {
			$data['post_content'] = wp_slash( serialize_blocks( $blocks ) );
		}
		return $data;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                             $changed Set when something was cleaned.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_block_list( array $blocks, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			if ( isset( $this->blocks[ $block['blockName'] ?? '' ] ) && is_array( $block['attrs']['data'] ?? null ) ) {
				$clean = self::kses_deep( $block['attrs']['data'] );
				if ( $clean !== $block['attrs']['data'] ) {
					$blocks[ $i ]['attrs']['data'] = $clean;
					$changed                       = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = $this->sanitize_block_list( $block['innerBlocks'], $changed );
			}
		}
		return $blocks;
	}

	/**
	 * wp_kses_post() on every string, keeping the structure.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function kses_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'kses_deep' ), $value );
		}
		return is_string( $value ) ? wp_kses_post( $value ) : $value;
	}

	/**
	 * Registers a block from PHP. Accepts ACF's settings.
	 *
	 * @param array<string, mixed> $args name, title, description, category, icon, keywords, mode,
	 *                                   render_template, render_callback, supports, post_types, enqueue_style,
	 *                                   enqueue_script, enqueue_assets.
	 * @param string               $block_namespace Namespace for names given without one: `wpff`, or `acf` for ACF's functions.
	 * @return array<string, mixed>|false Block settings, or false when the name is missing.
	 */
	public function register( array $args, string $block_namespace = 'wpff' ) {
		if ( empty( $args['name'] ) ) {
			return false;
		}
		$name = (string) $args['name'];
		if ( ! str_contains( $name, '/' ) ) {
			$name = $block_namespace . '/' . sanitize_title( $name );
		}
		$args['name'] = $name;
		if ( ! did_action( 'init' ) ) {
			$this->pending[] = $args;
			return $args;
		}
		return $this->register_now( $args );
	}

	public function register_pending(): void {
		foreach ( $this->pending as $args ) {
			$this->register_now( $args );
		}
		$this->pending = array();
	}

	/**
	 * @param array<string, mixed> $args Settings.
	 * @return array<string, mixed>
	 */
	private function register_now( array $args ): array {
		$config = $this->normalize( $args );
		$type   = register_block_type(
			$config['name'],
			array(
				'title'                 => $config['title'],
				'description'           => $config['description'],
				'category'              => $config['category'],
				'icon'                  => $config['icon'],
				'keywords'              => $config['keywords'],
				'supports'              => $config['supports'],
				'attributes'            => self::attributes(),
				'render_callback'       => array( $this, 'render' ),
				'editor_script_handles' => array( self::EDITOR_HANDLE ),
				'uses_context'          => array( 'postId', 'postType' ),
			)
		);
		if ( $type ) {
			$this->blocks[ $config['name'] ] = $config;
		}
		return $config;
	}

	/**
	 * Hooks blocks registered from a block.json with a `wpff` / `acf` key.
	 *
	 * @param array<string, mixed> $settings Block type settings.
	 * @param array<string, mixed> $metadata block.json contents (with `file`).
	 * @return array<string, mixed>
	 */
	public function from_metadata( $settings, $metadata ) {
		// With ACF active, blocks with only an `acf` key are ACF's.
		$extra = $metadata['wpff'] ?? ( \FreeformFields\Plugin::acf_active() ? null : ( $metadata['acf'] ?? null ) );
		if ( ! is_array( $extra ) ) {
			return $settings;
		}
		$dir                             = isset( $metadata['file'] ) ? dirname( (string) $metadata['file'] ) : '';
		$config                          = $this->normalize(
			array(
				'name'            => $metadata['name'] ?? '',
				'title'           => $metadata['title'] ?? '',
				'description'     => $metadata['description'] ?? '',
				'category'        => $metadata['category'] ?? 'text',
				'icon'            => $metadata['icon'] ?? 'block-default',
				'keywords'        => $metadata['keywords'] ?? array(),
				'supports'        => $metadata['supports'] ?? array(),
				'mode'            => $extra['mode'] ?? 'preview',
				'render_template' => isset( $extra['renderTemplate'] ) ? self::resolve_path( (string) $extra['renderTemplate'], $dir ) : '',
				'render_callback' => $extra['renderCallback'] ?? null,
			)
		);
		$this->blocks[ $config['name'] ] = $config;

		$settings['attributes']            = array_merge( self::attributes(), (array) ( $settings['attributes'] ?? array() ) );
		$settings['render_callback']       = array( $this, 'render' );
		$settings['editor_script_handles'] = array_merge( (array) ( $settings['editor_script_handles'] ?? array() ), array( self::EDITOR_HANDLE ) );
		$settings['uses_context']          = array_unique( array_merge( (array) ( $settings['uses_context'] ?? array() ), array( 'postId', 'postType' ) ) );
		return $settings;
	}

	/**
	 * Attributes every field block has.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function attributes(): array {
		return array(
			'data' => array(
				'type'    => 'object',
				'default' => array(),
			),
			'mode' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Fills ACF's defaults.
	 *
	 * @param array<string, mixed> $args Raw settings.
	 * @return array<string, mixed>
	 */
	private function normalize( array $args ): array {
		$config = wp_parse_args(
			$args,
			array(
				'name'            => '',
				'title'           => '',
				'description'     => '',
				'category'        => 'text',
				'icon'            => 'block-default',
				'keywords'        => array(),
				'mode'            => 'preview',
				'render_template' => '',
				'render_callback' => null,
				'supports'        => array(),
				'post_types'      => array(),
				'enqueue_style'   => '',
				'enqueue_script'  => '',
				'enqueue_assets'  => null,
			)
		);
		// ACF's old category names.
		$config['category'] = array(
			'formatting' => 'text',
			'layout'     => 'design',
			'common'     => 'text',
		)[ $config['category'] ] ?? $config['category'];
		$config['supports'] = wp_parse_args(
			(array) $config['supports'],
			array(
				'align' => true,
				'html'  => false,
			)
		);
		$config['title']    = '' !== $config['title'] ? $config['title'] : $config['name'];
		// Like ACF: `supports.jsx` lets the template place inner blocks with <InnerBlocks />.
		$config['inner_blocks'] = ! empty( $config['supports']['jsx'] );
		if ( '' !== $config['render_template'] && ! str_starts_with( (string) $config['render_template'], '/' ) ) {
			$config['render_template'] = self::resolve_path( (string) $config['render_template'], '' );
		}
		return $config;
	}

	/**
	 * Absolute template path: relative to the block.json folder, else to the theme.
	 *
	 * @param string $path Path.
	 * @param string $dir  block.json folder.
	 */
	private static function resolve_path( string $path, string $dir ): string {
		if ( str_starts_with( $path, '/' ) && file_exists( $path ) ) {
			return $path;
		}
		if ( '' !== $dir && file_exists( $dir . '/' . $path ) ) {
			return $dir . '/' . $path;
		}
		$located = locate_template( $path );
		return '' !== $located ? $located : $path;
	}

	/**
	 * All field blocks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->blocks;
	}

	/**
	 * @param string $name Block name.
	 * @return array<string, mixed>|null
	 */
	public function get( string $name ): ?array {
		return $this->blocks[ $name ] ?? null;
	}

	/**
	 * Groups shown for a block.
	 *
	 * @param string $name Block name.
	 * @return array<string, array<string, mixed>>
	 */
	public function groups_for( string $name ): array {
		return ( new FormSupport( $this->plugin ) )->groups_for( array( 'block' => $name ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks HTML.
	 * @param \WP_Block|null       $wp_block   Block instance.
	 */
	public function render( $attributes, $content = '', $wp_block = null ): string {
		$name   = $wp_block instanceof \WP_Block ? $wp_block->name : (string) ( $attributes['name'] ?? '' );
		$config = $this->get( $name );
		if ( ! $config ) {
			return '';
		}
		$data = is_array( $attributes['data'] ?? null ) ? $attributes['data'] : array();
		$id   = 'block_' . substr( md5( $name . wp_json_encode( $data ) ), 0, 12 ) . '_' . ( ++$this->counter );

		MetaStore::set_block_data( $id, $data );
		BlockContext::push( $id );

		$block      = array_merge(
			$config,
			$attributes,
			array(
				'id'   => $id,
				'name' => $name,
				'data' => $data,
			)
		);
		$post_id    = $wp_block instanceof \WP_Block && isset( $wp_block->context['postId'] ) ? (int) $wp_block->context['postId'] : (int) get_the_ID();
		$is_preview = self::is_preview();
		$context    = $wp_block instanceof \WP_Block ? $wp_block->context : array();

		self::enqueue_block_assets( $config );

		ob_start();
		try {
			if ( is_callable( $config['render_callback'] ) ) {
				call_user_func( $config['render_callback'], $block, $content, $is_preview, $post_id, $wp_block, $context );
			} elseif ( '' !== $config['render_template'] && file_exists( $config['render_template'] ) ) {
				self::include_template( $config['render_template'], $block, $content, $is_preview, $post_id, $wp_block, $context );
			} elseif ( $is_preview ) {
				printf(
					'<p class="wpff-block-missing">%s</p>',
					/* translators: %s: block name. */
					esc_html( sprintf( __( 'Block "%s" has no render template.', 'freeform-fields' ), $name ) )
				);
			}
		} finally {
			BlockContext::pop();
			MetaStore::forget_block( $id );
		}
		$html = (string) ob_get_clean();
		return $config['inner_blocks'] ? self::place_inner_blocks( $html, (string) $content, $is_preview ) : $html;
	}

	/**
	 * Replaces the template's `<InnerBlocks />` tag: with the saved inner blocks on the site,
	 * with a placeholder the editor fills with an editable InnerBlocks area in the preview.
	 *
	 * Supported attributes (JSON-encoded where they are arrays, as in ACF):
	 * allowedBlocks, template, templateLock, orientation.
	 *
	 * @param string $html       Rendered template.
	 * @param string $content    Inner blocks HTML.
	 * @param bool   $is_preview Editor preview.
	 */
	public static function place_inner_blocks( string $html, string $content, bool $is_preview ): string {
		return (string) preg_replace_callback(
			'#<InnerBlocks((?:\s[^>]*?)?)\s*(?:/>|>\s*</InnerBlocks>)#i',
			static function ( array $m ) use ( $content, $is_preview ): string {
				if ( ! $is_preview ) {
					return $content;
				}
				$props = array();
				preg_match_all( '/\b(allowedBlocks|template|templateLock|orientation)="([^"]*)"/', $m[1], $attrs, PREG_SET_ORDER );
				foreach ( $attrs as $attr ) {
					$raw     = html_entity_decode( $attr[2], ENT_QUOTES );
					$decoded = json_decode( $raw, true );
					// templateLock="false" means no lock.
					$props[ $attr[1] ] = null === $decoded && 'null' !== $raw ? $raw : $decoded;
				}
				return '<div class="wpff-inner-blocks" data-wpff-inner-blocks="' . esc_attr( (string) wp_json_encode( (object) $props ) ) . '"></div>';
			},
			$html,
			1
		);
	}

	/**
	 * Includes a template with ACF's variables in scope.
	 *
	 * @param string               $template   File.
	 * @param array<string, mixed> $block      Block settings and attributes.
	 * @param string               $content    Inner blocks HTML.
	 * @param bool                 $is_preview Rendered in the editor.
	 * @param int                  $post_id    Post being edited or viewed.
	 * @param \WP_Block|null       $wp_block   Block instance.
	 * @param array<string, mixed> $context    Block context.
	 */
	private static function include_template( string $template, array $block, string $content, bool $is_preview, int $post_id, $wp_block, array $context ): void {
		include $template;
	}

	/**
	 * Whether the block is rendered for the editor preview.
	 */
	private static function is_preview(): bool {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
		return isset( $_GET['context'] ) && 'edit' === $_GET['context'];
	}

	/**
	 * Enqueues a block's own assets (ACF's enqueue_style / enqueue_script / enqueue_assets).
	 *
	 * @param array<string, mixed> $config Block settings.
	 */
	private static function enqueue_block_assets( array $config ): void {
		$handle = 'wpff-block-' . sanitize_key( str_replace( '/', '-', $config['name'] ) );
		if ( '' !== $config['enqueue_style'] ) {
			wp_enqueue_style( $handle, $config['enqueue_style'], array(), WPFF_VERSION );
		}
		if ( '' !== $config['enqueue_script'] ) {
			wp_enqueue_script( $handle, $config['enqueue_script'], array(), WPFF_VERSION, array( 'in_footer' => true ) );
		}
		if ( is_callable( $config['enqueue_assets'] ) ) {
			call_user_func( $config['enqueue_assets'], $config );
		}
	}

	/**
	 * Validates submitted form values for a block and turns them into flat block data.
	 *
	 * @param string               $name      Block name.
	 * @param array<string, mixed> $submitted Values keyed by field key (the form's `wpff[...]`).
	 * @return array{data: array<string, mixed>, errors: array<string, string>}
	 */
	public function data_from_form( string $name, array $submitted ): array {
		$form   = new FormSupport( $this->plugin );
		$id     = 'block_form_' . ( ++$this->counter );
		$ref    = new ObjectRef( 'block', $id );
		$result = $form->validate( array( 'block' => $name ), $submitted, $id );

		MetaStore::set_block_data( $id, array() );
		foreach ( $result['values'] as $key => $value ) {
			$field = $this->plugin->fields()->get_field( $key );
			if ( $field ) {
				$this->plugin->values()->save( $field, $value, $ref );
			}
		}
		$data = MetaStore::block_data( $id );
		MetaStore::forget_block( $id );

		return array(
			'data'   => $data,
			'errors' => $result['errors'],
		);
	}

	/**
	 * Form HTML for a block's fields, filled from its data.
	 *
	 * @param string               $name Block name.
	 * @param array<string, mixed> $data Flat block data.
	 */
	public function form_html( string $name, array $data ): string {
		$id = 'block_form_' . ( ++$this->counter );
		MetaStore::set_block_data( $id, $data );
		$ref      = new ObjectRef( 'block', $id );
		$renderer = new \FreeformFields\Admin\FieldRenderer( $this->plugin->types(), $this->plugin->values() );

		ob_start();
		foreach ( $this->groups_for( $name ) as $group ) {
			// A brand-new block has no data: show defaults.
			$renderer->render_group( $group, array() === $data ? null : $ref );
		}
		MetaStore::forget_block( $id );
		return (string) ob_get_clean();
	}
}
