<?php
/**
 * Exact-byte coverage for parent template output and child extension points.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$repo_root = dirname( __DIR__, 2 );
$autoload  = $repo_root . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer dependencies are required for the template extension smoke test.\n" );
	exit( 1 );
}

require_once $autoload;

$GLOBALS['emulsify_extension_repo_root'] = $repo_root;
$GLOBALS['emulsify_extension_child']     = sys_get_temp_dir() . '/emulsify-template-extension-' . uniqid( '', true );
$GLOBALS['emulsify_extension_hooks']     = array();

function get_template_directory(): string {
	return $GLOBALS['emulsify_extension_repo_root'];
}

function get_stylesheet_directory(): string {
	return $GLOBALS['emulsify_extension_child'];
}

function apply_filters( string $hook, $value, ...$arguments ) {
	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function do_action( string $hook, ...$arguments ): void {
	$GLOBALS['emulsify_extension_hooks'][] = $hook;
	echo '<!-- action:' . $hook . ' -->';
}

function wp_head(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_head';
	echo '<!-- wp_head -->';
}

function wp_body_open(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_body_open';
	echo '<!-- wp_body_open -->';
}

function wp_footer(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_footer';
	echo '<!-- wp_footer -->';
}

/**
 * Builds deterministic data with fresh attribute helpers for every render.
 *
 * @param bool $has_title   Whether the document has a title.
 * @param bool $has_menu    Whether primary menu items exist.
 * @param bool $has_content Whether the fixture content exists.
 * @return array Template context.
 */
function emulsify_extension_context( bool $has_title, bool $has_menu, bool $has_content ): array {
	$items = array(
		array(
			'title'    => 'Current <link> & fixture',
			'link'     => '/current/?first=1&second=2',
			'classes'  => array( 'fixture-menu-item' ),
			'current'  => true,
			'children' => array(
				array( 'title' => 'Nested & fixture', 'link' => '/nested/', 'target' => '_blank' ),
			),
		),
		array( 'title' => 'Other & fixture', 'link' => '/other/' ),
	);
	$context = array(
		'site'            => array(
			'url'                 => 'https://example.test/?first=1&second=2',
			'name'                => 'Fixture <site> & WordPress',
			'charset'             => 'UTF-8',
			'language_attributes' => new \Twig\Markup( 'lang="en" dir="ltr"', 'UTF-8' ),
		),
		'body_class'      => 'fixture-body route-page',
		'menu'            => $has_menu ? array( 'get_items' => $items ) : null,
		'fixture_content' => $has_content ? '<p>Content &amp; fixture</p>' : '',
	);

	if ( $has_title ) {
		$context['title'] = 'Page <title> & fixture';
	}

	return $context;
}

/**
 * Captures explicitly or compares every output byte against a committed file.
 *
 * @param string $name    Golden name.
 * @param string $output  Rendered output.
 * @param bool   $capture Whether to update the golden deliberately.
 * @return void
 */
function emulsify_extension_golden( string $name, string $output, bool $capture ): void {
	$file = __DIR__ . '/../fixtures/template-extension/' . $name . '.html';

	if ( $capture ) {
		if ( false === file_put_contents( $file, $output ) ) {
			throw new RuntimeException( 'Could not capture ' . $file );
		}
	} elseif ( ! is_file( $file ) || file_get_contents( $file ) !== $output ) {
		throw new RuntimeException( $name . ': rendered bytes differ from the pre-extension golden file.' );
	}

	echo ( $capture ? 'CAPTURE ' : 'PASS ' ) . $name . ' (' . strlen( $output ) . " bytes)\n";
}

$capture = array_slice( $argv, 1 ) === array( '--update-goldens' );

if ( count( $argv ) > 1 && ! $capture ) {
	fwrite( STDERR, "Only --update-goldens is supported; ordinary runs never rewrite fixtures.\n" );
	exit( 1 );
}

try {
	if ( ! mkdir( get_stylesheet_directory() . '/templates', 0777, true ) ) {
		throw new RuntimeException( 'Could not create the disposable child theme.' );
	}
	$integration = new Twig();
	$filesystem  = $integration->loader_paths( new \Twig\Loader\FilesystemLoader() );
	$fixtures    = new \Twig\Loader\ArrayLoader(
		array(
			'content-fixture.twig' => '{% extends "@emulsify-tpl/layouts/base.twig" %}{% block content %}{{ fixture_content|raw }}{% endblock %}',
		)
	);
	$environment = new \Twig\Environment(
		new \Twig\Loader\ChainLoader( array( $fixtures, $filesystem ) ),
		$integration->environment_options( array( 'cache' => false ) )
	);

	foreach ( $integration->functions( array() ) as $name => $definition ) {
		$environment->addFunction( new \Twig\TwigFunction( $name, $definition['callable'], $definition['options'] ?? array() ) );
	}
	$environment->addFunction( new \Twig\TwigFunction( 'function', array( new \Timber\Twig(), 'exec_function' ) ) );
	$environment->addFunction( new \Twig\TwigFunction( 'action', 'do_action' ) );
	$integration->extensions( $environment );
	$core = $environment->getExtension( \Twig\Extension\CoreExtension::class );
	$core->setTimezone( 'UTC' );
	$environment->addFilter(
		new \Twig\TwigFilter(
			'date',
			static function ( $date, $format = null, $timezone = null ) use ( $core ): string {
				return $core->formatDate( 'now' === $date ? new DateTimeImmutable( '2026-10-22T12:00:00Z' ) : $date, $format, $timezone );
			}
		)
	);

	foreach ( array( false, true ) as $has_title ) {
		foreach ( array( false, true ) as $has_menu ) {
			$suffix = ( $has_title ? 'title' : 'no-title' ) . '-' . ( $has_menu ? 'menu' : 'no-menu' );

			foreach ( array( false, true ) as $has_content ) {
				$template = $has_content ? 'content-fixture.twig' : '@emulsify-tpl/layouts/base.twig';
				$output   = $environment->render( $template, emulsify_extension_context( $has_title, $has_menu, $has_content ) );
				if ( ! $has_content && $environment->render( 'content-fixture.twig', emulsify_extension_context( $has_title, $has_menu, false ) ) !== $output ) {
					throw new RuntimeException( 'An empty child content block must preserve the direct base output.' );
				}
				emulsify_extension_golden( 'content-' . $suffix . '-' . ( $has_content ? 'populated' : 'empty' ), $output, $capture );
			}
		}
	}

	foreach ( array( false, true ) as $has_post ) {
		$context         = emulsify_extension_context( false, true, $has_post );
		$context['post'] = $has_post ? array( 'id' => 42, 'type' => 'page', 'title' => 'Post <title> & fixture', 'content' => $context['fixture_content'] ) : null;
		emulsify_extension_golden( $has_post ? 'page-post' : 'page-no-post', $environment->render( '@emulsify-tpl/page.twig', $context ), $capture );
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	if ( is_dir( get_stylesheet_directory() . '/templates' ) ) {
		rmdir( get_stylesheet_directory() . '/templates' );
	}
	if ( is_dir( get_stylesheet_directory() ) ) {
		rmdir( get_stylesheet_directory() );
	}
}
