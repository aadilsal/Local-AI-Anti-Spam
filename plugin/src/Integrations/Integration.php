<?php
/**
 * One adapter per form plugin (architecture.md §5).
 *
 * The release() method and the "Mark as spam" action join this interface with the quarantine (F8).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

/**
 * Hooks a form plugin's spam check up to the pipeline.
 */
interface Integration {

	/**
	 * Short ID, e.g. 'comment' or 'cf7'.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether the form plugin is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Add the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
