<?php
/**
 * Comments adapter decisions, without WordPress.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Integrations;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Integrations\Comments;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CommentsTest extends TestCase {

	const COMMENT = array(
		'comment_content'      => 'Great post, thanks for writing this up.',
		'comment_author'       => 'Jo',
		'comment_author_email' => 'jo@example.com',
		'comment_author_IP'    => '192.0.2.10',
		'comment_post_ID'      => 42,
		'user_id'              => 0,
	);

	protected function tear_down() {
		$GLOBALS['laas_test_filters'] = array();
		parent::tear_down();
	}

	private static function comments_with( string $label ): Comments {
		$GLOBALS['laas_test_filters']['laas_pre_classify'][] = static function () use ( $label ) {
			return new Verdict( $label, 0.5, 'test' );
		};
		return new Comments(
			new Pipeline(
				static function () {
					throw new \LogicException( 'not needed' );
				}
			)
		);
	}

	public function test_maps_comment_fields() {
		$submission = Comments::submission( self::COMMENT );
		$this->assertSame( 'comment', $submission->source );
		$this->assertSame( 'Great post, thanks for writing this up.', $submission->text );
		$this->assertSame( 'jo@example.com', $submission->email );
		$this->assertSame( '42', $submission->form_id );
		$this->assertSame( '192.0.2.10', $submission->ip );
	}

	public function test_logged_in_users_are_not_checked() {
		$this->assertNull( Comments::submission( array( 'user_id' => 7 ) + self::COMMENT ) );
		$this->assertSame( 1, self::comments_with( Verdict::SPAM )->filter_approved( 1, array( 'user_id' => 7 ) + self::COMMENT ) );
	}

	public function test_spam_goes_to_core_spam_and_the_rest_keeps_cores_decision() {
		$this->assertSame( 'spam', self::comments_with( Verdict::SPAM )->filter_approved( 1, self::COMMENT ) );
		$this->tear_down();
		$this->assertSame( 0, self::comments_with( Verdict::BORDERLINE )->filter_approved( 0, self::COMMENT ) );
		$this->tear_down();
		$this->assertSame( 1, self::comments_with( Verdict::CLEAN )->filter_approved( 1, self::COMMENT ) );
	}

	public function test_the_same_comment_is_classified_once_per_request() {
		$calls = 0;
		$GLOBALS['laas_test_filters']['laas_pre_classify'][] = static function () use ( &$calls ) {
			++$calls;
			return new Verdict( Verdict::CLEAN, 0.1, 'test' );
		};
		$comments = new Comments(
			new Pipeline(
				static function () {
					throw new \LogicException( 'not needed' );
				}
			)
		);
		$comments->filter_approved( 0, self::COMMENT ); // From wp_allow_comment().
		$comments->filter_approved( 0, self::COMMENT ); // Again from wp_new_comment().
		$this->assertSame( 1, $calls );
		$comments->filter_approved( 0, array( 'comment_content' => 'A different comment.' ) + self::COMMENT );
		$this->assertSame( 2, $calls );
	}

	public function test_already_judged_comments_are_left_alone() {
		$comments = self::comments_with( Verdict::CLEAN );
		$this->assertSame( 'trash', $comments->filter_approved( 'trash', self::COMMENT ) );
		$this->assertSame( 'spam', $comments->filter_approved( 'spam', self::COMMENT ) );
	}

	public function test_errors_fail_open() {
		$GLOBALS['laas_test_filters']['laas_pre_classify'][] = static function () {
			throw new \RuntimeException( 'boom' );
		};
		$comments = new Comments(
			new Pipeline(
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			)
		);
		$this->assertSame( 1, $comments->filter_approved( 1, self::COMMENT ) );
	}
}
