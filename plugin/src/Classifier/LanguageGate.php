<?php
/**
 * Is this message English enough for the model? (architecture.md §3.3)
 *
 * No intl, no mbstring, no library. It errs towards "English", because a message that fails the
 * gate is delivered unchecked, never quarantined.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Classifier;

/**
 * Two cheap tests: mostly ASCII letters, and some common English words.
 */
final class LanguageGate {

	const MIN_ASCII_LETTER_SHARE = 0.7;
	const MIN_STOPWORD_HITS      = 2;
	const FEW_WORDS              = 8; // Below this, skip the stop-word test.

	const STOPWORDS = 'a about above after again against all am an and any are as at be because been before being below between both but by can could did do does doing down during each few for from further had has have having he her here hers herself him himself his how i if in into is it its itself just me more most my myself no nor not now of off on once only or other our ours out over own same she should so some such than that the their theirs them then there these they this those through to too under until up very was we were what when where which while who whom why will with would you your yours yourself hello hi please thanks thank regards dear could would like need want get got know let make see send call email phone today tomorrow week';

	/**
	 * Whether the text should be scored by the English model.
	 *
	 * @param string $text Message text.
	 * @return bool
	 */
	public static function is_english( string $text ): bool {
		$ascii     = preg_match_all( '/[A-Za-z]/', $text );
		$non_ascii = preg_match_all( '/[\xC2-\xF4][\x80-\xBF]+/', $text ); // Multibyte UTF-8 characters.
		if ( 0 === $ascii + $non_ascii ) {
			return true; // Nothing to judge; let the model see it.
		}
		if ( $ascii / ( $ascii + $non_ascii ) < self::MIN_ASCII_LETTER_SHARE ) {
			return false;
		}

		$lower = strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
		$words = preg_split( "/[^a-z']+/", $lower, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) < self::FEW_WORDS ) {
			return true;
		}
		$stopwords = array_flip( explode( ' ', self::STOPWORDS ) );
		$hits      = 0;
		foreach ( $words as $word ) {
			if ( isset( $stopwords[ $word ] ) && ++$hits >= self::MIN_STOPWORD_HITS ) {
				return true;
			}
		}
		return false;
	}
}
