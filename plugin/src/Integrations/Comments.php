<?php
/**
 * WordPress comments, through the core `pre_comment_approved` filter (architecture.md §5).
 *
 * Spam goes to core's own Spam list, where "Not spam" already releases it. Logged-in users are
 * trusted, and a comment that core or another plugin has already judged is left alone.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Support\Log;

/**
 * Classifies new comments.
 */
final class Comments implements Integration {

	const META_LABEL = 'laas_verdict';
	const META_SCORE = 'laas_score';

	/**
	 * The pipeline.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	/**
	 * Verdict for the comment being saved, kept for `comment_post`.
	 *
	 * @var Verdict|null
	 */
	private $pending = null;

	/**
	 * Key of the comment last classified on this request, and its verdict. WordPress applies
	 * `pre_comment_approved` twice for one new comment (from wp_allow_comment() and again from
	 * wp_new_comment(), seen in WordPress 6.9), so the second call reuses the first verdict instead
	 * of scoring again and counting rule hits twice.
	 *
	 * @var array{0: string, 1: Verdict}|null
	 */
	private $memo = null;

	/**
	 * Constructor.
	 *
	 * @param Pipeline $pipeline The pipeline.
	 */
	public function __construct( Pipeline $pipeline ) {
		$this->pipeline = $pipeline;
	}

	/**
	 * Short ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'comment';
	}

	/**
	 * Comments are part of core.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_comment_approved', array( $this, 'filter_approved' ), 20, 2 );
		add_action( 'comment_post', array( $this, 'record_verdict' ), 10, 1 );
	}

	/**
	 * Map a comment to a submission, or null if it shouldn't be checked.
	 *
	 * @param array<string, mixed> $commentdata Comment data from wp_new_comment().
	 * @return Submission|null
	 */
	public static function submission( array $commentdata ): ?Submission {
		if ( ! empty( $commentdata['user_id'] ) ) {
			return null; // Logged-in users are trusted.
		}
		return new Submission(
			'comment',
			(string) ( $commentdata['comment_content'] ?? '' ),
			isset( $commentdata['comment_author_email'] ) ? (string) $commentdata['comment_author_email'] : null,
			isset( $commentdata['comment_author'] ) ? (string) $commentdata['comment_author'] : null,
			(string) ( $commentdata['comment_post_ID'] ?? '' ),
			isset( $commentdata['comment_author_IP'] ) ? (string) $commentdata['comment_author_IP'] : null
		);
	}

	/**
	 * `pre_comment_approved`: return 'spam' for spam, otherwise what core decided.
	 *
	 * @param int|string|\WP_Error $approved    Core's decision: 1, 0, 'spam', 'trash' or an error.
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public function filter_approved( $approved, $commentdata ) {
		$this->pending = null;
		try {
			// Only judge comments core would publish or hold; leave spam, trash and errors alone.
			if ( ! in_array( $approved, array( 0, 1, '0', '1' ), true ) || ! is_array( $commentdata ) ) {
				return $approved;
			}
			$submission = self::submission( $commentdata );
			if ( null === $submission ) {
				return $approved;
			}
			$key = hash( 'sha256', implode( "\0", array( $submission->form_id, (string) $submission->email, (string) $submission->ip, $submission->text ) ) );
			if ( null === $this->memo || $this->memo[0] !== $key ) {
				$this->memo = array( $key, $this->pipeline->classify( $submission ) );
			}
			$this->pending = $this->memo[1];
			return $this->pending->is_spam() ? 'spam' : $approved;
		} catch ( \Throwable $error ) {
			Log::error( 'comment', $error );
			return $approved; // Fail open.
		}
	}

	/**
	 * `comment_post`: store the verdict on the comment, for the admin columns.
	 *
	 * @param int $comment_id New comment ID.
	 * @return void
	 */
	public function record_verdict( $comment_id ): void {
		$verdict       = $this->pending;
		$this->pending = null;
		if ( null === $verdict ) {
			return;
		}
		try {
			add_comment_meta( (int) $comment_id, self::META_LABEL, $verdict->label, true );
			add_comment_meta( (int) $comment_id, self::META_SCORE, round( $verdict->score, 4 ), true );
		} catch ( \Throwable $error ) {
			Log::error( 'comment_meta', $error );
		}
	}
}
