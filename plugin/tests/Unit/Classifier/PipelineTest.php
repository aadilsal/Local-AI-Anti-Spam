<?php
/**
 * Pipeline behaviour with the shipped model: gates, thresholds, hooks and fail-open.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Classifier;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PipelineTest extends TestCase {

	const ENQUIRY = 'Hi, could I book a table for four people this Friday at 7pm? Many thanks, Sarah';
	const PITCH   = 'Hello, I noticed your website is not ranking on the first page of Google. Our SEO agency can get you backlinks and more traffic. Reply for a free audit: https://seo-boost.example/offer';

	protected function tear_down() {
		$GLOBALS['laas_test_filters'] = array();
		parent::tear_down();
	}

	public static function load(): LogisticModel {
		return new LogisticModel( ModelFile::load( dirname( __DIR__, 3 ) . '/model/model.dat' ) );
	}

	private static function pipeline( float $offset = 0.0 ): Pipeline {
		return new Pipeline( array( self::class, 'load' ), $offset );
	}

	public function test_real_enquiry_is_clean_and_pitch_is_spam() {
		$clean = self::pipeline()->classify( new Submission( 'test', self::ENQUIRY, 'sarah@example.com' ) );
		$this->assertSame( Verdict::CLEAN, $clean->label );
		$this->assertSame( 'model', $clean->reason );
		$this->assertStringStartsWith( 'local-', $clean->model_version );

		$spam = self::pipeline()->classify( new Submission( 'test', self::PITCH, 'mark@seo-boost.example' ) );
		$this->assertSame( Verdict::SPAM, $spam->label );
		$this->assertTrue( $spam->is_spam() );
		$this->assertNotEmpty( $spam->top_features );
		$this->assertLessThanOrEqual( 8, count( $spam->top_features ) );
	}

	public function test_empty_and_non_english_are_unchecked_and_never_load_the_model() {
		$pipeline = new Pipeline(
			static function () {
				throw new \LogicException( 'model must not load' );
			}
		);
		$this->assertSame( 'empty', $pipeline->classify( new Submission( 'test', "  \n " ) )->reason );
		$lang = $pipeline->classify( new Submission( 'test', 'Здравствуйте, я хотел бы заказать новую кухню в следующем месяце, пожалуйста.' ) );
		$this->assertSame( Verdict::UNCHECKED, $lang->label );
		$this->assertSame( 'lang', $lang->reason );
	}

	public function test_a_broken_model_fails_open() {
		$pipeline = new Pipeline(
			static function () {
				throw new \RuntimeException( 'corrupt model' );
			}
		);
		$verdict  = $pipeline->classify( new Submission( 'test', self::PITCH ) );
		$this->assertSame( Verdict::UNCHECKED, $verdict->label );
		$this->assertSame( 'error', $verdict->reason );
		$this->assertFalse( $verdict->is_spam() );
	}

	public function test_a_throwing_filter_fails_open() {
		$GLOBALS['laas_test_filters']['laas_verdict'][] = static function () {
			throw new \RuntimeException( 'third-party bug' );
		};
		$this->assertSame( 'error', self::pipeline()->classify( new Submission( 'test', self::PITCH ) )->reason );
	}

	public function test_pre_classify_and_verdict_filters_take_over() {
		$GLOBALS['laas_test_filters']['laas_pre_classify'][] = static function () {
			return new Verdict( Verdict::CLEAN, 0.0, 'rule:allow' );
		};
		$this->assertSame( 'rule:allow', self::pipeline()->classify( new Submission( 'test', self::PITCH ) )->reason );

		$GLOBALS['laas_test_filters'] = array(
			'laas_verdict' => array(
				static function ( Verdict $verdict ) {
					return new Verdict( Verdict::CLEAN, $verdict->score, 'cloud' );
				},
			),
		);
		$this->assertSame( 'cloud', self::pipeline()->classify( new Submission( 'test', self::PITCH ) )->reason );
	}

	public function test_thresholds_filter_and_offset_move_the_decision() {
		$GLOBALS['laas_test_filters']['laas_thresholds'][] = static function () {
			return array( 0.0, 0.0 );
		};
		$this->assertSame( Verdict::SPAM, self::pipeline()->classify( new Submission( 'test', self::ENQUIRY ) )->label );

		$GLOBALS['laas_test_filters']['laas_thresholds'] = array(
			static function () {
				return array( 0.9, 0.1 ); // Out of order: ignored.
			},
		);
		$this->assertSame( Verdict::CLEAN, self::pipeline()->classify( new Submission( 'test', self::ENQUIRY ) )->label );
	}

	public function test_short_messages_need_a_higher_score() {
		// Every message scores 0.5; spam from 0.48, or from 0.53 for messages under 5 words.
		$GLOBALS['laas_test_filters']['laas_score'][]      = static function () {
			return 0.5;
		};
		$GLOBALS['laas_test_filters']['laas_thresholds'][] = static function () {
			return array( 0.1, 0.48 );
		};
		$this->assertSame( Verdict::BORDERLINE, self::pipeline()->classify( new Submission( 'test', 'buy cheap backlinks now' ) )->label );
		$this->assertSame( Verdict::SPAM, self::pipeline()->classify( new Submission( 'test', 'buy cheap backlinks right now' ) )->label );
	}

	public function test_submission_caps_text_without_splitting_a_character() {
		$submission = new Submission( 'test', str_repeat( 'é', 4001 ) );
		$this->assertSame( 8000, strlen( $submission->text ) );
		$this->assertSame( 1, preg_match( '//u', $submission->text ) ); // Still valid UTF-8.
	}
}
