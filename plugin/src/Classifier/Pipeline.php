<?php
/**
 * Submission in, verdict out (architecture.md §3).
 *
 * Order: laas_pre_classify, empty text, language gate, model, laas_score, thresholds (with
 * laas_thresholds and the short-message lean), laas_verdict. Rules (F9) slot in before the model
 * once they exist. Any error, including one thrown by a filter callback, fails open.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Classifier;

use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Support\Log;

/**
 * Classifies submissions with the local model.
 */
final class Pipeline {

	const SHORT_WORDS    = 5;    // Fewer words than this: lean towards delivering.
	const SHORT_LEAN     = 0.05; // Added to t_high for short messages.
	const MAX_T_HIGH     = 0.999;
	const DEFAULT_T_LOW  = 0.50; // Only if a model file lacks thresholds.
	const DEFAULT_T_HIGH = 0.92;

	/**
	 * Returns the model; called at most once, on the first classification.
	 *
	 * @var callable(): LogisticModel
	 */
	private $load_model;

	/**
	 * The model, once loaded.
	 *
	 * @var LogisticModel|null
	 */
	private $model = null;

	/**
	 * User's offset from the model's t_high (Relaxed +0.04, Balanced 0, Strict -0.04).
	 *
	 * @var float
	 */
	private $t_high_offset;

	/**
	 * Finds the user's allow or block rule for a submission (F9), or null.
	 *
	 * @var callable(Submission): (array{id: int, kind: string, value: string, action: string}|null)|null
	 */
	private $match_rule;

	/**
	 * Constructor.
	 *
	 * @param callable(): LogisticModel $load_model    Loads the model lazily, never on page load.
	 * @param float                     $t_high_offset Offset added to the model's t_high.
	 * @param callable|null             $match_rule    Rule lookup; null means no rules.
	 */
	public function __construct( callable $load_model, float $t_high_offset = 0.0, ?callable $match_rule = null ) {
		$this->load_model    = $load_model;
		$this->t_high_offset = $t_high_offset;
		$this->match_rule    = $match_rule;
	}

	/**
	 * Classify one submission. Never throws.
	 *
	 * @param Submission $submission What to classify.
	 * @return Verdict
	 */
	public function classify( Submission $submission ): Verdict {
		try {
			$verdict = $this->decide( $submission );
		} catch ( \Throwable $error ) {
			Log::error( 'classify', $error );
			$verdict = Verdict::fail_open();
		}
		try {
			do_action( 'laas_classified', $verdict, $submission ); // Counts (Stats); never changes the verdict.
		} catch ( \Throwable $error ) {
			Log::error( 'classified_hook', $error );
		}
		return $verdict;
	}

	/**
	 * The pipeline itself; may throw, and classify() catches it.
	 *
	 * @param Submission $submission What to classify.
	 * @return Verdict
	 */
	private function decide( Submission $submission ): Verdict {
		$early = apply_filters( 'laas_pre_classify', null, $submission );
		if ( $early instanceof Verdict ) {
			return $early;
		}

		// The user's own rules come first, before the gates: an explicit block or allow applies to
		// any message, English or not.
		$rule = null !== $this->match_rule ? call_user_func( $this->match_rule, $submission ) : null;
		if ( is_array( $rule ) ) {
			$allow = 'allow' === $rule['action'];
			return new Verdict(
				$allow ? Verdict::CLEAN : Verdict::SPAM,
				$allow ? 0.0 : 1.0,
				$allow ? 'rule:allow' : 'rule:block',
				array(
					array(
						'token'  => $rule['kind'] . ':' . $rule['value'],
						'weight' => 0.0,
					),
				)
			);
		}

		$text = trim( $submission->text );
		if ( '' === $text ) {
			return Verdict::unchecked( 'empty' );
		}
		if ( ! LanguageGate::is_english( $text ) ) {
			return Verdict::unchecked( 'lang' );
		}

		$model  = $this->model();
		$header = $model->header();
		$result = $model->classify( $text, $submission->email );
		$score  = (float) apply_filters( 'laas_score', $result['score'], $submission );

		list( $t_low, $t_high ) = $this->thresholds( $header, $submission );
		if ( count( preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ) < self::SHORT_WORDS ) {
			$t_high = min( self::MAX_T_HIGH, $t_high + self::SHORT_LEAN );
		}

		if ( $score >= $t_high ) {
			$label = Verdict::SPAM;
		} elseif ( $score >= $t_low ) {
			$label = Verdict::BORDERLINE;
		} else {
			$label = Verdict::CLEAN;
		}
		$verdict = new Verdict( $label, $score, 'model', $result['top_features'], (string) $header['model_version'] );

		$final = apply_filters( 'laas_verdict', $verdict, $submission );
		return $final instanceof Verdict ? $final : $verdict;
	}

	/**
	 * The loaded model.
	 *
	 * @return LogisticModel
	 */
	private function model(): LogisticModel {
		if ( null === $this->model ) {
			$this->model = call_user_func( $this->load_model );
		}
		return $this->model;
	}

	/**
	 * Thresholds: the model's own, the user's offset, then the laas_thresholds filter.
	 *
	 * @param array<string, mixed> $header     Model header.
	 * @param Submission           $submission The submission.
	 * @return array{0: float, 1: float}
	 */
	private function thresholds( array $header, Submission $submission ): array {
		$t_low  = isset( $header['t_low'] ) ? (float) $header['t_low'] : self::DEFAULT_T_LOW;
		$t_high = isset( $header['t_high'] ) ? (float) $header['t_high'] : self::DEFAULT_T_HIGH;
		$t_high = min( self::MAX_T_HIGH, max( $t_low, $t_high + $this->t_high_offset ) );

		$filtered = apply_filters( 'laas_thresholds', array( $t_low, $t_high ), $submission->source );
		if ( is_array( $filtered ) && 2 === count( $filtered ) ) {
			list( $low, $high ) = array_map( 'floatval', array_values( $filtered ) );
			if ( 0.0 <= $low && $low <= $high && $high <= 1.0 ) {
				return array( $low, $high );
			}
		}
		return array( $t_low, $t_high );
	}
}
