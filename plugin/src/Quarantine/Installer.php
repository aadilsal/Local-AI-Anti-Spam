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

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'laas_db_version';
	const RETENTION_HOOK    = 'laas_retention';
	const OPTIONS           = array( 'laas_settings', 'laas_db_version', 'laas_log', 'laas_model_verified' );

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
		wp_clear_scheduled_hook( self::RETENTION_HOOK );
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
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
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
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/**
	 * Remove everything the plugin created on this site (called from uninstall.php).
	 *
	 * @return void
	 */
	public static function uninstall_site(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( self::RETENTION_HOOK );
		delete_metadata( 'comment', 0, 'laas_verdict', '', true );
		delete_metadata( 'comment', 0, 'laas_score', '', true );
	}
}
