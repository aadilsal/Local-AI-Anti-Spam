<?php
/**
 * Daily counts per source (architecture.md §6.3). Counts only, never content. Feeds the dashboard
 * widget (F13) and the digest (F15).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Stats;

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;
use LocalAIAntiSpam\Support\Log;

/**
 * The `laas_stats_daily` table.
 */
final class Stats {

	const COLUMNS = array( 'checked', 'clean', 'borderline', 'spam', 'released', 'unchecked', 'errors' );

	/** Sources that are not real submissions and are never counted. */
	const NOT_COUNTED = array( 'self-test', 'api' );

	const KEEP_DAYS = 400;

	/**
	 * Table name with this site's prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'laas_stats_daily';
	}

	/**
	 * `laas_classified`: count one verdict.
	 *
	 * @param Verdict    $verdict    The verdict.
	 * @param Submission $submission The submission.
	 * @return void
	 */
	public static function record( $verdict, $submission ): void {
		if ( ! $verdict instanceof Verdict || ! $submission instanceof Submission || in_array( $submission->source, self::NOT_COUNTED, true ) ) {
			return;
		}
		$columns = array( 'checked', in_array( $verdict->label, self::COLUMNS, true ) ? $verdict->label : 'unchecked' );
		if ( 'error' === $verdict->reason ) {
			$columns[] = 'errors';
		}
		self::increment( $submission->source, $columns );
	}

	/**
	 * `laas_released`: count a release under the row's source.
	 *
	 * @param int    $id     Quarantine row ID.
	 * @param int    $user   Who released it.
	 * @param string $source The row's source.
	 * @return void
	 */
	public static function record_release( $id, $user, $source = '' ): void {
		self::increment( '' !== (string) $source ? (string) $source : 'unknown', array( 'released' ) );
	}

	/**
	 * Add 1 to today's columns for a source. Never throws.
	 *
	 * @param string   $source  Source.
	 * @param string[] $columns Columns from COLUMNS.
	 * @return void
	 */
	private static function increment( string $source, array $columns ): void {
		global $wpdb;
		try {
			$columns = array_values( array_intersect( self::COLUMNS, $columns ) );
			$table   = self::table();
			$names   = implode( ', ', $columns );
			$ones    = implode( ', ', array_fill( 0, count( $columns ), '1' ) );
			$updates = implode(
				', ',
				array_map(
					static function ( $c ) {
						return "{$c} = {$c} + 1";
					},
					$columns
				)
			); // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie, Squiz.Functions.MultiLineFunctionDeclaration -- One-line closure.
			// Column names come from the COLUMNS allow-list; values are prepared.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (day, source, {$names}) VALUES (%s, %s, {$ones}) ON DUPLICATE KEY UPDATE {$updates}", gmdate( 'Y-m-d' ), substr( $source, 0, 32 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
		} catch ( \Throwable $error ) {
			Log::error( 'stats', $error );
		}
	}

	/**
	 * Totals and held-per-day for the last $days days (all sources).
	 *
	 * @param int $days Days, including today.
	 * @return array{totals: array<string, int>, held_per_day: array<string, int>}
	 */
	public static function last_days( int $days ): array {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(checked) AS checked, SUM(clean) AS clean, SUM(borderline) AS borderline, SUM(spam) AS spam, SUM(released) AS released, SUM(unchecked) AS unchecked, SUM(errors) AS errors FROM {$table} WHERE day >= %s GROUP BY day", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.

		$totals = array_fill_keys( self::COLUMNS, 0 );
		$held   = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$held[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			foreach ( self::COLUMNS as $column ) {
				$totals[ $column ] += (int) $row[ $column ];
			}
			if ( isset( $held[ $row['day'] ] ) ) {
				$held[ $row['day'] ] = (int) $row['spam'];
			}
		}
		return array(
			'totals'       => $totals,
			'held_per_day' => $held,
		);
	}

	/**
	 * Drop rows older than KEEP_DAYS (daily cron).
	 *
	 * @return void
	 */
	public static function rollup(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
	}
}
