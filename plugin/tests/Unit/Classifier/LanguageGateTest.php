<?php
/**
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Classifier;

use LocalAIAntiSpam\Classifier\LanguageGate;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class LanguageGateTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function texts(): array {
		return array(
			'english enquiry'    => array( 'Hello, could you send me a quote for a new kitchen? I would like to start next month.', true ),
			'short english'      => array( 'Table for 4 Friday?', true ),
			'english, curly'     => array( "I\u{2019}d like to book, and I\u{2019}m free on Tuesday or Wednesday this week.", true ),
			'no letters'         => array( '+44 20 7946 0958 !!!', true ),
			'russian'            => array( 'Здравствуйте, я хотел бы заказать новую кухню в следующем месяце, пожалуйста.', false ),
			'japanese'           => array( '来週の金曜日に4人で予約をお願いしたいのですが、空いていますか。', false ),
			'german, many words' => array( 'Guten Tag, ich möchte gerne einen Termin für nächste Woche vereinbaren bitte sehr', false ),
			'spanish, few words' => array( 'Hola, quiero reservar', true ), // Too short to judge; lean English.
		);
	}

	/**
	 * @dataProvider texts
	 *
	 * @param string $text     Text.
	 * @param bool   $expected Whether it counts as English.
	 */
	public function test_is_english( string $text, bool $expected ) {
		$this->assertSame( $expected, LanguageGate::is_english( $text ) );
	}
}
