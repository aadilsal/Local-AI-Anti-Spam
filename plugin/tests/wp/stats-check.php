<?php
/**
 * Stats, digest, dashboard widget and privacy tools inside wp-env. Needs the mail-capture
 * mu-plugin (cf7-setup.php installs it). Exits non-zero on any mismatch.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/stats-check.php
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery -- CLI test script.

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Plugin;
use LocalAIAntiSpam\Stats\Stats;

$laas_failed = false;
$laas_check  = static function ( string $what, bool $ok ) use ( &$laas_failed ) {
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $what );
	$laas_failed = $laas_failed || ! $ok;
};

global $wpdb;
$laas_check( 'schema version is 3', 3 === (int) get_option( 'laas_db_version' ) );
$laas_check( 'digest cron scheduled', false !== wp_next_scheduled( 'laas_digest' ) );
foreach ( array( Stats::table(), LocalAIAntiSpam\Quarantine\Installer::table() ) as $laas_table ) {
	$wpdb->query( "TRUNCATE TABLE {$laas_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names.
}
delete_option( 'laas_test_mail' );
delete_option( 'laas_digest_last' );

// Two real comments: one clean, one spam.
$laas_post = wp_insert_post(
	array(
		'post_title'     => 'LAAS stats test',
		'post_status'    => 'publish',
		'comment_status' => 'open',
	)
);
foreach ( array( 'Thanks for the recipe, the bread came out really well.', 'Cheap backlinks! Rank first on Google today, free SEO audit at https://seo-boost.example/offer' ) as $laas_i => $laas_text ) {
	wp_new_comment(
		array(
			'comment_post_ID'      => $laas_post,
			'comment_author'       => 'Visitor ' . $laas_i,
			'comment_author_email' => "visitor{$laas_i}@example.org",
			'comment_author_url'   => '',
			'comment_author_IP'    => '192.0.2.' . ( 200 + $laas_i ),
			'comment_content'      => $laas_text,
			'comment_type'         => 'comment',
			'user_id'              => 0,
		),
		true
	);
}
$laas_totals = Stats::last_days( 30 )['totals'];
$laas_check( "stats count two checks once each (checked {$laas_totals['checked']})", 2 === $laas_totals['checked'] );
$laas_check( "stats count one spam (spam {$laas_totals['spam']})", 1 === $laas_totals['spam'] );
$laas_check( 'self-test is not counted', 2 === ( LocalAIAntiSpam\Admin\SettingsPage::self_test( Plugin::instance() ) ? Stats::last_days( 30 )['totals']['checked'] : -1 ) );

// A held form message for the digest and the privacy tools.
$laas_repo = Plugin::instance()->repository();
$laas_repo->hold(
	new Submission( 'cf7', "Hello there, visit https://evil.example/now for SEO\nsecond line", 'jo@example.org', 'Jo <b>Bloggs</b>', '0', '192.0.2.9', array() ),
	new Verdict( Verdict::SPAM, 0.95, 'model', array(), 'local-test' ),
	'your-message: Hello there, visit https://evil.example/now for SEO'
);
$laas_digest = new LocalAIAntiSpam\Stats\Digest( $laas_repo );
$laas_check( 'digest sent when forced', $laas_digest->run( true ) );
$laas_mail = (array) get_option( 'laas_test_mail', array() );
$laas_last = end( $laas_mail );
$laas_check( 'digest subject counts the held message', isset( $laas_last['subject'] ) && false !== strpos( $laas_last['subject'], '1 message held as spam' ) );
$laas_check( 'digest lists the sender and score', isset( $laas_last['message'] ) && false !== strpos( $laas_last['message'], 'Jo Bloggs: "Hello there, visit [link] for SEO"  score 0.95' ) );
$laas_check( 'digest has no spam links', isset( $laas_last['message'] ) && false === strpos( $laas_last['message'], 'evil.example' ) );
$laas_check( 'digest links into wp-admin', isset( $laas_last['message'] ) && false !== strpos( $laas_last['message'], 'wp-admin/admin.php?page=laas-quarantine&item=' ) );
$laas_check( 'digest not sent again with nothing new', ! $laas_digest->run() );

// Privacy tools, through WordPress's own registries.
$laas_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$laas_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$laas_export    = call_user_func( $laas_exporters['local-ai-anti-spam']['callback'], 'jo@example.org', 1 );
$laas_check( 'exporter returns the held message', 1 === count( $laas_export['data'] ) && $laas_export['done'] );
$laas_erase = call_user_func( $laas_erasers['local-ai-anti-spam']['callback'], 'jo@example.org', 1 );
$laas_check( 'eraser removes it', $laas_erase['items_removed'] && 0 === count( $laas_repo->by_email( 'jo@example.org', 1, 10 ) ) );

// Dashboard widget markup.
ob_start();
( new LocalAIAntiSpam\Admin\DashboardWidget( Plugin::instance() ) )->render();
$laas_widget = ob_get_clean();
$laas_check( 'widget shows the 30-day counts', false !== strpos( $laas_widget, '2 checked · 1 held · 0 released by you' ) );
$laas_check( 'widget sparkline has a text alternative', false !== strpos( $laas_widget, 'role="img" aria-label="Held per day, last 30 days: min 0, max 1"' ) );

wp_delete_post( $laas_post, true );
echo $laas_failed ? "FAIL\n" : "OK\n";
exit( $laas_failed ? 1 : 0 );
