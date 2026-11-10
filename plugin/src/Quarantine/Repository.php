<?php
/**
 * Reads and writes held messages (architecture.md §6.1). All SQL goes through $wpdb->prepare()
 * and sort columns come from an allow-list (hard rule 5).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Quarantine;

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Classifier\Verdict;

/**
 * The quarantine table.
 */
final class Repository {

	const HELD     = 'held';
	const RELEASED = 'released';

	const SORTABLE = array(
		'created_at' => 'created_at',
		'score'      => 'score',
		'source'     => 'source',
	);

	const PURGE_BATCH = 500;

	/**
	 * Store a submission the pipeline judged spam. Never deletes anything (hard rule 12).
	 *
	 * @param Submission $submission The submission.
	 * @param Verdict    $verdict    Its verdict.
	 * @param string     $body       Text to show in the detail view (all stored fields).
	 * @return int Row ID, or 0 on failure.
	 */
	public function hold( Submission $submission, Verdict $verdict, string $body ): int {
		global $wpdb;
		$first_line = trim( (string) strtok( $submission->text, "\n" ) );
		$inserted   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.
			Installer::table(),
			array(
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'source'        => substr( $submission->source, 0, 32 ),
				'form_id'       => substr( $submission->form_id, 0, 64 ),
				'folder'        => 'spam',
				'status'        => self::HELD,
				'verdict'       => $verdict->label,
				'score'         => round( $verdict->score, 4 ),
				'reason'        => substr( $verdict->reason, 0, 32 ),
				'top_features'  => (string) wp_json_encode( $verdict->top_features ),
				'model_version' => substr( $verdict->model_version, 0, 48 ),
				'sender_email'  => substr( (string) $submission->email, 0, 191 ),
				'sender_name'   => substr( (string) $submission->name, 0, 191 ),
				'sender_ip'     => self::store_ip() ? $submission->ip : null,
				'subject_line'  => substr( $first_line, 0, 255 ),
				'body'          => $body,
				'payload'       => (string) wp_json_encode( $submission->payload ),
				'msg_hash'      => hash( 'sha256', strtolower( preg_replace( '/\s+/', ' ', trim( $submission->text ) ) ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One row, with payload and top_features decoded.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Installer::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['payload']      = (array) json_decode( (string) $row['payload'], true );
		$row['top_features'] = (array) json_decode( (string) $row['top_features'], true );
		return $row;
	}

	/**
	 * A page of rows for the list screen.
	 *
	 * @param string $status   'held' or 'released'.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @param string $orderby  Column from SORTABLE.
	 * @param string $order    'asc' or 'desc'.
	 * @param string $search   Optional text to find in sender or subject.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function page( string $status, int $page, int $per_page, string $orderby = 'created_at', string $order = 'desc', string $search = '' ): array {
		global $wpdb;
		$table  = Installer::table();
		$column = self::SORTABLE[ $orderby ] ?? 'created_at';
		$dir    = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
		$where  = $wpdb->prepare( 'status = %s', $status );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (sender_email LIKE %s OR sender_name LIKE %s OR subject_line LIKE %s)', $like, $like, $like );
		}
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; $where is prepared above and $column/$dir come from allow-lists.
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, created_at, source, form_id, status, verdict, score, reason, sender_email, sender_name, subject_line, released_at FROM {$table} WHERE {$where} ORDER BY {$column} {$dir}, id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// phpcs:enable
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Number of held rows.
	 *
	 * @return int
	 */
	public function count_held(): int {
		global $wpdb;
		$table = Installer::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::HELD ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
	}

	/**
	 * Mark a row released.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function mark_released( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
			Installer::table(),
			array(
				'status'      => self::RELEASED,
				'released_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete one row (a user action).
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Installer::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
	}

	/**
	 * Retention: delete rows older than $days, in batches (F6, C5). Called by the daily cron.
	 *
	 * @param int $days Keep this many days.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;
		$table   = Installer::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		do {
			$batch    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::PURGE_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
			$deleted += $batch;
		} while ( self::PURGE_BATCH === $batch );
		return $deleted;
	}

	/**
	 * Whether to store sender IPs (setting, default on).
	 *
	 * @return bool
	 */
	private static function store_ip(): bool {
		$settings = (array) get_option( 'laas_settings', array() );
		return ! isset( $settings['store_ip'] ) || (bool) $settings['store_ip'];
	}
}
