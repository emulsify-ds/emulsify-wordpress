<?php
/**
 * Smoke checks for optional asset manifest loading.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

$GLOBALS['emulsify_asset_manifest_smoke_hooks']          = array();
$GLOBALS['emulsify_asset_manifest_smoke_styles']         = array();
$GLOBALS['emulsify_asset_manifest_smoke_scripts']        = array();
$GLOBALS['emulsify_asset_manifest_smoke_script_modules'] = array();
$GLOBALS['emulsify_asset_manifest_smoke_inline']         = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_asset_manifest_smoke_hooks'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_asset_manifest_smoke_hooks'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_asset_manifest_smoke_hooks'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_asset_manifest_smoke_hooks'][ $hook ] as $callbacks ) {
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

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return $GLOBALS['emulsify_asset_manifest_smoke_child'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_asset_manifest_smoke_parent'];
	}
}

if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri(): string {
		return 'https://example.test/child';
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/parent';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return trim( preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $key ) ), '-' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src, array $deps = array(), $ver = false ): void {
		$GLOBALS['emulsify_asset_manifest_smoke_styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src, array $deps = array(), $ver = false, $args = array() ): void {
		$GLOBALS['emulsify_asset_manifest_smoke_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );
	}
}

if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
	function wp_enqueue_script_module( string $id, string $src, array $deps = array(), $version = false ): void {
		$GLOBALS['emulsify_asset_manifest_smoke_script_modules'][ $id ] = compact( 'src', 'deps', 'version' );
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		$GLOBALS['emulsify_asset_manifest_smoke_inline'][] = compact( 'handle', 'data', 'position' );

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_asset_manifest_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Writes a fixture file.
 *
 * @param string $path    File path.
 * @param string $content File content.
 * @return void
 */
function emulsify_asset_manifest_smoke_write( string $path, string $content ): void {
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) ) {
		mkdir( $directory, 0777, true );
	}

	file_put_contents( $path, $content );
}

/**
 * Writes JSON fixture data.
 *
 * @param string $path File path.
 * @param array  $data JSON data.
 * @return void
 */
function emulsify_asset_manifest_smoke_write_json( string $path, array $data ): void {
	emulsify_asset_manifest_smoke_write( $path, (string) json_encode( $data ) );
}

/**
 * Resets smoke globals for a case.
 *
 * @param string $child  Child theme path.
 * @param string $parent Parent theme path.
 * @return void
 */
function emulsify_asset_manifest_smoke_reset( string $child, string $parent ): void {
	$GLOBALS['emulsify_asset_manifest_smoke_child']          = $child;
	$GLOBALS['emulsify_asset_manifest_smoke_parent']         = $parent;
	$GLOBALS['emulsify_asset_manifest_smoke_hooks']          = array();
	$GLOBALS['emulsify_asset_manifest_smoke_styles']         = array();
	$GLOBALS['emulsify_asset_manifest_smoke_scripts']        = array();
	$GLOBALS['emulsify_asset_manifest_smoke_script_modules'] = array();
	$GLOBALS['emulsify_asset_manifest_smoke_inline']         = array();
}

/**
 * Recursively removes a path.
 *
 * @param string $path Path to remove.
 * @return void
 */
function emulsify_asset_manifest_smoke_remove( string $path ): void {
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

$repo_root = dirname( __DIR__, 2 );
$work_root = sys_get_temp_dir() . '/emulsify-asset-manifest-' . uniqid( '', true );
$debug_log = $work_root . '/debug.log';

ini_set( 'log_errors', '1' );
ini_set( 'error_log', $debug_log );

try {
	require_once $repo_root . '/includes/Support/FileDiscovery.php';
	require_once $repo_root . '/includes/Support/AssetRecord.php';
	require_once $repo_root . '/includes/Support/AssetEnqueuer.php';
	require_once $repo_root . '/includes/Support/AssetManifest.php';
	require_once $repo_root . '/includes/Support/Diagnostics.php';
	require_once $repo_root . '/includes/Blocks/ComponentLocator.php';
	require_once $repo_root . '/includes/Runtime/Assets.php';
	require_once $repo_root . '/includes/Editor/Enhancements.php';

	$child  = $work_root . '/fallback-child';
	$parent = $work_root . '/fallback-parent';
	emulsify_asset_manifest_smoke_reset( $child, $parent );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/fallback.css', '.fallback{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/components/fallback.js', 'export default true;' );

	( new Emulsify\Theme\Runtime\Assets() )->styles();
	( new Emulsify\Theme\Runtime\Assets() )->frontend_scripts();

	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-fallback'] ),
		'No manifest should fall back to recursive global CSS discovery.'
	);
	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_script_modules']['emulsify-component-fallback'] ),
		'No manifest should fall back to recursive component JS discovery.'
	);

	$child  = $work_root . '/manifest-child';
	$parent = $work_root . '/manifest-parent';
	emulsify_asset_manifest_smoke_reset( $child, $parent );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/scanner-only.css', '.scanner{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/manifest.css', '.manifest{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/app.js', 'window.manifestApp = true;' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/components/card.css', '.card{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/components/card.js', 'export default true;' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/components/blocks/hero.css', '.hero{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/editor/editor.css', '.editor{}' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/editor/editor.js', 'window.editor = true;' );
	emulsify_asset_manifest_smoke_write_json(
		$child . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'global'     => array(
					'css' => array(
						array(
							'path'         => 'global/manifest.css',
							'relative'     => 'manifest.css',
							'version'      => 'manifest-css',
							'dependencies' => array( 'wp-block-library' ),
						),
					),
					'js'  => array(
						array(
							'path'         => 'global/app.js',
							'relative'     => 'app.js',
							'hash'         => 'manifest-js',
							'deps'         => array( 'jquery' ),
							'module'       => false,
						),
					),
				),
				'components' => array(
					'css' => array(
						array(
							'path'     => 'components/card.css',
							'relative' => 'card.css',
							'version'  => 'component-css',
						),
					),
					'js'  => array(
						array(
							'path'     => 'components/card.js',
							'relative' => 'card.js',
							'version'  => 'component-js',
							'module'   => true,
						),
					),
				),
				'blocks'     => array(
					'emulsify/hero' => array(
						'css' => array(
							array(
								'path'     => 'components/blocks/hero.css',
								'relative' => 'blocks/hero.css',
								'version'  => 'block-css',
							),
						),
					),
				),
				'editor'     => array(
					'css' => array(
						array(
							'path'    => 'global/editor/editor.css',
							'version' => 'editor-css',
						),
					),
					'js'  => array(
						array(
							'path'         => 'global/editor/editor.js',
							'version'      => 'editor-js',
							'dependencies' => array( 'wp-edit-post' ),
						),
					),
				),
			),
		)
	);

	( new Emulsify\Theme\Runtime\Assets() )->styles();
	( new Emulsify\Theme\Runtime\Assets() )->frontend_scripts();
	( new Emulsify\Theme\Editor\Enhancements() )->editor_assets();

	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-manifest'] )
		&& 'manifest-css' === $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-manifest']['ver'],
		'Valid manifest should enqueue global CSS with explicit version metadata.'
	);
	emulsify_asset_manifest_smoke_assert(
		in_array( 'wp-block-library', $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-manifest']['deps'], true ),
		'Valid manifest should preserve style dependencies.'
	);
	emulsify_asset_manifest_smoke_assert(
		! isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-scanner-only'] ),
		'Declared manifest scopes should avoid recursive scanner-only assets.'
	);
	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-component-card'] )
		&& ! isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-component-blocks-hero'] ),
		'Valid manifest should enqueue broad component CSS without auto-loading block-specific CSS records.'
	);
	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_scripts']['emulsify-global-app'] )
		&& 'manifest-js' === $GLOBALS['emulsify_asset_manifest_smoke_scripts']['emulsify-global-app']['ver']
		&& in_array( 'jquery', $GLOBALS['emulsify_asset_manifest_smoke_scripts']['emulsify-global-app']['deps'], true ),
		'Manifest global JS should support classic scripts, hashes, and dependencies.'
	);
	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_script_modules']['emulsify-component-card'] ),
		'Manifest component JS should support script modules.'
	);
	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-editor-global-editor-editor'] )
		&& isset( $GLOBALS['emulsify_asset_manifest_smoke_scripts']['emulsify-editor-global-editor-editor'] )
		&& in_array( 'wp-edit-post', $GLOBALS['emulsify_asset_manifest_smoke_scripts']['emulsify-editor-global-editor-editor']['deps'], true ),
		'Manifest editor assets should enqueue with editor dependencies.'
	);

	$child  = $work_root . '/invalid-child';
	$parent = $work_root . '/invalid-parent';
	emulsify_asset_manifest_smoke_reset( $child, $parent );
	emulsify_asset_manifest_smoke_write( $child . '/dist/emulsify-assets.json', '{invalid' );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/fallback.css', '.fallback{}' );
	emulsify_asset_manifest_smoke_write( $parent . '/dist/global/parent.css', '.parent{}' );
	emulsify_asset_manifest_smoke_write_json(
		$parent . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'global' => array(
					'css' => array(
						array( 'path' => 'global/parent.css' ),
						array( 'path' => 'global/missing.css' ),
					),
				),
			),
		)
	);

	( new Emulsify\Theme\Runtime\Assets() )->styles();

	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-global-parent'] )
		&& ! isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-fallback'] ),
		'Invalid manifest should fall back to the valid parent manifest before recursive discovery.'
	);

	$debug_contents = is_readable( $debug_log ) ? file_get_contents( $debug_log ) : '';

	emulsify_asset_manifest_smoke_assert(
		is_string( $debug_contents )
		&& false !== strpos( $debug_contents, '[Emulsify] Asset manifest contains invalid JSON: ' . $child . '/dist/emulsify-assets.json' ),
		'WP_DEBUG should log malformed asset manifest candidates.'
	);
	emulsify_asset_manifest_smoke_assert(
		is_string( $debug_contents )
		&& false !== strpos( $debug_contents, '[Emulsify] Asset not readable: ' . $parent . '/dist/global/missing.css' ),
		'WP_DEBUG should log missing manifest asset files.'
	);

	$child  = $work_root . '/priority-child';
	$parent = $work_root . '/priority-parent';
	emulsify_asset_manifest_smoke_reset( $child, $parent );
	emulsify_asset_manifest_smoke_write( $child . '/dist/global/child.css', '.child{}' );
	emulsify_asset_manifest_smoke_write( $parent . '/dist/global/parent.css', '.parent{}' );
	emulsify_asset_manifest_smoke_write_json(
		$child . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'global' => array(
					'css' => array( array( 'path' => 'global/child.css' ) ),
				),
			),
		)
	);
	emulsify_asset_manifest_smoke_write_json(
		$parent . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'global' => array(
					'css' => array( array( 'path' => 'global/parent.css' ) ),
				),
			),
		)
	);

	( new Emulsify\Theme\Runtime\Assets() )->styles();

	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-global-child'] )
		&& ! isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-global-parent'] ),
		'Child manifest should take priority over parent manifest.'
	);

	$child  = $work_root . '/filter-child';
	$parent = $work_root . '/filter-parent';
	emulsify_asset_manifest_smoke_reset( $child, $parent );
	emulsify_asset_manifest_smoke_write( $child . '/dist/alt/filter.css', '.filter{}' );
	emulsify_asset_manifest_smoke_write_json(
		$child . '/dist/custom-assets.json',
		array(
			'assets' => array(
				'global' => array(
					'css' => array( array( 'path' => 'alt/filter.css' ) ),
				),
			),
		)
	);

	add_filter(
		'emulsify_theme_asset_manifest_path',
		static function ( string $path ): string {
			unset( $path );

			return 'dist/custom-assets.json';
		}
	);
	add_filter(
		'emulsify_theme_asset_manifest_data',
		static function ( array $data ): array {
			$data['assets']['global']['css'][0]['version'] = 'filtered-manifest';

			return $data;
		}
	);
	add_filter(
		'emulsify_theme_asset_files',
		static function ( array $assets, string $directory ): array {
			if ( 'dist/global' === $directory && isset( $assets[0]['manifest_path'] ) ) {
				$assets[0]['version'] = 'filtered-final';
			}

			return $assets;
		},
		10,
		2
	);

	( new Emulsify\Theme\Runtime\Assets() )->styles();

	emulsify_asset_manifest_smoke_assert(
		isset( $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-alt-filter'] )
		&& 'filtered-final' === $GLOBALS['emulsify_asset_manifest_smoke_styles']['emulsify-global-alt-filter']['ver'],
		'Manifest path, parsed data, and final asset record filters should apply before enqueue.'
	);

	echo "Asset manifest smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_asset_manifest_smoke_remove( $work_root );
}
