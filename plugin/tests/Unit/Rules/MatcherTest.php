<?php
/**
 * Rule matching and value normalising.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Rules;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Rules\Matcher;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class MatcherTest extends TestCase {

	private static function rule( int $id, string $kind, string $value, string $action ): array {
		return array(
			'id'     => $id,
			'kind'   => $kind,
			'value'  => $value,
			'action' => $action,
		);
	}

	public function test_each_kind_matches() {
		$sub = new Submission( 'test', 'Get CHEAP Backlinks today', 'Mark@Mail.SEO-Pros.example', 'Mark', '1', '192.0.2.9' );
		$this->assertSame( 1, Matcher::match( array( self::rule( 1, 'email', 'mark@mail.seo-pros.example', 'block' ) ), $sub )['id'] );
		$this->assertSame( 2, Matcher::match( array( self::rule( 2, 'domain', 'seo-pros.example', 'block' ) ), $sub )['id'] );
		$this->assertSame( 3, Matcher::match( array( self::rule( 3, 'ip', '192.0.2.9', 'block' ) ), $sub )['id'] );
		$this->assertSame( 4, Matcher::match( array( self::rule( 4, 'phrase', 'cheap backlinks', 'block' ) ), $sub )['id'] );
		$this->assertNull( Matcher::match( array( self::rule( 5, 'domain', 'pros.example', 'block' ) ), $sub ) ); // Not a label boundary.
		$this->assertNull( Matcher::match( array( self::rule( 6, 'domain', 'seo-pros.example', 'block' ) ), new Submission( 'test', 'hi' ) ) );
	}

	public function test_allow_wins_over_block() {
		$sub   = new Submission( 'test', 'free seo audit', 'jo@client.example' );
		$rules = array( self::rule( 1, 'phrase', 'seo audit', 'block' ), self::rule( 2, 'email', 'jo@client.example', 'allow' ) );
		$this->assertSame( 'allow', Matcher::match( $rules, $sub )['action'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public function values(): array {
		return array(
			'email lowercased'    => array( 'email', ' Sarah@Example.CO.UK ', 'sarah@example.co.uk' ),
			'email without @'     => array( 'email', 'sarah.example.com', null ),
			'domain from url'     => array( 'domain', 'https://www.SEO-pros.example/offer?x=1', 'seo-pros.example' ),
			'domain from @'       => array( 'domain', '@seo-pros.example', 'seo-pros.example' ),
			'domain wildcard'     => array( 'domain', '*.seo-pros.example', 'seo-pros.example' ),
			'domain without dot'  => array( 'domain', 'localhost', null ),
			'ip v4'               => array( 'ip', '192.0.2.9', '192.0.2.9' ),
			'ip v6'               => array( 'ip', '2001:db8::1', '2001:db8::1' ),
			'ip invalid'          => array( 'ip', '999.1.1.1', null ),
			'phrase collapsed'    => array( 'phrase', "  Guest   POST\n", 'guest post' ),
			'phrase too short'    => array( 'phrase', 'ab', null ),
			'regex is plain text' => array( 'phrase', '(a+)+$', '(a+)+$' ),
		);
	}

	/**
	 * @dataProvider values
	 *
	 * @param string      $kind     Kind.
	 * @param string      $value    Raw value.
	 * @param string|null $expected Stored value.
	 */
	public function test_normalise( string $kind, string $value, ?string $expected ) {
		$this->assertSame( $expected, Matcher::normalise( $kind, $value ) );
	}

	public function test_pipeline_applies_rules_before_the_gates() {
		$rules    = array( self::rule( 9, 'domain', 'seo-pros.example', 'block' ) );
		$pipeline = new Pipeline(
			static function () {
				throw new \LogicException( 'model must not load' );
			},
			0.0,
			static function ( Submission $sub ) use ( $rules ) {
				return Matcher::match( $rules, $sub );
			}
		);
		$verdict  = $pipeline->classify( new Submission( 'test', 'Здравствуйте', 'x@seo-pros.example' ) );
		$this->assertSame( Verdict::SPAM, $verdict->label );
		$this->assertSame( 'rule:block', $verdict->reason );
		$this->assertSame( 'domain:seo-pros.example', $verdict->top_features[0]['token'] );
	}
}
