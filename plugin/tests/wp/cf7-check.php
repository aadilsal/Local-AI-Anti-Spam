<?php
/**
 * Checks what two real CF7 submissions did (sent through CF7's REST endpoint after cf7-setup.php),
 * then releases the held one and checks the mail it sends. Exits non-zero on any mismatch.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/cf7-check.php
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- CLI test script.

$laas_failed = false;
$laas_check  = static function ( string $what, bool $ok ) use ( &$laas_failed ) {
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $what );
	$laas_failed = $laas_failed || ! $ok;
};

$laas_repo = LocalAIAntiSpam\Plugin::instance()->repository();
$laas_mail = (array) get_option( 'laas_test_mail', array() );
$laas_page = $laas_repo->page( 'held', 1, 10 );

$laas_check( 'genuine enquiry was mailed by CF7 (1 mail captured, got ' . count( $laas_mail ) . ')', 1 === count( $laas_mail ) );
$laas_check( 'genuine mail carries the message', isset( $laas_mail[0]['message'] ) && false !== strpos( $laas_mail[0]['message'], 'table for four' ) );
$laas_check( 'one message held (got ' . $laas_page['total'] . ')', 1 === $laas_page['total'] );
if ( 1 !== $laas_page['total'] ) {
	exit( 1 );
}

$laas_item = $laas_repo->find( (int) $laas_page['rows'][0]['id'] );
printf( "held: score=%s reason=%s sender=%s subject=%s\n", $laas_item['score'], $laas_item['reason'], $laas_item['sender_email'], $laas_item['subject_line'] );
$laas_check( 'held row is the SEO pitch', false !== strpos( $laas_item['body'], 'backlinks' ) );
$laas_check( 'held row keeps the posted fields for release', isset( $laas_item['payload']['fields']['your-message'] ) );
$laas_check( 'held row has top features', array() !== $laas_item['top_features'] );

$laas_released = LocalAIAntiSpam\Plugin::instance()->releaser()->release( (int) $laas_item['id'], 1 );
$laas_check( 'release delivered', $laas_released );
$laas_mail = (array) get_option( 'laas_test_mail', array() );
$laas_last = end( $laas_mail );
$laas_check( 'release sent one more mail', 2 === count( $laas_mail ) );
$laas_check( 'released mail goes to the admin', isset( $laas_last['to'] ) && get_option( 'admin_email' ) === $laas_last['to'] );
$laas_check( 'released mail has the message', isset( $laas_last['message'] ) && false !== strpos( $laas_last['message'], 'backlinks' ) );
$laas_check( 'released mail has CF7 subject', isset( $laas_last['subject'] ) && false !== strpos( $laas_last['subject'], 'Grow your traffic' ) );
$laas_check( 'released mail keeps Reply-To', isset( $laas_last['headers'] ) && in_array( 'Reply-To: mark@seo-boost.example', (array) $laas_last['headers'], true ) );
$laas_check( 'row now released', 'released' === $laas_repo->find( (int) $laas_item['id'] )['status'] );
$laas_check( 'second release refused', ! LocalAIAntiSpam\Plugin::instance()->releaser()->release( (int) $laas_item['id'], 1 ) );
printf( "laas_log entries: %d\n", count( (array) get_option( 'laas_log', array() ) ) );

echo $laas_failed ? "FAIL\n" : "OK\n";
exit( $laas_failed ? 1 : 0 );
