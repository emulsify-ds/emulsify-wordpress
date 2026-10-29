<?php
/**
 * Exercises filesystem discovery failures without WordPress or provider IO.
 *
 * A native permission fixture covers ordinary users. The directory-removal
 * fixture also runs as root, whose filesystem permissions bypass chmod(000).
 *
 * @package Emulsify
 */

use Emulsify\Theme\Support\FileDiscovery;

require dirname( __DIR__, 2 ) . '/includes/Support/FileDiscovery.php';

define( 'WP_DEBUG', ! in_array( '--production', $argv, true ) );
$GLOBALS['file_discovery_diagnostics'] = array();
function wp_trigger_error( $function, $message, $level = E_USER_NOTICE ): void {
	$GLOBALS['file_discovery_diagnostics'][] = array( $function, $message, $level );
}

function discovery_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function discovery_write( string $path, string $contents = 'fixture' ): void {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $contents );
}
function discovery_remove( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}

/** Removes a selected directory exactly when SPL attempts to open it. */
final class DiscoveryVanishingDirectory {
	public $context;
	private $directory;
	public static string $remove_on_open;
	public static bool $removed = false;

	private static function native( callable $operation ) {
		stream_wrapper_restore( 'file' );
		try {
			return $operation();
		} finally {
			stream_wrapper_unregister( 'file' );
			stream_wrapper_register( 'file', self::class );
		}
	}
	public function url_stat( $path, $flags ) {
		return self::native( static fn() => $flags & STREAM_URL_STAT_LINK ? @lstat( $path ) : @stat( $path ) );
	}
	public function dir_opendir( $path, $options ): bool {
		if ( ! self::$removed && $path === self::$remove_on_open ) {
			self::native( static function () use ( $path ): void { discovery_remove( $path ); } );
			self::$removed = true;
		}
		$this->directory = self::native( static fn() => @opendir( $path ) );
		return is_resource( $this->directory );
	}
	public function dir_readdir() { return readdir( $this->directory ); }
	public function dir_rewinddir(): bool { rewinddir( $this->directory ); return true; }
	public function dir_closedir(): bool { closedir( $this->directory ); return true; }
}

$root = sys_get_temp_dir() . '/emulsify-file-discovery-' . bin2hex( random_bytes( 5 ) );
$failures = array();
$wrapped = false;
$denied = $root . '/permissions/a-denied';
try {
	discovery_write( $root . '/ordinary/z.js' );
	discovery_write( $root . '/ordinary/a/deep.CSS' );
	discovery_write( $root . '/ordinary/a/readme.txt' );
	$expected = array( $root . '/ordinary/a/deep.CSS', $root . '/ordinary/z.js' );
	discovery_assert( $expected === FileDiscovery::recursive_files( $root . '/ordinary', array( '.CSS', 'js' ) ), 'Discovery must retain deterministic ordering and extension normalization.' );
	discovery_assert( array( $root . '/ordinary/z.js' ) === FileDiscovery::directory_files( $root . '/ordinary', array( 'js' ) ), 'Non-recursive discovery must retain its immediate-file behavior.' );
	$complete = false;
	FileDiscovery::file_records( array( array( 'path' => $root . '/ordinary' ) ), array( 'js' ), true, $complete );
	discovery_assert( $complete, 'A complete file-record scan must set its completion flag.' );

	discovery_write( $root . '/permissions/z-readable/visible.component.json' );
	discovery_write( $denied . '/hidden.component.json' );
	chmod( $denied, 0000 );
	clearstatcache( true );
	if ( ! is_readable( $denied ) ) {
		$before = count( $GLOBALS['file_discovery_diagnostics'] );
		try {
			$complete = true;
			$actual = FileDiscovery::recursive_files( $root . '/permissions', array( 'json' ), $complete );
			discovery_assert( array( $root . '/permissions/z-readable/visible.component.json' ) === $actual, 'An unreadable component branch must not hide readable siblings.' );
			discovery_assert( ! $complete, 'An unreadable branch must mark its scan incomplete so it cannot be cached.' );
			FileDiscovery::recursive_files( $root . '/permissions', array( 'json' ) );
			discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'An unreadable branch must report once per root only in debug mode.' );
		} catch ( Throwable $error ) {
			$failures[] = 'Unreadable subdirectory: ' . $error->getMessage();
		}
		$before = count( $GLOBALS['file_discovery_diagnostics'] );
		discovery_assert( array() === FileDiscovery::normalize_roots( array( $denied ) ), 'An unreadable root must be excluded.' );
		FileDiscovery::normalize_roots( array( $denied ) );
		discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'An existing unreadable root must report once in debug mode.' );
	} else {
		echo "Permission bits are bypassed by this user; the vanished-directory race below still exercises failure handling.\n";
	}
	chmod( $denied, 0755 );

	discovery_write( $root . '/race/z-readable/visible.json' );
	discovery_write( $root . '/race/a-vanishing/hidden.json' );
	DiscoveryVanishingDirectory::$remove_on_open = $root . '/race/a-vanishing';
	$before = count( $GLOBALS['file_discovery_diagnostics'] );
	stream_wrapper_unregister( 'file' );
	stream_wrapper_register( 'file', DiscoveryVanishingDirectory::class );
	$wrapped = true;
	try {
		$complete = true;
		$actual = FileDiscovery::file_records( array( array( 'path' => $root . '/race' ) ), array( 'json' ), true, $complete );
		discovery_assert( DiscoveryVanishingDirectory::$removed, 'The race fixture must actually remove a directory during the scan.' );
		discovery_assert( array( $root . '/race/z-readable/visible.json' ) === array_column( $actual, 'path' ), 'A vanished branch must not stop discovery of readable siblings.' );
		discovery_assert( ! $complete, 'A vanished branch must mark file records incomplete so they cannot be cached.' );
		discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'A vanished branch must report once per root only in debug mode.' );
	} catch ( Throwable $error ) {
		$failures[] = 'Vanished subdirectory: ' . $error->getMessage();
	} finally {
		stream_wrapper_restore( 'file' );
		$wrapped = false;
	}
	discovery_write( $root . '/race-flat/hidden.json' );
	DiscoveryVanishingDirectory::$remove_on_open = $root . '/race-flat';
	DiscoveryVanishingDirectory::$removed = false;
	$before = count( $GLOBALS['file_discovery_diagnostics'] );
	stream_wrapper_unregister( 'file' );
	stream_wrapper_register( 'file', DiscoveryVanishingDirectory::class );
	$wrapped = true;
	try {
		$complete = true;
		discovery_assert( array() === FileDiscovery::directory_files( $root . '/race-flat', array(), $complete ), 'A vanished non-recursive root must return an empty result.' );
		discovery_assert( DiscoveryVanishingDirectory::$removed && ! $complete, 'A vanished non-recursive root must mark its scan incomplete.' );
		discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'A vanished non-recursive root must report only in debug mode.' );
	} finally {
		stream_wrapper_restore( 'file' );
		$wrapped = false;
	}

	$before = count( $GLOBALS['file_discovery_diagnostics'] );
	try {
		$complete = true;
		discovery_assert( array() === FileDiscovery::recursive_files( $root . '/missing', array(), $complete ), 'A missing scan root must return an empty result.' );
		discovery_assert( ! $complete, 'A missing root must mark its scan incomplete.' );
		FileDiscovery::recursive_files( $root . '/missing' );
		discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'A missing scan root must not be silently dropped in debug mode.' );
	} catch ( Throwable $error ) {
		$failures[] = 'Missing root: ' . $error->getMessage();
	}
	$before = count( $GLOBALS['file_discovery_diagnostics'] );
	$complete = true;
	discovery_assert( array() === FileDiscovery::directory_files( $root . '/missing-flat', array(), $complete ) && ! $complete, 'A missing non-recursive root must be harmless and mark its scan incomplete.' );
	discovery_assert( ( WP_DEBUG ? 1 : 0 ) === count( $GLOBALS['file_discovery_diagnostics'] ) - $before, 'A missing non-recursive root must report only in debug mode.' );
	$before = count( $GLOBALS['file_discovery_diagnostics'] );
	FileDiscovery::normalize_roots( array( $root . '/missing-optional' ) );
	discovery_assert( $before === count( $GLOBALS['file_discovery_diagnostics'] ), 'Optional missing roots must stay quiet.' );
	if ( $failures ) {
		throw new RuntimeException( implode( "\n", $failures ) );
	}
	echo 'File discovery smoke passed in ' . ( WP_DEBUG ? 'debug' : 'production' ) . " mode.\n";
	if ( WP_DEBUG ) {
		passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --production', $status );
		discovery_assert( 0 === $status, 'Production-mode discovery must also pass.' );
	}
} finally {
	if ( $wrapped ) {
		stream_wrapper_restore( 'file' );
	}
	if ( is_dir( $denied ) ) {
		chmod( $denied, 0755 );
	}
	discovery_remove( $root );
}
