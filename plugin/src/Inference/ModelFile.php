<?php
/**
 * Reader for model.bin (architecture.md §4.2).
 *
 * The weights stay in one binary string and are read with unpack() at an offset, one bucket at a
 * time. Unpacking 2^19 floats into a PHP array would cost 13-25 MB (review note R1).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Inference;

/**
 * A loaded model file.
 */
final class ModelFile {

	const MAGIC          = 'LAAS';
	const FORMAT_VERSION = 1;
	const PREFIX_BYTES   = 10; // 4 magic + uint16 version + uint32 header length.

	/**
	 * Whole file contents.
	 *
	 * @var string
	 */
	private $blob;

	/**
	 * Decoded JSON header.
	 *
	 * @var array<string, mixed>
	 */
	private $header;

	/**
	 * Byte offset where the hashed weights start.
	 *
	 * @var int
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param string               $blob   File contents.
	 * @param array<string, mixed> $header Decoded header.
	 * @param int                  $base   Offset of the first weight.
	 */
	private function __construct( string $blob, array $header, int $base ) {
		$this->blob   = $blob;
		$this->header = $header;
		$this->base   = $base;
	}

	/**
	 * Load and validate a model file.
	 *
	 * @param string $path            Path to model.bin.
	 * @param bool   $verify_checksum Check the weights' sha256 (do this once per plugin version).
	 * @return self
	 * @throws ModelException If the file is missing, malformed or corrupt.
	 */
	public static function load( string $path, bool $verify_checksum = true ): self {
		if ( PHP_INT_SIZE < 8 ) {
			throw new ModelException( 'A 64-bit PHP build is required.' );
		}
		if ( ! is_readable( $path ) ) {
			throw new ModelException( 'Model file not readable.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; no WordPress in this library.
		$blob = file_get_contents( $path );
		if ( false === $blob || strlen( $blob ) < self::PREFIX_BYTES || self::MAGIC !== substr( $blob, 0, 4 ) ) {
			throw new ModelException( 'Not a model file.' );
		}

		$prefix = unpack( 'vversion/Vheader_len', $blob, 4 );
		if ( self::FORMAT_VERSION !== $prefix['version'] ) {
			throw new ModelException( 'Unsupported model format version.' );
		}

		$header = json_decode( (string) substr( $blob, self::PREFIX_BYTES, $prefix['header_len'] ), true );
		if ( ! is_array( $header ) || ! isset( $header['n_buckets'], $header['dense_names'], $header['featurizer_version'] ) ) {
			throw new ModelException( 'Model header is unreadable.' );
		}
		if ( Featurizer::VERSION !== $header['featurizer_version'] ) {
			throw new ModelException( 'Model was trained with a different featurizer version.' );
		}

		$base     = self::PREFIX_BYTES + $prefix['header_len'];
		$expected = $base + 4 * ( (int) $header['n_buckets'] + count( $header['dense_names'] ) );
		if ( strlen( $blob ) !== $expected ) {
			throw new ModelException( 'Model file is truncated or has trailing bytes.' );
		}
		if ( $verify_checksum && hash( 'sha256', (string) substr( $blob, $base ) ) !== $header['sha256_of_weights'] ) {
			throw new ModelException( 'Model weights checksum mismatch.' );
		}

		return new self( $blob, $header, $base );
	}

	/**
	 * Header values (model_version, thresholds, calibration, ...).
	 *
	 * @return array<string, mixed>
	 */
	public function header(): array {
		return $this->header;
	}

	/**
	 * Number of hash buckets.
	 *
	 * @return int
	 */
	public function n_buckets(): int {
		return (int) $this->header['n_buckets'];
	}

	/**
	 * Weight of one hashed bucket.
	 *
	 * @param int $bucket Bucket index.
	 * @return float
	 */
	public function weight( int $bucket ): float {
		return unpack( 'g', $this->blob, $this->base + 4 * $bucket )[1];
	}

	/**
	 * Dense feature weights, in DENSE_NAMES order.
	 *
	 * @return float[]
	 */
	public function dense_weights(): array {
		$count = count( $this->header['dense_names'] );
		return array_values( unpack( 'g' . $count, $this->blob, $this->base + 4 * $this->n_buckets() ) );
	}
}
