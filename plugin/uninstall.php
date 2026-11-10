<?php
/**
 * Uninstall: remove every table, option, cron job and comment meta the plugin created (C5, T2).
 *
 * @package LocalAIAntiSpam
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Quarantine/Installer.php';

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $laas_site_id ) {
		switch_to_blog( (int) $laas_site_id );
		LocalAIAntiSpam\Quarantine\Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	LocalAIAntiSpam\Quarantine\Installer::uninstall_site();
}
