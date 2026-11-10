<?php
/**
 * Accordion field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a collapsible section: following fields (until the next accordion) belong to it.
 * An accordion with `endpoint` closes the previous section without starting a new one.
 */
class Accordion extends LayoutField {

	public string $name = 'accordion';

	public function label(): string {
		return __( 'Accordion', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			'open'         => 0,
			'multi_expand' => 0,
			'endpoint'     => 0,
		);
	}

	public function settings(): array {
		return array(
			self::setting( 'open', __( 'Open', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Show this section open when the page loads.', 'freeform-fields' ) ) ),
			self::setting( 'multi_expand', __( 'Multi-Expand', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Allow this section to stay open when another opens.', 'freeform-fields' ) ) ),
			self::setting( 'endpoint', __( 'Endpoint', 'freeform-fields' ), 'toggle', array( 'help' => __( 'Close the previous accordion here; fields below are not in a section.', 'freeform-fields' ) ) ),
		);
	}

	public function render_input( array $field ): void {
		printf(
			'<span class="wpff-accordion-marker" data-label="%s" data-open="%d" data-multi="%d" data-endpoint="%d" hidden></span>',
			esc_attr( $field['label'] ),
			! empty( $field['open'] ) ? 1 : 0,
			! empty( $field['multi_expand'] ) ? 1 : 0,
			! empty( $field['endpoint'] ) ? 1 : 0
		);
	}
}
