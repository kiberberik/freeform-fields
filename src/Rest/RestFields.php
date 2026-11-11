<?php
/**
 * Field values in WordPress core REST responses.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Rest;

use FreeformFields\Admin\FormSupport;
use FreeformFields\Admin\UserScreen;
use FreeformFields\Plugin;
use FreeformFields\Storage\ObjectRef;
use FreeformFields\Storage\ValueManager;
use FreeformFields\Validation\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an `acf` property (named like ACF's, `wpff` when ACF itself is active) to posts, terms
 * and users in `wp/v2`, with the fields of groups that have "Show in REST" turned on.
 *
 *  - Reading returns unformatted values (`?acf_format=standard` formats them like get_field()).
 *  - Writing takes `{ "acf": { "field_name": value } }`; values are validated like form input,
 *    and the core endpoint has already checked that the user may edit the object.
 */
final class RestFields {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_fields' ), 20 );
	}

	public static function property(): string {
		return class_exists( 'ACF' ) ? 'wpff' : 'acf';
	}

	public function register_fields(): void {
		// While ACF is active it serves the `acf` property itself; adding ours would only change every response.
		// ACF serves `acf` itself; with acf-to-rest-api active, its output is emulated instead.
		if ( Plugin::acf_active() || \FreeformFields\Integrations\AcfToRestApi::active() ) {
			return;
		}
		$args  = array(
			'get_callback'    => array( $this, 'get_values' ),
			'update_callback' => array( $this, 'update_values' ),
			'schema'          => array(
				'description' => __( 'Custom field values.', 'freeform-fields' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
			),
		);
		$types = array_merge(
			array_values( get_post_types( array( 'show_in_rest' => true ) ) ),
			array_values( get_taxonomies( array( 'show_in_rest' => true ) ) ),
			array( 'user' )
		);
		foreach ( $types as $type ) {
			register_rest_field( $type, self::property(), $args );
		}
	}

	/**
	 * Unformatted value typed like ACF's default REST format: numbers and IDs as numbers,
	 * true/false as booleans, no rows as null, empty text stays "", rows and groups keyed by sub field name.
	 *
	 * @param mixed                $value Loaded value.
	 * @param array<string, mixed> $field Field.
	 * @return mixed
	 */
	private function rest_light( $value, array $field ) {
		$kind = $this->plugin->values()->kind_of( $field );
		if ( 'group' === $kind ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			foreach ( $field['sub_fields'] as $sub ) {
				if ( array_key_exists( $sub['name'], $value ) ) {
					$value[ $sub['name'] ] = $this->rest_light( $value[ $sub['name'] ], $sub );
				}
			}
			return $value;
		}
		if ( 'rows' === $kind || 'layouts' === $kind ) {
			if ( ! is_array( $value ) || array() === $value ) {
				return null;
			}
			foreach ( array_values( $value ) as $i => $row ) {
				$subs = 'layouts' === $kind ? ( ValueManager::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) )['sub_fields'] ?? array() ) : $field['sub_fields'];
				foreach ( $subs as $sub ) {
					if ( is_array( $row ) && array_key_exists( $sub['name'], $row ) ) {
						$row[ $sub['name'] ] = $this->rest_light( $row[ $sub['name'] ], $sub );
					}
				}
				$value[ $i ] = $row;
			}
			return array_values( $value );
		}
		$number = static fn( $v ) => is_numeric( $v ) ? $v + 0 : $v;
		switch ( $field['type'] ) {
			case 'number':
			case 'range':
				return $number( $value );
			case 'true_false':
				return (bool) $value;
			case 'image':
			case 'file':
			case 'post_object':
			case 'page_link':
			case 'user':
			case 'taxonomy':
			case 'relationship':
			case 'gallery':
				if ( '' === $value || null === $value || false === $value ) {
					return null;
				}
				return is_array( $value ) ? array_map( $number, array_values( $value ) ) : $number( $value );
			default:
				return $value;
		}
	}

	/**
	 * Object and location screen for a REST item.
	 *
	 * @param array<string, mixed> $item        Prepared item (`id`, `taxonomy`, ...).
	 * @param string               $object_type Post type, taxonomy or `user`.
	 * @return array{0: ObjectRef, 1: array<string, mixed>}|null
	 */
	private function target( array $item, string $object_type ): ?array {
		$id = (int) ( $item['id'] ?? 0 );
		if ( ! $id ) {
			return null;
		}
		if ( 'user' === $object_type ) {
			return array( new ObjectRef( 'user', $id ), UserScreen::screen( $id, 'edit' ) );
		}
		if ( taxonomy_exists( $object_type ) ) {
			return array(
				new ObjectRef( 'term', $id ),
				array(
					'taxonomy' => $object_type,
					'term_id'  => $id,
				),
			);
		}
		return array( new ObjectRef( 'post', $id ), array( 'post_id' => $id ) );
	}

	/**
	 * Top-level fields of REST-enabled groups for a screen, keyed by name.
	 *
	 * @param array<string, mixed> $screen Screen.
	 * @return array<string, array<string, mixed>>
	 */
	private function any_rest_group(): bool {
		foreach ( $this->plugin->fields()->get_groups() as $group ) {
			if ( ! empty( $group['show_in_rest'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function fields_for( array $screen ): array {
		$fields = array();
		foreach ( ( new FormSupport( $this->plugin ) )->groups_for( $screen ) as $group ) {
			if ( empty( $group['show_in_rest'] ) ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				$handler = $this->plugin->types()->get( $field['type'] );
				if ( $handler && $handler->has_value() && '' !== $field['name'] ) {
					$fields[ $field['name'] ] = $field;
				}
			}
		}
		return $fields;
	}

	/**
	 * @param array<string, mixed> $item        Prepared item.
	 * @param string               $attr        Property name.
	 * @param \WP_REST_Request     $request     Request.
	 * @param string               $object_type Object type.
	 * @return array<string, mixed> Encoded as `[]` when empty, like ACF.
	 */
	public function get_values( $item, $attr, $request, $object_type ) {
		if ( ! $this->any_rest_group() ) {
			return array(); // Skips location checks for every item when no group is shown in REST.
		}
		$target = $this->target( (array) $item, (string) $object_type );
		if ( ! $target ) {
			return array();
		}
		[ $ref, $screen ] = $target;
		// Like the content, field values of password-protected posts need the password.
		if ( 'post' === $ref->type && post_password_required( (int) $ref->id ) && ! current_user_can( 'edit_post', (int) $ref->id ) ) {
			return array();
		}
		$format = 'standard' === $request->get_param( 'acf_format' );
		$values = array();
		foreach ( $this->fields_for( $screen ) as $name => $field ) {
			if ( 'password' === $field['type'] ) {
				continue; // Stored in plain text; writable through REST, never readable.
			}
			$value           = $this->plugin->values()->load( $field, $ref );
			$values[ $name ] = $format ? $this->rest_safe( $this->plugin->values()->format( $value, $field, $ref ), $field ) : $this->rest_light( $value, $field );
		}
		return $values;
	}

	/**
	 * Reduces formatted values to what the requester may see: no raw WP_Post / WP_User objects,
	 * only readable posts and attachments, user details only for users who may list users.
	 *
	 * With `$compat` (the acf-to-rest-api emulation), readable posts keep their full shape and
	 * users keep the ACF array shape, minus private details: the original plugin's consumers
	 * expect those, but never a WP_User (its `data` holds the password hash).
	 *
	 * @param mixed                $value  Formatted value.
	 * @param array<string, mixed> $field  Field.
	 * @param bool                 $compat Keep ACF's shapes for readable items.
	 * @return mixed
	 */
	public function rest_safe( $value, array $field, bool $compat = false ) {
		$kind = $this->plugin->values()->kind_of( $field );
		if ( 'group' === $kind && is_array( $value ) ) {
			foreach ( $field['sub_fields'] as $sub ) {
				if ( array_key_exists( $sub['name'], $value ) ) {
					$value[ $sub['name'] ] = $this->rest_safe( $value[ $sub['name'] ], $sub, $compat );
				}
			}
			return $value;
		}
		if ( ( 'rows' === $kind || 'layouts' === $kind ) && is_array( $value ) ) {
			foreach ( $value as $i => $row ) {
				$subs = 'layouts' === $kind ? ( ValueManager::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) )['sub_fields'] ?? array() ) : $field['sub_fields'];
				foreach ( $subs as $sub ) {
					if ( is_array( $row ) && array_key_exists( $sub['name'], $row ) ) {
						$value[ $i ][ $sub['name'] ] = $this->rest_safe( $row[ $sub['name'] ], $sub, $compat );
					}
				}
			}
			return $value;
		}

		$list = is_array( $value ) && array_is_list( $value ) && in_array( $field['type'], array( 'post_object', 'relationship', 'page_link', 'user', 'gallery', 'taxonomy' ), true );
		$one  = function ( $item ) use ( $field, $compat ) {
			switch ( $field['type'] ) {
				case 'post_object':
				case 'relationship':
					$post = $item instanceof \WP_Post ? $item : get_post( (int) $item );
					if ( ! $post || ! self::can_read( $post ) ) {
						return null;
					}
					if ( $compat ) {
						return $item;
					}
					return 'id' === ( $field['return_format'] ?? '' ) ? $post->ID : array(
						'ID'         => $post->ID,
						'post_title' => get_the_title( $post ),
						'post_type'  => $post->post_type,
						'permalink'  => get_permalink( $post ),
					);
				case 'page_link':
					$id = url_to_postid( (string) $item );
					return $id && ! self::can_read( get_post( $id ) ) ? null : $item;
				case 'user':
					$id   = is_array( $item ) ? (int) ( $item['ID'] ?? 0 ) : ( $item instanceof \WP_User ? $item->ID : (int) $item );
					$user = get_userdata( $id );
					if ( ! $user ) {
						return null;
					}
					if ( 'id' === ( $field['return_format'] ?? '' ) ) {
						return $user->ID;
					}
					if ( $compat ) {
						$out = array(
							'ID'               => $user->ID,
							'user_firstname'   => $user->first_name,
							'user_lastname'    => $user->last_name,
							'nickname'         => $user->nickname,
							'user_nicename'    => $user->user_nicename,
							'display_name'     => $user->display_name,
							'user_url'         => $user->user_url,
							'user_description' => $user->description,
							'user_avatar'      => get_avatar( $user->ID ),
						);
						if ( current_user_can( 'list_users' ) ) {
							$out += array(
								'user_email'      => $user->user_email,
								'user_registered' => $user->user_registered,
							);
						}
						return $out;
					}
					$out = array(
						'ID'           => $user->ID,
						'display_name' => $user->display_name,
					);
					if ( current_user_can( 'list_users' ) ) {
						$out += array(
							'user_email'    => $user->user_email,
							'user_nicename' => $user->user_nicename,
						);
					}
					return $out;
				case 'image':
				case 'file':
				case 'gallery':
					$id         = is_array( $item ) ? (int) ( $item['ID'] ?? 0 ) : ( is_numeric( $item ) ? (int) $item : url_to_postid( (string) $item ) );
					$attachment = $id ? get_post( $id ) : null;
					if ( $attachment && ! self::can_read( $attachment ) ) {
						return null;
					}
					return $item;
				case 'taxonomy':
					return $item instanceof \WP_Term ? (array) $item->to_array() : $item;
				default:
					return $item instanceof \WP_Post || $item instanceof \WP_User ? null : $item;
			}
		};
		if ( $list ) {
			return array_values( array_filter( array_map( $one, $value ), static fn( $v ) => null !== $v ) );
		}
		if ( false === $value || null === $value || '' === $value ) {
			return $value;
		}
		return $one( $value );
	}

	/**
	 * Whether the requester may read a post: published (and not protected), or readable by capability.
	 *
	 * @param \WP_Post|null $post Post.
	 */
	private static function can_read( ?\WP_Post $post ): bool {
		if ( ! $post ) {
			return false;
		}
		if ( 'attachment' === $post->post_type ) {
			return ! $post->post_parent || self::can_read( get_post( $post->post_parent ) );
		}
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			return true;
		}
		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * @param mixed  $value       Submitted `acf` object.
	 * @param mixed  $item        WP_Post, WP_Term or WP_User.
	 * @param string $attr        Property name.
	 * @return true|\WP_Error
	 */
	public function update_values( $value, $item, $attr ) {
		if ( ! is_array( $value ) ) {
			return true;
		}
		if ( $item instanceof \WP_Post ) {
			$target = $this->target( array( 'id' => $item->ID ), $item->post_type );
		} elseif ( $item instanceof \WP_Term ) {
			$target = $this->target( array( 'id' => $item->term_id ), $item->taxonomy );
		} elseif ( $item instanceof \WP_User ) {
			$target = $this->target( array( 'id' => $item->ID ), 'user' );
		} else {
			return true;
		}
		if ( ! $target ) {
			return true;
		}
		[ $ref, $screen ] = $target;
		$fields           = $this->fields_for( $screen );

		$unknown = array_diff( array_keys( $value ), array_keys( $fields ) );
		if ( array() !== $unknown ) {
			return new \WP_Error(
				'wpff_rest_unknown_field',
				/* translators: %s: field names. */
				sprintf( __( 'These fields cannot be updated here: %s', 'freeform-fields' ), implode( ', ', $unknown ) ),
				array( 'status' => 400 )
			);
		}

		$submitted = array();
		foreach ( $value as $name => $field_value ) {
			$submitted[ $fields[ $name ]['key'] ] = $this->names_to_keys( $fields[ $name ], $field_value );
		}
		$result = ( new Validator( $this->plugin->types() ) )->validate( $submitted, array_column( $fields, null, 'key' ), $ref->to_string() );
		if ( array() !== $result['errors'] ) {
			return new \WP_Error(
				'wpff_rest_invalid',
				implode( ' ', $result['errors'] ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}
		foreach ( $result['values'] as $key => $clean ) {
			$this->plugin->values()->save( $this->plugin->fields()->get_field( $key ), $clean, $ref );
		}
		return true;
	}

	/**
	 * Turns name-keyed compound values (as returned by GET) into the key-keyed shape forms submit.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @return mixed
	 */
	private function names_to_keys( array $field, $value ) {
		$kind = $this->plugin->values()->kind_of( $field );
		if ( null === $kind || ! is_array( $value ) ) {
			return $value;
		}
		$map_row = function ( array $subs, array $row ): array {
			$out = array();
			foreach ( $subs as $sub ) {
				if ( array_key_exists( $sub['name'], $row ) ) {
					$out[ $sub['key'] ] = $this->names_to_keys( $sub, $row[ $sub['name'] ] );
				} elseif ( array_key_exists( $sub['key'], $row ) ) {
					$out[ $sub['key'] ] = $this->names_to_keys( $sub, $row[ $sub['key'] ] );
				}
			}
			return $out;
		};
		if ( 'group' === $kind ) {
			return $map_row( $field['sub_fields'], $value );
		}
		$rows = array();
		foreach ( array_values( $value ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'layouts' === $kind ) {
				$layout = ValueManager::find_layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
				if ( ! $layout ) {
					continue;
				}
				$rows[ 'r' . $i ] = array( 'acf_fc_layout' => $layout['name'] ) + $map_row( $layout['sub_fields'], $row );
			} else {
				$rows[ 'r' . $i ] = $map_row( $field['sub_fields'], $row );
			}
		}
		return $rows;
	}
}
