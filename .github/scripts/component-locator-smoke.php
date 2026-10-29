<?php
/**
 * Smoke checks for component block discovery.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$GLOBALS['emulsify_locator_smoke_hooks'] = array();
$GLOBALS['emulsify_locator_transients']  = array();
$GLOBALS['emulsify_locator_environment'] = 'development';
$GLOBALS['emulsify_locator_failures']    = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_locator_smoke_hooks'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_locator_smoke_hooks'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_locator_smoke_hooks'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_locator_smoke_hooks'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func_array(
					$callback['callback'],
					array_slice(
						array_merge( array( $value ), $arguments ),
						0,
						$callback['accepted_args']
					)
				);
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return $GLOBALS['emulsify_locator_stylesheet'];
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return $GLOBALS['emulsify_locator_child_theme'];
	}
}

if ( ! function_exists( 'get_template' ) ) {
	function get_template(): string {
		return $GLOBALS['emulsify_locator_template'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_locator_parent_theme'];
	}
}

if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri(): string {
		return $GLOBALS['emulsify_locator_child_uri'] ?? 'https://example.test/themes/child';
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/themes/parent';
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( string $stylesheet = '' ) {
		return new class( $stylesheet ) {
			/**
			 * Theme stylesheet slug.
			 *
			 * @var string
			 */
			private $stylesheet;

			/**
			 * Constructor.
			 *
			 * @param string $stylesheet Theme stylesheet slug.
			 */
			public function __construct( string $stylesheet ) {
				$this->stylesheet = $stylesheet;
			}

			/**
			 * Gets theme metadata.
			 *
			 * @param string $header Metadata header.
			 * @return string Metadata value.
			 */
			public function get( string $header ): string {
				if ( 'Version' !== $header ) {
					return '';
				}

				return isset( $GLOBALS['emulsify_locator_theme_versions'][ $this->stylesheet ] )
					? $GLOBALS['emulsify_locator_theme_versions'][ $this->stylesheet ]
					: '';
			}
		};
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return $GLOBALS['emulsify_locator_environment'];
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		if ( array_key_exists( 'emulsify_locator_transient_override', $GLOBALS ) ) {
			return $GLOBALS['emulsify_locator_transient_override'];
		}

		return array_key_exists( $key, $GLOBALS['emulsify_locator_transients'] )
			? $GLOBALS['emulsify_locator_transients'][ $key ]
			: false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		unset( $expiration );

		$GLOBALS['emulsify_locator_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		$exists = array_key_exists( $key, $GLOBALS['emulsify_locator_transients'] );
		unset( $GLOBALS['emulsify_locator_transients'][ $key ] );

		return $exists;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$slug = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $title ) );
		$slug = trim( (string) $slug, '-' );

		return $slug;
	}
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
	function acf_register_block_type( array $args ) {
		$GLOBALS['emulsify_locator_acf_registered'][] = $args;

		return $args;
	}
}

if ( ! function_exists( 'acf_get_block_type' ) ) {
	function acf_get_block_type( string $name ) {
		return isset( $GLOBALS['emulsify_locator_existing_acf_blocks'][ $name ] )
			? $GLOBALS['emulsify_locator_existing_acf_blocks'][ $name ]
			: null;
	}
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_locator_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Records independent deployment regressions so a baseline run reports each. */
function emulsify_locator_smoke_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$GLOBALS['emulsify_locator_failures'][] = $message;
	}
}

/** Counts real directory opens while delegating to PHP's native file wrapper. */
final class ComponentDiscoveryFileSpy {
	public $context;
	private $stream;
	private $directory;
	public static array $directory_opens = array();
	public static int $stat_calls = 0;

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
		++self::$stat_calls;
		$stat = $flags & STREAM_URL_STAT_LINK ? 'lstat' : 'stat';
		return self::native( static fn() => $flags & STREAM_URL_STAT_QUIET ? @$stat( $path ) : $stat( $path ) );
	}
	public function stream_open( $path, $mode, $options, &$opened_path ): bool {
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
		self::$directory_opens[] = $path;
		$this->directory = self::native( static fn() => opendir( $path ) );
		return is_resource( $this->directory );
	}
	public function dir_readdir() { return readdir( $this->directory ); }
	public function dir_rewinddir(): bool { rewinddir( $this->directory ); return true; }
	public function dir_closedir(): bool { closedir( $this->directory ); return true; }
}

/** Gets the private persistent key without relying on the transient payload. */
function emulsify_locator_smoke_cache_key(): string {
	return ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'component_files_cache_key' ) )
		->invoke( new Emulsify\Theme\Blocks\ComponentLocator() );
}

/**
 * Writes a fixture file, creating its parent directory when needed.
 *
 * @param string $path     File path.
 * @param string $contents File contents.
 * @return void
 */
function emulsify_locator_smoke_write( string $path, string $contents ): void {
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create fixture directory: %s', $directory ) );
	}

	if ( false === file_put_contents( $path, $contents ) ) {
		throw new RuntimeException( sprintf( 'Could not write fixture file: %s', $path ) );
	}
}

/**
 * Recursively removes a path.
 *
 * @param string $path Path to remove.
 * @return void
 */
function emulsify_locator_smoke_remove( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $path );
}

/**
 * Gets a list of record relative paths.
 *
 * @param array $records Component records.
 * @return array Relative paths.
 */
function emulsify_locator_smoke_relatives( array $records ): array {
	return array_map(
		static function ( array $record ): string {
			return $record['relative'];
		},
		$records
	);
}

/**
 * Checks whether a duplicate record was captured.
 *
 * @param array  $duplicates Duplicate records.
 * @param string $type       Duplicate type.
 * @param string $name       Duplicate name/key.
 * @return bool TRUE when a matching record exists.
 */
function emulsify_locator_smoke_has_duplicate( array $duplicates, string $type, string $name ): bool {
	foreach ( $duplicates as $duplicate ) {
		if ( $type === $duplicate['type'] && $name === $duplicate['name'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Gets registered ACF block names from the smoke stub.
 *
 * @return array Registered ACF block names.
 */
function emulsify_locator_smoke_acf_registered_names(): array {
	return array_map(
		static function ( array $args ): string {
			return $args['name'];
		},
		$GLOBALS['emulsify_locator_acf_registered']
	);
}

$repo_root = dirname( __DIR__, 2 );
$work_root = sys_get_temp_dir() . '/emulsify-component-locator-' . uniqid( '', true );
$child = $work_root . '/child-theme';
$parent = $work_root . '/parent-theme';

$GLOBALS['emulsify_locator_child_theme']  = $child;
$GLOBALS['emulsify_locator_parent_theme'] = $parent;
$GLOBALS['emulsify_locator_stylesheet']   = 'child-theme';
$GLOBALS['emulsify_locator_template']     = 'parent-theme';
$GLOBALS['emulsify_locator_theme_versions'] = array(
	'child-theme'  => '1.0.0',
	'parent-theme' => '2.0.0',
);
$GLOBALS['emulsify_locator_acf_registered'] = array();
$GLOBALS['emulsify_locator_existing_acf_blocks'] = array();

try {
	emulsify_locator_smoke_write( $child . '/dist/components/card/card.component.json', '{"title":"Child Card"}' );
	emulsify_locator_smoke_write( $child . '/dist/components/card/card.twig', '<article>Child card</article>' );
	emulsify_locator_smoke_write( $child . '/dist/components/icon-card/icon-card.component.json', '{"title":"Child Icon Card"}' );
	emulsify_locator_smoke_write( $child . '/dist/components/icon-card/icon-card.twig', '<article>Child icon card</article>' );
	emulsify_locator_smoke_write( $parent . '/dist/components/card/card.component.json', '{"title":"Parent Card"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/card/card.twig', '<article>Parent card</article>' );
	emulsify_locator_smoke_write( $parent . '/dist/components/icon/card/card.component.json', '{"title":"Parent Icon Card"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/icon/card/card.twig', '<article>Parent icon card</article>' );
	emulsify_locator_smoke_write( $parent . '/dist/components/parent-card/parent-card.component.json', '{"title":"Parent Only"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/parent-card/parent-card.twig', '<article>Parent only</article>' );
	emulsify_locator_smoke_write( $child . '/dist/components/native/block.json', '{"name":"emulsify/native"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/duplicate-native/block.json', '{"name":"emulsify/native"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/native/block.json', '{"name":"emulsify/native-parent"}' );
	emulsify_locator_smoke_write( $parent . '/dist/components/parent-native/block.json', '{"name":"emulsify/parent-native"}' );

	require_once $repo_root . '/includes/Support/FileDiscovery.php';
	require_once $repo_root . '/includes/Support/AssetRecord.php';
	require_once $repo_root . '/includes/Support/Diagnostics.php';
	require_once $repo_root . '/includes/Blocks/ComponentLocator.php';

	$locator = new Emulsify\Theme\Blocks\ComponentLocator();
	$acf = $locator->acf_components();

	emulsify_locator_smoke_assert(
		array( 'card', 'icon-card', 'parent-card' ) === emulsify_locator_smoke_relatives( $acf ),
		'ACF/Twig discovery should be deterministic and child-theme-first.'
	);
	emulsify_locator_smoke_assert(
		false !== strpos( $acf[0]['metadata_path'], '/child-theme/dist/components/card/card.component.json' ),
		'Child ACF/Twig component metadata should override a parent component at the same relative path.'
	);
	emulsify_locator_smoke_assert(
		'dist/components/card/card.twig' === $acf[0]['template'],
		'ACF/Twig discovery should return the theme-relative Twig template.'
	);

	emulsify_locator_smoke_write( $child . '/dist/components/late-native/block.json', '{"name":"emulsify/late-native"}' );

	$native = $locator->native_block_directories();

	emulsify_locator_smoke_assert(
		array( 'native', 'parent-native' ) === emulsify_locator_smoke_relatives( $native ),
		'Native block discovery should reuse the memoized file index and remain child-theme-first.'
	);
	emulsify_locator_smoke_assert(
		false !== strpos( $native[0]['path'], '/child-theme/dist/components/native' ),
		'Child native block metadata should override a parent block at the same relative path.'
	);

	$locator_duplicates = $locator->skipped_duplicates();

	emulsify_locator_smoke_assert(
		emulsify_locator_smoke_has_duplicate( $locator_duplicates, 'acf_component_path', 'card' ),
		'ACF/Twig discovery should report duplicate component paths.'
	);
	emulsify_locator_smoke_assert(
		emulsify_locator_smoke_has_duplicate( $locator_duplicates, 'acf_component_slug', 'icon-card' ),
		'ACF/Twig discovery should report duplicate component slugs.'
	);
	emulsify_locator_smoke_assert(
		emulsify_locator_smoke_has_duplicate( $locator_duplicates, 'native_component_path', 'native' ),
		'Native discovery should report duplicate component paths.'
	);
	emulsify_locator_smoke_assert(
		emulsify_locator_smoke_has_duplicate( $locator_duplicates, 'native_block_name', 'emulsify/native' ),
		'Native discovery should report duplicate block.json name values.'
	);

	emulsify_locator_smoke_write( $child . '/dist/components/late-card/late-card.component.json', '{"title":"Late Card"}' );
	emulsify_locator_smoke_write( $child . '/dist/components/late-card/late-card.twig', '<article>Late card</article>' );

	emulsify_locator_smoke_assert(
		array( 'card', 'icon-card', 'parent-card' ) === emulsify_locator_smoke_relatives( $locator->acf_components() ),
		'ACF/Twig discovery should return the per-request memoized result on repeated calls.'
	);

	$fresh_locator = new Emulsify\Theme\Blocks\ComponentLocator();

	emulsify_locator_smoke_assert(
		in_array( 'late-card', emulsify_locator_smoke_relatives( $fresh_locator->acf_components() ), true ),
		'A fresh locator instance should see files that were not present during the previous locator scan.'
	);
	emulsify_locator_smoke_assert(
		in_array( 'late-native', emulsify_locator_smoke_relatives( $fresh_locator->native_block_directories() ), true ),
		'A fresh locator instance should see native blocks that were not present during the previous locator scan.'
	);

	emulsify_locator_smoke_write( $child . '/dist/components/acf-name-a/acf-name-a.component.json', '{"name":"emulsify/shared-acf","title":"Shared A"}' );
	emulsify_locator_smoke_write( $child . '/dist/components/acf-name-a/acf-name-a.twig', '<article>Shared A</article>' );
	emulsify_locator_smoke_write( $child . '/dist/components/acf-name-b/acf-name-b.component.json', '{"name":"emulsify/shared-acf","title":"Shared B"}' );
	emulsify_locator_smoke_write( $child . '/dist/components/acf-name-b/acf-name-b.twig', '<article>Shared B</article>' );

	require_once $repo_root . '/includes/Support/AssetEnqueuer.php';
	require_once $repo_root . '/includes/Support/Diagnostics.php';
	require_once $repo_root . '/includes/Support/AssetManifest.php';
	require_once $repo_root . '/includes/Blocks/AcfBlocks.php';

	$acf_blocks = new Emulsify\Theme\Blocks\AcfBlocks( new Emulsify\Theme\Blocks\ComponentLocator() );
	$acf_blocks->register_blocks();
	$registered_names = emulsify_locator_smoke_acf_registered_names();

	emulsify_locator_smoke_assert(
		1 === count( array_keys( $registered_names, 'emulsify-shared-acf', true ) ),
		'ACF block registration should normalize and skip duplicate final block names.'
	);
	emulsify_locator_smoke_assert(
		emulsify_locator_smoke_has_duplicate( $acf_blocks->skipped_duplicates(), 'acf_block_name', 'emulsify-shared-acf' ),
		'ACF block registration should report duplicate normalized final block names.'
	);

	$registered_card = null;

	foreach ( $GLOBALS['emulsify_locator_acf_registered'] as $registered_block ) {
		if ( 'emulsify-card' === $registered_block['name'] ) {
			$registered_card = $registered_block;
			break;
		}
	}

	emulsify_locator_smoke_assert(
		is_array( $registered_card ) && empty( $registered_card['data']['twig_template'] ),
		'ACF block registration should not persist the discovered Twig template in block data.'
	);

	$template_method = new ReflectionMethod( $acf_blocks, 'template' );

	emulsify_locator_smoke_assert(
		'dist/components/card/card.twig' === $template_method->invoke(
			$acf_blocks,
			array(
				'twig_template' => 'dist/components/card/card.twig',
				'data'          => array(
					'twig_template' => 'dist/components/parent-card/parent-card.twig',
				),
			)
		),
		'ACF block rendering should prefer the registered Twig template over persisted block data.'
	);
	emulsify_locator_smoke_assert(
		'dist/components/parent-card/parent-card.twig' === $template_method->invoke(
			$acf_blocks,
			array(
				'data' => array(
					'twig_template' => 'dist/components/parent-card/parent-card.twig',
				),
			)
		),
		'ACF block rendering should allow a legacy persisted template only when component discovery found it.'
	);
	emulsify_locator_smoke_assert(
		'' === $template_method->invoke(
			$acf_blocks,
			array(
				'data' => array(
					'twig_template' => '../../hostile.twig',
				),
			)
		),
		'ACF block rendering should reject persisted templates outside the discovered component set.'
	);

	add_filter(
		'emulsify_theme_component_discovery_cache_enabled',
		static function (): bool {
			return true;
		}
	);
	add_filter(
		'emulsify_theme_component_discovery_cache_key_parts',
		static function ( array $key_parts ): array {
			$key_parts['smoke'] = 'component-locator';

			return $key_parts;
		}
	);
	add_filter(
		'emulsify_theme_component_discovery_cache_ttl',
		static function (): int {
			return 3600;
		}
	);

	$cache_child  = $work_root . '/cache-child';
	$cache_parent = $work_root . '/cache-parent';
	$GLOBALS['emulsify_locator_child_theme']  = $cache_child;
	$GLOBALS['emulsify_locator_parent_theme'] = $cache_parent;
	$GLOBALS['emulsify_locator_stylesheet']   = 'cache-child';
	$GLOBALS['emulsify_locator_template']     = 'cache-parent';
	$GLOBALS['emulsify_locator_theme_versions'] = array(
		'cache-child'  => '1.0.0',
		'cache-parent' => '2.0.0',
	);
	$GLOBALS['emulsify_locator_transients'] = array();
	emulsify_locator_smoke_write( $cache_child . '/dist/components/cache-card/cache-card.component.json', '{"title":"Cache Card"}' );
	emulsify_locator_smoke_write( $cache_child . '/dist/components/cache-card/cache-card.twig', '<article>Cache card</article>' );

	$cache_locator = new Emulsify\Theme\Blocks\ComponentLocator();

	emulsify_locator_smoke_assert(
		array( 'cache-card' ) === emulsify_locator_smoke_relatives( $cache_locator->acf_components() ),
		'Missing persistent discovery cache should fall back to filesystem scanning.'
	);
	$serialized_cache = serialize( $GLOBALS['emulsify_locator_transients'] );
	emulsify_locator_smoke_check(
		! str_contains( $serialized_cache, $work_root ),
		'Persistent discovery must store root-relative paths, never absolute deployment paths.'
	);
	clearstatcache();
	$warm_key_before = emulsify_locator_smoke_cache_key();
	stream_wrapper_unregister( 'file' );
	stream_wrapper_register( 'file', ComponentDiscoveryFileSpy::class );
	try {
		$warm_records = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	} finally {
		stream_wrapper_restore( 'file' );
	}
	emulsify_locator_smoke_assert(
		array( 'cache-card' ) === emulsify_locator_smoke_relatives( $warm_records ) && array() === ComponentDiscoveryFileSpy::$directory_opens,
		'Enabled persistent discovery cache should be reused across locator instances.'
	);
	echo 'Warm discovery cache: ' . count( ComponentDiscoveryFileSpy::$directory_opens ) . ' directory opens; ' . ComponentDiscoveryFileSpy::$stat_calls . " stat calls.\n";
	for ( $lookup = 0; $lookup < 3; ++$lookup ) {
		clearstatcache();
		emulsify_locator_smoke_check(
			$warm_key_before === emulsify_locator_smoke_cache_key()
			&& $warm_records === ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components(),
			'Unchanged warm lookups must preserve the cache key and component records.'
		);
	}
	$GLOBALS['emulsify_locator_child_uri'] = 'https://new.example.test/current-theme';
	$current_uri_records = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_check(
		'https://new.example.test/current-theme/dist/components' === $current_uri_records[0]['root_uri'],
		'Cached files must use the current component root URI.'
	);
	unset( $GLOBALS['emulsify_locator_child_uri'] );
	$build_root_mtime = filemtime( $cache_child . '/dist/components' );

	emulsify_locator_smoke_write( $cache_child . '/dist/components/cache-late/cache-late.component.json', '{"title":"Cache Late"}' );
	emulsify_locator_smoke_write( $cache_child . '/dist/components/cache-late/cache-late.twig', '<article>Cache late</article>' );
	touch( $cache_child . '/dist/components', $build_root_mtime + 10 );
	clearstatcache();

	$cached_locator = new Emulsify\Theme\Blocks\ComponentLocator();

	emulsify_locator_smoke_check(
		in_array( 'cache-late', emulsify_locator_smoke_relatives( $cached_locator->acf_components() ), true ),
		'A new component directory must invalidate discovery without a version bump or asset manifest.'
	);
	emulsify_locator_smoke_assert(
		Emulsify\Theme\Blocks\ComponentLocator::clear_discovery_cache(),
		'Component discovery cache clear method should delete the active transient.'
	);

	$cleared_locator = new Emulsify\Theme\Blocks\ComponentLocator();

	emulsify_locator_smoke_assert(
		in_array( 'cache-late', emulsify_locator_smoke_relatives( $cleared_locator->acf_components() ), true ),
		'Clearing persistent discovery cache should force the next locator to scan.'
	);

	$version_child  = $work_root . '/version-child';
	$version_parent = $work_root . '/version-parent';
	$GLOBALS['emulsify_locator_child_theme']  = $version_child;
	$GLOBALS['emulsify_locator_parent_theme'] = $version_parent;
	$GLOBALS['emulsify_locator_stylesheet']   = 'version-child';
	$GLOBALS['emulsify_locator_template']     = 'version-parent';
	$GLOBALS['emulsify_locator_theme_versions'] = array(
		'version-child'  => '1.0.0',
		'version-parent' => '2.0.0',
	);
	$GLOBALS['emulsify_locator_transients'] = array();
	emulsify_locator_smoke_write( $version_child . '/dist/components/version-card/version-card.component.json', '{"title":"Version Card"}' );
	emulsify_locator_smoke_write( $version_child . '/dist/components/version-card/version-card.twig', '<article>Version card</article>' );
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_write( $version_child . '/dist/components/version-late/version-late.component.json', '{"title":"Version Late"}' );
	emulsify_locator_smoke_write( $version_child . '/dist/components/version-late/version-late.twig', '<article>Version late</article>' );
	clearstatcache();
	$version_key_before = emulsify_locator_smoke_cache_key();

	$GLOBALS['emulsify_locator_theme_versions']['version-child'] = '1.0.1';

	emulsify_locator_smoke_assert(
		$version_key_before !== emulsify_locator_smoke_cache_key() && in_array( 'version-late', emulsify_locator_smoke_relatives( ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components() ), true ),
		'Changing the child theme version should change the persistent discovery cache key.'
	);

	$mtime_child  = $work_root . '/mtime-child';
	$mtime_parent = $work_root . '/mtime-parent';
	$GLOBALS['emulsify_locator_child_theme']  = $mtime_child;
	$GLOBALS['emulsify_locator_parent_theme'] = $mtime_parent;
	$GLOBALS['emulsify_locator_stylesheet']   = 'mtime-child';
	$GLOBALS['emulsify_locator_template']     = 'mtime-parent';
	$GLOBALS['emulsify_locator_theme_versions'] = array(
		'mtime-child'  => '1.0.0',
		'mtime-parent' => '2.0.0',
	);
	$GLOBALS['emulsify_locator_transients'] = array();
	emulsify_locator_smoke_write( $mtime_child . '/dist/emulsify-assets.json', '{"assets":{}}' );
	emulsify_locator_smoke_write( $mtime_child . '/dist/components/mtime-card/mtime-card.component.json', '{"title":"Mtime Card"}' );
	emulsify_locator_smoke_write( $mtime_child . '/dist/components/mtime-card/mtime-card.twig', '<article>Mtime card</article>' );
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_write( $mtime_child . '/dist/components/mtime-late/mtime-late.component.json', '{"title":"Mtime Late"}' );
	emulsify_locator_smoke_write( $mtime_child . '/dist/components/mtime-late/mtime-late.twig', '<article>Mtime late</article>' );
	$manifest_key_before = emulsify_locator_smoke_cache_key();

	touch( $mtime_child . '/dist/emulsify-assets.json', time() + 10 );
	clearstatcache( true, $mtime_child . '/dist/emulsify-assets.json' );

	emulsify_locator_smoke_assert(
		$manifest_key_before !== emulsify_locator_smoke_cache_key() && in_array( 'mtime-late', emulsify_locator_smoke_relatives( ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components() ), true ),
		'Changing the asset manifest mtime should change the persistent discovery cache key.'
	);

	$invalid_child  = $work_root . '/invalid-cache-child';
	$invalid_parent = $work_root . '/invalid-cache-parent';
	$GLOBALS['emulsify_locator_child_theme']  = $invalid_child;
	$GLOBALS['emulsify_locator_parent_theme'] = $invalid_parent;
	$GLOBALS['emulsify_locator_stylesheet']   = 'invalid-cache-child';
	$GLOBALS['emulsify_locator_template']     = 'invalid-cache-parent';
	$GLOBALS['emulsify_locator_theme_versions'] = array(
		'invalid-cache-child'  => '1.0.0',
		'invalid-cache-parent' => '2.0.0',
	);
	$GLOBALS['emulsify_locator_transients'] = array();
	$GLOBALS['emulsify_locator_transient_override'] = array(
		'files' => array(
			array(
				'path' => $invalid_child . '/broken.component.json',
			),
		),
	);
	emulsify_locator_smoke_write( $invalid_child . '/dist/components/invalid-card/invalid-card.component.json', '{"title":"Invalid Card"}' );
	emulsify_locator_smoke_write( $invalid_child . '/dist/components/invalid-card/invalid-card.twig', '<article>Invalid card</article>' );

	emulsify_locator_smoke_assert(
		array( 'invalid-card' ) === emulsify_locator_smoke_relatives( ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components() ),
		'Invalid persistent discovery cache data should fall back to filesystem scanning.'
	);
	unset( $GLOBALS['emulsify_locator_transient_override'] );

	// Release directories may move while slugs, versions and manifests stay fixed.
	$release_a = $work_root . '/releases/a/child';
	$release_b = $work_root . '/releases/b/child';
	foreach ( array( $release_a, $release_b ) as $release ) {
		emulsify_locator_smoke_write( $release . '/dist/components/deployed/deployed.component.json', '{"title":"Deployed"}' );
		emulsify_locator_smoke_write( $release . '/dist/components/deployed/deployed.twig', '<article>Deployed</article>' );
	}
	$GLOBALS['emulsify_locator_stylesheet'] = 'unchanged-release';
	$GLOBALS['emulsify_locator_theme_versions']['unchanged-release'] = '1.0.0';
	$GLOBALS['emulsify_locator_child_theme'] = $release_a;
	$GLOBALS['emulsify_locator_transients'] = array();
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	$GLOBALS['emulsify_locator_child_theme'] = $release_b;
	$moved = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_check(
		$release_b . '/dist/components/deployed' === ( $moved[0]['path'] ?? '' ),
		'A release-directory deploy must use the new theme path with unchanged versions and slugs.'
	);

	$current = $work_root . '/current';
	emulsify_locator_smoke_assert( symlink( $release_a, $current ), 'Unable to create the release symlink fixture.' );
	$GLOBALS['emulsify_locator_child_theme'] = $current;
	$GLOBALS['emulsify_locator_transients'] = array();
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	$symlink_key_before = emulsify_locator_smoke_cache_key();
	emulsify_locator_smoke_write( $release_b . '/dist/components/native/block.json', '{"name":"fixture/current-release"}' );
	unlink( $current );
	emulsify_locator_smoke_assert( symlink( $release_b, $current ), 'Unable to retarget the release symlink fixture.' );
	clearstatcache( true );
	$retargeted_locator = new Emulsify\Theme\Blocks\ComponentLocator();
	$retargeted = $retargeted_locator->acf_components();
	emulsify_locator_smoke_check(
		$symlink_key_before !== emulsify_locator_smoke_cache_key()
		&& realpath( $release_b . '/dist/components/deployed' ) === realpath( $retargeted[0]['path'] ?? '' )
		&& 'fixture/current-release' === ( $retargeted_locator->native_block_directories()[0]['name'] ?? '' ),
		'A retargeted theme symlink must resolve cached records against the active release.'
	);

	// Filtered discovery roots are part of the state, even outside either theme.
	$original_hooks = $GLOBALS['emulsify_locator_smoke_hooks'];
	$GLOBALS['emulsify_locator_filtered_root'] = $release_a . '/dist/components';
	add_filter(
		'emulsify_theme_component_roots',
		static fn(): array => array( array( 'path' => $GLOBALS['emulsify_locator_filtered_root'], 'source' => 'filtered', 'priority' => 0 ) )
	);
	$GLOBALS['emulsify_locator_transients'] = array();
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	$GLOBALS['emulsify_locator_filtered_root'] = $release_b . '/dist/components';
	$filtered = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_check(
		$release_b . '/dist/components/deployed' === ( $filtered[0]['path'] ?? '' ),
		'Changing filtered component roots must invalidate cached absolute paths.'
	);
	add_filter(
		'emulsify_theme_component_discovery_cache_key_parts',
		static fn(): array => array( 'custom' => 'constant-key' ),
		100
	);
	$GLOBALS['emulsify_locator_filtered_root'] = $release_a . '/dist/components';
	$GLOBALS['emulsify_locator_transients'] = array();
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	$custom_key = emulsify_locator_smoke_cache_key();
	$GLOBALS['emulsify_locator_filtered_root'] = $release_b . '/dist/components';
	$custom_key_records = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	emulsify_locator_smoke_check(
		$custom_key === emulsify_locator_smoke_cache_key()
		&& $release_b . '/dist/components/deployed' === ( $custom_key_records[0]['path'] ?? '' ),
		'Root receipts must reject another root even when a filter intentionally replaces every cache key part.'
	);
	$GLOBALS['emulsify_locator_smoke_hooks'] = $original_hooks;
	unset( $GLOBALS['emulsify_locator_filtered_root'] );

	// Force a formerly valid payload to exercise read validation independently of key changes.
	$GLOBALS['emulsify_locator_child_theme'] = $release_b;
	$GLOBALS['emulsify_locator_transients'] = array();
	emulsify_locator_smoke_write( $release_b . '/dist/components/healthy/healthy.component.json', '{"title":"Healthy"}' );
	emulsify_locator_smoke_write( $release_b . '/dist/components/healthy/healthy.twig', '<article>Healthy</article>' );
	( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	$cached_payload = $GLOBALS['emulsify_locator_transients'][ emulsify_locator_smoke_cache_key() ];
	emulsify_locator_smoke_remove( $release_b . '/dist/components/deployed' );
	emulsify_locator_smoke_write( $release_b . '/dist/components/recovered/recovered.component.json', '{"title":"Recovered"}' );
	emulsify_locator_smoke_write( $release_b . '/dist/components/recovered/recovered.twig', '<article>Recovered</article>' );
	// Keep the receipt current so this exercises per-file validation, not only
	// the earlier root-state mismatch check.
	$cached_payload['roots'] = ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'component_roots_signature' ) )
		->invoke( new Emulsify\Theme\Blocks\ComponentLocator() );
	emulsify_locator_smoke_check(
		null === ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'normalize_cached_component_files' ) )
			->invoke( new Emulsify\Theme\Blocks\ComponentLocator(), $cached_payload['files'] ),
		'Per-file cache validation must reject a missing artifact even when its root receipt matches.'
	);
	$GLOBALS['emulsify_locator_transient_override'] = $cached_payload;
	$recovered = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
	unset( $GLOBALS['emulsify_locator_transient_override'] );
	emulsify_locator_smoke_check(
		array( 'healthy', 'recovered' ) === emulsify_locator_smoke_relatives( $recovered ),
		'A missing cached file must discard the whole index and rescan, including newly built components.'
	);

	// Poisoned relative paths must invalidate the complete payload. Each case
	// uses a current receipt so root validation cannot hide a path-validation bug.
	$outside_directory = dirname( $release_b ) . '/outside';
	emulsify_locator_smoke_write( $outside_directory . '/outside.component.json', '{"title":"Outside"}' );
	emulsify_locator_smoke_write( $outside_directory . '/outside.twig', '<article>Outside</article>' );
	emulsify_locator_smoke_assert(
		symlink( $outside_directory, $release_b . '/dist/components/escaping' ),
		'Unable to create the cache-containment symlink fixture.'
	);
	$attack_payload = $GLOBALS['emulsify_locator_transients'][ emulsify_locator_smoke_cache_key() ] ?? $cached_payload;
	$attack_payload['roots'] = ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'component_roots_signature' ) )
		->invoke( new Emulsify\Theme\Blocks\ComponentLocator() );
	$poisoned_files = array(
		'traversal'      => array( 'root' => 0, 'relative' => '../../../outside/outside.component.json' ),
		'absolute'       => array( 'root' => 0, 'relative' => $outside_directory . '/outside.component.json' ),
		'dot traversal'  => array( 'root' => 0, 'relative' => 'healthy/../healthy/healthy.component.json' ),
		'symlink escape' => array( 'root' => 0, 'relative' => 'escaping/outside.component.json' ),
		'root index'     => array( 'root' => '0', 'relative' => 'healthy/healthy.component.json' ),
	);
	foreach ( $poisoned_files as $label => $poisoned_file ) {
		$attack_payload['files'] = array( $poisoned_file );
		$GLOBALS['emulsify_locator_transient_override'] = $attack_payload;
		$unpoisoned = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
		unset( $GLOBALS['emulsify_locator_transient_override'] );
		emulsify_locator_smoke_check(
			array( 'healthy', 'recovered' ) === emulsify_locator_smoke_relatives( $unpoisoned ),
			'Unsafe cached ' . $label . ' must invalidate the whole index and rescan contained components.'
		);
	}

	// A temporarily inaccessible subtree must not become a complete warm index.
	$partial_child = $work_root . '/partial-build-child';
	foreach ( array( 'healthy', 'temporarily-unreadable' ) as $name ) {
		emulsify_locator_smoke_write( $partial_child . '/dist/components/' . $name . '/' . $name . '.component.json', '{"title":"Partial build"}' );
		emulsify_locator_smoke_write( $partial_child . '/dist/components/' . $name . '/' . $name . '.twig', '<article>Partial build</article>' );
	}
	$GLOBALS['emulsify_locator_child_theme'] = $partial_child;
	$GLOBALS['emulsify_locator_transients'] = array();
	$unreadable_directory = $partial_child . '/dist/components/temporarily-unreadable';
	chmod( $unreadable_directory, 0000 );
	clearstatcache();
	if ( ! is_readable( $unreadable_directory ) ) {
		$partial_key_before = emulsify_locator_smoke_cache_key();
		try {
			$partial = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
			emulsify_locator_smoke_check(
				array( 'healthy' ) === emulsify_locator_smoke_relatives( $partial ) && array() === $GLOBALS['emulsify_locator_transients'],
				'Incomplete discovery must preserve readable components without persisting a partial index.'
			);
		} catch ( Throwable $exception ) {
			emulsify_locator_smoke_check( false, 'Unreadable component subtree must not throw: ' . $exception->getMessage() );
		} finally {
			chmod( $unreadable_directory, 0700 );
			clearstatcache();
		}
		$recovered = ( new Emulsify\Theme\Blocks\ComponentLocator() )->acf_components();
		emulsify_locator_smoke_check(
			$partial_key_before === emulsify_locator_smoke_cache_key()
			&& array( 'healthy', 'temporarily-unreadable' ) === emulsify_locator_smoke_relatives( $recovered ),
			'Restored directory access must recover components without a build token or version bump.'
		);
	} else {
		chmod( $unreadable_directory, 0700 );
		echo "Incomplete discovery case skipped: current user can read mode-000 directories.\n";
	}

	$metadata_warnings = array();
	$unreadable_native = $work_root . '/unreadable/block.json';
	$unreadable_acf = $work_root . '/unreadable/card.component.json';
	emulsify_locator_smoke_write( $unreadable_native, '{"name":"fixture/unreadable"}' );
	emulsify_locator_smoke_write( $unreadable_acf, '{"title":"Unreadable"}' );
	chmod( $unreadable_native, 0000 );
	chmod( $unreadable_acf, 0000 );
	clearstatcache();
	set_error_handler(
		static function ( int $severity, string $message ) use ( &$metadata_warnings ): bool {
			$metadata_warnings[] = $message;
			return true;
		}
	);
	try {
		$native_name = ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'native_block_name' ) )
			->invoke( new Emulsify\Theme\Blocks\ComponentLocator(), $work_root . '/removed/block.json' );
		$acf_metadata = ( new ReflectionMethod( Emulsify\Theme\Blocks\AcfBlocks::class, 'metadata' ) )
			->invoke( new Emulsify\Theme\Blocks\AcfBlocks(), $work_root . '/removed/card.component.json' );
		if ( ! is_readable( $unreadable_native ) && ! is_readable( $unreadable_acf ) ) {
			$native_unreadable = ( new ReflectionMethod( Emulsify\Theme\Blocks\ComponentLocator::class, 'native_block_name' ) )
				->invoke( new Emulsify\Theme\Blocks\ComponentLocator(), $unreadable_native );
			$acf_unreadable = ( new ReflectionMethod( Emulsify\Theme\Blocks\AcfBlocks::class, 'metadata' ) )
				->invoke( new Emulsify\Theme\Blocks\AcfBlocks(), $unreadable_acf );
			emulsify_locator_smoke_check(
				'' === $native_unreadable && null === $acf_unreadable,
				'Unreadable native and ACF metadata must be skipped.'
			);
		} else {
			echo "Unreadable metadata case skipped: current user can read mode-000 files.\n";
		}
	} finally {
		restore_error_handler();
		chmod( $unreadable_native, 0600 );
		chmod( $unreadable_acf, 0600 );
	}
	emulsify_locator_smoke_check(
		'' === $native_name && null === $acf_metadata && array() === $metadata_warnings,
		'Missing or unreadable metadata after discovery must be skipped without PHP read warnings.'
	);

	emulsify_locator_smoke_assert(
		array() === $GLOBALS['emulsify_locator_failures'],
		implode( "\n", $GLOBALS['emulsify_locator_failures'] )
	);

	echo "Component locator smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_locator_smoke_remove( $work_root );
}
