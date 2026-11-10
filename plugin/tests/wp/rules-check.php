<?php
/**
 * Allow and block rules inside wp-env, through real comments. Exits non-zero on any mismatch.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/rules-check.php
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
$laas_rules_table = LocalAIAntiSpam\Rules\RuleRepository::table();
$laas_check( 'rules table exists after upgrade', $laas_rules_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $laas_rules_table ) ) );
$laas_check( 'schema version has the rules table (2+)', (int) get_option( 'laas_db_version' ) >= 2 );
$wpdb->query( "TRUNCATE TABLE {$laas_rules_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.

$laas_rules = LocalAIAntiSpam\Plugin::instance()->rules();
$laas_check( 'block rule stored', 'seo-boost.example' === $laas_rules->add( 'domain', 'https://www.seo-boost.example/x', 'block', 1 ) );
$laas_check( 'allow rule stored', 'trusted@example.org' === $laas_rules->add( 'email', 'Trusted@Example.org', 'allow', 1 ) );
$laas_check( 'invalid rule refused', null === $laas_rules->add( 'ip', 'not-an-ip', 'block', 1 ) );

$laas_post    = wp_insert_post(
	array(
		'post_title'     => 'LAAS rules test',
		'post_status'    => 'publish',
		'comment_status' => 'open',
	)
);
$laas_comment = static function ( string $email, string $text, int $n ) use ( $laas_post ) {
	$id = wp_new_comment(
		array(
			'comment_post_ID'      => $laas_post,
			'comment_author'       => 'Visitor ' . $n,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'comment_author_IP'    => '192.0.2.' . ( 100 + $n ),
			'comment_content'      => $text,
			'comment_type'         => 'comment',
			'user_id'              => 0,
		),
		true
	);
	return is_wp_error( $id ) ? 'error:' . $id->get_error_code() : wp_get_comment_status( $id );
};

$laas_check( 'harmless comment from a blocked domain goes to spam', 'spam' === $laas_comment( 'mark@mail.seo-boost.example', 'Lovely photos of the garden, thank you for sharing them.', 1 ) );
$laas_status = $laas_comment( 'trusted@example.org', 'Get cheap backlinks and rank first on Google! Visit https://seo.example/offer now for a free SEO audit!!!', 2 );
$laas_check( "spammy comment from an allowed sender is delivered (status {$laas_status})", in_array( $laas_status, array( 'approved', 'unapproved' ), true ) );

$laas_hits       = $wpdb->get_results( "SELECT value, hits FROM {$laas_rules_table} ORDER BY value", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
$laas_block_hits = isset( $laas_hits['seo-boost.example'] ) ? (int) $laas_hits['seo-boost.example']->hits : -1;
$laas_allow_hits = isset( $laas_hits['trusted@example.org'] ) ? (int) $laas_hits['trusted@example.org']->hits : -1;
$laas_check( "block rule counted its hit (hits {$laas_block_hits})", 1 === $laas_block_hits );
$laas_check( "allow rule counted its hit (hits {$laas_allow_hits})", 1 === $laas_allow_hits );

wp_delete_post( $laas_post, true );
$wpdb->query( "TRUNCATE TABLE {$laas_rules_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
echo $laas_failed ? "FAIL\n" : "OK\n";
exit( $laas_failed ? 1 : 0 );
