<?php
/**
 * Location rule engine.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Location;

use FreeformFields\Multilingual\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a field group appears on a screen.
 *
 * Rules use the ACF format: an OR-list of AND-lists of rules.
 *
 *     [
 *       [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ],
 *         [ 'param' => 'page_template', 'operator' => '==', 'value' => 'templates/home.php' ] ],
 *       [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'product' ] ],
 *     ]
 *
 * A screen is an array describing the edit context, e.g. `[ 'post_id' => 12, 'post_type' => 'page' ]`.
 */
final class LocationRules {

	/**
	 * Rule matchers keyed by param. Each returns whether `$screen` equals the rule value.
	 *
	 * @var array<string, callable(array, array): bool>|null
	 */
	private ?array $matchers = null;

	/**
	 * Whether a group with these rules should show on the screen.
	 *
	 * @param array<int, array<int, array<string, string>>> $location Rule groups.
	 * @param array<string, mixed>                          $screen   Screen context.
	 */
	public function matches( array $location, array $screen ): bool {
		$screen = $this->complete_screen( $screen );
		foreach ( $location as $and_rules ) {
			if ( ! is_array( $and_rules ) || array() === $and_rules ) {
				continue;
			}
			$all = true;
			foreach ( $and_rules as $rule ) {
				if ( ! $this->match_rule( (array) $rule, $screen ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registers or replaces a rule matcher.
	 *
	 * @param string                       $param   Rule param, e.g. `post_type`.
	 * @param callable(array, array): bool $matcher Receives ($rule, $screen), returns true when the screen equals the rule value.
	 */
	public function register( string $param, callable $matcher ): void {
		$this->load();
		$this->matchers[ $param ] = $matcher;
	}

	/**
	 * Names of all registered rule params.
	 *
	 * @return string[]
	 */
	public function params(): array {
		$this->load();
		return array_keys( $this->matchers );
	}

	/**
	 * Evaluates one rule. Unknown params never match.
	 *
	 * @param array<string, string> $rule   Rule.
	 * @param array<string, mixed>  $screen Completed screen.
	 */
	private function match_rule( array $rule, array $screen ): bool {
		$this->load();
		$param    = (string) ( $rule['param'] ?? '' );
		$operator = (string) ( $rule['operator'] ?? '==' );
		if ( ! isset( $this->matchers[ $param ] ) ) {
			return false;
		}

		// Rules about one kind of object never match on screens of another kind.
		if ( in_array( $param, self::POST_PARAMS, true ) && empty( $screen['post_type'] ) ) {
			return false;
		}
		if ( 'taxonomy' === $param && empty( $screen['taxonomy'] ) ) {
			return false;
		}
		if ( 'options_page' === $param && empty( $screen['options_page'] ) ) {
			return false;
		}
		if ( 'block' === $param && empty( $screen['block'] ) ) {
			return false;
		}
		if ( in_array( $param, array( 'user_form', 'user_role' ), true ) && empty( $screen['user_form'] ) ) {
			return false;
		}

		$equal  = (bool) call_user_func( $this->matchers[ $param ], $rule, $screen );
		$result = '!=' === $operator ? ! $equal : $equal;

		/**
		 * Filters the result of one location rule.
		 *
		 * @param bool  $result Whether the rule matches.
		 * @param array $rule   Rule.
		 * @param array $screen Screen context.
		 */
		return (bool) apply_filters( "wpff/location/match_rule/type={$param}", $result, $rule, $screen );
	}

	private const POST_PARAMS = array(
		'post_type',
		'post',
		'page',
		'post_template',
		'page_template',
		'post_status',
		'post_format',
		'post_category',
		'post_taxonomy',
		'page_parent',
		'page_type',
	);

	/**
	 * Derives missing screen values from `post_id`.
	 *
	 * @param array<string, mixed> $screen Partial screen.
	 * @return array<string, mixed>
	 */
	private function complete_screen( array $screen ): array {
		$post_id = (int) ( $screen['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( $post ) {
			$screen += array(
				'post_type'     => $post->post_type,
				'post_status'   => $post->post_status,
				'page_template' => get_page_template_slug( $post ) ?: 'default',
				'post_format'   => get_post_format( $post ) ?: 'standard',
				'post_parent'   => (int) $post->post_parent,
			);
		}
		return $screen + array(
			'post_id'   => $post_id,
			'post_type' => '',
		);
	}

	/**
	 * Built-in matchers.
	 */
	private function load(): void {
		if ( null !== $this->matchers ) {
			return;
		}

		$value_of = static fn( array $rule ): string => (string) ( $rule['value'] ?? '' );

		$template = static fn( array $rule, array $screen ): bool => ( $screen['page_template'] ?? 'default' ) === $value_of( $rule );

		$term_rule = static function ( array $rule, array $screen ) use ( $value_of ): bool {
			$value = $value_of( $rule );
			if ( ! str_contains( $value, ':' ) ) {
				// post_category allows a bare category slug.
				$value = 'category:' . $value;
			}
			[ $taxonomy, $slug ] = explode( ':', $value, 2 );
			if ( isset( $screen['post_terms'][ $taxonomy ] ) ) {
				return in_array( $slug, (array) $screen['post_terms'][ $taxonomy ], true );
			}
			return $screen['post_id'] && has_term( $slug, $taxonomy, (int) $screen['post_id'] );
		};

		$this->matchers = array(
			'post_type'         => static fn( $rule, $screen ) => $screen['post_type'] === $value_of( $rule ) || 'all' === $value_of( $rule ),
			// A rule on one page also matches its translations on multilingual sites.
			'post'              => static fn( $rule, $screen ) => Multilingual::same_post( (int) $screen['post_id'], (int) $value_of( $rule ) ),
			'page'              => static fn( $rule, $screen ) => Multilingual::same_post( (int) $screen['post_id'], (int) $value_of( $rule ) ),
			'post_template'     => $template,
			'page_template'     => $template,
			'post_status'       => static fn( $rule, $screen ) => ( $screen['post_status'] ?? '' ) === $value_of( $rule ),
			'post_format'       => static fn( $rule, $screen ) => ( $screen['post_format'] ?? 'standard' ) === $value_of( $rule ),
			'post_category'     => $term_rule,
			'post_taxonomy'     => $term_rule,
			'page_parent'       => static fn( $rule, $screen ) => Multilingual::same_post( (int) ( $screen['post_parent'] ?? 0 ), (int) $value_of( $rule ) ),
			'page_type'         => static function ( $rule, $screen ) use ( $value_of ): bool {
				$id     = (int) $screen['post_id'];
				$parent = (int) ( $screen['post_parent'] ?? 0 );
				switch ( $value_of( $rule ) ) {
					case 'front_page':
						return $id && (int) get_option( 'page_on_front' ) === $id;
					case 'posts_page':
						return $id && (int) get_option( 'page_for_posts' ) === $id;
					case 'top_level':
						return 0 === $parent;
					case 'parent':
						return $id && array() !== get_children(
							array(
								'post_parent' => $id,
								'post_type'   => $screen['post_type'],
								'numberposts' => 1,
								'fields'      => 'ids',
							)
						);
					case 'child':
						return $parent > 0;
				}
				return false;
			},
			'block'             => static fn( $rule, $screen ) => 'all' === $value_of( $rule ) || ( $screen['block'] ?? '' ) === $value_of( $rule ),
			'options_page'      => static fn( $rule, $screen ) => 'all' === $value_of( $rule ) || ( $screen['options_page'] ?? '' ) === $value_of( $rule ),
			'taxonomy'          => static fn( $rule, $screen ) => 'all' === $value_of( $rule ) || ( $screen['taxonomy'] ?? '' ) === $value_of( $rule ),
			'user_form'         => static fn( $rule, $screen ) => 'all' === $value_of( $rule ) || ( $screen['user_form'] ?? '' ) === $value_of( $rule ),
			'user_role'         => static fn( $rule, $screen ) => 'all' === $value_of( $rule ) || in_array( $value_of( $rule ), (array) ( $screen['user_roles'] ?? array() ), true ),
			'current_user'      => static function ( $rule ) use ( $value_of ): bool {
				switch ( $value_of( $rule ) ) {
					case 'logged_in':
						return is_user_logged_in();
					case 'viewing_front':
						return ! is_admin();
					case 'viewing_back':
						return is_admin();
				}
				return false;
			},
			'current_user_role' => static function ( $rule ) use ( $value_of ): bool {
				$value = $value_of( $rule );
				if ( 'super_admin' === $value ) {
					return is_super_admin();
				}
				$user = wp_get_current_user();
				return $user->exists() && in_array( $value, (array) $user->roles, true );
			},
		);

		/**
		 * Filters the available location rule matchers.
		 *
		 * @param array<string, callable> $matchers Matchers keyed by rule param.
		 */
		$this->matchers = (array) apply_filters( 'wpff/location/matchers', $this->matchers );
	}
}
