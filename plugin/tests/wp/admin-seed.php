<?php
/**
 * Seeds the wp-env site for the admin checks: an empty quarantine with one hostile row (XSS
 * payloads in every field) and one plausible enquiry, plus a subscriber account. Prints the row IDs.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/admin-seed.php
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery -- CLI test script.

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;

global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . LocalAIAntiSpam\Quarantine\Installer::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
delete_option( 'laas_test_mail' );

$laas_repo  = LocalAIAntiSpam\Plugin::instance()->repository();
$laas_xss   = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'><svg onload=alert(3)>';
$laas_evil  = new Submission( 'cf7', "Buy now {$laas_xss} javascript:alert(4) https://evil.example", 'x@evil.example', $laas_xss, '0', '192.0.2.66', array() );
$laas_evil_v = new Verdict(
	Verdict::SPAM,
	0.9999,
	'model',
	array(
		array(
			'token'  => 'w:' . $laas_xss,
			'weight' => 1.0,
		),
	),
	'local-test'
);
$laas_id1 = $laas_repo->hold( $laas_evil, $laas_evil_v, "your-message: {$laas_xss}\nyour-name: {$laas_xss}" );

// A plausible enquiry held by mistake, on a real CF7 form, so "Not spam" can deliver it.
$laas_forms = get_posts(
	array(
		'post_type'   => 'wpcf7_contact_form',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
$laas_form  = $laas_forms ? (int) $laas_forms[0] : 0;
$laas_text  = 'Hi, could you quote for a kitchen refit next month?';
$laas_real  = new Submission(
	'cf7',
	$laas_text,
	'sarah@example.co.uk',
	'Sarah Mitchell',
	(string) $laas_form,
	'192.0.2.67',
	array(
		'form_id' => $laas_form,
		'fields'  => array(
			'your-name'    => 'Sarah Mitchell',
			'your-email'   => 'sarah@example.co.uk',
			'your-subject' => 'Kitchen refit',
			'your-message' => $laas_text,
		),
		'meta'    => array(),
	)
);
$laas_id2   = $laas_repo->hold( $laas_real, new Verdict( Verdict::SPAM, 0.9311, 'model', array(), 'local-test' ), "your-message: {$laas_text}" );

// No form behind it: release must fail and the row must stay held.
$laas_orphan = new Submission( 'cf7', 'Orphaned message from a deleted form.', 'jo@example.org', 'Jo', '0', null, array( 'form_id' => 0 ) );
$laas_id3    = $laas_repo->hold( $laas_orphan, new Verdict( Verdict::SPAM, 0.9500, 'model', array(), 'local-test' ), 'your-message: Orphaned message from a deleted form.' );

if ( ! get_user_by( 'login', 'laas_sub' ) ) {
	wp_insert_user(
		array(
			'user_login' => 'laas_sub',
			'user_pass'  => 'laas-sub-pass',
			'user_email' => 'laas_sub@example.com',
			'role'       => 'subscriber',
		)
	);
}
echo "{$laas_id1} {$laas_id2} {$laas_id3}\n";
