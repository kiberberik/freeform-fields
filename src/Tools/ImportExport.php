<?php
/**
 * Export, import and migration from ACF.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tools;

use FreeformFields\Plugin;
use FreeformFields\Registry\LocalJson;

defined( 'ABSPATH' ) || exit;

/**
 * - Export groups as JSON (ACF's export format) or as PHP code for `wpff_add_local_field_group()`.
 * - Import a JSON export (from WP Freeform Fields or ACF) into the database.
 * - Read field groups that ACF stored in the database (`acf-field-group` posts with `acf-field`
 *   children) and import them, keeping keys so existing values stay linked.
 */
final class ImportExport {

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Groups in export format.
	 *
	 * @param string[] $keys Group keys; empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function export( array $keys = array() ): array {
		$out = array();
		foreach ( $this->plugin->fields()->get_all_group_definitions() as $key => $group ) {
			if ( array() === $keys || in_array( $key, $keys, true ) ) {
				$out[] = LocalJson::export_shape( $group );
			}
		}
		return $out;
	}

	/**
	 * PHP code registering the groups.
	 *
	 * @param string[] $keys Group keys; empty for all.
	 */
	public function export_php( array $keys = array() ): string {
		$code = "add_action( 'wpff/init', function () {\n";
		foreach ( $this->export( $keys ) as $group ) {
			unset( $group['modified'] );
			$code .= "\twpff_add_local_field_group( " . self::php_value( $group, 1 ) . " );\n";
		}
		return $code . "} );\n";
	}

	/**
	 * Short-array PHP literal with tab indentation.
	 *
	 * @param mixed $value  Value.
	 * @param int   $indent Indent level.
	 */
	private static function php_value( $value, int $indent ): string {
		if ( ! is_array( $value ) ) {
			return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- generating code.
		}
		if ( array() === $value ) {
			return 'array()';
		}
		$tabs = str_repeat( "\t", $indent + 1 );
		$list = array_is_list( $value );
		$out  = "array(\n";
		foreach ( $value as $k => $v ) {
			$out .= $tabs . ( $list ? '' : var_export( $k, true ) . ' => ' ) . self::php_value( $v, $indent + 1 ) . ",\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return $out . str_repeat( "\t", $indent ) . ')';
	}

	/**
	 * Imports decoded JSON: one item or a list. Field groups are the usual content; an ACF 6 export
	 * may also hold post types, taxonomies and options pages, which are imported first so that
	 * location rules find their targets. Keys are kept; existing items are updated.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>, warnings: string[]}
	 */
	public function import( $data ): array {
		$items    = is_array( $data ) && isset( $data['key'] ) ? array( $data ) : ( is_array( $data ) ? $data : array() );
		$imported = array();
		$errors   = array();
		$warnings = array();
		$groups   = array();
		$types    = array();
		$pages    = array();
		foreach ( $items as $i => $item ) {
			$kind = is_array( $item ) ? self::acf_item_kind( $item ) : '';
			if ( 'options_page' === $kind ) {
				$pages[ $i ] = $item;
			} elseif ( '' !== $kind ) {
				$types[ $i ] = array( $kind, $item );
			} else {
				$groups[ $i ] = $item;
			}
		}

		foreach ( $types as $i => list( $kind, $acf ) ) {
			$item = self::acf_content_type_item( $kind, $acf, ! isset( $acf['active'] ) || ! empty( $acf['active'] ) );
			if ( '' === $item['key'] ) {
				/* translators: %d: position in the file. */
				$errors[ 'item_' . $i ] = sprintf( __( 'Item %d has no post type or taxonomy key.', 'freeform-fields' ), $i + 1 );
				continue;
			}
			$store    = $this->plugin->content_types();
			$original = $store->find_post( $kind, sanitize_key( $item['key'] ) ) ? sanitize_key( $item['key'] ) : '';
			$saved    = $store->save( $kind, $item, $original );
			if ( is_wp_error( $saved ) ) {
				$errors[ $kind . ':' . $item['key'] ] = $saved->get_error_message();
				continue;
			}
			$imported[] = array(
				'key'   => $saved['key'],
				'title' => $saved['plural'],
			);
		}

		foreach ( $pages as $i => $acf ) {
			$page = self::acf_options_page_item( $acf, ! isset( $acf['active'] ) || ! empty( $acf['active'] ) );
			if ( null === $page ) {
				/* translators: %d: position in the file. */
				$errors[ 'item_' . $i ] = sprintf( __( 'Item %d is an options page without a menu slug.', 'freeform-fields' ), $i + 1 );
				continue;
			}
			$store    = $this->plugin->options_store();
			$slug     = sanitize_title( $page['menu_slug'] );
			$original = $store->find_post( $slug ) ? $slug : '';
			$saved    = $store->save( $page, $original );
			if ( is_wp_error( $saved ) ) {
				$errors[ 'options_page:' . $page['menu_slug'] ] = $saved->get_error_message();
				continue;
			}
			$imported[] = array(
				'key'   => $saved['menu_slug'],
				'title' => $saved['page_title'],
			);
		}

		foreach ( $groups as $i => $group ) {
			if ( ! is_array( $group ) || empty( $group['key'] ) || empty( $group['title'] ) ) {
				/* translators: %d: position in the file. */
				$errors[ 'item_' . $i ] = sprintf( __( 'Item %d is not a field group.', 'freeform-fields' ), $i + 1 );
				continue;
			}
			$group  = self::from_acf_shape( $group );
			$group  = $this->drop_unknown_types( $group, $warnings );
			$result = $this->plugin->groups()->save( $group );
			if ( is_wp_error( $result ) ) {
				$errors[ (string) $group['key'] ] = $result->get_error_message();
				continue;
			}
			$imported[] = array(
				'key'   => $result['key'],
				'title' => $result['title'],
			);
		}
		return array(
			'imported' => $imported,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * What a non-group item of an ACF 6 export is, by its key prefix: 'post_type', 'taxonomy',
	 * 'options_page', or '' for anything else (a field group).
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private static function acf_item_kind( array $item ): string {
		$key = (string) ( $item['key'] ?? '' );
		foreach ( array(
			'post_type_'       => 'post_type',
			'taxonomy_'        => 'taxonomy',
			'ui_options_page_' => 'options_page',
		) as $prefix => $kind ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return $kind;
			}
		}
		return '';
	}

	/**
	 * Removes fields whose type WP Freeform Fields does not have (e.g. google_map), noting each one.
	 *
	 * @param array<string, mixed> $group    Group.
	 * @param string[]             $warnings Warnings (updated).
	 * @return array<string, mixed>
	 */
	private function drop_unknown_types( array $group, array &$warnings ): array {
		$types           = $this->plugin->types();
		$walk            = function ( array $fields ) use ( &$walk, $types, $group, &$warnings ): array {
			$out = array();
			foreach ( $fields as $field ) {
				$type = (string) ( $field['type'] ?? 'text' );
				if ( ! $types->get( $type ) ) {
					$warnings[] = sprintf(
						/* translators: 1: field label, 2: field type, 3: group title. */
						__( 'Skipped field "%1$s" (type "%2$s" is not supported) in "%3$s".', 'freeform-fields' ),
						$field['label'] ?? $field['name'] ?? '?',
						$type,
						$group['title']
					);
					continue;
				}
				if ( ! empty( $field['sub_fields'] ) ) {
					$field['sub_fields'] = $walk( $field['sub_fields'] );
				}
				foreach ( $field['layouts'] ?? array() as $i => $layout ) {
					$field['layouts'][ $i ]['sub_fields'] = $walk( $layout['sub_fields'] ?? array() );
				}
				$out[] = $field;
			}
			return $out;
		};
		$group['fields'] = $walk( $group['fields'] );
		return $group;
	}

	/**
	 * Field groups ACF stored in the database.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function acf_db_groups(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ACF's post types are not registered when ACF is inactive.
		$posts  = $wpdb->get_results(
			"SELECT ID, post_name, post_title, post_content, post_status, menu_order FROM {$wpdb->posts}
			WHERE post_type = 'acf-field-group' AND post_status IN ('publish','acf-disabled') ORDER BY menu_order, post_title"
		);
		$groups = array();
		foreach ( $posts as $post ) {
			$settings = maybe_unserialize( $post->post_content );
			$settings = is_array( $settings ) ? $settings : array();
			$groups[] = array_merge(
				$settings,
				array(
					'key'        => $post->post_name,
					'title'      => $post->post_title,
					'menu_order' => (int) $post->menu_order,
					'active'     => 'publish' === $post->post_status,
					'fields'     => $this->acf_db_fields( (int) $post->ID ),
				)
			);
		}
		return $groups;
	}

	/**
	 * Children of an ACF group or field post, with their own children.
	 *
	 * @param int $parent Parent post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function acf_db_fields( int $parent ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_title, post_excerpt, post_content, menu_order FROM {$wpdb->posts}
				WHERE post_type = 'acf-field' AND post_parent = %d AND post_status = 'publish' ORDER BY menu_order",
				$parent
			)
		);
		$fields = array();
		foreach ( $posts as $post ) {
			$settings = maybe_unserialize( $post->post_content );
			$field    = array_merge(
				is_array( $settings ) ? $settings : array(),
				array(
					'key'   => $post->post_name,
					'label' => $post->post_title,
					'name'  => $post->post_excerpt,
				)
			);
			$children = $this->acf_db_fields( (int) $post->ID );
			if ( 'flexible_content' === ( $field['type'] ?? '' ) ) {
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$layout['sub_fields'] = array_values(
						array_filter( $children, static fn( $c ) => ( $c['parent_layout'] ?? '' ) === ( $layout['key'] ?? '' ) )
					);
					$layouts[]            = $layout;
				}
				$field['layouts'] = $layouts;
			} elseif ( array() !== $children ) {
				$field['sub_fields'] = $children;
			}
			$fields[] = $field;
		}
		return $fields;
	}

	/**
	 * Post types and taxonomies ACF (6.1+) stored in the database, in WP Freeform Fields' shape.
	 *
	 * @return array{post_type: array<int, array<string, mixed>>, taxonomy: array<int, array<string, mixed>>}
	 */
	public function acf_db_content_types(): array {
		global $wpdb;
		$out = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);
		$map = array(
			'acf-post-type' => 'post_type',
			'acf-taxonomy'  => 'taxonomy',
		);
		foreach ( $map as $acf_type => $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_content, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','acf-disabled')",
					$acf_type
				)
			);
			foreach ( $rows as $row ) {
				$acf = maybe_unserialize( $row->post_content );
				if ( ! is_array( $acf ) ) {
					continue;
				}
				$out[ $kind ][] = self::acf_content_type_item( $kind, $acf, 'publish' === $row->post_status );
			}
		}
		return $out;
	}

	/**
	 * Converts one ACF post type or taxonomy (database settings or export item) to WP Freeform Fields' shape.
	 *
	 * @param string               $kind   'post_type' or 'taxonomy'.
	 * @param array<string, mixed> $acf    ACF settings.
	 * @param bool                 $active Whether it is active.
	 * @return array<string, mixed>
	 */
	private static function acf_content_type_item( string $kind, array $acf, bool $active ): array {
		$labels = (array) ( $acf['labels'] ?? array() );
		$icon   = $acf['menu_icon'] ?? '';
		$item   = array(
			'key'          => (string) ( $acf[ $kind ] ?? '' ),
			'singular'     => (string) ( $labels['singular_name'] ?? '' ),
			'plural'       => (string) ( $labels['name'] ?? '' ),
			'description'  => (string) ( $acf['description'] ?? '' ),
			'public'       => ! empty( $acf['public'] ),
			'hierarchical' => ! empty( $acf['hierarchical'] ),
			'show_in_rest' => ! empty( $acf['show_in_rest'] ),
			'rewrite_slug' => (string) ( $acf['rewrite']['slug'] ?? '' ),
			'active'       => $active,
		);
		if ( 'post_type' === $kind ) {
			$item += array(
				'menu_icon'           => is_array( $icon ) ? (string) ( $icon['value'] ?? '' ) : (string) $icon,
				'menu_position'       => $acf['menu_position'] ?? null,
				'supports'            => (array) ( $acf['supports'] ?? array() ),
				'taxonomies'          => (array) ( $acf['taxonomies'] ?? array() ),
				'has_archive'         => ! empty( $acf['has_archive'] ),
				'exclude_from_search' => ! empty( $acf['exclude_from_search'] ),
			);
		} else {
			$item += array(
				'object_type'       => (array) ( $acf['object_type'] ?? array() ),
				'show_admin_column' => ! empty( $acf['show_admin_column'] ),
			);
		}
		return $item;
	}

	/**
	 * Imports ACF's post types and taxonomies. Items whose key is already registered are skipped.
	 *
	 * @return array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>, warnings: string[]}
	 */
	public function import_acf_content_types(): array {
		$result = array(
			'imported' => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		foreach ( $this->acf_db_content_types() as $kind => $items ) {
			foreach ( $items as $item ) {
				$saved = $this->plugin->content_types()->save( $kind, $item );
				if ( is_wp_error( $saved ) ) {
					$result['errors'][ $kind . ':' . $item['key'] ] = $saved->get_error_message();
					continue;
				}
				$result['imported'][] = array(
					'key'   => $saved['key'],
					'title' => $saved['plural'],
				);
			}
		}
		return $result;
	}

	/**
	 * Options pages created in ACF's admin UI (ACF 6.2+), in WP Freeform Fields' shape.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function acf_db_options_pages(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT post_content, post_status FROM {$wpdb->posts} WHERE post_type = 'acf-ui-options-page' AND post_status IN ('publish','acf-disabled') ORDER BY menu_order, ID"
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$acf = maybe_unserialize( $row->post_content );
			if ( ! is_array( $acf ) || empty( $acf['menu_slug'] ) ) {
				continue;
			}
			$out[] = self::acf_options_page_item( $acf, 'publish' === $row->post_status );
		}
		return $out;
	}

	/**
	 * Converts one ACF UI options page (database settings or export item) to WP Freeform Fields' shape.
	 *
	 * @param array<string, mixed> $acf    ACF settings.
	 * @param bool                 $active Whether it is active.
	 * @return array<string, mixed>|null Null when the page has no menu slug.
	 */
	private static function acf_options_page_item( array $acf, bool $active ): ?array {
		if ( empty( $acf['menu_slug'] ) ) {
			return null;
		}
		$icon    = $acf['menu_icon'] ?? ( $acf['icon_url'] ?? '' );
		$parent  = (string) ( $acf['parent_slug'] ?? '' );
		$storage = (string) ( $acf['data_storage'] ?? '' );
		return array(
			'page_title'      => (string) ( $acf['page_title'] ?? '' ),
			'menu_title'      => (string) ( $acf['menu_title'] ?? '' ),
			'menu_slug'       => (string) $acf['menu_slug'],
			'parent_slug'     => 'none' === $parent ? '' : $parent,
			'capability'      => (string) ( $acf['capability'] ?? 'edit_posts' ),
			'position'        => $acf['position'] ?? null,
			'icon_url'        => is_array( $icon ) ? (string) ( $icon['value'] ?? '' ) : (string) $icon,
			'redirect'        => ! empty( $acf['redirect'] ),
			'post_id'         => 'custom_storage' === $storage ? (string) ( $acf['custom_storage'] ?? 'options' ) : (string) ( $acf['post_id'] ?? 'options' ),
			'autoload'        => ! empty( $acf['autoload'] ),
			'update_button'   => (string) ( $acf['update_button'] ?? '' ),
			'updated_message' => (string) ( $acf['updated_message'] ?? '' ),
			'description'     => (string) ( $acf['description'] ?? '' ),
			'active'          => $active,
		);
	}

	/**
	 * Imports ACF's UI options pages. Pages whose slug already exists are skipped.
	 *
	 * @return array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>, warnings: string[]}
	 */
	public function import_acf_options_pages(): array {
		$result = array(
			'imported' => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		$store  = $this->plugin->options_store();
		foreach ( $this->acf_db_options_pages() as $page ) {
			if ( $store->find_post( sanitize_title( $page['menu_slug'] ) ) || $this->plugin->options_pages()->is_local( sanitize_title( $page['menu_slug'] ) ) ) {
				/* translators: %s: options page slug. */
				$result['warnings'][] = sprintf( __( 'Options page "%s" already exists and was skipped.', 'freeform-fields' ), $page['menu_slug'] );
				continue;
			}
			$saved = $store->save( $page );
			if ( is_wp_error( $saved ) ) {
				$result['errors'][ 'options_page:' . $page['menu_slug'] ] = $saved->get_error_message();
				continue;
			}
			$result['imported'][] = array(
				'key'   => $saved['menu_slug'],
				'title' => $saved['page_title'],
			);
		}
		return $result;
	}

	/**
	 * Imports everything ACF stored in the database: post types, taxonomies, options pages
	 * and field groups (in that order, so location rules find their targets).
	 *
	 * @return array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>, warnings: string[]}
	 */
	public function import_acf_all(): array {
		$parts = array( $this->import_acf_content_types(), $this->import_acf_options_pages(), $this->import_acf() );
		return array(
			'imported' => array_merge( ...array_column( $parts, 'imported' ) ),
			'errors'   => array_merge( ...array_column( $parts, 'errors' ) ),
			'warnings' => array_merge( ...array_column( $parts, 'warnings' ) ),
		);
	}

	/**
	 * Imports every group ACF stored in the database.
	 *
	 * @return array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>}
	 */
	public function import_acf(): array {
		return $this->import( $this->acf_db_groups() );
	}

	/**
	 * Smooths over ACF export details: layouts keyed by layout key, choices as objects, empty labels, etc.
	 *
	 * @param array<string, mixed> $group Group.
	 * @return array<string, mixed>
	 */
	public static function from_acf_shape( array $group ): array {
		$fix             = static function ( array $fields ) use ( &$fix ): array {
			$out = array();
			foreach ( array_values( $fields ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
					$field['sub_fields'] = $fix( $field['sub_fields'] );
				}
				if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
					$field['layouts'] = array_values( $field['layouts'] );
					foreach ( $field['layouts'] as $i => $layout ) {
						$field['layouts'][ $i ]['sub_fields'] = $fix( (array) ( $layout['sub_fields'] ?? array() ) );
						if ( is_array( $layout ) && '' === trim( (string) ( $layout['label'] ?? '' ) ) ) {
							$field['layouts'][ $i ]['label'] = (string) ( $layout['name'] ?? '' );
						}
					}
				}
				// ACF allows an empty label; fields that store a value fall back to their name.
				if ( '' === trim( (string) ( $field['label'] ?? '' ) ) && ! in_array( $field['type'] ?? '', array( 'tab', 'message', 'accordion' ), true ) ) {
					$field['label'] = (string) ( $field['name'] ?? '' );
				}
				// ACF stores the post type filter as a list or a single string.
				if ( isset( $field['post_type'] ) && is_string( $field['post_type'] ) ) {
					$field['post_type'] = '' === $field['post_type'] ? array() : array( $field['post_type'] );
				}
				if ( isset( $field['role'] ) && is_string( $field['role'] ) ) {
					$field['role'] = '' === $field['role'] ? array() : array( $field['role'] );
				}
				$out[] = $field;
			}
			return $out;
		};
		$group['fields'] = $fix( (array) ( $group['fields'] ?? array() ) );
		return $group;
	}
}
