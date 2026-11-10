<?php
/**
 * The laas_classified action fires once per classification, including fail-open ones.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Classifier;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PipelineHooksTest extends TestCase {

	protected function tear_down() {
		$GLOBALS['laas_test_filters'] = array();
		parent::tear_down();
	}

	public function test_classified_fires_with_the_final_verdict_even_on_error() {
		$seen = array();
		$GLOBALS['laas_test_filters']['laas_classified'][] = static function ( Verdict $verdict, Submission $submission ) use ( &$seen ) {
			$seen[] = $verdict->reason . '/' . $submission->source;
		};
		$pipeline = new Pipeline(
			static function () {
				throw new \RuntimeException( 'broken model' );
			}
		);
		$pipeline->classify( new Submission( 'cf7', '' ) );
		$pipeline->classify( new Submission( 'cf7', 'A perfectly normal enquiry about opening hours on Sunday please' ) );
		$this->assertSame( array( 'empty/cf7', 'error/cf7' ), $seen );
	}

	public function test_a_throwing_listener_does_not_change_the_verdict() {
		$GLOBALS['laas_test_filters']['laas_classified'][] = static function () {
			throw new \RuntimeException( 'listener bug' );
		};
		$pipeline = new Pipeline(
			static function () {
				throw new \LogicException( 'not needed' );
			}
		);
		$this->assertSame( 'empty', $pipeline->classify( new Submission( 'cf7', ' ' ) )->reason );
	}
}
