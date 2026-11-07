<?php
/**
 * Error log: a capped ring buffer in the non-autoloaded `laas_log` option (architecture.md §6.4).
 *
 * Entries hold the error class and where it was thrown, never message content or the exception
 * message (which could quote submitted text, e.g. from a third-party filter).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Support;

/**
 * Records fail-open events for Settings, Diagnostics.
 */
final class Log {

	const OPTION      = 'laas_log';
	const MAX_ENTRIES = 200;

	/**
	 * Record an error.
	 *
	 * @param string     $where Short code for the caller, e.g. 'classify'.
	 * @param \Throwable $error The error.
	 * @return void
	 */
	public static function error( string $where, \Throwable $error ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return; // No WordPress (unit tests, CLI).
		}
		try {
			$entries   = (array) get_option( self::OPTION, array() );
			$entries[] = array(
				'time'  => time(),
				'where' => $where,
				'error' => get_class( $error ),
				'at'    => basename( $error->getFile() ) . ':' . $error->getLine(),
			);
			update_option( self::OPTION, array_slice( $entries, -self::MAX_ENTRIES ), false );
		} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Logging must never break a submission.
		}
	}

	/**
	 * Logged entries, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries(): array {
		return function_exists( 'get_option' ) ? (array) get_option( self::OPTION, array() ) : array();
	}
}
