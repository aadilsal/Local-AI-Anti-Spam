<?php
/**
 * Wiring: builds the pipeline, quarantine and integrations, and registers hooks (architecture.md §2).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Inference\LogisticModel;
use LocalAIAntiSpam\Inference\ModelFile;
use LocalAIAntiSpam\Integrations\Comments;
use LocalAIAntiSpam\Integrations\ContactForm7;
use LocalAIAntiSpam\Integrations\Integration;
use LocalAIAntiSpam\Integrations\Releasable;
use LocalAIAntiSpam\Quarantine\Installer;
use LocalAIAntiSpam\Quarantine\Releaser;
use LocalAIAntiSpam\Quarantine\Repository;

/**
 * The plugin's single entry point.
 */
final class Plugin {

	const SETTINGS_OPTION        = 'laas_settings';
	const VERIFIED_OPTION        = 'laas_model_verified'; // Plugin version whose model passed the checksum.
	const OFFSETS                = array(
		'relaxed'  => 0.04,
		'balanced' => 0.0,
		'strict'   => -0.04,
	);
	const DEFAULT_STRENGTH       = 'balanced';
	const DEFAULT_RETENTION_DAYS = 30;

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
	 * The quarantine.
	 *
	 * @var Repository|null
	 */
	private $repository = null;

	/**
	 * Integrations registered on this request, by ID.
	 *
	 * @var array<string, Integration>
	 */
	private $integrations = array();

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
		$plugin = self::instance();
		add_action( 'plugins_loaded', array( $plugin, 'register_integrations' ) );
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( Installer::RETENTION_HOOK, array( $plugin, 'run_retention' ) );
	}

	/**
	 * Register every integration whose form plugin is active.
	 *
	 * @return void
	 */
	public function register_integrations(): void {
		$candidates = array(
			new Comments( $this->pipeline() ),
			new ContactForm7( $this->pipeline(), $this->repository() ),
		);
		foreach ( $candidates as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
				$this->integrations[ $integration->id() ] = $integration;
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
	 * The quarantine.
	 *
	 * @return Repository
	 */
	public function repository(): Repository {
		if ( null === $this->repository ) {
			$this->repository = new Repository();
		}
		return $this->repository;
	}

	/**
	 * Releases held messages through the integration that held them.
	 *
	 * @return Releaser
	 */
	public function releaser(): Releaser {
		$releasable = array_filter(
			$this->integrations,
			static function ( $integration ) {
				return $integration instanceof Releasable;
			}
		);
		return new Releaser( $this->repository(), $releasable );
	}

	/**
	 * Daily cron: delete rows past the retention period (F6, C5).
	 *
	 * @return void
	 */
	public function run_retention(): void {
		$settings = (array) get_option( self::SETTINGS_OPTION, array() );
		$days     = isset( $settings['retention_days'] ) ? max( 1, (int) $settings['retention_days'] ) : self::DEFAULT_RETENTION_DAYS;
		$this->repository()->purge_older_than( $days );
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
