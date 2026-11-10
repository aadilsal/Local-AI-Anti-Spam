<?php
/**
 * Build the release zip: php bin/build-zip.php  (from plugin/)
 *
 * Writes dist/local-ai-anti-spam.zip with everything except the entries in .distignore, inside a
 * local-ai-anti-spam/ folder, as WordPress.org and "Upload plugin" expect. Fails if the version in
 * the plugin header, LAAS_VERSION and readme.txt's stable tag disagree, or if the model is missing.
 *
 * @package LocalAIAntiSpam
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI build script, not loaded by WordPress.

const LAAS_SLUG = 'local-ai-anti-spam';

$laas_root = dirname( __DIR__ );
$laas_main = (string) file_get_contents( $laas_root . '/local-ai-anti-spam.php' );
$laas_read = (string) file_get_contents( $laas_root . '/readme.txt' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $laas_main, $laas_header );
preg_match( "/define\(\s*'LAAS_VERSION',\s*'([^']+)'/", $laas_main, $laas_const );
preg_match( '/^Stable tag:\s*(\S+)/m', $laas_read, $laas_stable );
$laas_versions = array( $laas_header[1] ?? '?', $laas_const[1] ?? '?', $laas_stable[1] ?? '?' );
if ( count( array_unique( $laas_versions ) ) !== 1 ) {
	fwrite( STDERR, 'Version mismatch (header, LAAS_VERSION, Stable tag): ' . implode( ', ', $laas_versions ) . "\n" );
	exit( 1 );
}
if ( ! is_file( $laas_root . '/model/model.dat' ) ) {
	fwrite( STDERR, "model/model.dat is missing: run python -m laas.export first\n" );
	exit( 1 );
}

$laas_ignore = array_filter(
	array_map( 'trim', file( $laas_root . '/.distignore' ) ),
	static function ( $line ) {
		return '' !== $line && '#' !== $line[0];
	}
);

@mkdir( $laas_root . '/dist' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May already exist.
$laas_zip_path = $laas_root . '/dist/' . LAAS_SLUG . '.zip';
@unlink( $laas_zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May not exist.
$laas_zip = new ZipArchive();
if ( true !== $laas_zip->open( $laas_zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot write {$laas_zip_path}\n" );
	exit( 1 );
}

$laas_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $laas_root, FilesystemIterator::SKIP_DOTS ) );
$laas_count = 0;
foreach ( $laas_files as $laas_file ) {
	$laas_relative = str_replace( '\\', '/', substr( $laas_file->getPathname(), strlen( $laas_root ) + 1 ) );
	$laas_top      = explode( '/', $laas_relative )[0];
	if ( in_array( $laas_top, $laas_ignore, true ) || $laas_file->isDir() ) {
		continue;
	}
	if ( '.php' === substr( $laas_relative, -4 ) && "\xEF\xBB\xBF" === (string) file_get_contents( $laas_file->getPathname(), false, null, 0, 3 ) ) {
		fwrite( STDERR, "{$laas_relative} starts with a UTF-8 byte-order mark, which breaks headers\n" );
		exit( 1 );
	}
	$laas_zip->addFile( $laas_file->getPathname(), LAAS_SLUG . '/' . $laas_relative );
	++$laas_count;
}
$laas_zip->close();
printf( "Built %s: version %s, %d files, %.1f MB\n", $laas_zip_path, $laas_versions[0], $laas_count, filesize( $laas_zip_path ) / 1048576 );
