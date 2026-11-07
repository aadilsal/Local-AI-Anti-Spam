<?php
/**
 * Plugin Name:       Local AI Anti-Spam
 * Plugin URI:        https://github.com/aadilsal/Local-AI-Anti-Spam
 * Description:       Spam filtering for contact forms and comments, using a machine-learning model that runs on your own server. No CAPTCHA, no API key, no check limits.
 * Version:           0.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Aadil Salman Butt
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       local-ai-anti-spam
 * Domain Path:       /languages
 *
 * @package LocalAIAntiSpam
 */

defined( 'ABSPATH' ) || exit;

define( 'LAAS_VERSION', '0.0.1' );
define( 'LAAS_FILE', __FILE__ );
define( 'LAAS_DIR', plugin_dir_path( __FILE__ ) );

// The plugin has no runtime Composer dependencies, so it ships its own PSR-4 autoloader.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'LocalAIAntiSpam\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$path = LAAS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

require LAAS_DIR . 'src/functions.php';

LocalAIAntiSpam\Plugin::boot();
