<?php
/**
 * WPForms end to end inside wp-env: creates a contact form, runs two submissions through WPForms'
 * own process(), then releases the held one. Needs the mail-capture mu-plugin (cf7-setup.php
 * installs it). WPForms' token and AJAX checks are off on the test form so process() can run
 * from the CLI; the plugin's hook runs exactly as it does for a browser submission.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/wpforms-check.php
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery -- CLI test script.

$laas_failed = false;
$laas_check  = static function ( string $what, bool $ok ) use ( &$laas_failed ) {
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $what );
	$laas_failed = $laas_failed || ! $ok;
};

global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . LocalAIAntiSpam\Quarantine\Installer::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
delete_option( 'laas_test_mail' );

$laas_form_data       = array(
	'fields'   => array(
		0 => array(
			'id'     => '0',
			'type'   => 'name',
			'label'  => 'Name',
			'format' => 'simple',
		),
		1 => array(
			'id'    => '1',
			'type'  => 'email',
			'label' => 'Email',
		),
		2 => array(
			'id'    => '2',
			'type'  => 'textarea',
			'label' => 'Comment or Message',
		),
	),
	'settings' => array(
		'form_title'          => 'LAAS WPForms test',
		'notification_enable' => '1',
		'notifications'       => array(
			1 => array(
				'email'          => '{admin_email}',
				'subject'        => 'New Entry: LAAS WPForms test',
				'sender_name'    => 'Test site',
				'sender_address' => '{admin_email}',
				'replyto'        => '{field_id="1"}',
				'message'        => '{all_fields}',
			),
		),
		'antispam'            => '0',
		'antispam_v3'         => '0',
		'ajax_submit'         => '0',
		'store_spam_entries'  => '0',
	),
	'meta'     => array( 'template' => 'blank' ),
);
$laas_form_id         = wp_insert_post(
	array(
		'post_type'   => 'wpforms',
		'post_status' => 'publish',
		'post_title'  => 'LAAS WPForms test',
	)
);
$laas_form_data['id'] = $laas_form_id;
wp_update_post(
	array(
		'ID'           => $laas_form_id,
		'post_content' => wpforms_encode( $laas_form_data ),
	)
);

$laas_submit = static function ( string $name, string $email, string $message ) use ( $laas_form_id ) {
	$process         = wpforms()->obj( 'process' );
	$process->errors = array();
	$process->fields = array();
	$_POST           = array();
	$process->process(
		array(
			'id'     => $laas_form_id,
			'fields' => array(
				0 => $name,
				1 => $email,
				2 => $message,
			),
		)
	);
	return $process->errors;
};

$laas_errors = $laas_submit( 'Sarah Jones', 'sarah@example.com', 'Hi, could I book a table for four this Friday at 7pm? Thanks, Sarah' );
$laas_mail   = (array) get_option( 'laas_test_mail', array() );
$laas_check( 'genuine submission has no errors', empty( $laas_errors[ $laas_form_id ] ) );
$laas_check( 'genuine submission notified the admin (mails: ' . count( $laas_mail ) . ')', 1 === count( $laas_mail ) );

$laas_errors = $laas_submit( 'Mark', 'mark@seo-boost.example', 'Hello, I noticed your website is not ranking on the first page of Google. Our SEO agency can get you quality backlinks and more traffic. Reply for a free audit: https://seo-boost.example/offer' );
$laas_mail   = (array) get_option( 'laas_test_mail', array() );
$laas_check( 'spam submission sent no notification (mails: ' . count( $laas_mail ) . ')', 1 === count( $laas_mail ) );
$laas_check( 'spam shows the visitor no error', empty( $laas_errors[ $laas_form_id ] ) );

$laas_repo = LocalAIAntiSpam\Plugin::instance()->repository();
$laas_page = $laas_repo->page( 'held', 1, 10 );
$laas_check( 'one message held (got ' . $laas_page['total'] . ')', 1 === $laas_page['total'] );
if ( 1 === $laas_page['total'] ) {
	$laas_item = $laas_repo->find( (int) $laas_page['rows'][0]['id'] );
	printf( "held: source=%s score=%s sender=%s name=%s\n", $laas_item['source'], $laas_item['score'], $laas_item['sender_email'], $laas_item['sender_name'] );
	$laas_check( 'held row is the WPForms pitch', 'wpforms' === $laas_item['source'] && false !== strpos( $laas_item['body'], 'backlinks' ) );
	$laas_check( 'sender name taken from the name field', 'Mark' === $laas_item['sender_name'] );

	$laas_check( 'release delivered', LocalAIAntiSpam\Plugin::instance()->releaser()->release( (int) $laas_item['id'], 1 ) );
	$laas_mail = (array) get_option( 'laas_test_mail', array() );
	$laas_last = end( $laas_mail );
	$laas_check( 'release sent the notification (mails: ' . count( $laas_mail ) . ')', 2 === count( $laas_mail ) );
	$laas_check( 'notification subject is the form\'s', isset( $laas_last['subject'] ) && false !== strpos( $laas_last['subject'], 'New Entry: LAAS WPForms test' ) );
	$laas_check( 'notification body has the message', isset( $laas_last['message'] ) && false !== strpos( $laas_last['message'], 'backlinks' ) );
}
printf( "laas_log entries: %d\n", count( (array) get_option( 'laas_log', array() ) ) );

wp_delete_post( $laas_form_id, true );
echo $laas_failed ? "FAIL\n" : "OK\n";
exit( $laas_failed ? 1 : 0 );
