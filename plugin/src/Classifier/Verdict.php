<?php
/**
 * The pipeline's decision about one submission (architecture.md §3.2).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Classifier;

/**
 * What to do with a message, and why.
 */
final class Verdict {

	const CLEAN      = 'clean';
	const BORDERLINE = 'borderline';
	const SPAM       = 'spam';
	const UNCHECKED  = 'unchecked';

	/**
	 * One of the label constants.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Calibrated P(spam), 0..1. Zero when unchecked.
	 *
	 * @var float
	 */
	public $score;

	/**
	 * 'model', 'empty', 'lang', 'error', 'rule:allow', 'rule:block', 'cloud', ...
	 *
	 * @var string
	 */
	public $reason;

	/**
	 * Up to 8 words that pushed the score up: [['token' => ..., 'weight' => ...]].
	 *
	 * @var array<int, array{token: string, weight: float}>
	 */
	public $top_features;

	/**
	 * Model that decided, e.g. 'local-baseline-20261107-0958'.
	 *
	 * @var string
	 */
	public $model_version;

	/**
	 * Pro only: 'solicitation', 'crypto', ...
	 *
	 * @var string|null
	 */
	public $subtype;

	/**
	 * Constructor.
	 *
	 * @param string                                          $label         Label constant.
	 * @param float                                           $score         P(spam).
	 * @param string                                          $reason        Why.
	 * @param array<int, array{token: string, weight: float}> $top_features  Explanation.
	 * @param string                                          $model_version Model ID.
	 * @param string|null                                     $subtype       Pro subtype.
	 */
	public function __construct( string $label, float $score, string $reason, array $top_features = array(), string $model_version = '', ?string $subtype = null ) {
		$this->label         = $label;
		$this->score         = $score;
		$this->reason        = $reason;
		$this->top_features  = $top_features;
		$this->model_version = $model_version;
		$this->subtype       = $subtype;
	}

	/**
	 * A message that was not checked, and is delivered.
	 *
	 * @param string $reason Why it was skipped.
	 * @return self
	 */
	public static function unchecked( string $reason ): self {
		return new self( self::UNCHECKED, 0.0, $reason );
	}

	/**
	 * Something went wrong: deliver the message (hard rule 2, fail open).
	 *
	 * @return self
	 */
	public static function fail_open(): self {
		return self::unchecked( 'error' );
	}

	/**
	 * Whether the form plugin should be told "spam".
	 *
	 * @return bool
	 */
	public function is_spam(): bool {
		return self::SPAM === $this->label;
	}

	/**
	 * Array form, as returned by laas_classify().
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'label'         => $this->label,
			'score'         => $this->score,
			'reason'        => $this->reason,
			'model_version' => $this->model_version,
			'top_features'  => $this->top_features,
		);
	}
}
