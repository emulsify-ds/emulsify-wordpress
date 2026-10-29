<?php
/**
 * Smoke checks for JSON block pattern registration.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$GLOBALS['emulsify_pattern_smoke_actions']    = array();
$GLOBALS['emulsify_pattern_smoke_filters']    = array();
$GLOBALS['emulsify_pattern_smoke_patterns']   = array();
$GLOBALS['emulsify_pattern_smoke_categories'] = array();
$GLOBALS['emulsify_pattern_smoke_registrations'] = array();

final class WP_Block_Patterns_Registry {
	public static function get_instance(): self {
		static $instance;
		return $instance ??= new self();
	}
	public function is_registered( string $name ): bool {
		return isset( $GLOBALS['emulsify_pattern_smoke_patterns'][ $name ] );
	}
}

final class WP_Block_Pattern_Categories_Registry {
	public static function get_instance(): self {
		static $instance;
		return $instance ??= new self();
	}
	public function is_registered( string $name ): bool {
		return isset( $GLOBALS['emulsify_pattern_smoke_categories'][ $name ] );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_pattern_smoke_actions'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_pattern_smoke_actions'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$arguments ): void {
		if ( empty( $GLOBALS['emulsify_pattern_smoke_actions'][ $hook ] ) ) {
			return;
		}

		foreach ( $GLOBALS['emulsify_pattern_smoke_actions'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				call_user_func_array(
					$callback['callback'],
					array_slice( $arguments, 0, $callback['accepted_args'] )
				);
			}
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_pattern_smoke_filters'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_pattern_smoke_filters'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_pattern_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_pattern_smoke_filters'][ $hook ] as $callbacks ) {
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
		return $GLOBALS['emulsify_pattern_smoke_child'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_pattern_smoke_parent'];
	}
}

if ( ! function_exists( 'register_block_pattern' ) ) {
	function register_block_pattern( string $name, array $args ): bool {
		$GLOBALS['emulsify_pattern_smoke_patterns'][ $name ] = $args;
		$GLOBALS['emulsify_pattern_smoke_registrations'][] = 'pattern:' . $name;

		return true;
	}
}

if ( ! function_exists( 'register_block_pattern_category' ) ) {
	function register_block_pattern_category( string $name, array $args ): bool {
		$GLOBALS['emulsify_pattern_smoke_categories'][ $name ] = $args;
		$GLOBALS['emulsify_pattern_smoke_registrations'][] = 'category:' . $name;

		return true;
	}
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_pattern_smoke_assert( bool $condition, string $message ): void {
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
function emulsify_pattern_smoke_write( string $path, string $contents ): void {
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
function emulsify_pattern_smoke_remove( string $path ): void {
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
$work_root = sys_get_temp_dir() . '/emulsify-pattern-registry-' . uniqid( '', true );
$child     = $work_root . '/child-theme';
$parent    = $work_root . '/parent-theme';
$extra     = $work_root . '/extra-patterns';

$GLOBALS['emulsify_pattern_smoke_child']  = $child;
$GLOBALS['emulsify_pattern_smoke_parent'] = $parent;

try {
	require_once $repo_root . '/includes/Support/FileDiscovery.php';
	require_once $repo_root . '/includes/Blocks/Patterns.php';

	$patterns = new Emulsify\Theme\Blocks\Patterns();
	$patterns->register();

	emulsify_pattern_smoke_assert(
		isset( $GLOBALS['emulsify_pattern_smoke_actions']['init'] ),
		'Patterns service should register on init.'
	);

	do_action( 'init' );

	emulsify_pattern_smoke_assert(
		array() === $GLOBALS['emulsify_pattern_smoke_patterns'],
		'Patterns service should no-op when no pattern directories exist.'
	);

	emulsify_pattern_smoke_write(
		$child . '/patterns/hero.json',
		(string) json_encode(
			array(
				'name'          => 'child/hero',
				'title'         => 'Child Hero',
				'description'   => 'A child pattern.',
				'categories'    => array( 'starter' ),
				'keywords'      => array( 'hero' ),
				'postTypes'     => array( 'page' ),
				'viewportWidth' => 1200,
				'content'       => '<!-- wp:paragraph --><p>Child hero</p><!-- /wp:paragraph -->',
			)
		)
	);
	emulsify_pattern_smoke_write(
		$parent . '/patterns/categories.json',
		(string) json_encode(
			array(
				'starter' => array(
					'label'       => 'Parent Starter',
					'description' => 'Parent starter patterns.',
				),
			)
		)
	);
	emulsify_pattern_smoke_write(
		$child . '/patterns/categories.json',
		(string) json_encode(
			array(
				'name'    => 'child/categories',
				'title'   => 'Category Metadata Should Not Register',
				'content' => '<!-- wp:paragraph --><p>Not a block pattern.</p><!-- /wp:paragraph -->',
				'starter' => array(
					'label' => 'Child Starter',
				),
			)
		)
	);
	emulsify_pattern_smoke_write(
		$child . '/patterns/override.json',
		(string) json_encode(
			array(
				'name'    => 'child/override',
				'title'   => 'Child Override',
				'content' => '<!-- wp:paragraph --><p>Child override</p><!-- /wp:paragraph -->',
			)
		)
	);
	emulsify_pattern_smoke_write(
		$parent . '/patterns/override.json',
		(string) json_encode(
			array(
				'name'    => 'parent/override',
				'title'   => 'Parent Override',
				'content' => '<!-- wp:paragraph --><p>Parent override</p><!-- /wp:paragraph -->',
			)
		)
	);
	emulsify_pattern_smoke_write(
		$parent . '/patterns/invalid.json',
		(string) json_encode(
			array(
				'name'  => 'parent/invalid',
				'title' => 'Invalid',
			)
		)
	);
	emulsify_pattern_smoke_write(
		$extra . '/extra.json',
		(string) json_encode(
			array(
				'name'       => 'extra/promo',
				'title'      => 'Extra Promo',
				'categories' => array( 'extra' ),
				'content'    => '<!-- wp:paragraph --><p>Extra promo</p><!-- /wp:paragraph -->',
			)
		)
	);

	add_filter(
		'emulsify_theme_pattern_directories',
		static function ( array $directories ) use ( $extra ): array {
			$directories[] = array(
				'path'   => $extra,
				'source' => 'smoke',
			);

			return $directories;
		}
	);

	add_filter(
		'emulsify_theme_pattern_data',
		static function ( array $data, array $file ): array {
			if ( 'hero.json' === $file['relative'] ) {
				$data['title'] = 'Filtered Hero';
			}

			return $data;
		},
		10,
		2
	);

	add_filter(
		'emulsify_theme_pattern_categories',
		static function ( array $categories ): array {
			$categories['starter']['description'] = 'Starter category from smoke coverage.';

			return $categories;
		}
	);

	add_filter(
		'emulsify_theme_pattern_args',
		static function ( array $args, array $data ): array {
			if ( 'extra/promo' === $args['name'] ) {
				$args['keywords'][] = 'filtered';
			}

			return $args;
		},
		10,
		2
	);

	$GLOBALS['emulsify_pattern_smoke_patterns']   = array();
	$GLOBALS['emulsify_pattern_smoke_categories'] = array();

	$patterns->register_patterns();

	emulsify_pattern_smoke_assert(
		array( 'child/hero', 'child/override', 'extra/promo' ) === array_keys( $GLOBALS['emulsify_pattern_smoke_patterns'] ),
		'Patterns service should register valid child-first and filtered JSON patterns without registering category metadata files.'
	);
	emulsify_pattern_smoke_assert(
		! isset( $GLOBALS['emulsify_pattern_smoke_patterns']['parent/override'] ),
		'Child pattern JSON files should override parent files with the same basename.'
	);
	emulsify_pattern_smoke_assert(
		'Filtered Hero' === $GLOBALS['emulsify_pattern_smoke_patterns']['child/hero']['title'],
		'Decoded pattern data filter should alter metadata before registration.'
	);
	emulsify_pattern_smoke_assert(
		1200 === $GLOBALS['emulsify_pattern_smoke_patterns']['child/hero']['viewportWidth'],
		'Patterns service should preserve viewportWidth metadata.'
	);
	emulsify_pattern_smoke_assert(
		array( 'starter', 'extra' ) === array_keys( $GLOBALS['emulsify_pattern_smoke_categories'] ),
		'Patterns service should register categories discovered from valid pattern metadata.'
	);
	emulsify_pattern_smoke_assert(
		'Child Starter' === $GLOBALS['emulsify_pattern_smoke_categories']['starter']['label'],
		'Child pattern category metadata should override parent category labels.'
	);
	emulsify_pattern_smoke_assert(
		'Starter category from smoke coverage.' === $GLOBALS['emulsify_pattern_smoke_categories']['starter']['description'],
		'Pattern categories filter should alter category registration args.'
	);
	emulsify_pattern_smoke_assert(
		'Extra' === $GLOBALS['emulsify_pattern_smoke_categories']['extra']['label'],
		'Pattern categories without metadata should fall back to a readable slug label.'
	);
	emulsify_pattern_smoke_assert(
		in_array( 'filtered', $GLOBALS['emulsify_pattern_smoke_patterns']['extra/promo']['keywords'], true ),
		'Pattern args filter should alter final registration args.'
	);

	$registrations = $GLOBALS['emulsify_pattern_smoke_registrations'];
	$patterns->register_patterns();
	emulsify_pattern_smoke_assert(
		$registrations === $GLOBALS['emulsify_pattern_smoke_registrations'],
		'Patterns and categories already in the native registries must not register twice.'
	);

	echo "Pattern registry smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_pattern_smoke_remove( $work_root );
}
