<?php
/**
 * Public developer API (architecture.md §9, F17). A contract under semver.
 *
 * @package LocalAIAntiSpam
 */

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'laas_classify' ) ) {
	/**
	 * Classify arbitrary text. No side effects, nothing stored.
	 *
	 * @param string               $text    Text to classify.
	 * @param array<string, mixed> $context Optional: 'email', 'name', 'source' (default 'api').
	 * @return array<string, mixed> 'label', 'score', 'reason', 'model_version', 'top_features'.
	 */
	function laas_classify( string $text, array $context = array() ): array {
		try {
			$submission = new Submission(
				isset( $context['source'] ) ? (string) $context['source'] : 'api',
				$text,
				isset( $context['email'] ) ? (string) $context['email'] : null,
				isset( $context['name'] ) ? (string) $context['name'] : null
			);
			return Plugin::instance()->pipeline()->classify( $submission )->to_array();
		} catch ( \Throwable $error ) {
			return Verdict::fail_open()->to_array();
		}
	}
}
