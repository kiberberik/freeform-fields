<?php
/**
 * WP-CLI commands.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Cli;

use FreeformFields\Admin\BuilderData;
use FreeformFields\Plugin;
use FreeformFields\Registry\LocalJson;
use FreeformFields\Tools\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Manage WP Freeform Fields field groups.
 *
 * ## EXAMPLES
 *
 *     wp wpff list
 *     wp wpff export --file=fields.json
 *     wp wpff export group_abc --format=php
 *     wp wpff import fields.json
 *     wp wpff sync
 *     wp wpff import-acf --dry-run
 */
final class Command {

	private function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Lists field groups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function list_( array $args, array $assoc ): void {
		$rows = array();
		foreach ( ( new BuilderData( $this->plugin() ) )->group_summaries() as $group ) {
			$rows[] = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'source' => $group['source'],
				'fields' => $group['field_count'],
				'active' => $group['active'] ? 'yes' : 'no',
				'sync'   => $group['sync'] ? 'available' : '',
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'title', 'source', 'fields', 'active', 'sync' ) );
	}

	/**
	 * Exports field groups as JSON (ACF's format) or PHP.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Group keys. Default: all groups.
	 *
	 * [--format=<format>]
	 * : json or php.
	 * ---
	 * default: json
	 * ---
	 *
	 * [--file=<file>]
	 * : Write to this file instead of the output.
	 *
	 * @param string[]              $args  Group keys.
	 * @param array<string, string> $assoc Options.
	 */
	public function export( array $args, array $assoc ): void {
		$tools  = new ImportExport( $this->plugin() );
		$output = 'php' === ( $assoc['format'] ?? 'json' )
			? "<?php\n" . $tools->export_php( $args )
			: wp_json_encode( $tools->export( $args ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		if ( ! empty( $assoc['file'] ) ) {
			if ( false === file_put_contents( $assoc['file'], $output ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				\WP_CLI::error( 'Could not write ' . $assoc['file'] );
			}
			\WP_CLI::success( 'Exported to ' . $assoc['file'] );
			return;
		}
		\WP_CLI::line( rtrim( (string) $output ) );
	}

	/**
	 * Imports field groups from a JSON export (WP Freeform Fields or ACF). Existing groups with the same key are updated.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file.
	 *
	 * @param string[] $args File.
	 */
	public function import( array $args ): void {
		$json = file_exists( $args[0] ) ? json_decode( (string) file_get_contents( $args[0] ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $json ) ) {
			\WP_CLI::error( 'Not a JSON file: ' . $args[0] );
		}
		$this->report( ( new ImportExport( $this->plugin() ) )->import( $json ) );
	}

	/**
	 * Imports Local JSON files that are newer than the database (or not in it yet).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Import every JSON file, even if the database copy is up to date.
	 *
	 * @param string[]            $args  Unused.
	 * @param array<string, bool> $assoc Options.
	 */
	public function sync( array $args, array $assoc ): void {
		$count = 0;
		foreach ( $this->plugin()->local_json()->files() as $key => $entry ) {
			$group = $this->plugin()->fields()->get_group( $key );
			if ( ! $group || ( empty( $assoc['all'] ) && ! LocalJson::needs_sync( $group ) ) ) {
				continue;
			}
			$result = $this->plugin()->groups()->sync( $key );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $key . ': ' . $result->get_error_message() );
				continue;
			}
			\WP_CLI::log( 'Synced ' . $key . ' (' . $result['title'] . ')' );
			++$count;
		}
		\WP_CLI::success( sprintf( '%d group(s) synced.', $count ) );
	}

	/**
	 * Imports what ACF stored in the database: post types, taxonomies, options pages and field groups.
	 * Keys are kept, so existing values stay linked.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only list what would be imported.
	 *
	 * @subcommand import-acf
	 *
	 * @param string[]            $args  Unused.
	 * @param array<string, bool> $assoc Options.
	 */
	public function import_acf( array $args, array $assoc ): void {
		$tools = new ImportExport( $this->plugin() );
		$rows  = array();
		foreach ( $tools->acf_db_content_types() as $kind => $items ) {
			foreach ( $items as $item ) {
				$rows[] = array(
					'type'   => $kind,
					'key'    => $item['key'],
					'title'  => $item['plural'],
					'fields' => '',
				);
			}
		}
		foreach ( $tools->acf_db_options_pages() as $page ) {
			$rows[] = array(
				'type'   => 'options_page',
				'key'    => $page['menu_slug'],
				'title'  => $page['page_title'],
				'fields' => '',
			);
		}
		foreach ( $tools->acf_db_groups() as $group ) {
			$rows[] = array(
				'type'   => 'field_group',
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => count( $group['fields'] ),
			);
		}
		if ( array() === $rows ) {
			\WP_CLI::success( 'Nothing from ACF found in the database.' );
			return;
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'key', 'title', 'fields' ) );
			$this->theme_report();
			return;
		}
		$this->report( $tools->import_acf_all() );
	}

	/**
	 * Warns about ACF functions and hooks in the theme that will not work without ACF.
	 */
	private function theme_report(): void {
		$report = ( new \FreeformFields\Tools\ThemeCheck() )->run();
		if ( array() === $report['functions'] && array() === $report['hooks'] ) {
			\WP_CLI::success( sprintf( 'Theme check: %d PHP files, no unsupported ACF functions or hooks.', $report['files'] ) );
			return;
		}
		foreach ( $report['functions'] as $name => $where ) {
			\WP_CLI::warning( "Function {$name}() is not provided and will cause a fatal error: " . implode( ', ', $where ) );
		}
		foreach ( $report['hooks'] as $name => $where ) {
			\WP_CLI::warning( "Hook {$name} is not fired and will be ignored: " . implode( ', ', $where ) );
		}
	}

	/**
	 * Prints an import result.
	 *
	 * @param array{imported: array<int, array{key: string, title: string}>, errors: array<string, string>, warnings: string[]} $result Result.
	 */
	private function report( array $result ): void {
		foreach ( $result['imported'] as $group ) {
			\WP_CLI::log( 'Imported ' . $group['key'] . ' (' . $group['title'] . ')' );
		}
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		foreach ( $result['errors'] as $key => $error ) {
			\WP_CLI::warning( $key . ': ' . $error );
		}
		if ( array() !== $result['errors'] && array() === $result['imported'] ) {
			\WP_CLI::error( 'Nothing was imported.' );
		}
		\WP_CLI::success( sprintf( '%d group(s) imported.', count( $result['imported'] ) ) );
	}
}
