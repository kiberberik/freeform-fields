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

	/**
	 * Fields offered in the Block Bindings field picker: top-level fields of active groups with
	 * "Allow in Block Bindings" on, with the post types their group is shown on (empty: any).
	 *
	 * @return array<int, array{key: string, label: string, group: string, postTypes: array<int, string>}>
	 */
	private function bindable_fields(): array {
		$not_posts = array( 'options_page', 'taxonomy', 'user_form', 'user_role', 'current_user', 'current_user_role', 'block', 'nav_menu', 'nav_menu_item', 'comment', 'widget', 'attachment' );
		$list      = array();
		foreach ( $this->plugin->fields()->get_all_groups() as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}
			$fields = array_filter(
				$group['fields'],
				static fn( $field ) => ! empty( $field['allow_in_bindings'] ) && '' !== (string) ( $field['name'] ?? '' )
			);
			if ( array() === $fields ) {
				continue;
			}
			$post_types = array();
			$any        = false;
			$on_posts   = false;
			foreach ( (array) $group['location'] as $rules ) {
				$params = array_column( (array) $rules, 'param' );
				$types  = array();
				foreach ( (array) $rules as $rule ) {
					if ( 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
						$types[] = (string) $rule['value'];
					}
				}
				if ( array() !== $types ) {
					$post_types = array_merge( $post_types, $types );
					$on_posts   = true;
				} elseif ( array() === array_intersect( $params, $not_posts ) ) {
					$any      = true;
					$on_posts = true;
				}
			}
			if ( ! $on_posts ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$list[] = array(
					'key'       => (string) $field['name'],
					'label'     => (string) ( '' !== (string) ( $field['label'] ?? '' ) ? $field['label'] : $field['name'] ),
					'group'     => (string) $group['title'],
					'postTypes' => $any ? array() : array_values( array_unique( $post_types ) ),
				);
			}
		}
		return $list;
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
					'bindings' => $this->bindable_fields(),
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
