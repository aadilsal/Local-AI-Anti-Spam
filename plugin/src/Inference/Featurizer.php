<?php
/**
 * Featurizer: the PHP twin of training/laas/featurize.py (architecture.md §4.1).
 *
 * Every rule mirrors the Python one byte for byte; the parity fixtures prove it. Regexes use
 * ASCII-only classes and no `u` flag so PCRE matches the same spans as Python's `re`.
 * This class must not call WordPress functions (agents.md §5).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Inference;

/**
 * Turns a message into hashed sparse features plus dense features.
 */
final class Featurizer {

	const VERSION   = 1;
	const MAX_BYTES = 8000;
	// Char n-grams cost ~3 per byte; capping their input keeps an 8 KB message inside the 20 ms budget.
	const CHAR_MAX_BYTES = 3000;
	const DENSE_NAMES    = array(
		'link_count',
		'distinct_link_domains',
		'caps_ratio',
		'log_length',
		'email_domain_matches_link',
		'has_email',
		'short_message',
	);

	// \x0b, not \v: in PCRE "\v" means any vertical whitespace (including byte 0x85).
	const URL_RE    = '~(?:https?://|www\.)[^ \t\n\r\f\x0b<>"\']+~';
	const EMAIL_RE  = '~[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+~';
	const PHONE_RE  = '~\+?[0-9][0-9 ().-]{6,}[0-9]~';
	const NUM_RE    = '~[0-9]+~';
	const WS_RE     = '~[ \t\n\r\f\x0b]+~';
	const TOKEN_RE  = '~[^a-z0-9_\']+~';
	const DOMAIN_RE = '~[^a-z0-9]+~';

	const SECOND_LEVEL = array(
		'co.uk'  => true,
		'org.uk' => true,
		'ac.uk'  => true,
		'gov.uk' => true,
		'com.au' => true,
		'net.au' => true,
		'org.au' => true,
		'co.nz'  => true,
		'co.in'  => true,
		'com.pk' => true,
		'co.za'  => true,
		'com.br' => true,
		'co.jp'  => true,
	);

	/**
	 * Zero-width and format characters removed, and Cyrillic/Greek look-alikes folded to Latin.
	 *
	 * @var array<string, string>|null
	 */
	private static $translate = null;

	/**
	 * Featurize one message.
	 *
	 * @param string      $text      Message text (UTF-8).
	 * @param string|null $email     Sender email, if the form has one.
	 * @param int         $n_buckets Number of hash buckets (a power of two).
	 * @return array{hashed: array<int, float>, dense: float[], names: array<int, string>}
	 */
	public static function featurize( string $text, ?string $email, int $n_buckets ): array {
		list( $norm, $caps_ratio, $domains ) = self::normalise( $text );

		$tokens = array();
		foreach ( preg_split( self::TOKEN_RE, $norm ) as $token ) {
			$token = trim( $token, "'" );
			if ( '' !== $token ) {
				$tokens[] = $token;
			}
		}

		$words = array();
		$names = array();
		foreach ( $tokens as $token ) {
			$key           = 'w:' . $token;
			$words[ $key ] = ( isset( $words[ $key ] ) ? $words[ $key ] : 0 ) + 1;
			$bucket        = self::hash_feature( $key, $n_buckets )[0];
			if ( ! isset( $names[ $bucket ] ) ) {
				$names[ $bucket ] = $token;
			}
		}
		$count = count( $tokens );
		for ( $i = 0; $i + 1 < $count; $i++ ) {
			$key           = 'b:' . $tokens[ $i ] . ' ' . $tokens[ $i + 1 ];
			$words[ $key ] = ( isset( $words[ $key ] ) ? $words[ $key ] : 0 ) + 1;
		}

		$padded = ' ' . substr( $norm, 0, self::CHAR_MAX_BYTES ) . ' ';
		$length = strlen( $padded );
		$chars  = array();
		foreach ( array( 3, 4, 5 ) as $n ) {
			for ( $i = 0; $i + $n <= $length; $i++ ) {
				$key           = 'c:' . substr( $padded, $i, $n );
				$chars[ $key ] = ( isset( $chars[ $key ] ) ? $chars[ $key ] : 0 ) + 1;
			}
		}

		$hashed = array();
		self::add_family( $words, $hashed, $n_buckets );
		self::add_family( $chars, $hashed, $n_buckets );

		$email_domain = '';
		if ( null !== $email && false !== strpos( $email, '@' ) ) {
			$email_domain = self::ascii_lower( (string) substr( $email, strrpos( $email, '@' ) + 1 ) );
		}

		$dense = array(
			min( count( $domains ), 10 ) / 10.0,
			min( count( array_unique( $domains ) ), 5 ) / 5.0,
			$caps_ratio,
			log1p( strlen( $norm ) ) / 10.0,
			( '' !== $email_domain && in_array( self::registrable_domain( $email_domain ), $domains, true ) ) ? 1.0 : 0.0,
			'' !== $email_domain ? 1.0 : 0.0,
			$count < 5 ? 1.0 : 0.0,
		);

		return array(
			'hashed' => $hashed,
			'dense'  => $dense,
			'names'  => $names,
		);
	}

	/**
	 * Normalise text and pull out link domains.
	 *
	 * @param string $text Raw text.
	 * @return array{0: string, 1: float, 2: string[]} Normalised text, caps ratio, link domains.
	 */
	public static function normalise( string $text ): array {
		if ( ! preg_match( '//u', $text ) ) {
			// Invalid UTF-8 never reaches Python; keep only ASCII bytes so the result is predictable.
			$text = (string) preg_replace( '/[\x80-\xFF]/', '', $text );
		}
		$text = strtr( self::truncate_utf8( $text ), self::translate_table() );

		$letters    = preg_match_all( '/[A-Za-z]/', $text );
		$caps       = preg_match_all( '/[A-Z]/', $text );
		$caps_ratio = $letters > 0 ? $caps / $letters : 0.0;

		$text    = self::ascii_lower( $text );
		$domains = array();
		$text    = (string) preg_replace_callback(
			self::URL_RE,
			static function ( array $m ) use ( &$domains ): string {
				$domain    = self::registrable_domain( $m[0] );
				$domains[] = $domain;
				return ' __url__ __dom_' . preg_replace( self::DOMAIN_RE, '_', $domain ) . '__ ';
			},
			$text
		);
		$text    = (string) preg_replace( self::EMAIL_RE, ' __email__ ', $text );
		$text    = (string) preg_replace( self::PHONE_RE, ' __phone__ ', $text );
		$text    = (string) preg_replace( self::NUM_RE, ' __num__ ', $text );
		$text    = trim( (string) preg_replace( self::WS_RE, ' ', $text ), ' ' );

		return array( $text, (float) $caps_ratio, $domains );
	}

	/**
	 * Registrable domain of a URL or host ("www.shop.example.co.uk/x" -> "example.co.uk").
	 *
	 * @param string $url URL or bare host, already lower-cased.
	 * @return string
	 */
	public static function registrable_domain( string $url ): string {
		$host   = (string) preg_replace( '~^(?:https?://)?(?:www\.)?~', '', $url, 1 );
		$host   = rtrim( (string) preg_split( '~[/?#:]~', $host, 2 )[0], '.,;!?)]}' );
		$labels = array_values(
			array_filter(
				explode( '.', $host ),
				static function ( string $label ): bool {
					return '' !== $label;
				}
			)
		);
		$count  = count( $labels );
		if ( $count >= 3 && isset( self::SECOND_LEVEL[ $labels[ $count - 2 ] . '.' . $labels[ $count - 1 ] ] ) ) {
			return implode( '.', array_slice( $labels, -3 ) );
		}
		return implode( '.', array_slice( $labels, -2 ) );
	}

	/**
	 * Bucket and sign for a feature string (IEEE CRC-32, same as Python's zlib.crc32).
	 *
	 * @param string $feature   Feature bytes.
	 * @param int    $n_buckets Number of buckets (power of two).
	 * @return array{0: int, 1: float}
	 */
	public static function hash_feature( string $feature, int $n_buckets ): array {
		$h = crc32( $feature );
		return array( $h & ( $n_buckets - 1 ), ( ( $h >> 31 ) & 1 ) ? -1.0 : 1.0 );
	}

	/**
	 * Cut to MAX_BYTES without leaving a broken UTF-8 sequence at the end.
	 *
	 * @param string $text Valid UTF-8.
	 * @return string
	 */
	private static function truncate_utf8( string $text ): string {
		if ( strlen( $text ) <= self::MAX_BYTES ) {
			return $text;
		}
		$text = substr( $text, 0, self::MAX_BYTES );
		$i    = strlen( $text ) - 1;
		$cont = 0;
		while ( $i >= 0 && 0x80 === ( ord( $text[ $i ] ) & 0xC0 ) ) {
			--$i;
			++$cont;
		}
		if ( $i < 0 ) {
			return '';
		}
		$lead = ord( $text[ $i ] );
		$need = 1;
		if ( $lead >= 0xF0 ) {
			$need = 4;
		} elseif ( $lead >= 0xE0 ) {
			$need = 3;
		} elseif ( $lead >= 0xC0 ) {
			$need = 2;
		}
		return ( $cont + 1 < $need ) ? substr( $text, 0, $i ) : $text;
	}

	/**
	 * Add one feature family (words or chars), log-scaled and L2-normalised, into $out.
	 *
	 * @param array<string, int> $counts    Feature string => count.
	 * @param array<int, float>  $out       Accumulated bucket values.
	 * @param int                $n_buckets Number of buckets.
	 */
	private static function add_family( array $counts, array &$out, int $n_buckets ): void {
		$family = array();
		foreach ( $counts as $feature => $count ) {
			list( $bucket, $sign ) = self::hash_feature( (string) $feature, $n_buckets );
			$family[ $bucket ]     = ( isset( $family[ $bucket ] ) ? $family[ $bucket ] : 0.0 ) + $sign * log1p( $count );
		}
		$sum = 0.0;
		foreach ( $family as $value ) {
			$sum += $value * $value;
		}
		$norm = sqrt( $sum );
		if ( $norm > 0 ) {
			foreach ( $family as $bucket => $value ) {
				$out[ $bucket ] = ( isset( $out[ $bucket ] ) ? $out[ $bucket ] : 0.0 ) + $value / $norm;
			}
		}
	}

	/**
	 * ASCII-only lower-casing (strtolower is locale-dependent before PHP 8.2).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ascii_lower( string $text ): string {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * The zero-width removal and homoglyph folding table.
	 *
	 * @return array<string, string>
	 */
	private static function translate_table(): array {
		if ( null === self::$translate ) {
			$table = array();
			foreach ( array( "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{2060}", "\u{FEFF}", "\u{00AD}" ) as $char ) {
				$table[ $char ] = '';
			}
			$pairs           = array(
				'а' => 'a',
				'е' => 'e',
				'о' => 'o',
				'р' => 'p',
				'с' => 'c',
				'у' => 'y',
				'х' => 'x',
				'і' => 'i',
				'ј' => 'j',
				'ѕ' => 's',
				'А' => 'A',
				'В' => 'B',
				'Е' => 'E',
				'К' => 'K',
				'М' => 'M',
				'Н' => 'H',
				'О' => 'O',
				'Р' => 'P',
				'С' => 'C',
				'Т' => 'T',
				'Х' => 'X',
				'α' => 'a',
				'ο' => 'o',
				'ν' => 'v',
				'Α' => 'A',
				'Β' => 'B',
				'Ε' => 'E',
				'Ζ' => 'Z',
				'Η' => 'H',
				'Ι' => 'I',
				'Κ' => 'K',
				'Μ' => 'M',
				'Ν' => 'N',
				'Ο' => 'O',
				'Ρ' => 'P',
				'Τ' => 'T',
				'Υ' => 'Y',
				'Χ' => 'X',
			);
			self::$translate = array_merge( $table, $pairs );
		}
		return self::$translate;
	}
}
