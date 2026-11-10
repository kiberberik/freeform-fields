<?php
/**
 * User field.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Picks one or more users, optionally limited to roles.
 *
 * Searching users needs the `list_users` capability, so user data is not exposed to authors.
 */
class User extends RelationalField {

	public string $name = 'user';

	public function label(): string {
		return __( 'User', 'freeform-fields' );
	}

	public function defaults(): array {
		return array(
			// Empty means all roles.
			'role'                 => array(),
			'allow_null'           => 0,
			'multiple'             => 0,
			// 'array', 'object' or 'id'.
			'return_format'        => 'array',
			'bidirectional'        => 0,
			'bidirectional_target' => array(),
		);
	}

	public function settings(): array {
		return array_merge( $this->own_settings(), \FreeformFields\Fields\Bidirectional::settings() );
	}

	/**
	 * Settings of this type without the shared bidirectional ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function own_settings(): array {
		$roles = array();
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$roles[ $role ] = translate_user_role( $name );
		}
		return array_merge(
			array(
				self::setting(
					'role',
					__( 'Filter by Role', 'freeform-fields' ),
					'multiselect',
					array(
						'options' => $roles,
						'help'    => __( 'None selected: all roles.', 'freeform-fields' ),
					)
				),
			),
			self::multiple_settings(),
			array(
				self::setting(
					'return_format',
					__( 'Return Format', 'freeform-fields' ),
					'select',
					array(
						'options' => array(
							'array'  => __( 'User Array', 'freeform-fields' ),
							'object' => __( 'User Object', 'freeform-fields' ),
							'id'     => __( 'User ID', 'freeform-fields' ),
						),
					)
				),
			)
		);
	}

	public function search_capability(): string {
		return 'list_users';
	}

	/**
	 * @param array<string, mixed> $field Field.
	 * @return string[]
	 */
	private static function roles( array $field ): array {
		return array_values( array_filter( (array) ( $field['role'] ?? array() ) ) );
	}

	public function query( array $field, string $search, int $page ): array {
		$args = array(
			'number'  => self::PER_PAGE,
			'paged'   => max( 1, $page ),
			'orderby' => 'display_name',
			'fields'  => array( 'ID', 'display_name', 'user_login' ),
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}
		if ( self::roles( $field ) ) {
			$args['role__in'] = self::roles( $field );
		}
		$query   = new \WP_User_Query( self::query_filter( $args, $field ) );
		$results = array();
		foreach ( $query->get_results() as $user ) {
			$results[] = array(
				'id'   => (int) $user->ID,
				'text' => $user->display_name . ' (' . $user->user_login . ')',
			);
		}
		return array(
			'results' => $results,
			'more'    => $page * self::PER_PAGE < $query->get_total(),
		);
	}

	protected function item_text( int $id, array $field ): ?string {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return null;
		}
		$roles = self::roles( $field );
		if ( $roles && ! array_intersect( $roles, (array) $user->roles ) ) {
			return null;
		}
		return $user->display_name;
	}

	public function format_value( $value, $object_id, array $field ) {
		$out = array();
		$ids = self::ids( $value );
		if ( count( $ids ) > 1 ) {
			cache_users( $ids );
		}
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			switch ( $field['return_format'] ) {
				case 'id':
					$out[] = $user->ID;
					break;
				case 'object':
					$out[] = $user;
					break;
				default:
					$out[] = array(
						'ID'               => $user->ID,
						'user_firstname'   => $user->first_name,
						'user_lastname'    => $user->last_name,
						'nickname'         => $user->nickname,
						'user_nicename'    => $user->user_nicename,
						'display_name'     => $user->display_name,
						'user_email'       => $user->user_email,
						'user_url'         => $user->user_url,
						'user_registered'  => $user->user_registered,
						'user_description' => $user->description,
						'user_avatar'      => get_avatar( $user->ID ),
					);
			}
		}
		if ( $this->is_multiple( $field ) ) {
			return $out;
		}
		return $out[0] ?? ( null === $value ? null : false );
	}
}
