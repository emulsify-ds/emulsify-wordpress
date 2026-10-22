<?php
/**
 * Smoke checks for project machine-name Twig component references.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$repo_root = dirname( __DIR__, 2 );
$autoload  = $repo_root . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer dependencies are required for Twig project namespace smoke checks.\n" );
	exit( 1 );
}

require_once $autoload;

if ( ! class_exists( \Twig\Environment::class ) || ! class_exists( \Twig\Loader\FilesystemLoader::class ) ) {
	fwrite( STDERR, "Twig is required for Twig project namespace smoke checks.\n" );
	exit( 1 );
}

$GLOBALS['emulsify_twig_project_smoke_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['emulsify_twig_project_smoke_filters'][ $hook ][ $priority ][] = array(
			'accepted_args' => $accepted_args,
			'callback'      => $callback,
		);

		ksort( $GLOBALS['emulsify_twig_project_smoke_filters'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		if ( empty( $GLOBALS['emulsify_twig_project_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['emulsify_twig_project_smoke_filters'][ $hook ] as $callbacks ) {
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
		return $GLOBALS['emulsify_twig_project_smoke_child'];
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['emulsify_twig_project_smoke_parent'];
	}
}

require_once $repo_root . '/includes/Support/AttributeBag.php';
require_once $repo_root . '/includes/Support/FileDiscovery.php';
require_once $repo_root . '/includes/Support/ProjectConfig.php';
require_once $repo_root . '/includes/Twig/ProjectComponentLoader.php';
require_once $repo_root . '/includes/Runtime/Twig.php';

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_twig_project_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Asserts strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 * @return void
 */
function emulsify_twig_project_smoke_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf(
				"%s\nExpected: %s\nActual:   %s",
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

/**
 * Writes a fixture file.
 *
 * @param string $path     File path.
 * @param string $contents File contents.
 * @return void
 */
function emulsify_twig_project_smoke_write( string $path, string $contents ): void {
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
function emulsify_twig_project_smoke_remove( string $path ): void {
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
 * Builds a Twig environment for a child and parent theme pair.
 *
 * @param string $child  Child theme path.
 * @param string $parent Parent theme path.
 * @return \Twig\Environment Twig environment.
 */
function emulsify_twig_project_smoke_environment( string $child, string $parent ): \Twig\Environment {
	$GLOBALS['emulsify_twig_project_smoke_child']  = $child;
	$GLOBALS['emulsify_twig_project_smoke_parent'] = $parent;

	$loader = new \Twig\Loader\FilesystemLoader();
	$loader = ( new \Emulsify\Theme\Runtime\Twig() )->loader_paths( $loader );

	return new \Twig\Environment(
		$loader,
		array(
			'autoescape' => false,
			'cache'      => false,
		)
	);
}

/**
 * Renders an inline Twig template.
 *
 * @param \Twig\Environment $environment Twig environment.
 * @param string            $template    Inline Twig template.
 * @return string Rendered output.
 */
function emulsify_twig_project_smoke_render( \Twig\Environment $environment, string $template ): string {
	return $environment->createTemplate( $template )->render();
}

$work_root     = sys_get_temp_dir() . '/emulsify-twig-project-namespace-' . uniqid( '', true );
$child         = $work_root . '/child-theme';
$missing_child = $work_root . '/missing-project-child';
$invalid_child = $work_root . '/invalid-project-child';
$parent        = $work_root . '/parent-theme';
$extra         = $work_root . '/extra-components';
$failures      = array();

try {
	emulsify_twig_project_smoke_write(
		$child . '/project.emulsify.json',
		json_encode(
			array(
				'project' => array(
					'machineName' => 'whisk',
				),
				'variant' => array(
					'structureImplementations' => array(
						array(
							'name'      => 'custom',
							'directory' => 'custom-twig',
						),
						array(
							'name'      => '@unsafe',
							'directory' => '../outside',
						),
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		)
	);
	emulsify_twig_project_smoke_write( $child . '/src/components/example-card/example-card.twig', 'Child example card' );
	emulsify_twig_project_smoke_write( $child . '/src/components/badge.twig', 'Child badge' );
	emulsify_twig_project_smoke_write( $child . '/src/components/ui/heading/heading.twig', 'Child heading' );
	emulsify_twig_project_smoke_write( $child . '/components/link/link.twig', 'Child legacy link' );
	emulsify_twig_project_smoke_write( $child . '/custom-twig/teaser.twig', 'Configured namespace teaser' );
	emulsify_twig_project_smoke_write( $parent . '/src/components/example-card/example-card.twig', 'Parent example card' );
	emulsify_twig_project_smoke_write( $extra . '/filtered/filtered.twig', 'Filtered root' );

	$component_fixtures = array(
		'exact-file.twig'                           => 'Twig extension after exact file',
		'trailing.twig'                             => 'Flat trailing name must not win',
		'trailing/.twig'                            => 'Trailing slash dot Twig candidate',
		'trailing/trailing.twig'                    => 'Nested trailing name after dot Twig',
		'atoms/button/button.twig'                   => 'Grouped button',
		'ui/controls/deep-card/deep-card.twig'       => 'Deep grouped card',
		'01-duplicate/duplicate/duplicate.twig'      => 'First sibling duplicate',
		'02-duplicate/duplicate/duplicate.twig'      => 'Second sibling duplicate',
		'a-depth/deeper/breadth/breadth.twig'        => 'Deeper duplicate',
		'z-depth/breadth/breadth.twig'               => 'Shallower duplicate',
		"\u{1F600}/unicode-order/unicode-order.twig" => 'UTF-16 first duplicate',
		"\u{E000}/unicode-order/unicode-order.twig"  => 'UTF-8 first duplicate',
		'exact-file'                                 => 'Exact file candidate',
		'direct-priority.twig'                       => 'Direct before grouped',
		'atoms/direct-priority/direct-priority.twig' => 'Grouped after direct',
		'candidate-order.twig'                       => 'Flat Twig candidate',
		'candidate-order.html.twig'                  => 'Flat HTML Twig candidate',
		'candidate-order/candidate-order.twig'       => 'Nested Twig candidate',
		'flat-html.html.twig'                        => 'HTML Twig before nested',
		'flat-html/flat-html.twig'                   => 'Nested after HTML Twig',
		'nested-order/nested-order.twig'             => 'Nested Twig before HTML Twig',
		'nested-order/nested-order.html.twig'        => 'Nested HTML Twig after Twig',
		'nested-html/nested-html.html.twig'          => 'Nested HTML Twig candidate',
		'nested.html/nested.html.twig'               => 'HTML extension stays in nested stem',
		'atoms/grouped-html/grouped-html.html.twig'  => 'Grouped HTML Twig candidate',
		'atoms/override/override.twig'               => 'Child grouped override',
	);

	foreach ( $component_fixtures as $relative => $contents ) {
		emulsify_twig_project_smoke_write( $child . '/src/components/' . $relative, $contents );
	}

	emulsify_twig_project_smoke_write( $parent . '/src/components/override.twig', 'Parent direct override' );
	emulsify_twig_project_smoke_write( $parent . '/src/components/parent-only.twig', 'Parent source root' );
	emulsify_twig_project_smoke_write( $parent . '/components/parent-legacy.twig', 'Parent legacy root' );

	$skipped_directories = array( 'node_modules', '.git', '.cache', '.vite', '.out', '.coverage', 'dist' );
	foreach ( $skipped_directories as $index => $directory ) {
		emulsify_twig_project_smoke_write( $child . '/src/components/' . $directory . '/skipped-' . $index . '/skipped-' . $index . '.twig', 'Skipped component' );
		emulsify_twig_project_smoke_write( $child . '/src/components/ui/' . $directory . '/nested-skip-' . $index . '/nested-skip-' . $index . '.twig', 'Nested skipped component' );
	}

	$outside = $work_root . '/outside-components';
	emulsify_twig_project_smoke_write( $outside . '/escape.twig', 'Outside directory template' );
	emulsify_twig_project_smoke_write( $outside . '/external/external.twig', 'Outside grouped template' );
	if ( ! symlink( $outside, $child . '/src/components/escape' ) || ! symlink( $outside . '/escape.twig', $child . '/src/components/escape-file.twig' ) ) {
		throw new RuntimeException( 'Could not create component containment symlink fixtures.' );
	}
	if ( ! symlink( $child . '/src/components/badge.twig', $child . '/src/components/inside-file.twig' ) || ! symlink( $child . '/src/components/atoms/button', $child . '/src/components/inside-directory' ) ) {
		throw new RuntimeException( 'Could not create contained component symlink fixtures.' );
	}

	add_filter(
		'emulsify_theme_project_component_roots',
		static function ( array $roots, string $machine_name ) use ( $extra ): array {
			if ( 'whisk' === $machine_name ) {
				$roots[] = $extra;
			}

			return $roots;
		},
		10,
		2
	);

	$environment = emulsify_twig_project_smoke_environment( $child, $parent );

	emulsify_twig_project_smoke_assert_same(
		'Child example card',
		emulsify_twig_project_smoke_render( $environment, '{% include "whisk:example-card" %}' ),
		'machineName:component should resolve to the child src component template.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Child example card',
		emulsify_twig_project_smoke_render( $environment, '{% include "@components/example-card/example-card.twig" %}' ),
		'@components should still resolve child components.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Configured namespace teaser',
		emulsify_twig_project_smoke_render( $environment, '{% include "@custom/teaser.twig" %}' ),
		'variant.structureImplementations should register matching Twig namespaces.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Child badge',
		emulsify_twig_project_smoke_render( $environment, '{% include "whisk:badge" %}' ),
		'machineName:component should support root-level component.twig candidates.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Child heading',
		emulsify_twig_project_smoke_render( $environment, '{% include "whisk:ui/heading" %}' ),
		'machineName:component should support one-level grouped component folders.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Child legacy link',
		emulsify_twig_project_smoke_render( $environment, '{% include "whisk:link" %}' ),
		'machineName:component should support legacy child components roots.'
	);

	emulsify_twig_project_smoke_assert_same(
		'Filtered root',
		emulsify_twig_project_smoke_render( $environment, '{% include "whisk:filtered" %}' ),
		'Project component roots should be filterable.'
	);

	emulsify_twig_project_smoke_assert(
		false === $environment->getLoader()->exists( 'whisk:../example-card' ),
		'Unsafe project component references should be rejected.'
	);

	$resolution_cases = array(
		array( 'whisk:button', 'Grouped button', 'A component name should resolve inside a group.' ),
		array( 'whisk:deep-card', 'Deep grouped card', 'A component name should resolve inside two grouping levels.' ),
		array( 'whisk:ui/controls/deep-card', 'Deep grouped card', 'Explicit grouping paths should support more than two segments.' ),
		array( 'whisk:atoms\\button', 'Grouped button', 'Windows-style separators should normalize before candidate construction.' ),
		array( 'whisk:./badge', 'Child badge', 'Safe current-directory segments should normalize within the root.' ),
		array( 'whisk:missing/../badge', 'Child badge', 'Contained parent-directory segments should normalize without requiring an existing directory.' ),
		array( 'whisk:atoms//button', 'Grouped button', 'Repeated separators should normalize before candidate construction.' ),
		array( 'whisk:atoms/button/', 'Grouped button', 'A trailing separator should preserve nested component candidates.' ),
		array( 'whisk:trailing/', 'Trailing slash dot Twig candidate', 'Candidate suffixes should be added before normalizing a trailing separator.' ),
		array( 'whisk:duplicate', 'First sibling duplicate', 'Duplicate shorthand names should use sorted sibling order.' ),
		array( 'whisk:breadth', 'Shallower duplicate', 'Shallower groups should precede alphabetically earlier deeper groups.' ),
		array( 'whisk:unicode-order', 'UTF-16 first duplicate', 'Sibling sorting should use UTF-16 code units rather than UTF-8 bytes.' ),
		array( 'whisk:exact-file', 'Exact file candidate', 'An exact file should be the first candidate.' ),
		array( 'whisk:direct-priority', 'Direct before grouped', 'Direct candidates should precede every grouped candidate in the same root.' ),
		array( 'whisk:candidate-order', 'Flat Twig candidate', 'A flat Twig candidate should precede HTML Twig and nested candidates.' ),
		array( 'whisk:flat-html', 'HTML Twig before nested', 'A flat HTML Twig candidate should precede nested candidates.' ),
		array( 'whisk:nested-order', 'Nested Twig before HTML Twig', 'A nested Twig candidate should precede nested HTML Twig.' ),
		array( 'whisk:nested-html', 'Nested HTML Twig candidate', 'Nested HTML Twig candidates should resolve.' ),
		array( 'whisk:grouped-html', 'Grouped HTML Twig candidate', 'Grouped HTML Twig candidates should resolve.' ),
		array( 'whisk:badge.twig', 'Child badge', 'Explicit Twig extensions should resolve as exact files.' ),
		array( 'whisk:nested-order.twig', 'Nested Twig before HTML Twig', 'Nested candidate stems should strip an explicit final Twig extension.' ),
		array( 'whisk:nested.html.twig', 'HTML extension stays in nested stem', 'Nested candidate stems should preserve HTML before the final Twig extension.' ),
		array( 'whisk:flat-html.html.twig', 'HTML Twig before nested', 'Explicit HTML Twig extensions should resolve as exact files.' ),
		array( 'whisk:inside-file', 'Child badge', 'File symlinks contained by the root should remain usable.' ),
		array( 'whisk:inside-directory/button.twig', 'Grouped button', 'Explicit directory symlinks contained by the root should remain usable.' ),
		array( '@whisk/button', 'Grouped button', 'Project at-namespace syntax should use grouped lookup.' ),
		array( 'whisk:override', 'Child grouped override', 'A child grouped component should precede a parent direct component.' ),
		array( 'whisk:parent-only', 'Parent source root', 'The parent source root should remain available.' ),
		array( 'whisk:parent-legacy', 'Parent legacy root', 'The parent legacy root should remain available.' ),
	);

	foreach ( $resolution_cases as list( $reference, $expected, $message ) ) {
		try {
			emulsify_twig_project_smoke_assert_same(
				$expected,
				emulsify_twig_project_smoke_render( $environment, '{% include ' . json_encode( $reference, JSON_UNESCAPED_SLASHES ) . ' %}' ),
				$message
			);
		} catch ( Throwable $throwable ) {
			$failures[] = $message . ' ' . $throwable->getMessage();
		}
	}

	$missing_references = array(
		'whisk:escape'               => 'Directory symlinks must not escape the component root.',
		'whisk:escape-file'          => 'File symlinks must not escape the component root.',
		'@whisk/escape-file'         => 'Project at-namespace aliases must not escape the component root.',
		'whisk:external'             => 'Grouping discovery must not follow directory symlinks.',
		'whisk:../../outside'        => 'Lexical traversal must not escape the component root.',
		'whisk:missing/button'       => 'Project paths must preserve a missing first segment.',
		'missing/button'             => 'Bare paths must preserve a missing first segment.',
		'missing/button/button.twig' => 'Bare explicit template paths must preserve a missing first segment.',
	);

	foreach ( $skipped_directories as $index => $directory ) {
		$missing_references[ 'whisk:skipped-' . $index ]     = $directory . ' must not be searched as a group.';
		$missing_references[ 'whisk:nested-skip-' . $index ] = $directory . ' must be skipped inside nested groups.';
	}

	foreach ( $missing_references as $reference => $message ) {
		try {
			emulsify_twig_project_smoke_assert( false === $environment->getLoader()->exists( $reference ), $message );
		} catch ( Throwable $throwable ) {
			$failures[] = $throwable->getMessage();
		}
	}

	foreach ( array( 'whisk:missing-component' => 'is not defined', 'whisk:../example-card' => 'name "whisk:../example-card" is invalid' ) as $reference => $expected_message ) {
		try {
			$environment->getLoader()->getSourceContext( $reference );
			$failures[] = 'Missing or invalid project references should throw LoaderError: ' . $reference;
		} catch ( \Twig\Error\LoaderError $exception ) {
			emulsify_twig_project_smoke_assert( false !== strpos( $exception->getMessage(), $expected_message ), 'Project component loader errors should retain their existing wording.' );
		}
	}

	emulsify_twig_project_smoke_write( $missing_child . '/src/components/example-card/example-card.twig', 'Missing project example card' );

	$missing_environment = emulsify_twig_project_smoke_environment( $missing_child, $parent );

	emulsify_twig_project_smoke_assert_same(
		'Missing project example card',
		emulsify_twig_project_smoke_render( $missing_environment, '{% include "@components/example-card/example-card.twig" %}' ),
		'Missing project.emulsify.json should not break @components.'
	);

	emulsify_twig_project_smoke_assert(
		false === $missing_environment->getLoader()->exists( 'whisk:example-card' ),
		'Missing project.emulsify.json should not register a project component loader.'
	);

	emulsify_twig_project_smoke_write( $invalid_child . '/project.emulsify.json', '{"project":' );
	emulsify_twig_project_smoke_write( $invalid_child . '/src/components/example-card/example-card.twig', 'Invalid project example card' );

	$invalid_environment = emulsify_twig_project_smoke_environment( $invalid_child, $parent );

	emulsify_twig_project_smoke_assert_same(
		'Invalid project example card',
		emulsify_twig_project_smoke_render( $invalid_environment, '{% include "@components/example-card/example-card.twig" %}' ),
		'Invalid project.emulsify.json should not break @components.'
	);

	if ( $failures ) {
		throw new RuntimeException( implode( "\n", $failures ) );
	}

	echo "Twig project namespace smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_twig_project_smoke_remove( $work_root );
}
