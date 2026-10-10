<?php
/**
 * PHP must featurize and score exactly like Python (PRD M3, T8). Fixtures come from
 * `python -m laas.parity` in training/.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Inference;

use LocalAIAntiSpam\Inference\Featurizer;
use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ParityTest extends TestCase {

	const SCORE_TOLERANCE   = 5e-5; // PRD M3: four decimal places. Never loosen this.
	const FEATURE_TOLERANCE = 1e-9;

	/**
	 * Parsed fixtures, shared by all cases.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $fixtures = null;

	private static function parity_dir(): string {
		return dirname( __DIR__, 4 ) . '/parity';
	}

	private static function fixtures(): array {
		if ( null === self::$fixtures ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
			self::$fixtures = json_decode( (string) file_get_contents( self::parity_dir() . '/fixtures.json' ), true );
		}
		return self::$fixtures;
	}

	public function test_fixtures_match_featurizer_version() {
		$this->assertSame( Featurizer::VERSION, self::fixtures()['featurizer_version'] );
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
	 * @param array<string, mixed> $fixture One fixture case.
	 */
	public function test_php_matches_python( array $fixture ) {
		$file     = ModelFile::load( self::parity_dir() . '/' . self::fixtures()['model'] );
		$model    = new LogisticModel( $file );
		$features = Featurizer::featurize( $fixture['text'], $fixture['email'], $file->n_buckets() );

		$this->assertCount( $fixture['n_features'], $features['hashed'], 'number of active buckets' );
		foreach ( $fixture['dense'] as $i => $expected ) {
			$this->assertEqualsWithDelta( $expected, $features['dense'][ $i ], self::FEATURE_TOLERANCE, "dense[$i]" );
		}
		if ( isset( $fixture['features'] ) ) {
			foreach ( $fixture['features'] as $pair ) {
				list( $bucket, $expected ) = $pair;
				$this->assertArrayHasKey( $bucket, $features['hashed'], "bucket $bucket" );
				$this->assertEqualsWithDelta( $expected, $features['hashed'][ $bucket ], self::FEATURE_TOLERANCE, "bucket $bucket" );
			}
		}

		$this->assertEqualsWithDelta( $fixture['logit'], $model->logit( $features ), 1e-6, 'logit' );
		$this->assertEqualsWithDelta( $fixture['score'], $model->classify( $fixture['text'], $fixture['email'] )['score'], self::SCORE_TOLERANCE, 'score' );
	}
}
