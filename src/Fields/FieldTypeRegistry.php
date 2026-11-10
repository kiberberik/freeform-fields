<?php
/**
 * Registry of available field types.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields;

use FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a type name (`text`, `image`, ...) to its handler.
 */
final class FieldTypeRegistry {

	/**
	 * Registered types.
	 *
	 * @var array<string, AbstractField>
	 */
	private array $types = array();

	private bool $loaded = false;

	/**
	 * Registers a field type, replacing any existing type with the same name.
	 *
	 * @param AbstractField $type Handler.
	 */
	public function register( AbstractField $type ): void {
		if ( '' === $type->name ) {
			throw new \InvalidArgumentException( 'Field type must define $name.' );
		}
		$this->types[ $type->name ] = $type;
	}

	/**
	 * Returns the handler for a type, or null when unknown.
	 *
	 * @param string $name Type name.
	 */
	public function get( string $name ): ?AbstractField {
		$this->load();
		return $this->types[ $name ] ?? null;
	}

	/**
	 * All registered types.
	 *
	 * @return array<string, AbstractField>
	 */
	public function all(): array {
		$this->load();
		return $this->types;
	}

	/**
	 * Registers the built-in types and lets other code add theirs, once.
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		foreach (
			array(
				new Types\Text(),
				new Types\Textarea(),
				new Types\Number(),
				new Types\Email(),
				new Types\Url(),
				new Types\Select(),
				new Types\Checkbox(),
				new Types\Radio(),
				new Types\TrueFalse(),
				new Types\Image(),
				new Types\Password(),
				new Types\Range(),
				new Types\ButtonGroup(),
				new Types\Wysiwyg(),
				new Types\Oembed(),
				new Types\File(),
				new Types\Link(),
				new Types\PostObject(),
				new Types\PageLink(),
				new Types\Relationship(),
				new Types\Taxonomy(),
				new Types\User(),
				new Types\DatePicker(),
				new Types\DateTimePicker(),
				new Types\TimePicker(),
				new Types\ColorPicker(),
				new Types\GoogleMap(),
				new Types\Message(),
				new Types\Accordion(),
				new Types\Tab(),
				new Types\Group(),
				new Types\Repeater(),
				new Types\FlexibleContent(),
				new Types\Gallery(),
				new Types\CloneField(),
			) as $type
		) {
			$this->register( $type );
		}

		/**
		 * Register custom field types here with `$registry->register( new My_Type() )`.
		 *
		 * @param FieldTypeRegistry $registry Registry.
		 */
		do_action( 'wpff/register_field_types', $this );
	}
}
