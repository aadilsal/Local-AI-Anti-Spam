<?php
/**
 * The model the plugin ships must score exactly as Python scored it (PRD M3). Fixtures come from
 * `python -m laas.export --run runs/<id>`, which writes them together with plugin/model/model.bin.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Inference;

use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ReleaseModelTest extends TestCase {

	const SCORE_TOLERANCE = 5e-5; // PRD M3. Never loosen this.

	/**
	 * Parsed fixtures.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $fixtures = null;

	/**
	 * The shipped model.
	 *
	 * @var LogisticModel|null
	 */
	private static $model = null;

	private static function model_path(): string {
		return dirname( __DIR__, 3 ) . '/model/model.bin';
	}

	private static function fixtures(): array {
		if ( null === self::$fixtures ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
			self::$fixtures = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/parity/release-fixtures.json' ), true );
		}
		return self::$fixtures;
	}

	private static function model(): LogisticModel {
		if ( null === self::$model ) {
			self::$model = new LogisticModel( ModelFile::load( self::model_path() ) );
		}
		return self::$model;
	}

	public function test_fixtures_belong_to_the_shipped_model() {
		$this->assertSame( self::fixtures()['model_sha256'], hash_file( 'sha256', self::model_path() ), 'Re-run laas.export: model.bin and release-fixtures.json are out of step.' );
		$this->assertSame( self::fixtures()['model_version'], self::model()->header()['model_version'] );
	}

	public function test_model_carries_ordered_thresholds() {
		$header = self::model()->header();
		$this->assertGreaterThan( 0.0, $header['t_low'] );
		$this->assertLessThan( $header['t_high'], $header['t_low'] );
		$this->assertLessThan( 1.0, $header['t_high'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function cases(): array {
		$out = array();
		foreach ( self::fixtures()['cases'] as $i => $fixture ) {
			$out[ sprintf( '#%03d %s', $i, substr( preg_replace( '/[^\x20-\x7E]/', '?', $fixture['text'] ), 0, 40 ) ) ] = array( $fixture );
		}
		return $out;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param array<string, mixed> $fixture One case.
	 */
	public function test_php_scores_the_shipped_model_like_python( array $fixture ) {
		$this->assertEqualsWithDelta( $fixture['score'], self::model()->classify( $fixture['text'], $fixture['email'] )['score'], self::SCORE_TOLERANCE );
	}
}
