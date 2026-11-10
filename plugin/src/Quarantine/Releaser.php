<?php
/**
 * Delivers a held message through the integration that held it (F8).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Quarantine;

use LocalAIAntiSpam\Integrations\Releasable;
use LocalAIAntiSpam\Support\Log;

/**
 * Release = deliver, then mark the row released. A failed delivery leaves the row held.
 */
final class Releaser {

	/**
	 * The quarantine.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Integration ID => integration that can release.
	 *
	 * @var array<string, Releasable>
	 */
	private $integrations;

	/**
	 * Constructor.
	 *
	 * @param Repository                $repository   The quarantine.
	 * @param array<string, Releasable> $integrations By ID.
	 */
	public function __construct( Repository $repository, array $integrations ) {
		$this->repository   = $repository;
		$this->integrations = $integrations;
	}

	/**
	 * Release one held row. The caller checks the nonce and capability.
	 *
	 * @param int $id      Row ID.
	 * @param int $user_id Who released it.
	 * @return bool Whether it was delivered.
	 */
	public function release( int $id, int $user_id ): bool {
		$item = $this->repository->find( $id );
		if ( null === $item || Repository::HELD !== $item['status'] ) {
			return false;
		}
		$integration = $this->integrations[ (string) $item['source'] ] ?? null;
		if ( null === $integration ) {
			return false;
		}
		try {
			$delivered = $integration->release( $item );
		} catch ( \Throwable $error ) {
			Log::error( 'release', $error );
			return false;
		}
		if ( $delivered ) {
			$this->repository->mark_released( $id );
			do_action( 'laas_released', $id, $user_id, (string) $item['source'] );
		}
		return $delivered;
	}
}
