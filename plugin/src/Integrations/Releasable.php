<?php
/**
 * Integrations whose held messages can be delivered after all (F8).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

/**
 * Re-delivers a quarantined message the way the form plugin would have.
 */
interface Releasable {

	/**
	 * Deliver a held row.
	 *
	 * @param array<string, mixed> $item Quarantine row from Repository::find().
	 * @return bool Whether delivery succeeded.
	 */
	public function release( array $item ): bool;
}
