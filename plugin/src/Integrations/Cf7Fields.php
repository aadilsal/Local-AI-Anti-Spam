<?php
/**
 * Contact Form 7 field mapping and mail rebuilding, without WordPress or CF7 calls, so it can be
 * unit-tested (architecture.md §5, review note R6).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

/**
 * Pure helpers for the CF7 adapter.
 */
final class Cf7Fields {

	/** Field names that may hold secrets: never classified, never stored. */
	const SENSITIVE_NAME = '/pass|card|cvv|cvc|iban|ssn|token|nonce|secret|captcha/i';

	/** Form-tag types that are not the sender's own words. */
	const SKIP_TYPES = array( 'hidden', 'quiz', 'captchac', 'captchar', 'acceptance', 'submit', 'turnstile', 'recaptcha', 'stripe' );

	/** Text fields whose name says they hold the message itself. */
	const MESSAGE_NAME = '/message|comment|enquiry|inquiry|question|subject|details|description/i';

	const NAME_FIELD = '/name/i';

	/**
	 * Split posted data into what the classifier needs and what may be stored.
	 *
	 * @param array<string, string> $types  Field name => CF7 base type (text, email, textarea, ...).
	 * @param array<string, mixed>  $posted CF7 posted data.
	 * @return array{text: string, email: ?string, name: ?string, stored: array<string, mixed>}
	 */
	public static function map( array $types, array $posted ): array {
		$text    = array();
		$extra   = array();
		$email   = null;
		$name    = null;
		$stored  = array();
		$has_msg = false;

		foreach ( $posted as $field => $value ) {
			$field = (string) $field;
			$type  = $types[ $field ] ?? 'text';
			if ( in_array( $type, self::SKIP_TYPES, true ) || preg_match( self::SENSITIVE_NAME, $field ) ) {
				continue;
			}
			$stored[ $field ] = $value;
			$flat             = self::flatten( $value );
			if ( '' === $flat || 'file' === $type ) {
				continue;
			}
			if ( 'email' === $type && null === $email ) {
				$email = $flat;
			} elseif ( 'textarea' === $type || ( 'text' === $type && preg_match( self::MESSAGE_NAME, $field ) ) ) {
				$text[]  = $flat;
				$has_msg = true;
			} elseif ( 'text' === $type && null === $name && preg_match( self::NAME_FIELD, $field ) ) {
				$name = $flat;
			} elseif ( in_array( $type, array( 'text', 'url' ), true ) ) {
				$extra[] = $flat;
			}
		}
		if ( ! $has_msg ) {
			$text = $extra; // A form without a message box: use its other free-text fields.
		}

		return array(
			'text'   => implode( "\n", $text ),
			'email'  => $email,
			'name'   => $name,
			'stored' => $stored,
		);
	}

	/**
	 * A posted value as one string (checkboxes and multi-selects are arrays).
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function flatten( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ) {
					$parts[] = (string) $item;
				}
			);
			return trim( implode( ', ', $parts ) );
		}
		return trim( (string) $value );
	}

	/**
	 * Rebuild one CF7 mail from its template and the stored submission, as CF7 would have sent it.
	 *
	 * Field tags like [your-message] take the stored values. Special tags take stored meta or
	 * site values; unknown ones become empty. Uploaded files are gone (CF7 deletes them after each
	 * submission), so file fields show their names and attachments are not resent.
	 *
	 * @param array<string, mixed>  $template CF7 mail property: recipient, sender, subject, body, additional_headers, use_html, exclude_blank.
	 * @param array<string, mixed>  $stored   Stored field values.
	 * @param array<string, string> $special  Special tag => value, e.g. '_site_title' => 'Example'.
	 * @return array{recipient: string, subject: string, body: string, headers: string[]}
	 */
	public static function compose( array $template, array $stored, array $special ): array {
		$html     = ! empty( $template['use_html'] );
		$replace  = static function ( string $content, bool $as_html ) use ( $stored, $special ): string {
			return (string) preg_replace_callback(
				'/\[(_?[a-zA-Z][0-9a-zA-Z:._-]*)\]/',
				static function ( array $m ) use ( $stored, $special, $as_html ): string {
					$tag = $m[1];
					if ( array_key_exists( $tag, $stored ) ) {
						$value = self::flatten( $stored[ $tag ] );
					} elseif ( '_' === $tag[0] ) {
						$value = $special[ $tag ] ?? '';
					} else {
						return $m[0]; // Not a field of this form: leave it, as CF7 does.
					}
					return $as_html ? nl2br( htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) ) : $value;
				},
				$content
			);
		};
		$one_line = static function ( string $value ): string {
			return trim( str_replace( array( "\r", "\n" ), ' ', $value ) );
		};

		$body = (string) ( $template['body'] ?? '' );
		if ( ! empty( $template['exclude_blank'] ) ) {
			// Like CF7: drop a line when every field tag on it is empty.
			$kept = array();
			foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
				preg_match_all( '/\[([a-zA-Z][0-9a-zA-Z:._-]*)\]/', $line, $tags );
				$fields = array_intersect( $tags[1], array_keys( $stored ) );
				$blank  = array() !== $fields;
				foreach ( $fields as $field ) {
					$blank = $blank && '' === self::flatten( $stored[ $field ] );
				}
				if ( ! $blank ) {
					$kept[] = $line;
				}
			}
			$body = implode( "\n", $kept );
		}
		$body = $replace( $body, $html );

		$headers = array( 'From: ' . $one_line( $replace( (string) ( $template['sender'] ?? '' ), false ) ) );
		if ( $html ) {
			$headers[] = 'Content-Type: text/html';
		}
		$additional = str_replace( "\r\n", "\n", $replace( (string) ( $template['additional_headers'] ?? '' ), false ) );
		foreach ( explode( "\n", $additional ) as $header ) {
			if ( '' !== trim( $header ) ) {
				$headers[] = trim( $header );
			}
		}

		return array(
			'recipient' => $one_line( $replace( (string) ( $template['recipient'] ?? '' ), false ) ),
			'subject'   => $one_line( $replace( (string) ( $template['subject'] ?? '' ), false ) ),
			'body'      => $body,
			'headers'   => $headers,
		);
	}
}
