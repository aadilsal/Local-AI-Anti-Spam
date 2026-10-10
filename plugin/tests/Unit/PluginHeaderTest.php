<?php
/**
 * Checks the main plugin file's header against the values WordPress.org and the code rely on.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PluginHeaderTest extends TestCase {

	/**
	 * Contents of the main plugin file.
	 *
	 * @var string
	 */
	private $source;

	protected function set_up() {
		parent::set_up();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, no WordPress loaded in unit tests.
		$this->source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/local-ai-anti-spam.php' );
	}

	private function header( string $name ): string {
		$this->assertSame( 1, preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $this->source, $m ), "Missing header: {$name}" );
		return trim( $m[1] );
	}

	public function test_version_header_matches_constant() {
		$this->assertSame( 1, preg_match( "/define\(\s*'LAAS_VERSION',\s*'([^']+)'\s*\)/", $this->source, $m ) );
		$this->assertSame( $this->header( 'Version' ), $m[1] );
	}

	public function test_minimum_requirements_match_the_prd() {
		$this->assertSame( '7.4', $this->header( 'Requires PHP' ) );
		$this->assertSame( '6.0', $this->header( 'Requires at least' ) );
	}

	public function test_text_domain_matches_slug() {
		$this->assertSame( 'local-ai-anti-spam', $this->header( 'Text Domain' ) );
	}

	public function test_license_is_gpl_compatible() {
		$this->assertSame( 'GPL-2.0-or-later', $this->header( 'License' ) );
	}
}
