<?php
/**
 * Tab field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a tab: following fields (until the next tab or an endpoint) belong to it.
 * Tabs are assembled in the browser from these markers.
 */
class Tab extends LayoutField {

	public string $name = 'tab';

	public function label(): string {
		return __( 'Tab', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// 'top' or 'left'.
			'placement' => 'top',
			'endpoint'  => 0,
		);
	}

	public function settings(): array {
		return array(
			self::setting(
				'placement',
				__( 'Placement', 'freeform-fields' ),
				'select',
				array(
					'options' => array(
						'top'  => __( 'Top aligned', 'freeform-fields' ),
						'left' => __( 'Left aligned', 'freeform-fields' ),
					),
				)
			),
			self::setting( 'endpoint', __( 'New Tab Group', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Start a new set of tabs.', 'freeform-fields' ) ) ),
		);
	}

	public function render_input( array $field ): void {
		printf(
			'<span class="wpff-tab-marker" data-label="%s" data-placement="%s" data-endpoint="%d" hidden></span>',
			esc_attr( $field['label'] ),
			esc_attr( 'left' === $field['placement'] ? 'left' : 'top' ),
			! empty( $field['endpoint'] ) ? 1 : 0
		);
	}
}
