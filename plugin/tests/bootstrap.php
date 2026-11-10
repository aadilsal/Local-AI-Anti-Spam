<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress loaded).
 *
 * @package LocalAIAntiSpam
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// A minimal stand-in for WordPress filters, so the pipeline's hooks can be tested without WordPress.
// Tests add callbacks to $GLOBALS['laas_test_filters'][ $hook ] and must clear them afterwards.
$GLOBALS['laas_test_filters'] = array();

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Run the test callbacks for an action.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	function do_action( $hook, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stand-in.
		foreach ( $GLOBALS['laas_test_filters'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Run the test callbacks for a hook.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stand-in.
		foreach ( $GLOBALS['laas_test_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
