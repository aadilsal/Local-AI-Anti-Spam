<?php
/**
 * Wiring: builds the pipeline and registers the integrations (architecture.md §2).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;
use LocalAIAntiSpam\Integrations\Comments;
use LocalAIAntiSpam\Integrations\Integration;

/**
 * The plugin's single entry point.
 */
final class Plugin {

	const SETTINGS_OPTION  = 'laas_settings';
	const VERIFIED_OPTION  = 'laas_model_verified'; // Plugin version whose model passed the checksum.
	const OFFSETS          = array(
		'relaxed'  => 0.04,
		'balanced' => 0.0,
		'strict'   => -0.04,
	);
	const DEFAULT_STRENGTH = 'balanced';

	/**
	 * The instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The pipeline, built on first use.
	 *
	 * @var Pipeline|null
	 */
	private $pipeline = null;

	/**
	 * The instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook in. Called once from the main plugin file.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::instance(), 'register_integrations' ) );
	}

	/**
	 * Register every integration whose form plugin is active.
	 *
	 * @return void
	 */
	public function register_integrations(): void {
		$integrations = array( new Comments( $this->pipeline() ) );
		foreach ( $integrations as $integration ) {
			if ( $integration instanceof Integration && $integration->is_available() ) {
				$integration->register();
			}
		}
	}

	/**
	 * The pipeline. Building it is cheap: the model loads on the first classification only.
	 *
	 * @return Pipeline
	 */
	public function pipeline(): Pipeline {
		if ( null === $this->pipeline ) {
			$this->pipeline = new Pipeline( array( $this, 'load_model' ), self::t_high_offset() );
		}
		return $this->pipeline;
	}

	/**
	 * Load model.bin. Checks the checksum once per plugin version (architecture.md §4.3).
	 *
	 * @return LogisticModel
	 */
	public function load_model(): LogisticModel {
		$verified = get_option( self::VERIFIED_OPTION ) === LAAS_VERSION;
		$file     = ModelFile::load( LAAS_DIR . 'model/model.bin', ! $verified );
		if ( ! $verified ) {
			update_option( self::VERIFIED_OPTION, LAAS_VERSION, false );
		}
		return new LogisticModel( $file );
	}

	/**
	 * Offset from the model's t_high for the chosen strength (architecture.md §3.4, A7).
	 *
	 * @return float
	 */
	public static function t_high_offset(): float {
		$settings = (array) get_option( self::SETTINGS_OPTION, array() );
		$strength = isset( $settings['strength'] ) ? (string) $settings['strength'] : self::DEFAULT_STRENGTH;
		return self::OFFSETS[ $strength ] ?? 0.0;
	}
}
