<?php
/**
 * Base test case.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Plugin;

/**
 * Cleans up posts and local groups created by a test.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** @var int[] */
	private array $created_posts = array();

	/** @var string[] */
	private array $created_groups = array();

	protected function tearDown(): void {
		foreach ( $this->created_posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->created_groups as $key ) {
			wpff_remove_local_field_group( $key );
		}
		// Groups saved to the (throwaway test) database.
		$stored = get_posts(
			array(
				'post_type'      => Plugin::GROUP_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'trash', \FreeformFields\Registry\GroupStore::DISABLED_STATUS ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $stored as $id ) {
			wp_delete_post( $id, true );
		}
		$this->plugin()->fields()->flush();
		$_POST = array();
		unset( $GLOBALS['post'] );
		wp_set_current_user( 1 );
		parent::tearDown();
	}

	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * @param array<string, mixed> $args wp_insert_post() args.
	 */
	protected function make_post( array $args = array() ): int {
		$id = wp_insert_post(
			$args + array(
				'post_title'  => 'Test',
				'post_status' => 'publish',
				'post_type'   => 'post',
			),
			true
		);
		$this->assertIsInt( $id );
		$this->created_posts[] = $id;
		return $id;
	}

	/**
	 * Registers a group shown on posts unless a location is given.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, mixed>             $extra  Other group settings.
	 */
	protected function make_group( array $fields, array $extra = array() ): string {
		static $n               = 0;
		$key                    = wpff_add_local_field_group(
			$extra + array(
				'key'      => 'group_test_' . ( ++$n ),
				'title'    => 'Test group ' . $n,
				'fields'   => $fields,
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
		$this->created_groups[] = $key;
		return $key;
	}

	/**
	 * Creates an image attachment from a generated PNG.
	 */
	protected function make_image(): int {
		$upload = wp_upload_bits( 'wpff-test-' . wp_generate_password( 6, false ) . '.png', null, self::png() );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Test image',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt text' );
		$this->created_posts[] = $id;
		return $id;
	}

	private static function png(): string {
		// 1x1 transparent PNG.
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
	}
}
