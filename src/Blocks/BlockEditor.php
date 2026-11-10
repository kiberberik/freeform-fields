<?php
/**
 * Editor assets for field blocks.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Blocks;

use FreeformFields\Admin\Assets;
use FreeformFields\Admin\FormSupport;
use FreeformFields\Plugin;
use FreeformFields\Rest\FieldGroupsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the editor script that gives field blocks their edit UI, and loads the field
 * scripts the block forms need.
 */
final class BlockEditor {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_script' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function register_script(): void {
		$asset_file = WPFF_PATH . 'build/blocks.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => WPFF_VERSION,
		);
		wp_register_script( BlockRegistry::EDITOR_HANDLE, WPFF_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], array( 'in_footer' => true ) );
		wp_set_script_translations( BlockRegistry::EDITOR_HANDLE, 'freeform-fields', WPFF_PATH . 'languages' );
	}

	public function enqueue(): void {
		$blocks = $this->plugin->blocks()->all();
		// The script also registers the Block Bindings source, so it loads without field blocks too.
		wp_enqueue_script( BlockRegistry::EDITOR_HANDLE );
		$list  = array();
		$types = array();
		foreach ( $blocks as $name => $config ) {
			$groups = $this->plugin->blocks()->groups_for( $name );
			$types  = array_merge( $types, FormSupport::types_of( $groups ) );
			$list[] = array(
				'name'        => $name,
				'mode'        => $config['mode'],
				'hasFields'   => array() !== $groups,
				'innerBlocks' => ! empty( $config['inner_blocks'] ),
			);
		}
		wp_add_inline_script(
			BlockRegistry::EDITOR_HANDLE,
			'window.wpffBlocks = ' . wp_json_encode(
				array(
					'blocks'   => $list,
					'restPath' => '/' . FieldGroupsController::NAMESPACE,
				)
			) . ';',
			'before'
		);
		if ( array() === $blocks ) {
			return;
		}

		$post = get_post();
		Assets::enqueue_form(
			$this->plugin,
			array_values( array_unique( $types ) ),
			array(
				'object'      => $post ? 'post_' . $post->ID : '',
				'postId'      => $post ? $post->ID : 0,
				'blockEditor' => true,
			)
		);
		wp_enqueue_style( 'wpff-blocks', WPFF_URL . 'assets/css/blocks.css', array( Assets::HANDLE ), WPFF_VERSION );
	}
}
