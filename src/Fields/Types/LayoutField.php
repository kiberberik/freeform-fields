<?php
/**
 * Base for fields that only shape the form and store nothing.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

use FreeformFields\Fields\AbstractField;

defined( 'ABSPATH' ) || exit;

/**
 * Message, Tab and Accordion.
 */
abstract class LayoutField extends AbstractField {

	public string $category = 'layout';

	public function has_value(): bool {
		return false;
	}

	public function sanitize( $value, array $field ) {
		return null;
	}
}
