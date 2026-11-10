<?php
/**
 * The `laas_settings` option: defaults, reading and sanitising (design.md §3.7, architecture §6.4).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Support;

/**
 * Plugin settings.
 */
final class Settings {

	const OPTION = 'laas_settings';

	const STRENGTHS = array( 'relaxed', 'balanced', 'strict' );

	const DEFAULTS = array(
		'strength'       => 'balanced',
		'retention_days' => 30,
		'store_ip'       => true,
		'subject_tag'    => '[Possible spam]',
		'protect'        => array(),
	);

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether an integration is switched on. Every detected integration is on unless turned off.
	 *
	 * @param string $id Integration ID.
	 * @return bool
	 */
	public static function protects( string $id ): bool {
		$protect = (array) self::get( 'protect' );
		return ! isset( $protect[ $id ] ) || (bool) $protect[ $id ];
	}

	/**
	 * Sanitise a submitted settings form (register_setting callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::all();

		if ( isset( $input['strength'] ) && in_array( $input['strength'], self::STRENGTHS, true ) ) {
			$clean['strength'] = $input['strength'];
		}
		if ( isset( $input['retention_days'] ) ) {
			$clean['retention_days'] = min( 365, max( 1, (int) $input['retention_days'] ) );
		}
		$clean['store_ip']    = ! empty( $input['store_ip'] );
		$clean['subject_tag'] = isset( $input['subject_tag'] ) ? substr( sanitize_text_field( (string) $input['subject_tag'] ), 0, 40 ) : $clean['subject_tag'];

		$protect = array();
		if ( isset( $input['protect_known'] ) ) {
			// From the form: unticked boxes are absent, so every known integration is listed.
			foreach ( (array) $input['protect_known'] as $id ) {
				$id             = sanitize_key( (string) $id );
				$protect[ $id ] = ! empty( $input['protect'][ $id ] );
			}
		} else {
			// Already clean (WordPress sanitises a new option twice): keep it.
			foreach ( (array) ( $input['protect'] ?? array() ) as $id => $on ) {
				$protect[ sanitize_key( (string) $id ) ] = (bool) $on;
			}
		}
		$clean['protect'] = $protect;
		unset( $clean['protect_known'] );
		return $clean;
	}
}
