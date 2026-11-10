<?php
/**
 * Test-only must-use plugin: records outgoing mail in an option instead of sending it.
 * cf7-setup.php copies it into wp-content/mu-plugins on the wp-env site; never ship it.
 *
 * @package LocalAIAntiSpam
 */

// Messages containing this marker score between the thresholds, to test borderline handling.
add_filter(
	'laas_score',
	static function ( $score, $submission ) {
		return false !== strpos( $submission->text, 'LAAS-BORDERLINE' ) ? 0.8 : $score;
	},
	10,
	2
);

add_filter(
	'pre_wp_mail',
	static function ( $result, $atts ) {
		$log   = (array) get_option( 'laas_test_mail', array() );
		$log[] = $atts;
		update_option( 'laas_test_mail', $log, false );
		return true;
	},
	10,
	2
);
