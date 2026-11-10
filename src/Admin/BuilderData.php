<?php
/**
 * Data the field group builder needs: field types, location rule catalog, group summaries.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds JSON-ready descriptions for the builder UI.
 */
final class BuilderData {

	/**
	 * Max items listed as values for post/term based rules.
	 */
	private const VALUE_LIMIT = 300;

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Field types with their settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function field_types(): array {
		$categories = array(
			'basic'      => __( 'Basic', 'freeform-fields' ),
			'content'    => __( 'Content', 'freeform-fields' ),
			'choice'     => __( 'Choice', 'freeform-fields' ),
			'relational' => __( 'Relational', 'freeform-fields' ),
			'advanced'   => __( 'Advanced', 'freeform-fields' ),
			'layout'     => __( 'Layout', 'freeform-fields' ),
		);
		$types      = array();
		foreach ( $this->plugin->types()->all() as $type ) {
			$types[] = array(
				'name'     => $type->name,
				'label'    => $type->label(),
				'category' => $categories[ $type->category ] ?? ucfirst( $type->category ),
				'group'    => $type->category,
				'settings' => $type->settings(),
				'hasValue' => $type->has_value(),
				'hasSubs'  => in_array( $type->compound(), array( 'group', 'rows' ), true ) && 'clone' !== $type->name,
				'compound' => $type->compound(),
				'defaults' => (object) $type->defaults(),
			);
		}
		return $types;
	}

	/**
	 * Location rule params with labels, groups and selectable values.
	 *
	 * A param whose `values` is null takes free text (e.g. rules registered by other code).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function location_params(): array {
		$post  = __( 'Post', 'freeform-fields' );
		$page  = __( 'Page', 'freeform-fields' );
		$user  = __( 'User', 'freeform-fields' );
		$known = array(
			'post_type'         => array( $post, __( 'Post Type', 'freeform-fields' ), fn() => $this->post_types() ),
			'post_template'     => array( $post, __( 'Post Template', 'freeform-fields' ), fn() => $this->templates() ),
			'post_status'       => array( $post, __( 'Post Status', 'freeform-fields' ), fn() => $this->post_statuses() ),
			'post_format'       => array( $post, __( 'Post Format', 'freeform-fields' ), fn() => get_post_format_strings() ),
			'post_category'     => array( $post, __( 'Post Category', 'freeform-fields' ), fn() => $this->terms( array( 'category' ) ) ),
			'post_taxonomy'     => array( $post, __( 'Post Taxonomy', 'freeform-fields' ), fn() => $this->terms( $this->taxonomies() ) ),
			'post'              => array( $post, __( 'Post', 'freeform-fields' ), fn() => $this->posts( 'post' ) ),
			'page_template'     => array( $page, __( 'Page Template', 'freeform-fields' ), fn() => $this->templates() ),
			'page_type'         => array(
				$page,
				__( 'Page Type', 'freeform-fields' ),
				fn() => array(
					'front_page' => __( 'Front Page', 'freeform-fields' ),
					'posts_page' => __( 'Posts Page', 'freeform-fields' ),
					'top_level'  => __( 'Top Level Page (no parent)', 'freeform-fields' ),
					'parent'     => __( 'Parent Page (has children)', 'freeform-fields' ),
					'child'      => __( 'Child Page (has parent)', 'freeform-fields' ),
				),
			),
			'page_parent'       => array( $page, __( 'Page Parent', 'freeform-fields' ), fn() => $this->posts( 'page' ) ),
			'page'              => array( $page, __( 'Page', 'freeform-fields' ), fn() => $this->posts( 'page' ) ),
			'block'             => array( __( 'Forms', 'freeform-fields' ), __( 'Block', 'freeform-fields' ), fn() => $this->block_options() ),
			'options_page'      => array( __( 'Forms', 'freeform-fields' ), __( 'Options Page', 'freeform-fields' ), fn() => $this->options_page_options() ),
			'taxonomy'          => array( __( 'Taxonomy', 'freeform-fields' ), __( 'Taxonomy', 'freeform-fields' ), fn() => $this->taxonomy_options() ),
			'user_form'         => array(
				$user,
				__( 'User Form', 'freeform-fields' ),
				fn() => array(
					'all'  => __( 'All', 'freeform-fields' ),
					'add'  => __( 'Add New User', 'freeform-fields' ),
					'edit' => __( 'Edit User / Profile', 'freeform-fields' ),
				),
			),
			'user_role'         => array( $user, __( 'User Role', 'freeform-fields' ), fn() => array( 'all' => __( 'All', 'freeform-fields' ) ) + $this->roles() ),
			'current_user'      => array(
				$user,
				__( 'Current User', 'freeform-fields' ),
				fn() => array(
					'logged_in'     => __( 'Logged in', 'freeform-fields' ),
					'viewing_front' => __( 'Viewing front end', 'freeform-fields' ),
					'viewing_back'  => __( 'Viewing back end', 'freeform-fields' ),
				),
			),
			'current_user_role' => array( $user, __( 'Current User Role', 'freeform-fields' ), fn() => $this->roles() ),
		);

		// Known params first, in catalog order (grouped by Post / Page / User), then others.
		$names = array_merge(
			array_values( array_intersect( array_keys( $known ), $this->plugin->locations()->params() ) ),
			array_values( array_diff( $this->plugin->locations()->params(), array_keys( $known ) ) )
		);

		$params = array();
		foreach ( $names as $name ) {
			if ( isset( $known[ $name ] ) ) {
				[ $group, $label, $values ] = $known[ $name ];
				$params[]                   = array(
					'name'   => $name,
					'group'  => $group,
					'label'  => $label,
					'values' => (object) $values(),
				);
			} else {
				$params[] = array(
					'name'   => $name,
					'group'  => __( 'Other', 'freeform-fields' ),
					'label'  => $name,
					'values' => null,
				);
			}
		}
		return $params;
	}

	/**
	 * Summaries of all groups for the list screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function group_summaries(): array {
		$list = array();
		foreach ( $this->plugin->fields()->get_all_groups() as $group ) {
			$list[] = self::summary( $group, $this->plugin->fields()->is_local( $group['key'] ) );
		}
		return $list;
	}

	/**
	 * One group as listed on the list screen.
	 *
	 * @param array<string, mixed> $group    Normalized group.
	 * @param bool                 $is_local Registered in PHP.
	 * @return array<string, mixed>
	 */
	public static function summary( array $group, bool $is_local ): array {
		return array(
			'key'         => $group['key'],
			'title'       => $group['title'],
			'description' => $group['description'],
			'active'      => (bool) $group['active'],
			'source'      => $is_local ? 'php' : ( in_array( $group['local'] ?? '', array( 'json', 'acf' ), true ) ? $group['local'] : 'db' ),
			'sync'        => ! $is_local && \FreeformFields\Registry\LocalJson::needs_sync( $group ),
			'field_count' => count( $group['fields'] ),
			'location'    => $group['location'],
			'menu_order'  => $group['menu_order'],
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function post_types(): array {
		$values = array( 'all' => __( 'All', 'freeform-fields' ) );
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'wp_block', 'wp_navigation', Plugin::GROUP_POST_TYPE ), true ) ) {
				continue;
			}
			$values[ $type->name ] = $type->labels->singular_name;
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function templates(): array {
		$values = array( 'default' => __( 'Default Template', 'freeform-fields' ) );
		$theme  = wp_get_theme();
		foreach ( get_post_types( array( 'show_ui' => true ) ) as $post_type ) {
			foreach ( $theme->get_page_templates( null, $post_type ) as $file => $name ) {
				$values[ $file ] = $name;
			}
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function block_options(): array {
		$values = array( 'all' => __( 'All', 'freeform-fields' ) );
		foreach ( $this->plugin->blocks()->all() as $name => $block ) {
			$values[ $name ] = $block['title'];
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function options_page_options(): array {
		$values = array();
		foreach ( $this->plugin->options_pages()->all( true ) as $slug => $page ) {
			$values[ $slug ] = $page['page_title'];
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function taxonomy_options(): array {
		$values = array( 'all' => __( 'All', 'freeform-fields' ) );
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$values[ $tax->name ] = $tax->labels->singular_name;
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function post_statuses(): array {
		$values = array();
		foreach ( get_post_stati( array( 'show_in_admin_status_list' => true ), 'objects' ) as $status ) {
			$values[ $status->name ] = (string) $status->label;
		}
		return $values;
	}

	/**
	 * Public taxonomies other than category and post_format.
	 *
	 * @return string[]
	 */
	private function taxonomies(): array {
		return array_values(
			array_diff(
				get_taxonomies( array( 'show_ui' => true ) ),
				array( 'category', 'post_format', 'link_category', 'wp_pattern_category' )
			)
		);
	}

	/**
	 * Terms as `taxonomy:slug` => `Taxonomy: Name`.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 * @return array<string, string>
	 */
	private function terms( array $taxonomies ): array {
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'number'     => self::VALUE_LIMIT,
			)
		);
		$values = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$tax = get_taxonomy( $term->taxonomy );
			$values[ $term->taxonomy . ':' . $term->slug ] = ( $tax ? $tax->labels->singular_name : $term->taxonomy ) . ': ' . $term->name;
		}
		return $values;
	}

	/**
	 * Recent posts of a type as ID => title.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, string>
	 */
	private function posts( string $post_type ): array {
		$posts  = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::VALUE_LIMIT,
				'orderby'        => 'hierarchical' === $post_type ? 'menu_order' : 'title',
				'order'          => 'ASC',
			)
		);
		$values = array();
		foreach ( $posts as $post ) {
			$values[ (string) $post->ID ] = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );
		}
		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	private function roles(): array {
		$values = array();
		if ( is_multisite() ) {
			$values['super_admin'] = __( 'Super Admin', 'freeform-fields' );
		}
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$values[ $role ] = translate_user_role( $name );
		}
		return $values;
	}
}
