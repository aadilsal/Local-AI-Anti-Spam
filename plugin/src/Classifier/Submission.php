<?php
/**
 * One message to classify, as an integration adapter built it (architecture.md §3.2).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Classifier;

/**
 * A form or comment submission, reduced to what the pipeline needs.
 */
final class Submission {

	const MAX_TEXT_BYTES = 8000;

	/**
	 * Where it came from: 'comment', 'cf7', 'api', ...
	 *
	 * @var string
	 */
	public $source;

	/**
	 * Form ID, or the post ID for a comment.
	 *
	 * @var string
	 */
	public $form_id;

	/**
	 * Free-text fields joined together, capped at MAX_TEXT_BYTES.
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Sender email, if the form has one.
	 *
	 * @var string|null
	 */
	public $email;

	/**
	 * Sender name, if the form has one.
	 *
	 * @var string|null
	 */
	public $name;

	/**
	 * Sender IP, for rules only.
	 *
	 * @var string|null
	 */
	public $ip;

	/**
	 * Data the integration needs to deliver the message later.
	 *
	 * @var array<string, mixed>
	 */
	public $payload;

	/**
	 * Constructor.
	 *
	 * @param string               $source  Integration ID.
	 * @param string               $text    Message text.
	 * @param string|null          $email   Sender email.
	 * @param string|null          $name    Sender name.
	 * @param string               $form_id Form or post ID.
	 * @param string|null          $ip      Sender IP.
	 * @param array<string, mixed> $payload Release data.
	 */
	public function __construct( string $source, string $text, ?string $email = null, ?string $name = null, string $form_id = '', ?string $ip = null, array $payload = array() ) {
		$this->source  = $source;
		$this->text    = self::cap( $text );
		$this->email   = ( null === $email || '' === trim( $email ) ) ? null : trim( $email );
		$this->name    = $name;
		$this->form_id = $form_id;
		$this->ip      = $ip;
		$this->payload = $payload;
	}

	/**
	 * Cut text to MAX_TEXT_BYTES without splitting a UTF-8 character.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cap( string $text ): string {
		if ( strlen( $text ) <= self::MAX_TEXT_BYTES ) {
			return $text;
		}
		$cut = self::MAX_TEXT_BYTES;
		while ( $cut > 0 && ( ord( $text[ $cut ] ) & 0xC0 ) === 0x80 ) {
			--$cut; // Step back off continuation bytes.
		}
		return substr( $text, 0, $cut );
	}
}
