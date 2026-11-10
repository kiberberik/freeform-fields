<?php
/**
 * Notice shown while ACF is active next to WP Freeform Fields.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Admin;

use FreeformFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Explains how the two plugins share a site during migration, on the Plugins screen and on
 * WP Freeform Fields' own screens.
 */
final class AcfNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::admin_capability() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( 'plugins' !== $screen->id && ! str_contains( (string) $screen->id, FieldGroupsPage::SLUG ) && ! str_contains( (string) $screen->id, '_page_wpff-' ) ) ) {
			return;
		}
		$tools = admin_url( 'admin.php?page=' . FieldGroupsPage::TOOLS_SLUG );
		if ( ! Plugin::acf_active() ) {
			$waiting = count( array_filter( Plugin::instance()->fields()->get_all_groups(), static fn( $g ) => 'acf' === ( $g['local'] ?? '' ) ) );
			if ( $waiting ) {
				echo '<div class="notice notice-warning"><p>';
				printf(
					/* translators: 1: number of field groups, 2: link to the Tools screen. */
					esc_html( _n( '%1$d field group is still read from ACF\'s tables. It works, but cannot be edited until you run %2$s.', '%1$d field groups are still read from ACF\'s tables. They work, but cannot be edited until you run %2$s.', $waiting, 'freeform-fields' ) ),
					(int) $waiting,
					'<a href="' . esc_url( $tools ) . '">' . esc_html__( 'Tools → Migrate from ACF', 'freeform-fields' ) . '</a>'
				);
				echo '</p></div>';
			}
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Advanced Custom Fields is active.', 'freeform-fields' ) . '</strong> ';
		echo esc_html__( 'While it is active, WP Freeform Fields leaves ACF\'s fields, acf-json files and blocks to ACF, and ACF-named functions such as get_field() belong to ACF.', 'freeform-fields' ) . '</p><p>';
		printf(
			/* translators: %s: link to the Tools screen. */
			esc_html__( 'To switch: run %s, check the report, then deactivate ACF. Until then, edit screens show ACF\'s forms for the groups both plugins have.', 'freeform-fields' ),
			'<a href="' . esc_url( $tools ) . '">' . esc_html__( 'Tools → Migrate from ACF', 'freeform-fields' ) . '</a>'
		);
		echo '</p></div>';
	}
}
