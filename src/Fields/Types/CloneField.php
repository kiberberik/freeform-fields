<?php
/**
 * Clone field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reuses fields from other groups.
 *
 * The registry expands clones when it builds groups (see FieldRegistry::expand_clones()):
 *  - `seamless`: the clone is replaced by copies of the cloned fields, as if they were defined here;
 *  - `group`: the clone stays and holds the copies as sub fields, storing them as `{clone}_{field}`.
 *
 * Copies get keys `{clone key}_{original key}`, so they are stable and unique.
 */
class CloneField extends Group {

	public string $name = 'clone';

	public function label(): string {
		return __( 'Clone', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// Field keys and/or group keys (a group key clones all its fields).
			'clone'        => array(),
			// 'seamless' or 'group'.
			'display'      => 'seamless',
			'layout'       => 'block',
			'prefix_label' => 0,
			'prefix_name'  => 0,
			'sub_fields'   => array(),
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'clone',
				__( 'Fields', 'freeform-fields' ),
				'multiselect',
				array(
					'options' => self::clone_options(),
					'help'    => __( 'Select a whole field group or single fields to reuse.', 'freeform-fields' ),
				)
			),
			self::setting(
				'display',
				__( 'Display', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'seamless' => __( 'Seamless (replaces this field with the selected fields)', 'freeform-fields' ),
						'group'    => __( 'Group (shows the selected fields inside this field)', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'prefix_label', __( 'Prefix Field Labels', 'freeform-fields' ), 'toggle' ),
			self::setting( 'prefix_name', __( 'Prefix Field Names', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Store values as {this name}_{field name}.', 'freeform-fields' ) ) ),
		);
	}

	/**
	 * Groups and their top-level fields that can be cloned.
	 *
	 * @return array<string, string>
	 */
	private static function clone_options(): array {
		$options = array();
		foreach ( Plugin::instance()->fields()->get_all_groups() as $group ) {
			/* translators: %s: field group title. */
			$options[ $group['key'] ] = sprintf( __( 'All fields of "%s"', 'freeform-fields' ), $group['title'] );
			foreach ( $group['fields'] as $field ) {
				if ( 'clone' === $field['type'] || str_contains( $field['key'], '_field_' ) ) {
					continue;
				}
				$options[ $field['key'] ] = $group['title'] . ' › ' . ( '' !== $field['label'] ? $field['label'] : $field['name'] );
			}
		}
		return $options;
	}
}
