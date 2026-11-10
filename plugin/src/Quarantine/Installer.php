<?php
/**
 * Creates, upgrades and removes the plugin's tables, options and cron jobs (architecture.md §6, §7).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Quarantine;

/**
 * Database schema and lifecycle.
 */
final class Installer {

	const DB_VERSION        = 3; // 2: laas_rules. 3: laas_stats_daily.
	const DIGEST_HOOK       = 'laas_digest';
	const DB_VERSION_OPTION = 'laas_db_version';
	const RETENTION_HOOK    = 'laas_retention';
	const NOTICE_OPTION     = 'laas_activation_notice';
	const OPTIONS           = array( 'laas_settings', 'laas_db_version', 'laas_log', 'laas_model_verified', 'laas_activation_notice', 'laas_digest_last' );
	const CRON_HOOKS        = array( 'laas_retention', 'laas_digest' );

	/**
	 * Quarantine table name, with this site's prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'laas_quarantine';
	}

	/**
	 * Activation: install on this site, or on every site when network-activated.
	 *
	 * @param bool $network_wide Network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		update_option( self::NOTICE_OPTION, 1, false ); // Shown once (design.md §3.1).
		if ( $network_wide && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Deactivation: stop the cron job. Data stays until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Install or upgrade when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION ) < self::DB_VERSION ) {
			self::install();
		}
		self::schedule();
	}

	/**
	 * Daily retention and digest jobs (architecture.md §7).
	 *
	 * @return void
	 */
	private static function schedule(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
			}
		}
	}

	/**
	 * Create or update the table with dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				source varchar(32) NOT NULL,
				form_id varchar(64) NOT NULL DEFAULT '',
				folder varchar(16) NOT NULL DEFAULT 'spam',
				status varchar(16) NOT NULL DEFAULT 'held',
				verdict varchar(16) NOT NULL,
				score decimal(5,4) NOT NULL DEFAULT 0,
				reason varchar(32) NOT NULL DEFAULT '',
				top_features text NOT NULL,
				model_version varchar(48) NOT NULL DEFAULT '',
				sender_email varchar(191) NOT NULL DEFAULT '',
				sender_name varchar(191) NOT NULL DEFAULT '',
				sender_ip varchar(45) DEFAULT NULL,
				subject_line varchar(255) NOT NULL DEFAULT '',
				body mediumtext NOT NULL,
				payload mediumtext NOT NULL,
				msg_hash char(64) NOT NULL,
				released_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY verdict_created (verdict,created_at),
				KEY created_at (created_at),
				KEY sender_email (sender_email),
				KEY msg_hash (msg_hash)
			) {$charset};"
		);
		$rules = \LocalAIAntiSpam\Rules\RuleRepository::table();
		dbDelta(
			"CREATE TABLE {$rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(16) NOT NULL,
				value varchar(191) NOT NULL,
				action varchar(8) NOT NULL,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				last_hit_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_value (kind,value)
			) {$charset};"
		);
		$stats = \LocalAIAntiSpam\Stats\Stats::table();
		dbDelta(
			"CREATE TABLE {$stats} (
				day date NOT NULL,
				source varchar(32) NOT NULL,
				checked int(10) unsigned NOT NULL DEFAULT 0,
				clean int(10) unsigned NOT NULL DEFAULT 0,
				borderline int(10) unsigned NOT NULL DEFAULT 0,
				spam int(10) unsigned NOT NULL DEFAULT 0,
				released int(10) unsigned NOT NULL DEFAULT 0,
				unchecked int(10) unsigned NOT NULL DEFAULT 0,
				errors int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,source)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::schedule();
	}

	/**
	 * Remove everything the plugin created on this site (called from uninstall.php).
	 *
	 * @return void
	 */
	public static function uninstall_site(): void {
		global $wpdb;
		foreach ( array( self::table(), $wpdb->prefix . 'laas_rules', $wpdb->prefix . 'laas_stats_daily' ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own tables; the names are not user input.
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		self::deactivate();
		delete_metadata( 'comment', 0, 'laas_verdict', '', true );
		delete_metadata( 'comment', 0, 'laas_score', '', true );
		delete_metadata( 'user', 0, 'laas_quarantine_seen', '', true );
	}
}
