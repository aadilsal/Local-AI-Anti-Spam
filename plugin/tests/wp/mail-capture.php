<?php
/**
 * Test-only must-use plugin: records outgoing mail in an option instead of sending it.
 * cf7-setup.php copies it into wp-content/mu-plugins on the wp-env site; never ship it.
 *
 * @package LocalAIAntiSpam
 */

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
