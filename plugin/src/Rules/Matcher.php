<?php
/**
 * Allow and block rules (F9, architecture.md §6.2): pure matching and value normalising, so both
 * can be unit-tested without WordPress.
 *
 * Kinds: email (exact sender address), domain (sender's email domain, or any of its subdomains),
 * ip (exact) and phrase (plain case-insensitive substring of the message; never a regex, so a user
 * can't enter a pathological pattern). Allow rules win over block rules.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Rules;

use LocalAIAntiSpam\Classifier\Submission;

/**
 * Rule matching.
 */
final class Matcher {

	const KINDS   = array( 'email', 'domain', 'ip', 'phrase' );
	const ACTIONS = array( 'allow', 'block' );

	const MIN_PHRASE = 3;
	const MAX_PHRASE = 200;

	/**
	 * The first matching rule, allow rules first.
	 *
	 * @param array<int, array{id: int, kind: string, value: string, action: string}> $rules      Rules.
	 * @param Submission                                                              $submission The submission.
	 * @return array{id: int, kind: string, value: string, action: string}|null
	 */
	public static function match( array $rules, Submission $submission ): ?array {
		$email  = null !== $submission->email ? self::ascii_lower( $submission->email ) : '';
		$domain = false !== strpos( $email, '@' ) ? substr( $email, strrpos( $email, '@' ) + 1 ) : '';
		$text   = self::ascii_lower( $submission->text );

		foreach ( self::ACTIONS as $action ) {
			foreach ( $rules as $rule ) {
				if ( $action !== $rule['action'] ) {
					continue;
				}
				$value = (string) $rule['value'];
				switch ( $rule['kind'] ) {
					case 'email':
						$hit = '' !== $email && $email === $value;
						break;
					case 'domain':
						$hit = '' !== $domain && ( $domain === $value || self::ends_with( $domain, '.' . $value ) );
						break;
					case 'ip':
						$hit = null !== $submission->ip && $submission->ip === $value;
						break;
					case 'phrase':
						$hit = '' !== $value && false !== strpos( $text, $value );
						break;
					default:
						$hit = false;
				}
				if ( $hit ) {
					return $rule;
				}
			}
		}
		return null;
	}

	/**
	 * Clean a value for storage, or return null if it isn't valid for its kind.
	 *
	 * @param string $kind  Rule kind.
	 * @param string $value Raw value.
	 * @return string|null
	 */
	public static function normalise( string $kind, string $value ): ?string {
		$value = trim( $value );
		switch ( $kind ) {
			case 'email':
				$value = self::ascii_lower( $value );
				return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z0-9-]{2,}$/', $value ) ? $value : null;
			case 'domain':
				$value = self::ascii_lower( $value );
				$value = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $value ); // Scheme.
				$value = (string) preg_replace( '#[/?\#:].*$#', '', $value );           // Path, query, port.
				$value = (string) preg_replace( '/^(\*\.|www\.|@)/', '', $value );
				return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $value ) ? $value : null;
			case 'ip':
				return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
			case 'phrase':
				$value = self::ascii_lower( (string) preg_replace( '/\s+/', ' ', $value ) );
				return strlen( $value ) >= self::MIN_PHRASE && strlen( $value ) <= self::MAX_PHRASE ? $value : null;
		}
		return null;
	}

	/**
	 * Locale-independent lowercase (hard rule 10).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ascii_lower( string $text ): string {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * PHP 7.4 has no str_ends_with.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Ending.
	 * @return bool
	 */
	private static function ends_with( string $haystack, string $needle ): bool {
		return '' !== $needle && substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
