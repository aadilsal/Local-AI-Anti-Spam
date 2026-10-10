<?php
/**
 * Latency and memory benchmark for local inference (PRD: < 20 ms, < 16 MB per submission).
 *
 * Usage: php tests/bench/inference-bench.php path/to/model.bin [iterations]
 * Exits non-zero if a budget is broken, so CI can run it.
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, not loaded by WordPress.

use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

const LAAS_BENCH_BUDGET_MS = 20.0;
const LAAS_BENCH_BUDGET_MB = 16.0;

$laas_model_path = isset( $argv[1] ) ? $argv[1] : '';
$laas_iterations = isset( $argv[2] ) ? (int) $argv[2] : 200;
if ( '' === $laas_model_path ) {
	fwrite( STDERR, "Usage: php inference-bench.php path/to/model.bin [iterations]\n" );
	exit( 2 );
}

$laas_messages = array(
	'short enquiry'  => 'Hi, can I book a table for four on Friday at 7pm? Thanks, Sarah',
	'typical'        => str_repeat( 'Hello, I am interested in a quote for a kitchen refit next month. Could someone call me back on 07700 900123? ', 4 ),
	'sales pitch'    => str_repeat( 'I noticed your website is not ranking on the first page of Google. Our SEO team can get you backlinks and traffic: https://seo.example/offer?ref=1 ', 8 ),
	'worst case 8KB' => str_repeat( 'BUY NOW http://a.example/x?y=1 call +1 555 010 9999 email x@y.example 12345 ', 120 ),
);

$laas_memory_before = memory_get_usage();
$laas_load_start    = hrtime( true );
$laas_file          = ModelFile::load( $laas_model_path, true );
$laas_check_ms      = ( hrtime( true ) - $laas_load_start ) / 1e6;
$laas_load_start    = hrtime( true );
$laas_file          = ModelFile::load( $laas_model_path, false );
$laas_load_ms       = ( hrtime( true ) - $laas_load_start ) / 1e6;
$laas_model         = new LogisticModel( $laas_file );

printf( "PHP %s · model %s (%d buckets)\n", PHP_VERSION, $laas_file->header()['model_version'], $laas_file->n_buckets() );
printf( "Load: %.1f ms per submission; with sha256 check: %.1f ms (once per plugin version)\n\n", $laas_load_ms, $laas_check_ms );
printf( "%-16s %8s %8s %8s %8s\n", 'message', 'bytes', 'p50 ms', 'p95 ms', 'max ms' );

$laas_failed = false;
foreach ( $laas_messages as $laas_name => $laas_text ) {
	$laas_model->classify( $laas_text, 'someone@example.com' ); // Warm-up.
	$laas_times = array();
	for ( $laas_i = 0; $laas_i < $laas_iterations; $laas_i++ ) {
		$laas_start = hrtime( true );
		$laas_model->classify( $laas_text, 'someone@example.com' );
		$laas_times[] = ( hrtime( true ) - $laas_start ) / 1e6;
	}
	sort( $laas_times );
	$laas_p50 = $laas_times[ (int) floor( 0.50 * ( count( $laas_times ) - 1 ) ) ];
	$laas_p95 = $laas_times[ (int) floor( 0.95 * ( count( $laas_times ) - 1 ) ) ];
	printf( "%-16s %8d %8.2f %8.2f %8.2f\n", $laas_name, strlen( $laas_text ), $laas_p50, $laas_p95, end( $laas_times ) );
	if ( $laas_p95 > LAAS_BENCH_BUDGET_MS ) {
		$laas_failed = true;
	}
}

$laas_peak_mb = ( memory_get_peak_usage() - $laas_memory_before ) / 1048576;
printf( "\nPeak extra memory: %.1f MB (budget %.0f MB)\n", $laas_peak_mb, LAAS_BENCH_BUDGET_MB );
if ( $laas_peak_mb > LAAS_BENCH_BUDGET_MB ) {
	$laas_failed = true;
}

echo $laas_failed ? "FAIL: over budget\n" : "OK: within budget\n";
exit( $laas_failed ? 1 : 0 );
