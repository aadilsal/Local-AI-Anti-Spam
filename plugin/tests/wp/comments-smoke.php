<?php
/**
 * End-to-end check of the comments integration inside a real WordPress (wp-env).
 *
 * Usage (from plugin/): npx wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/comments-smoke.php
 * Posts comments through wp_new_comment(), as a visitor would, and checks where they end up.
 * Exits non-zero on any mismatch. Uses fictional people and reserved domains only.
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- CLI test script.

if ( ! function_exists( 'laas_classify' ) ) {
	fwrite( STDERR, "FAIL: plugin not active (laas_classify missing)\n" );
	exit( 1 );
}

$laas_post_id = wp_insert_post(
	array(
		'post_title'     => 'LAAS smoke test ' . gmdate( 'c' ),
		'post_status'    => 'publish',
		'comment_status' => 'open',
	)
);

$laas_cases = array(
	array(
		'name'     => 'genuine comment',
		'text'     => 'Thanks for this post, the tips on pruning roses in autumn were really useful. Do you have any advice for climbing roses as well?',
		'email'    => 'jo.reader@example.com',
		'expected' => 'not spam',
	),
	array(
		'name'     => 'seo pitch',
		'text'     => 'Hello, I noticed your website is not ranking on the first page of Google. Our SEO agency can get you quality backlinks and more traffic. Reply for a free audit: https://seo-boost.example/offer',
		'email'    => 'mark@seo-boost.example',
		'expected' => 'spam',
	),
	array(
		'name'     => 'link spam',
		'text'     => 'Great article!!! Check out my channel for FREE crypto signals and earn 5% daily guaranteed http://crypto-signals.example/join http://crypto-signals.example/vip',
		'email'    => 'promo@crypto-signals.example',
		'expected' => 'spam',
	),
	array(
		'name'     => 'non-English',
		'text'     => 'Здравствуйте, спасибо за статью, она была очень полезной для нашего сада.',
		'email'    => 'ivan@example.org',
		'expected' => 'not spam',
	),
);

$laas_failed = false;
foreach ( $laas_cases as $laas_i => $laas_case ) {
	$laas_id = wp_new_comment(
		array(
			'comment_post_ID'      => $laas_post_id,
			'comment_author'       => 'Visitor ' . $laas_i,
			'comment_author_email' => $laas_case['email'],
			'comment_author_url'   => '',
			'comment_author_IP'    => '192.0.2.' . ( 10 + $laas_i ),
			'comment_content'      => $laas_case['text'],
			'comment_type'         => 'comment',
			'user_id'              => 0,
		),
		true
	);
	if ( is_wp_error( $laas_id ) ) {
		printf( "FAIL %-16s wp_new_comment error: %s\n", $laas_case['name'], $laas_id->get_error_code() );
		$laas_failed = true;
		continue;
	}
	$laas_status  = wp_get_comment_status( $laas_id );
	$laas_verdict = get_comment_meta( $laas_id, 'laas_verdict', true );
	$laas_score   = get_comment_meta( $laas_id, 'laas_score', true );
	$laas_ok      = ( 'spam' === $laas_case['expected'] ) === ( 'spam' === $laas_status );
	printf( "%s %-16s status=%-10s verdict=%-10s score=%s\n", $laas_ok ? 'ok  ' : 'FAIL', $laas_case['name'], $laas_status, $laas_verdict, $laas_score );
	$laas_failed = $laas_failed || ! $laas_ok;
}

$laas_api = laas_classify( 'Can I book a table for four on Friday at 7pm?' );
printf( "laas_classify: label=%s reason=%s model=%s\n", $laas_api['label'], $laas_api['reason'], $laas_api['model_version'] );
printf( "laas_log entries: %d\n", count( (array) get_option( 'laas_log', array() ) ) );

wp_delete_post( $laas_post_id, true );
echo $laas_failed ? "FAIL\n" : "OK\n";
exit( $laas_failed ? 1 : 0 );
