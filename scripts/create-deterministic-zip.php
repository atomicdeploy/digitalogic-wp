<?php
/**
 * Create a deterministic ZIP from one staged directory.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Build-only CLI diagnostics require STDERR.

if ( 4 !== $argc ) {
	fwrite( STDERR, "Usage: php create-deterministic-zip.php <stage-directory> <output.zip> <epoch>\n" );
	exit( 2 );
}

$stage  = realpath( (string) $argv[1] );
$output = (string) $argv[2];
$epoch  = (string) $argv[3];
if ( false === $stage || ! is_dir( $stage ) ) {
	fwrite( STDERR, "The deterministic ZIP stage directory is unavailable.\n" );
	exit( 2 );
}
if ( ! preg_match( '/^[0-9]+$/D', $epoch ) || (int) $epoch < 315532800 ) {
	fwrite( STDERR, "The deterministic ZIP epoch is invalid.\n" );
	exit( 2 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP ZipArchive extension is unavailable.\n" );
	exit( 1 );
}

$stage    = rtrim( str_replace( '\\', '/', $stage ), '/' );
$files    = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $iterator as $item ) {
	if ( $item->isLink() ) {
		fwrite( STDERR, "A symlink reached the deterministic ZIP stage.\n" );
		exit( 1 );
	}
	if ( ! $item->isFile() ) {
		continue;
	}
	$absolute = str_replace( '\\', '/', $item->getPathname() );
	if ( ! str_starts_with( $absolute, $stage . '/' ) ) {
		fwrite( STDERR, "A staged ZIP path escaped its root.\n" );
		exit( 1 );
	}
	$relative = substr( $absolute, strlen( $stage ) + 1 );
	if ( '' === $relative || str_contains( $relative, '../' ) ) {
		fwrite( STDERR, "A staged ZIP path is invalid.\n" );
		exit( 1 );
	}
	$files[ $relative ] = $absolute;
}
ksort( $files, SORT_STRING );
if ( empty( $files ) ) {
	fwrite( STDERR, "The deterministic ZIP stage is empty.\n" );
	exit( 1 );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "The deterministic ZIP destination could not be opened.\n" );
	exit( 1 );
}

foreach ( $files as $relative => $absolute ) {
	if ( ! $archive->addFile( $absolute, $relative ) ) {
		fwrite( STDERR, "A staged file could not be added to the deterministic ZIP.\n" );
		$archive->close();
		exit( 1 );
	}
	if (
		! $archive->setMtimeName( $relative, (int) $epoch )
		|| ! $archive->setCompressionName( $relative, ZipArchive::CM_DEFLATE, 9 )
		|| ! $archive->setExternalAttributesName( $relative, ZipArchive::OPSYS_UNIX, 0100644 << 16 )
	) {
		fwrite( STDERR, "A deterministic ZIP entry could not be normalized.\n" );
		$archive->close();
		exit( 1 );
	}
}
$archive->setArchiveComment( '' );
if ( ! $archive->close() ) {
	fwrite( STDERR, "The deterministic ZIP could not be finalized.\n" );
	exit( 1 );
}
