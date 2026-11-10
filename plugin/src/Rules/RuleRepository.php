<?php
/**
 * The `laas_rules` table (architecture.md §6.2). Rules are read once per request.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Rules;

/**
 * Stores allow and block rules.
 */
final class RuleRepository {

	/**
	 * Rules loaded on this request.
	 *
	 * @var array<int, array{id: int, kind: string, value: string, action: string}>|null
	 */
	private $cache = null;

	/**
	 * Table name with this site's prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'laas_rules';
	}

	/**
	 * All rules, for matching.
	 *
	 * @return array<int, array{id: int, kind: string, value: string, action: string}>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			global $wpdb;
			$table       = self::table();
			$rows        = $wpdb->get_results( "SELECT id, kind, value, action FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table, no input; cached for the request.
			$this->cache = array();
			foreach ( (array) $rows as $row ) {
				$this->cache[] = array(
					'id'     => (int) $row['id'],
					'kind'   => (string) $row['kind'],
					'value'  => (string) $row['value'],
					'action' => (string) $row['action'],
				);
			}
		}
		return $this->cache;
	}

	/**
	 * All rules with their counters, for the Rules screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listing(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY action ASC, created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table, no input.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Add a rule. An existing rule for the same kind and value is replaced (its action changes).
	 *
	 * @param string $kind    email, domain, ip or phrase.
	 * @param string $value   Raw value.
	 * @param string $action  allow or block.
	 * @param int    $user_id Who added it.
	 * @return string|null The stored value, or null if the value isn't valid for its kind.
	 */
	public function add( string $kind, string $value, string $action, int $user_id ): ?string {
		if ( ! in_array( $kind, Matcher::KINDS, true ) || ! in_array( $action, Matcher::ACTIONS, true ) ) {
			return null;
		}
		$clean = Matcher::normalise( $kind, $value );
		if ( null === $clean ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
			$wpdb->prepare(
				"INSERT INTO {$table} (kind, value, action, created_at, created_by, hits) VALUES (%s, %s, %s, %s, %d, 0) ON DUPLICATE KEY UPDATE action = VALUES(action)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
				$kind,
				$clean,
				$action,
				gmdate( 'Y-m-d H:i:s' ),
				$user_id
			)
		);
		$this->cache = null;
		return $clean;
	}

	/**
	 * Remove a rule.
	 *
	 * @param int $id Rule ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$this->cache = null;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
	}

	/**
	 * Count a match.
	 *
	 * @param int $id Rule ID.
	 * @return void
	 */
	public function record_hit( int $id ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
	}
}
