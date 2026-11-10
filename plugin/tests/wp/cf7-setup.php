<?php
/**
 * Prepares the wp-env site for cf7-check.php: a CF7 form with CF7's default fields and mail, the
 * mail-capture mu-plugin, and an empty quarantine. Prints the form ID.
 *
 * Usage (from plugin/): node_modules\.bin\wp-env run cli wp eval-file wp-content/plugins/local-ai-anti-spam/tests/wp/cf7-setup.php
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery -- CLI test script.

wp_mkdir_p( WPMU_PLUGIN_DIR );
copy( __DIR__ . '/mail-capture.php', WPMU_PLUGIN_DIR . '/laas-mail-capture.php' );
delete_option( 'laas_test_mail' );

global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . LocalAIAntiSpam\Quarantine\Installer::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.

$laas_form = WPCF7_ContactForm::get_template( array( 'title' => 'LAAS smoke test' ) );
$laas_form->save();
echo $laas_form->id() . "\n";
