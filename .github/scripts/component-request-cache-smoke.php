<?php
/**
 * Counts real filesystem work across Timber's three loaders per compile.
 *
 * --benchmark prints seven-run medians with the filesystem spy disabled.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig as ThemeTwig;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WP_DEBUG', false );
define( 'WP_CLI', false );

$GLOBALS['component_cache_child']  = '';
$GLOBALS['component_cache_parent'] = '';
$GLOBALS['component_cache_filters'] = 0;
$GLOBALS['component_cache_enabled'] = true;

function get_stylesheet_directory(): string { return $GLOBALS['component_cache_child']; }
function get_template_directory(): string { return $GLOBALS['component_cache_parent']; }
function apply_filters( $hook, $value, ...$arguments ) {
	if ( 'emulsify_theme_twig_request_cache_enabled' === $hook ) {
		return null === $GLOBALS['component_cache_enabled'] ? $value : $GLOBALS['component_cache_enabled'];
	}
	if ( 'timber/loader/loader' === $hook ) {
		++$GLOBALS['component_cache_filters'];
		return ( new ThemeTwig() )->loader_paths( $value );
	}
	return 'timber/cache/enable_extension' === $hook ? false : $value;
}
function apply_filters_deprecated( $hook, $arguments, ...$rest ) { return $arguments[0]; }
function do_action( $hook, ...$arguments ): void {}
function do_action_deprecated( $hook, ...$arguments ): void {}

/** Uses the installed Timber implementation, bypassing only location discovery. */
final class ComponentCacheTimberLoader extends \Timber\Loader {
	public function __construct() { $this->locations = array(); }
	public function compile( string $name ): string {
		$chosen = $this->choose_template( $name );
		if ( false === $chosen ) {
			throw new RuntimeException( 'Missing fixture component: ' . $name );
		}
		return $this->render_template( $chosen, array() );
	}
}

/**
 * Delegates to PHP's native file wrapper, counting actual directory opens.
 *
 * The production loader uses fully qualified FilesystemIterator, so replacing
 * that class or adding production counters would not test the same IO path.
 */
final class ComponentCacheFileSpy {
	public $context;
	private $stream;
	private $directory;
	public static array $directory_opens = array();
	public static array $file_opens = array();
	public static array $stat_calls = array();

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
		self::$stat_calls[] = $path;
		$stat = $flags & STREAM_URL_STAT_LINK ? 'lstat' : 'stat';
		return self::native( static fn() => $flags & STREAM_URL_STAT_QUIET ? @$stat( $path ) : $stat( $path ) );
	}
	public function stream_open( $path, $mode, $options, &$opened_path ): bool {
		self::$file_opens[] = realpath( $path );
		$this->stream = self::native( static fn() => fopen( $path, $mode, (bool) ( $options & STREAM_USE_PATH ) ) );
		return is_resource( $this->stream );
	}
	public function stream_read( $length ) { return fread( $this->stream, $length ); }
	public function stream_eof() { return feof( $this->stream ); }
	public function stream_stat() { return fstat( $this->stream ); }
	public function stream_seek( $offset, $whence = SEEK_SET ) { return 0 === fseek( $this->stream, $offset, $whence ); }
	public function stream_tell() { return ftell( $this->stream ); }
	public function stream_close(): void { fclose( $this->stream ); }
	public function stream_set_option( $option, $first, $second ) { return false; }
	public function dir_opendir( $path, $options ): bool {
		self::$directory_opens[] = realpath( $path );
		$this->directory = self::native( static fn() => opendir( $path ) );
		return is_resource( $this->directory );
	}
	public function dir_readdir() { return readdir( $this->directory ); }
	public function dir_rewinddir(): bool { rewinddir( $this->directory ); return true; }
	public function dir_closedir(): bool { closedir( $this->directory ); return true; }
}

function component_cache_write( string $path, string $contents ): void {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $contents );
}
function component_cache_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function component_cache_remove( string $path ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}
function component_cache_reset(): void {
	if ( method_exists( ThemeTwig::class, 'clear_request_cache' ) ) {
		ThemeTwig::clear_request_cache();
	}
}
function component_cache_compile( string $reference ): string {
	return ( new ComponentCacheTimberLoader() )->compile( $reference );
}

$temporary = sys_get_temp_dir() . '/emulsify-component-cache-' . bin2hex( random_bytes( 5 ) );
$child = $temporary . '/child';
$parent = $temporary . '/parent';
$GLOBALS['component_cache_child'] = $child;
$GLOBALS['component_cache_parent'] = $parent;
$spying = false;

try {
	component_cache_write( $child . '/project.emulsify.json', '{"project":{"machineName":"fixture"}}' );
	foreach ( array( 'one', 'two', 'three' ) as $name ) {
		component_cache_write( $child . '/src/components/group/' . $name . '/' . $name . '.twig', '{% include "fixture:parent" %}|' . $name );
	}
	component_cache_write( $parent . '/src/components/group/parent/parent.twig', 'parent' );
	component_cache_write( $child . '/src/components/direct.twig', 'direct' );
	$child_root = realpath( $child . '/src/components' );
	$parent_root = realpath( $parent . '/src/components' );

	if ( in_array( '--benchmark', $argv, true ) ) {
		for ( $index = 0; $index < 500; ++$index ) {
			$name = sprintf( 'g%04d', $index );
			component_cache_write( $child_root . '/' . $name . '/item/item.twig', $name );
		}
		component_cache_write( $child_root . '/g0499/target/target.twig', 'target' );
		$results = array();
		foreach ( array( 'direct', 'target' ) as $name ) {
			$times = array();
			for ( $sample = 0; $sample < 7; ++$sample ) {
				component_cache_reset();
				$start = hrtime( true );
				for ( $render = 0; $render < 10; ++$render ) {
					component_cache_assert( $name === component_cache_compile( 'fixture:' . $name ), 'Benchmark rendered the wrong component.' );
				}
				$times[] = ( hrtime( true ) - $start ) / 1e6 / 10;
			}
			sort( $times );
			$results[ $name . '_ms_per_compile' ] = $times[3];
		}
		echo json_encode( array( 'php' => PHP_VERSION, 'generated_group_entries' => 1500, 'samples' => 7, 'compiles_per_sample' => 10, 'medians' => $results ), JSON_PRETTY_PRINT ) . "\n";
	} else {
		// Load the relevant vendor classes before installing the IO observer.
		class_exists( \Twig\Loader\FilesystemLoader::class );
		class_exists( \Emulsify\Theme\Support\ProjectConfig::class );
		class_exists( \Emulsify\Theme\Twig\ProjectComponentLoader::class );
		stream_wrapper_unregister( 'file' );
		stream_wrapper_register( 'file', ComponentCacheFileSpy::class );
		$spying = true;
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			component_cache_assert( 'parent|' . $name === component_cache_compile( 'fixture:' . $name ), 'Timber must render each grouped entry and included parent component.' );
		}
		stream_wrapper_restore( 'file' );
		$spying = false;
		component_cache_assert( 9 === $GLOBALS['component_cache_filters'], 'Three Timber compiles must each apply the real loader filter three times.' );
		$counts = array_count_values( array_filter( ComponentCacheFileSpy::$directory_opens ) );
		$config_reads = count( array_filter( ComponentCacheFileSpy::$file_opens, static fn( $path ) => $path === realpath( $child . '/project.emulsify.json' ) ) );
		echo 'Observed directory opens: ' . json_encode( $counts ) . "; project JSON reads: $config_reads\n";
		component_cache_assert( 1 === ( $counts[ $child_root ] ?? 0 ) && 1 === ( $counts[ $parent_root ] ?? 0 ), 'Each component root must be scanned once across all Timber loader instances in the request.' );
		foreach ( $counts as $directory => $count ) {
			component_cache_assert( 1 === $count, 'A group directory was scanned more than once: ' . $directory );
		}
		component_cache_assert( 1 === $config_reads, 'Project configuration must be read once per request.' );
		$mutable_loader = ( new ThemeTwig() )->loader_paths( new \Twig\Loader\FilesystemLoader() );
		component_cache_assert( realpath( $child_root . '/group/one/one.twig' ) === $mutable_loader->getCacheKey( '@components/one' ), 'The late-registration fixture must begin with a cached grouped alias.' );
		component_cache_write( $temporary . '/late-components/one.twig', 'prepended plugin component' );
		$mutable_loader->prependPath( $temporary . '/late-components', 'components' );
		foreach ( array( '@components/one', 'fixture:one' ) as $reference ) {
			component_cache_assert( realpath( $temporary . '/late-components/one.twig' ) === $mutable_loader->getCacheKey( $reference ), 'Late prepended component roots must replace cached aliases: ' . $reference );
		}
		foreach ( array( '@components/appended-only', 'fixture:appended-only' ) as $reference ) {
			component_cache_assert( ! $mutable_loader->exists( $reference ), 'The late-registration fixture must cache a missing alias: ' . $reference );
		}
		component_cache_write( $temporary . '/appended-components/one.twig', 'lower-priority appended component' );
		component_cache_write( $temporary . '/appended-components/appended-only.twig', 'appended-only component' );
		$mutable_loader->addPath( $temporary . '/appended-components', 'components' );
		foreach ( array( '@components/one', 'fixture:one' ) as $reference ) {
			component_cache_assert( realpath( $temporary . '/late-components/one.twig' ) === $mutable_loader->getCacheKey( $reference ), 'Late appended component roots must preserve earlier priority: ' . $reference );
		}
		foreach ( array( '@components/appended-only', 'fixture:appended-only' ) as $reference ) {
			component_cache_assert( realpath( $temporary . '/appended-components/appended-only.twig' ) === $mutable_loader->getCacheKey( $reference ), 'Late appended component roots must expose previously missing aliases: ' . $reference );
		}
		ComponentCacheFileSpy::$stat_calls = array();
		stream_wrapper_unregister( 'file' );
		stream_wrapper_register( 'file', ComponentCacheFileSpy::class );
		$spying = true;
		$first_loader = ( new ThemeTwig() )->loader_paths( new \Twig\Loader\FilesystemLoader() );
		component_cache_assert( ! $first_loader->exists( 'fixture:never-component' ), 'The missing-resolution fixture must not exist.' );
		$first_miss_stats = count( array_filter( ComponentCacheFileSpy::$stat_calls, static fn( $path ) => str_contains( $path, 'never-component' ) ) );
		$second_loader = ( new ThemeTwig() )->loader_paths( new \Twig\Loader\FilesystemLoader() );
		component_cache_assert( ! $second_loader->exists( 'fixture:never-component' ), 'A missing component must remain missing through another loader.' );
		$second_miss_stats = count( array_filter( ComponentCacheFileSpy::$stat_calls, static fn( $path ) => str_contains( $path, 'never-component' ) ) );
		$alias = $temporary . '/child-alias';
		component_cache_assert( symlink( $child, $alias ), 'Unable to create a canonical-root alias fixture.' );
		$GLOBALS['component_cache_child'] = $alias;
		component_cache_assert( 'parent|one' === component_cache_compile( 'fixture:one' ), 'A canonical theme-root alias must resolve the same component.' );
		$GLOBALS['component_cache_child'] = $child;
		stream_wrapper_restore( 'file' );
		$spying = false;
		component_cache_assert( $first_miss_stats > 0 && $first_miss_stats === $second_miss_stats, 'Negative resolutions must be shared without repeating filesystem candidate checks.' );
		$alias_counts = array_count_values( array_filter( ComponentCacheFileSpy::$directory_opens ) );
		component_cache_assert( 1 === ( $alias_counts[ $child_root ] ?? 0 ) && 1 === ( $alias_counts[ $parent_root ] ?? 0 ), 'Canonical root aliases must share group discovery.' );
		$alias_config_reads = count( array_filter( ComponentCacheFileSpy::$file_opens, static fn( $path ) => $path === realpath( $child . '/project.emulsify.json' ) ) );
		component_cache_assert( 1 === $alias_config_reads, 'Canonical root aliases must share decoded configuration.' );

		component_cache_write( $child_root . '/common.twig', 'child common' );
		component_cache_write( $parent_root . '/common.twig', 'parent common' );
		$child_first = new \Emulsify\Theme\Twig\ProjectComponentLoader( 'fixture', array( $child_root, $parent_root ), new \Twig\Loader\FilesystemLoader() );
		$parent_first = new \Emulsify\Theme\Twig\ProjectComponentLoader( 'fixture', array( $parent_root, $child_root ), new \Twig\Loader\FilesystemLoader() );
		component_cache_assert( realpath( $child_root . '/common.twig' ) === $child_first->getCacheKey( 'fixture:common' ), 'The first root must win before its shared result is cached.' );
		component_cache_assert( realpath( $parent_root . '/common.twig' ) === $parent_first->getCacheKey( 'fixture:common' ), 'Resolution caches must preserve each loader\'s root ordering.' );
		component_cache_assert( realpath( $child_root . '/group/one/one.twig' ) === $child_first->getCacheKey( '@plugin/one.twig' ), 'An unregistered namespace may use component shorthand.' );
		component_cache_write( $temporary . '/plugin/one.twig', 'plugin one' );
		$plugin_files = new \Twig\Loader\FilesystemLoader();
		$plugin_files->addPath( $temporary . '/plugin', 'plugin' );
		$plugin_loader = new \Emulsify\Theme\Twig\ProjectComponentLoader( 'fixture', array( $child_root, $parent_root ), $plugin_files );
		component_cache_assert( realpath( $temporary . '/plugin/one.twig' ) === $plugin_loader->getCacheKey( '@plugin/one.twig' ), 'A shared shorthand hit must never shadow a namespace another loader registers.' );

		component_cache_assert( method_exists( ThemeTwig::class, 'clear_request_cache' ), 'Long-running workers need an explicit component/config cache reset boundary.' );
		$loader = ( new ThemeTwig() )->loader_paths( new \Twig\Loader\FilesystemLoader() );
		$original = $loader->getCacheKey( 'fixture:one' );
		component_cache_assert( ! $loader->exists( 'fixture:late' ), 'The lifecycle fixture must begin with a negative lookup.' );
		component_cache_write( $child_root . '/new-group/late/late.twig', 'late' );
		component_cache_write( $child_root . '/one.twig', 'new direct one' );
		component_cache_write( $child . '/project.emulsify.json', '{"project":{"machineName":"changed"}}' );
		component_cache_reset();
		component_cache_assert( realpath( $child_root . '/one.twig' ) === $loader->getCacheKey( 'fixture:one' ) && $original !== $loader->getCacheKey( 'fixture:one' ), 'Reset must invalidate successful resolutions on existing loader objects.' );
		component_cache_assert( $loader->exists( 'fixture:late' ), 'Reset must invalidate negative resolutions and discover new nested groups.' );
		component_cache_assert( 'changed' === \Emulsify\Theme\Support\ProjectConfig::machine_name(), 'Reset must reread same-path configuration rewrites.' );
		component_cache_write( $child_root . '/switchable/swap.twig', 'inside root' );
		component_cache_write( $temporary . '/outside/swap.twig', 'outside root' );
		component_cache_reset();
		component_cache_assert( realpath( $child_root . '/switchable/swap.twig' ) === $loader->getCacheKey( 'fixture:swap' ), 'A contained file must resolve before the mutation fixture.' );
		unlink( $child_root . '/switchable/swap.twig' );
		rmdir( $child_root . '/switchable' );
		component_cache_assert( symlink( $temporary . '/outside', $child_root . '/switchable' ), 'Unable to replace the fixture ancestor with a symlink.' );
		component_cache_reset();
		component_cache_assert( ! $loader->exists( 'fixture:swap' ), 'Reset must discard a cached hit whose ancestor now escapes the root.' );

		// CLI/queue mutation safety is the default policy; the HTTP count above
		// explicitly opts into request sharing because this harness runs in CLI.
		$GLOBALS['component_cache_enabled'] = null;
		component_cache_assert( false === \Emulsify\Theme\Support\ProjectConfig::request_cache_enabled(), 'Real CLI processes must bypass request caches by default, without a filter override.' );
		component_cache_write( $child . '/project.emulsify.json', '{"project":{"machineName":"fixture"}}' );
		$worker_loader = ( new ThemeTwig() )->loader_paths( new \Twig\Loader\FilesystemLoader() );
		component_cache_assert( realpath( $child_root . '/one.twig' ) === $worker_loader->getCacheKey( 'fixture:one' ), 'A worker begins at the new direct candidate.' );
		component_cache_assert( ! $worker_loader->exists( 'fixture:worker-late' ), 'The worker fixture must begin with a negative lookup.' );
		unlink( $child_root . '/one.twig' );
		component_cache_write( $child_root . '/worker-group/worker-late/worker-late.twig', 'worker late' );
		component_cache_write( $child . '/project.emulsify.json', '{"project":{"machineName":"worker_changed"}}' );
		clearstatcache( true );
		component_cache_assert( $original === $worker_loader->getCacheKey( 'fixture:one' ), 'Cache-disabled workers must see a deleted successful candidate without an explicit reset.' );
		component_cache_assert( $worker_loader->exists( 'fixture:worker-late' ), 'Cache-disabled workers must discover a new group after a negative lookup.' );
		component_cache_assert( 'worker_changed' === \Emulsify\Theme\Support\ProjectConfig::machine_name(), 'Cache-disabled workers must see rewritten configuration without reset.' );
		echo "Component request-cache smoke checks passed.\n";
	}
} finally {
	if ( $spying ) {
		stream_wrapper_restore( 'file' );
	}
	if ( is_dir( $temporary ) ) {
		component_cache_remove( $temporary );
	}
}
