<?php
/**
 * Bidirectional relationships.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields;

use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps two relational fields in step, like ACF 6.2's bidirectional relationships.
 *
 * A relationship, post object, user or taxonomy field with `bidirectional` on and
 * `bidirectional_target` = [ field keys ] updates those target fields on the objects it points to:
 * choosing Y on X adds X to Y's target field; removing Y removes X from it.
 */
final class Bidirectional {

	public const TYPES = array( 'relationship', 'post_object', 'user', 'taxonomy' );

	private static bool $syncing = false;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'wpff/update_value', array( $this, 'sync' ), 20, 3 );
	}

	/**
	 * Runs before a value is stored; compares it with the stored one and updates the other side.
	 *
	 * @param mixed                $value     New value.
	 * @param string               $object_id Object id (`12`, `user_3`, `term_5`).
	 * @param array<string, mixed> $field     Field.
	 * @return mixed The value, unchanged.
	 */
	public function sync( $value, $object_id, $field ) {
		if ( self::$syncing || ! in_array( $field['type'] ?? '', self::TYPES, true ) || empty( $field['bidirectional'] ) ) {
			return $value;
		}
		$targets = array_filter( (array) ( $field['bidirectional_target'] ?? array() ) );
		$ref     = ObjectRef::parse( $object_id );
		if ( array() === $targets || ! $ref || ! in_array( $ref->type, array( 'post', 'user', 'term' ), true ) ) {
			return $value;
		}

		$old     = self::ids( $this->plugin->values()->store()->get( $ref, $field['name'] ) );
		$new     = self::ids( $value );
		$added   = array_diff( $new, $old );
		$removed = array_diff( $old, $new );
		if ( array() === $added && array() === $removed ) {
			return $value;
		}

		// The other side's objects are of the kind this field points to.
		$other_type = array(
			'user'     => 'user',
			'taxonomy' => 'term',
		)[ $field['type'] ] ?? 'post';
		$self_id    = (int) $ref->id;

		self::$syncing = true;
		try {
			foreach ( $targets as $target_key ) {
				$target = $this->plugin->fields()->get_field( (string) $target_key );
				if ( ! $target || ! in_array( $target['type'], self::TYPES, true ) ) {
					continue;
				}
				foreach ( $added as $id ) {
					$this->change( $target, new ObjectRef( $other_type, $id ), $self_id, true );
				}
				foreach ( $removed as $id ) {
					$this->change( $target, new ObjectRef( $other_type, $id ), $self_id, false );
				}
			}
		} finally {
			self::$syncing = false;
		}
		return $value;
	}

	/**
	 * Adds or removes one ID in a target field on one object.
	 *
	 * @param array<string, mixed> $target Target field.
	 * @param ObjectRef            $ref    Object holding the target field.
	 * @param int                  $id     ID to add or remove.
	 * @param bool                 $add    Add (true) or remove.
	 */
	private function change( array $target, ObjectRef $ref, int $id, bool $add ): void {
		// A logged-in user only changes objects they may edit; code and cron (no user) may change any.
		if ( is_user_logged_in() && ! current_user_can( 'edit_' . $ref->type, (int) $ref->id ) ) {
			return;
		}
		$current  = self::ids( $this->plugin->values()->store()->get( $ref, $target['name'] ) );
		$multiple = 'relationship' === $target['type']
			|| ( 'taxonomy' === $target['type'] && in_array( $target['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true ) )
			|| ! empty( $target['multiple'] );

		if ( $add ) {
			// Never replace another link stored in a single-value field.
			if ( in_array( $id, $current, true ) || ( ! $multiple && array() !== $current ) ) {
				return;
			}
			$next = $multiple ? array_merge( $current, array( $id ) ) : array( $id );
		} else {
			if ( ! in_array( $id, $current, true ) ) {
				return;
			}
			$next = array_values( array_diff( $current, array( $id ) ) );
		}
		$value = $multiple ? array_map( 'strval', $next ) : ( $next[0] ?? '' );
		$this->plugin->values()->save( $target, $value, $ref );
	}

	/**
	 * @param mixed $value Stored or new value.
	 * @return int[]
	 */
	private static function ids( $value ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : array( $value ) ) ) ) ) );
	}

	/**
	 * Settings added to relational field types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings(): array {
		$options = array();
		foreach ( Plugin::instance()->fields()->get_all_groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( in_array( $field['type'], self::TYPES, true ) ) {
					$options[ $field['key'] ] = $group['title'] . ' › ' . ( '' !== $field['label'] ? $field['label'] : $field['name'] );
				}
			}
		}
		return array(
			array(
				'name'    => 'bidirectional',
				'label'   => __( 'Bidirectional', 'freeform-fields' ),
				'control' => 'toggle',
				'help'    => __( 'Also update a field on the selected items, so both sides list each other.', 'freeform-fields' ),
			),
			array(
				'name'    => 'bidirectional_target',
				'label'   => __( 'Target Field', 'freeform-fields' ),
				'control' => 'multiselect',
				'options' => $options,
				'help'    => __( 'The field on the other side that should list this item.', 'freeform-fields' ),
				'show_if' => array( 'bidirectional' => 1 ),
			),
		);
	}
}
