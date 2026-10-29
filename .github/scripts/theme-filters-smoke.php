<?php
/**
 * Smoke checks for parent theme runtime filters.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$GLOBALS['emulsify_filter_smoke_filters']       = array();
$GLOBALS['emulsify_filter_smoke_styles']        = array();
$GLOBALS['emulsify_filter_smoke_theme_support'] = array();
$GLOBALS['emulsify_filter_smoke_image_sizes']   = array();
$GLOBALS['emulsify_filter_smoke_acf_blocks']    = array();
$GLOBALS['emulsify_filter_smoke_native_blocks'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_filter_smoke_filters'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_filter_smoke_filters'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_filter_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_filter_smoke_filters'][ $hook ] as $callbacks ) {
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
		return $GLOBALS['emulsify_filter_smoke_child'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_filter_smoke_parent'];
	}
}

if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri(): string {
		return 'https://example.test/wp-content/themes/child';
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/wp-content/themes/parent';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src, array $deps = array(), $version = false ): void {
		$GLOBALS['emulsify_filter_smoke_styles'][ $handle ] = array(
			'src'     => $src,
			'version' => $version,
		);
	}
}

if ( ! function_exists( 'load_theme_textdomain' ) ) {
	function load_theme_textdomain( string $domain, string $path ): bool {
		$GLOBALS['emulsify_filter_smoke_textdomain'] = compact( 'domain', 'path' );

		return true;
	}
}

if ( ! function_exists( 'add_theme_support' ) ) {
	function add_theme_support( string $feature, $args = true ): void {
		$GLOBALS['emulsify_filter_smoke_theme_support'][ $feature ] = $args;
	}
}

if ( ! function_exists( 'add_image_size' ) ) {
	function add_image_size( string $name, int $width = 0, int $height = 0, $crop = false ): void {
		$GLOBALS['emulsify_filter_smoke_image_sizes'][ $name ] = compact( 'width', 'height', 'crop' );
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		return new class() {
			public function get( string $key ) {
				$values = array(
					'Name'       => 'Smoke Child',
					'Version'    => '1.0.0',
					'TextDomain' => 'smoke-child',
				);

				return $values[ $key ] ?? '';
			}

			public function parent() {
				return null;
			}
		};
	}
}

if ( ! function_exists( 'get_body_class' ) ) {
	function get_body_class(): array {
		$GLOBALS['emulsify_filter_body_calls'] = ( $GLOBALS['emulsify_filter_body_calls'] ?? 0 ) + 1;
		return array( 'filter-smoke' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}

foreach ( array( 'is_404', 'is_archive', 'is_front_page', 'is_home', 'is_page', 'is_search', 'is_single', 'is_singular', 'is_user_logged_in' ) as $function ) {
	if ( ! function_exists( $function ) ) {
		eval( 'function ' . $function . '(): bool { return false; }' );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url(): string {
		return 'https://example.test/wp-login.php';
	}
}

if ( ! function_exists( 'wp_logout_url' ) ) {
	function wp_logout_url(): string {
		$GLOBALS['emulsify_filter_logout_calls'] = ( $GLOBALS['emulsify_filter_logout_calls'] ?? 0 ) + 1;
		return 'https://example.test/wp-login.php?action=logout';
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url(): string {
		return 'https://example.test/wp-json/';
	}
}

if ( ! function_exists( 'get_search_query' ) ) {
	function get_search_query(): string {
		return '';
	}
}

if ( ! function_exists( 'get_theme_file_path' ) ) {
	function get_theme_file_path( string $path = '' ): string {
		return get_stylesheet_directory() . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return null;
	}
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
	function acf_register_block_type( array $args ) {
		$GLOBALS['emulsify_filter_smoke_acf_blocks'][] = $args;

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
		$GLOBALS['emulsify_filter_smoke_native_blocks'][] = $path;

		return $path;
	}
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_filter_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Writes a fixture file.
 *
 * @param string $path     File path.
 * @param string $contents File contents.
 * @return void
 */
function emulsify_filter_smoke_write( string $path, string $contents ): void {
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
function emulsify_filter_smoke_remove( string $path ): void {
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
$work_root = sys_get_temp_dir() . '/emulsify-theme-filters-' . uniqid( '', true );
$child     = $work_root . '/child-theme';
$parent    = $work_root . '/parent-theme';
$extra     = $work_root . '/extra';

$GLOBALS['emulsify_filter_smoke_child']  = $child;
$GLOBALS['emulsify_filter_smoke_parent'] = $parent;

try {
	emulsify_filter_smoke_write( $child . '/dist/global/child.css', 'body { color: black; }' );
	emulsify_filter_smoke_write( $child . '/dist/global/editor/css/index.css', '.editor-only { display: block; }' );
	emulsify_filter_smoke_write( $parent . '/dist/global/child.css', 'body { color: parent; }' );
	emulsify_filter_smoke_write( $extra . '/assets/extra.css', 'body { color: red; }' );
	emulsify_filter_smoke_write( $extra . '/twig/example.twig', 'Example' );
	emulsify_filter_smoke_write( $extra . '/components/filter-card/filter-card.component.json', '{"title":"Filter Card"}' );
	emulsify_filter_smoke_write( $extra . '/components/filter-card/filter-card.twig', '<article>Filter card</article>' );
	emulsify_filter_smoke_write( $extra . '/native/block.json', '{"name":"emulsify/filter-native"}' );

	require_once $repo_root . '/includes/Support/AttributeBag.php';
	require_once $repo_root . '/includes/Support/FileDiscovery.php';
	require_once $repo_root . '/includes/Support/AssetRecord.php';
	require_once $repo_root . '/includes/Support/AssetEnqueuer.php';
	require_once $repo_root . '/includes/Support/Diagnostics.php';
	require_once $repo_root . '/includes/Support/AssetManifest.php';
	require_once $repo_root . '/includes/Support/ProjectConfig.php';
	require_once $repo_root . '/includes/Runtime/Assets.php';
	require_once $repo_root . '/includes/Support/LogoutUrl.php';
	require_once $repo_root . '/includes/Runtime/Context.php';
	require_once $repo_root . '/includes/Runtime/Setup.php';
	require_once $repo_root . '/includes/Runtime/Twig.php';
	require_once $repo_root . '/includes/Blocks/ComponentLocator.php';
	require_once $repo_root . '/includes/Blocks/AcfBlocks.php';
	require_once $repo_root . '/includes/Blocks/NativeBlocks.php';

	add_filter(
		'emulsify_theme_asset_directories',
		static function ( array $directories, string $directory ) use ( $extra ): array {
			if ( 'dist/global' === $directory ) {
				$directories[] = array(
					'path'     => $extra . '/assets',
					'priority' => 0,
					'source'   => 'smoke',
					'uri'      => 'https://example.test/extra/assets',
				);
			}

			return $directories;
		},
		10,
		2
	);

	add_filter(
		'emulsify_theme_asset_files',
		static function ( array $assets, string $directory, array $extensions ): array {
			if ( 'dist/global' === $directory && in_array( 'css', $extensions, true ) ) {
				$assets[] = array(
					'path'     => __FILE__,
					'priority' => 0,
					'relative' => 'filtered.css',
					'uri'      => 'https://example.test/filtered.css',
					'version'  => 'filtered',
				);
			}

			return $assets;
		},
		10,
		3
	);

	add_filter(
		'emulsify_theme_twig_namespaces',
		static function ( array $namespaces ) use ( $extra ): array {
			$namespaces[] = array(
				'namespace' => 'project',
				'path'      => $extra . '/twig',
			);

			return $namespaces;
		}
	);

	add_filter(
		'emulsify_theme_context',
		static function ( array $context ): array {
			$context['project_flag'] = true;

			return $context;
		}
	);

	add_filter(
		'emulsify_theme_setup_options',
		static function ( array $options ): array {
			$options['theme_supports']['custom-spacing'] = true;
			$options['image_sizes']['smoke-card']        = array(
				'width'  => 320,
				'height' => 180,
				'crop'   => true,
			);

			return $options;
		}
	);

	add_filter(
		'emulsify_theme_component_roots',
		static function ( array $roots ) use ( $extra ): array {
			$roots[] = array(
				'path'   => $extra . '/components',
				'source' => 'smoke',
			);

			return $roots;
		}
	);

	add_filter(
		'emulsify_theme_acf_block_metadata',
		static function ( array $metadata ): array {
			$metadata['title'] = 'Filtered Card';

			return $metadata;
		}
	);

	add_filter(
		'emulsify_theme_acf_block_args',
		static function ( array $args ): array {
			$args['name']     = 'emulsify/filtered-card';
			$args['category'] = 'design';

			return $args;
		}
	);

	add_filter(
		'emulsify_theme_native_block_directories',
		static function ( array $directories ) use ( $extra ): array {
			$directories[] = array(
				'path'          => $extra . '/native',
				'relative'      => 'native',
				'source'        => 'smoke',
				'metadata_path' => $extra . '/native/block.json',
				'name'          => 'emulsify/filter-native',
			);

			return $directories;
		}
	);

	( new Emulsify\Theme\Runtime\Assets() )->styles();

	emulsify_filter_smoke_assert(
		'https://example.test/wp-content/themes/child/dist/global/child.css' === $GLOBALS['emulsify_filter_smoke_styles']['emulsify-global-child']['src'],
		'Asset discovery should keep child roots before parent fallback roots for duplicate relative paths.'
	);
	emulsify_filter_smoke_assert(
		isset( $GLOBALS['emulsify_filter_smoke_styles']['emulsify-global-extra'] ),
		'Asset directory filter should add an extra CSS asset root.'
	);
	emulsify_filter_smoke_assert(
		isset( $GLOBALS['emulsify_filter_smoke_styles']['emulsify-global-filtered'] ),
		'Asset files filter should add a filtered CSS asset.'
	);
	emulsify_filter_smoke_assert(
		empty(
			array_filter(
				array_keys( $GLOBALS['emulsify_filter_smoke_styles'] ),
				static function ( string $handle ): bool {
					return false !== strpos( $handle, 'editor' );
				}
			)
		),
		'Generic asset loading should skip editor-only global assets.'
	);

	$loader = new class() {
		public $paths = array();

		public function addPath( string $path, string $namespace ): void {
			$this->paths[] = compact( 'path', 'namespace' );
		}
	};

	( new Emulsify\Theme\Runtime\Twig() )->loader_paths( $loader );

	emulsify_filter_smoke_assert(
		in_array( array( 'path' => $extra . '/twig', 'namespace' => 'project' ), $loader->paths, true ),
		'Twig namespace filter should add a project namespace.'
	);

	$GLOBALS['emulsify_filter_body_calls']   = 0;
	$GLOBALS['emulsify_filter_logout_calls'] = 0;
	$context = ( new Emulsify\Theme\Runtime\Context() )->add( array() );

	emulsify_filter_smoke_assert( 1 === $GLOBALS['emulsify_filter_body_calls'], 'Global context must compute body classes only once.' );
	emulsify_filter_smoke_assert( 0 === $GLOBALS['emulsify_filter_logout_calls'], 'Unused logout URLs must not create a nonce.' );
	emulsify_filter_smoke_assert( 'filter-smoke' === $context['body_class'] && 'filter-smoke' === $context['wp']['body_class'], 'Both body-class context keys must remain available.' );
	$restored_url = unserialize( serialize( $context['wp']['logout_url'] ) );
	emulsify_filter_smoke_assert( 0 === $GLOBALS['emulsify_filter_logout_calls'], 'Serializing unused context must not force nonce creation.' );
	emulsify_filter_smoke_assert( 'https://example.test/wp-login.php?action=logout' === (string) $restored_url, 'PHP context caches must retain a usable logout URL.' );
	$GLOBALS['emulsify_filter_logout_calls'] = 0;
	$custom_context = ( new Emulsify\Theme\Runtime\Context() )->add( array( 'body_class' => 'project-body' ) );
	emulsify_filter_smoke_assert( 'project-body' === $custom_context['body_class'] && 'filter-smoke' === $custom_context['wp']['body_class'], 'An inherited body class must not replace the native WordPress helper value.' );
	require_once $repo_root . '/vendor/autoload.php';
	$twig = new \Twig\Environment( new \Twig\Loader\ArrayLoader( array( 'logout' => '{{ wp.logout_url }}|{{ wp.logout_url }}' ) ), array( 'autoescape' => 'html' ) );
	emulsify_filter_smoke_assert( 'https://example.test/wp-login.php?action=logout|https://example.test/wp-login.php?action=logout' === $twig->render( 'logout', $context ), 'Twig must render the lazy logout URL as text.' );
	emulsify_filter_smoke_assert( 1 === $GLOBALS['emulsify_filter_logout_calls'], 'Repeated logout URL reads must compute its nonce only once.' );
	emulsify_filter_smoke_assert( 'https://example.test/wp-login.php?action=logout' === json_decode( json_encode( $context['wp']['logout_url'] ), true ), 'JSON consumers must receive the logout URL text.' );

	emulsify_filter_smoke_assert(
		true === $context['project_flag'],
		'Context filter should add project context values.'
	);

	( new Emulsify\Theme\Runtime\Setup() )->theme_supports();

	emulsify_filter_smoke_assert(
		array_key_exists( 'custom-spacing', $GLOBALS['emulsify_filter_smoke_theme_support'] ),
		'Setup options filter should add custom theme support.'
	);
	emulsify_filter_smoke_assert(
		array_key_exists( 'smoke-card', $GLOBALS['emulsify_filter_smoke_image_sizes'] ),
		'Setup options filter should add custom image sizes.'
	);

	$locator = new Emulsify\Theme\Blocks\ComponentLocator();

	emulsify_filter_smoke_assert(
		in_array( 'filter-card', array_column( $locator->acf_components(), 'relative' ), true ),
		'Component roots filter should add an extra component discovery root.'
	);

	( new Emulsify\Theme\Blocks\AcfBlocks( new Emulsify\Theme\Blocks\ComponentLocator() ) )->register_blocks();

	emulsify_filter_smoke_assert(
		'emulsify-filtered-card' === $GLOBALS['emulsify_filter_smoke_acf_blocks'][0]['name'],
		'ACF block args filter should alter and normalize the final block name.'
	);
	emulsify_filter_smoke_assert(
		'Filtered Card' === $GLOBALS['emulsify_filter_smoke_acf_blocks'][0]['title'],
		'ACF block metadata filter should alter metadata before defaults are merged.'
	);

	( new Emulsify\Theme\Blocks\NativeBlocks( new Emulsify\Theme\Blocks\ComponentLocator() ) )->register_blocks();

	emulsify_filter_smoke_assert(
		in_array( $extra . '/native', $GLOBALS['emulsify_filter_smoke_native_blocks'], true ),
		'Native block directories filter should add a native block directory.'
	);

	echo "Theme filter smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_filter_smoke_remove( $work_root );
}
