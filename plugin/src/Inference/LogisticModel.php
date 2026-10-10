<?php
/**
 * Calibrated logistic regression over the featurizer's output (architecture.md §4.3).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Inference;

/**
 * Scores messages with a loaded model file.
 */
final class LogisticModel {

	const TOP_FEATURES = 8;

	/**
	 * The model file.
	 *
	 * @var ModelFile
	 */
	private $file;

	/**
	 * Constructor.
	 *
	 * @param ModelFile $file Loaded model.
	 */
	public function __construct( ModelFile $file ) {
		$this->file = $file;
	}

	/**
	 * Raw logit for already-featurized input.
	 *
	 * @param array{hashed: array<int, float>, dense: float[]} $features Featurizer output.
	 * @return float
	 */
	public function logit( array $features ): float {
		$header = $this->file->header();
		$z      = (float) $header['bias'];
		foreach ( $features['hashed'] as $bucket => $value ) {
			$z += $this->file->weight( $bucket ) * $value;
		}
		$weights = $this->file->dense_weights();
		foreach ( $features['dense'] as $i => $x ) {
			$z += $weights[ $i ] * ( $x - $header['dense_means'][ $i ] ) / $header['dense_stds'][ $i ];
		}
		return $z;
	}

	/**
	 * Score a message.
	 *
	 * @param string      $text  Message text.
	 * @param string|null $email Sender email, if any.
	 * @return array{score: float, logit: float, top_features: array<int, array{token: string, weight: float}>}
	 */
	public function classify( string $text, ?string $email = null ): array {
		$header   = $this->file->header();
		$features = Featurizer::featurize( $text, $email, $this->file->n_buckets() );
		$z        = $this->logit( $features );

		$contributions = array();
		foreach ( $features['names'] as $bucket => $token ) {
			$weight = $this->file->weight( $bucket ) * $features['hashed'][ $bucket ];
			if ( $weight > 0 && ! isset( $contributions[ $token ] ) ) {
				$contributions[ $token ] = $weight;
			}
		}
		arsort( $contributions );
		$top = array();
		foreach ( array_slice( $contributions, 0, self::TOP_FEATURES, true ) as $token => $weight ) {
			$top[] = array(
				'token'  => (string) $token,
				'weight' => round( $weight, 4 ),
			);
		}

		return array(
			'score'        => 1.0 / ( 1.0 + exp( -( $header['platt_a'] * $z + $header['platt_b'] ) ) ),
			'logit'        => $z,
			'top_features' => $top,
		);
	}
}
