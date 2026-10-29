<?php
/**
 * Smoke checks for block-scoped asset loading.
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

$GLOBALS['emulsify_block_assets_smoke_hooks']          = array();
$GLOBALS['emulsify_block_assets_smoke_acf_blocks']     = array();
$GLOBALS['emulsify_block_assets_smoke_native_blocks']  = array();
$GLOBALS['emulsify_block_assets_smoke_styles']         = array();
$GLOBALS['emulsify_block_assets_smoke_scripts']        = array();
$GLOBALS['emulsify_block_assets_smoke_script_modules'] = array();
$GLOBALS['emulsify_block_assets_smoke_is_admin']       = false;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_block_assets_smoke_hooks'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_block_assets_smoke_hooks'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_block_assets_smoke_hooks'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_block_assets_smoke_hooks'][ $hook ] as $callbacks ) {
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
		return $GLOBALS['emulsify_block_assets_smoke_child'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_block_assets_smoke_parent'];
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

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return trim( preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $key ) ), '-' );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return ! empty( $GLOBALS['emulsify_block_assets_smoke_is_admin'] );
	}
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
	function acf_register_block_type( array $args ) {
		$GLOBALS['emulsify_block_assets_smoke_acf_blocks'][ $args['name'] ] = $args;

		return $args;
	}
}

if ( ! function_exists( 'acf_get_block_type' ) ) {
	function acf_get_block_type( string $name ) {
		return null;
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $path ) {
		$GLOBALS['emulsify_block_assets_smoke_native_blocks'][] = $path;

		return $path;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src, array $deps = array(), $ver = false ): void {
		$GLOBALS['emulsify_block_assets_smoke_styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src, array $deps = array(), $ver = false, $args = array() ): void {
		$GLOBALS['emulsify_block_assets_smoke_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );
	}
}

if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
	function wp_enqueue_script_module( string $id, string $src, array $deps = array(), $version = false ): void {
		$GLOBALS['emulsify_block_assets_smoke_script_modules'][ $id ] = compact( 'src', 'deps', 'version' );
	}
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_block_assets_smoke_assert( bool $condition, string $message ): void {
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
function emulsify_block_assets_smoke_write( string $path, string $content ): void {
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
function emulsify_block_assets_smoke_write_json( string $path, array $data ): void {
	emulsify_block_assets_smoke_write( $path, (string) json_encode( $data ) );
}

/**
 * Resets captured enqueue calls.
 *
 * @return void
 */
function emulsify_block_assets_smoke_reset_enqueues(): void {
	$GLOBALS['emulsify_block_assets_smoke_styles']         = array();
	$GLOBALS['emulsify_block_assets_smoke_scripts']        = array();
	$GLOBALS['emulsify_block_assets_smoke_script_modules'] = array();
}

/**
 * Recursively removes a path.
 *
 * @param string $path Path to remove.
 * @return void
 */
function emulsify_block_assets_smoke_remove( string $path ): void {
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
$work_root = sys_get_temp_dir() . '/emulsify-block-assets-' . uniqid( '', true );
$child     = $work_root . '/child-theme';
$parent    = $work_root . '/parent-theme';
$debug_log = $work_root . '/debug.log';

ini_set( 'log_errors', '1' );
ini_set( 'error_log', $debug_log );

$GLOBALS['emulsify_block_assets_smoke_child']  = $child;
$GLOBALS['emulsify_block_assets_smoke_parent'] = $parent;

try {
	emulsify_block_assets_smoke_write_json(
		$child . '/dist/components/card/card.component.json',
		array(
			'title'  => 'Card',
			'assets' => array(
				'frontend' => array(
					'css' => array(
						array(
							'path'    => 'card.css',
							'version' => 'metadata-css',
						),
						array(
							'path' => 'missing.css',
						),
					),
					'js'  => array(
						array(
							'path'         => 'card.js',
							'version'      => 'metadata-js',
							'dependencies' => array( 'jquery' ),
							'module'       => false,
						),
					),
				),
				'editor'   => array(
					'css' => array(
						array(
							'path'    => 'editor.css',
							'version' => 'metadata-editor-css',
						),
					),
					'js'  => array(
						array(
							'path'         => 'editor.js',
							'version'      => 'metadata-editor-js',
							'dependencies' => array( 'wp-blocks' ),
						),
					),
				),
			),
		)
	);
	emulsify_block_assets_smoke_write( $child . '/dist/components/card/card.twig', '<article>Card</article>' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/card/card.css', '.card{}' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/card/card.js', 'window.card = true;' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/card/editor.css', '.editor{}' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/card/editor.js', 'window.editorCard = true;' );

	emulsify_block_assets_smoke_write_json(
		$child . '/dist/components/hero/hero.component.json',
		array(
			'title'  => 'Hero',
			'assets' => array(
				'frontend' => array(
					'css' => array(
						array(
							'path'    => 'metadata.css',
							'version' => 'metadata-hero-css',
						),
					),
				),
			),
		)
	);
	emulsify_block_assets_smoke_write( $child . '/dist/components/hero/hero.twig', '<section>Hero</section>' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/hero/metadata.css', '.metadata-hero{}' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/hero/manifest.css', '.manifest-hero{}' );

	emulsify_block_assets_smoke_write_json(
		$child . '/dist/components/plain/plain.component.json',
		array(
			'title' => 'Plain',
		)
	);
	emulsify_block_assets_smoke_write( $child . '/dist/components/plain/plain.twig', '<p>Plain</p>' );

	emulsify_block_assets_smoke_write_json(
		$child . '/dist/components/native/block.json',
		array(
			'name'         => 'emulsify/native',
			'style'        => 'file:./style.css',
			'viewScript'   => 'file:./view.js',
			'editorScript' => 'file:./editor.js',
		)
	);
	emulsify_block_assets_smoke_write( $child . '/dist/components/native/style.css', '.native{}' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/native/view.js', 'window.nativeView = true;' );
	emulsify_block_assets_smoke_write( $child . '/dist/components/native/editor.js', 'window.nativeEditor = true;' );

	emulsify_block_assets_smoke_write_json(
		$child . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'blocks' => array(
					'acf/emulsify-hero' => array(
						'frontend' => array(
							'css' => array(
								array(
									'path'     => 'components/hero/manifest.css',
									'relative' => 'hero-manifest.css',
									'version'  => 'manifest-hero-css',
								),
							),
						),
					),
				),
			),
		)
	);

	require_once $repo_root . '/includes/Support/FileDiscovery.php';
	require_once $repo_root . '/includes/Support/AssetRecord.php';
	require_once $repo_root . '/includes/Support/AssetEnqueuer.php';
	require_once $repo_root . '/includes/Support/Diagnostics.php';
	require_once $repo_root . '/includes/Support/AssetManifest.php';
	require_once $repo_root . '/includes/Runtime/Assets.php';
	require_once $repo_root . '/includes/Blocks/ComponentLocator.php';
	require_once $repo_root . '/includes/Blocks/AcfBlocks.php';
	require_once $repo_root . '/includes/Blocks/NativeBlocks.php';

	add_filter(
		'emulsify_theme_acf_block_asset_records',
		static function ( array $records, array $component ): array {
			if ( 'card' === $component['relative'] && isset( $records['frontend']['css'][0] ) ) {
				$records['frontend']['css'][0]['version'] = 'filtered-card-css';
			}

			return $records;
		},
		10,
		2
	);

	$acf_blocks = new Emulsify\Theme\Blocks\AcfBlocks( new Emulsify\Theme\Blocks\ComponentLocator() );
	$acf_blocks->register_blocks();

	$debug_contents = is_readable( $debug_log ) ? file_get_contents( $debug_log ) : '';

	emulsify_block_assets_smoke_assert(
		is_string( $debug_contents )
		&& false !== strpos( $debug_contents, '[Emulsify] Asset not readable: ' . $child . '/dist/components/card/missing.css' ),
		'WP_DEBUG should log missing ACF component metadata assets.'
	);

	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-card']['enqueue_assets'] )
		&& is_callable( $GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-card']['enqueue_assets'] ),
		'ACF/Twig blocks with component metadata assets should register an enqueue_assets callback.'
	);
	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-hero']['enqueue_assets'] )
		&& is_callable( $GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-hero']['enqueue_assets'] ),
		'ACF/Twig blocks with manifest assets should register an enqueue_assets callback.'
	);
	emulsify_block_assets_smoke_assert(
		empty( $GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-plain']['enqueue_assets'] ),
		'ACF/Twig blocks without manifest or metadata assets should keep global component loading as the fallback.'
	);

	$GLOBALS['emulsify_block_assets_smoke_is_admin'] = false;
	emulsify_block_assets_smoke_reset_enqueues();
	$GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-card']['enqueue_assets']();

	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-frontend-emulsify-card-card'] )
		&& 'filtered-card-css' === $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-frontend-emulsify-card-card']['ver'],
		'ACF/Twig metadata frontend CSS should enqueue only when the block callback runs and should be filterable.'
	);
	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_scripts']['emulsify-acf-frontend-emulsify-card-card'] )
		&& in_array( 'jquery', $GLOBALS['emulsify_block_assets_smoke_scripts']['emulsify-acf-frontend-emulsify-card-card']['deps'], true ),
		'ACF/Twig metadata frontend JS should enqueue with dependencies.'
	);
	emulsify_block_assets_smoke_assert(
		empty( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-editor-emulsify-card-editor'] ),
		'ACF/Twig editor assets should not enqueue on frontend block renders.'
	);

	$GLOBALS['emulsify_block_assets_smoke_is_admin'] = true;
	emulsify_block_assets_smoke_reset_enqueues();
	$GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-card']['enqueue_assets']();

	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-editor-emulsify-card-editor'] )
		&& isset( $GLOBALS['emulsify_block_assets_smoke_script_modules']['emulsify-acf-editor-emulsify-card-editor'] )
		&& in_array( 'wp-blocks', $GLOBALS['emulsify_block_assets_smoke_script_modules']['emulsify-acf-editor-emulsify-card-editor']['deps'], true ),
		'ACF/Twig metadata editor assets should enqueue in admin/editor contexts.'
	);

	$GLOBALS['emulsify_block_assets_smoke_is_admin'] = false;
	emulsify_block_assets_smoke_reset_enqueues();
	$GLOBALS['emulsify_block_assets_smoke_acf_blocks']['emulsify-hero']['enqueue_assets']();

	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-frontend-emulsify-hero-hero-manifest'] )
		&& 'manifest-hero-css' === $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-frontend-emulsify-hero-hero-manifest']['ver']
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-acf-frontend-emulsify-hero-metadata'] ),
		'Manifest block assets should take priority over component metadata assets.'
	);

	emulsify_block_assets_smoke_reset_enqueues();
	( new Emulsify\Theme\Blocks\NativeBlocks( new Emulsify\Theme\Blocks\ComponentLocator() ) )->register_blocks();

	emulsify_block_assets_smoke_assert(
		in_array( $child . '/dist/components/native', $GLOBALS['emulsify_block_assets_smoke_native_blocks'], true ),
		'Native blocks with block.json should be registered through register_block_type().'
	);
	emulsify_block_assets_smoke_assert(
		empty( $GLOBALS['emulsify_block_assets_smoke_styles'] )
		&& empty( $GLOBALS['emulsify_block_assets_smoke_scripts'] )
		&& empty( $GLOBALS['emulsify_block_assets_smoke_script_modules'] ),
		'Native block.json asset fields should be left for WordPress to enqueue.'
	);

	$GLOBALS['emulsify_block_assets_smoke_child']  = $work_root . '/scanner-child';
	$GLOBALS['emulsify_block_assets_smoke_parent'] = $work_root . '/scanner-parent';
	emulsify_block_assets_smoke_reset_enqueues();
	emulsify_block_assets_smoke_write_json(
		$GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/card.component.json',
		array(
			'title'  => 'Scanner Card',
			'assets' => array(
				'frontend' => array(
					'css' => array( array( 'path' => 'card.css' ), array( 'path' => 'styles/./nested.css' ) ),
					'js'  => array( array( 'path' => 'scripts//nested.js' ) ),
				),
				'editor'   => array(
					'css' => array( array( 'path' => 'editor/./editor.css' ) ),
					'js'  => array( array( 'path' => 'editor//editor.js' ) ),
				),
			),
		)
	);
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/card.css', '.card{}' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/styles/nested.css', '.nested{}' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/scripts/nested.js', 'window.nested = true;' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/editor/editor.css', '.editor{}' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/editor/editor.js', 'window.editor = true;' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/plain/plain.css', '.plain{}' );
	$filtered_components = $work_root . '/filtered-components';
	emulsify_block_assets_smoke_write_json(
		$filtered_components . '/card/card.component.json',
		array( 'assets' => array( 'css' => array( 'styles/filtered.css' ) ) )
	);
	emulsify_block_assets_smoke_write( $filtered_components . '/card/styles/filtered.css', '.filtered{}' );
	add_filter(
		'emulsify_theme_asset_directories',
		static function ( array $roots, string $directory ) use ( $filtered_components ): array {
			if ( 'dist/components' === $directory ) {
				$roots[] = array( 'path' => $filtered_components, 'uri' => 'https://example.test/filtered-components' );
			}

			return $roots;
		},
		10,
		2
	);

	$scanner_assets = new Emulsify\Theme\Runtime\Assets();
	$scanner_assets->styles();
	$scanner_assets->frontend_scripts();

	emulsify_block_assets_smoke_assert(
		! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-card-styles-nested'] )
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-card-editor-editor'] )
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_scripts']['emulsify-component-card-scripts-nested'] )
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_script_modules']['emulsify-component-card-scripts-nested'] )
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_scripts']['emulsify-component-card-editor-editor'] )
		&& ! isset( $GLOBALS['emulsify_block_assets_smoke_script_modules']['emulsify-component-card-editor-editor'] ),
		'Global component scanning must skip frontend and editor-only subfolder assets, including internal dots and repeated separators, without a manifest.'
	);
	emulsify_block_assets_smoke_assert(
		! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-card-styles-filtered'] ),
		'Scoped metadata in filtered asset-only roots must stay scoped without requiring a component-roots filter.'
	);

	emulsify_block_assets_smoke_assert(
		! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-card-card'] ),
		'Global component scanning should skip assets declared as block-scoped component metadata.'
	);
	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-plain-plain'] ),
		'Global component scanning should remain the fallback when no scoped metadata exists.'
	);

	$GLOBALS['emulsify_block_assets_smoke_child']  = $work_root . '/manifest-scanner-child';
	$GLOBALS['emulsify_block_assets_smoke_parent'] = $work_root . '/manifest-scanner-parent';
	emulsify_block_assets_smoke_reset_enqueues();
	emulsify_block_assets_smoke_write_json(
		$GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/emulsify-assets.json',
		array(
			'assets' => array(
				'blocks' => array(
					'acf/emulsify-card' => array(
						'frontend' => array(
							'css' => array( array( 'path' => 'components/card/card.css' ) ),
						),
					),
				),
			),
		)
	);
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/card/card.css', '.card{}' );
	emulsify_block_assets_smoke_write( $GLOBALS['emulsify_block_assets_smoke_child'] . '/dist/components/plain/plain.css', '.plain{}' );

	( new Emulsify\Theme\Runtime\Assets() )->styles();

	emulsify_block_assets_smoke_assert(
		! isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-card-card'] ),
		'Block-scoped manifest assets should not be loaded by the global component scanner.'
	);
	emulsify_block_assets_smoke_assert(
		isset( $GLOBALS['emulsify_block_assets_smoke_styles']['emulsify-component-plain-plain'] ),
		'Block-only manifests should still allow scanner fallback for undeclared component files.'
	);

	echo "Block scoped asset smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_block_assets_smoke_remove( $work_root );
}
