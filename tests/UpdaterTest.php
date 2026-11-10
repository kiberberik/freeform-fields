<?php
/**
 * Updates from GitHub releases: manifest checks, the update offer and the details window.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Tests;

use FreeformFields\Updates\Updater;

final class UpdaterTest extends TestCase {

	private int $requests = 0;

	/** @var array|\WP_Error|null Response the HTTP mock returns. */
	private $response = null;

	protected function setUp(): void {
		parent::setUp();
		delete_site_transient( Updater::CACHE_KEY );
		$this->requests = 0;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		remove_all_filters( 'wpff/updates/enabled' );
		delete_site_transient( Updater::CACHE_KEY );
		parent::tearDown();
	}

	/**
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request args.
	 * @param string      $url  URL.
	 */
	public function mock_http( $pre, $args, $url ) {
		if ( Updater::MANIFEST_URL !== $url ) {
			return $pre;
		}
		++$this->requests;
		return $this->response;
	}

	private function serve( array $manifest, int $code = 200 ): void {
		$this->response = array(
			'headers'  => array(),
			'body'     => wp_json_encode( $manifest ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function manifest( array $overrides = array() ): array {
		return array_merge(
			array(
				'version'      => '9.0.0',
				'download_url' => 'https://github.com/kiberberik/freeform-fields/releases/download/v9.0.0/freeform-fields-9.0.0.zip',
				'requires'     => '6.5',
				'requires_php' => '8.1',
				'tested'       => '7.1',
				'released'     => '2026-11-11',
				'changelog'    => "### Added\n- Updates from `GitHub` releases.\n- <script>x</script>",
			),
			$overrides
		);
	}

	private function check() {
		return ( new Updater() )->check( false, array( 'Version' => WPFF_VERSION ), plugin_basename( WPFF_FILE ) );
	}

	public function test_header_routes_updates_to_the_filter(): void {
		$headers = get_file_data( WPFF_FILE, array( 'uri' => 'Update URI' ) );
		$this->assertSame( 'https://' . Updater::HOST, $headers['uri'] );
		$this->assertNotFalse( has_filter( 'update_plugins_' . Updater::HOST ) );
	}

	public function test_offers_the_release_zip(): void {
		$this->serve( $this->manifest() );
		$update = $this->check();

		$this->assertSame( '9.0.0', $update['version'] );
		$this->assertSame( 'https://github.com/kiberberik/freeform-fields/releases/download/v9.0.0/freeform-fields-9.0.0.zip', $update['package'] );
		$this->assertSame( 'freeform-fields', $update['slug'] );
		$this->assertSame( '8.1', $update['requires_php'] );
		$this->assertSame( 'https://wpff.kiberberik.link', $update['url'] );
	}

	public function test_other_plugins_are_left_alone(): void {
		$this->serve( $this->manifest() );
		$this->assertFalse( ( new Updater() )->check( false, array(), 'other/other.php' ) );
		$this->assertSame( 0, $this->requests );
	}

	public function test_manifest_is_cached(): void {
		$this->serve( $this->manifest() );
		$this->check();
		$this->check();
		$this->assertSame( 1, $this->requests );

		( new Updater() )->flush();
		$this->check();
		$this->assertSame( 2, $this->requests );
	}

	public function test_failures_offer_nothing_and_are_cached(): void {
		$this->response = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertFalse( $this->check() );
		$this->assertFalse( $this->check() );
		$this->assertSame( 1, $this->requests );
	}

	public function test_packages_from_elsewhere_are_rejected(): void {
		$this->serve( $this->manifest( array( 'download_url' => 'https://evil.example/freeform-fields.zip' ) ) );
		$this->assertFalse( $this->check() );

		$this->assertNull( Updater::normalize( $this->manifest( array( 'version' => '9.0.0-beta<' ) ) ) );
		$this->assertNull( Updater::normalize( 'not json' ) );
	}

	public function test_can_be_turned_off(): void {
		add_filter( 'wpff/updates/enabled', '__return_false' );
		$this->serve( $this->manifest() );
		$this->assertFalse( $this->check() );
		$this->assertSame( 0, $this->requests );
	}

	public function test_details_window_shows_escaped_changelog(): void {
		$this->serve( $this->manifest() );
		$info = ( new Updater() )->info( false, 'plugin_information', (object) array( 'slug' => 'freeform-fields' ) );

		$this->assertSame( '9.0.0', $info->version );
		$this->assertStringContainsString( '<h4>Added</h4>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>Updates from <code>GitHub</code> releases.</li>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );

		$other = ( new Updater() )->info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) );
		$this->assertFalse( $other );
	}
}
