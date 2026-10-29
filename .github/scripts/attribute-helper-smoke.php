<?php
/**
 * Smoke tests for Emulsify Timber attribute helpers.
 *
 * @package Emulsify
 */

require_once __DIR__ . '/wordpress-attribute-test-adapter.php';
require_once __DIR__ . '/../../includes/Support/AttributeBag.php';
require_once __DIR__ . '/../../includes/Support/FileDiscovery.php';
require_once __DIR__ . '/../../includes/Runtime/Twig.php';

use Emulsify\Theme\Support\AttributeBag;
use Emulsify\Theme\Runtime\Twig;

/**
 * Asserts two values are identical.
 *
 * @param string $label    Assertion label.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function emulsify_attribute_helper_assert_same( string $label, $expected, $actual ): void {
	if ( $expected === $actual ) {
		return;
	}

	fwrite( STDERR, sprintf( "%s failed.\nExpected: %s\nActual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
	exit( 1 );
}

/**
 * Registers helper functions with a Twig environment.
 *
 * @param \Twig\Environment $environment Twig environment.
 * @param Twig              $helpers     Emulsify helper integration.
 * @return void
 */
function emulsify_register_smoke_twig_functions( \Twig\Environment $environment, Twig $helpers ): void {
	foreach ( $helpers->functions( array() ) as $name => $definition ) {
		$options = $definition['options'] ?? array();

		$environment->addFunction( new \Twig\TwigFunction( $name, $definition['callable'], $options ) );
	}
}

/**
 * Registers minimal WordPress/Timber shims needed to parse parent templates.
 *
 * @param \Twig\Environment $environment Twig environment.
 * @return void
 */
function emulsify_register_smoke_wordpress_twig_shims( \Twig\Environment $environment ): void {
	require_once __DIR__ . '/wordpress-text-test-adapter.php';
	emulsify_register_wordpress_text_test_filters( $environment );
	$environment->addFunction(
		new \Twig\TwigFunction(
			'function',
			static function ( string $name, ...$arguments ) {
				if ( '__' === $name || 'esc_attr__' === $name ) {
					return $arguments[0] ?? '';
				}

				return '';
			},
			array( 'is_safe' => array( 'html' ) )
		)
	);
	$environment->addFunction( new \Twig\TwigFunction( 'action', static function (): string { return ''; } ) );
	$environment->addFilter( new \Twig\TwigFilter( 'wpautop', static function ( $value ) { return $value; }, array( 'is_safe' => array( 'html' ) ) ) );
	$environment->addFilter( new \Twig\TwigFilter( 'resize', static function ( $value ) { return $value; } ) );
}

/**
 * Asserts parent templates route class output through add_attributes().
 *
 * @param string $templates_dir Parent template directory.
 * @return void
 */
function emulsify_attribute_helper_assert_parent_templates_use_helpers( string $templates_dir ): void {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $templates_dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( 'twig' !== $file->getExtension() ) {
			continue;
		}

		$contents = file_get_contents( $file->getPathname() );

		if ( is_string( $contents ) && preg_match( '/\sclass=(["\'])/', $contents ) ) {
			fwrite( STDERR, sprintf( "Parent template should use bem() or add_attributes() for classes: %s\n", $file->getPathname() ) );
			exit( 1 );
		}

		if ( is_string( $contents ) && preg_match( '/{{\s*bem\s*\(/', $contents ) ) {
			fwrite( STDERR, sprintf( "Parent template should pass bem() through add_attributes(): %s\n", $file->getPathname() ) );
			exit( 1 );
		}
	}
}

/**
 * Parses parent templates with the attribute helper functions registered.
 *
 * @param Twig    $helpers       Emulsify helper integration.
 * @param string $templates_dir Parent template directory.
 * @return int Parsed template count.
 */
function emulsify_attribute_helper_parse_parent_templates( Twig $helpers, string $templates_dir ): int {
	$loader = new \Twig\Loader\FilesystemLoader();
	$loader->addPath( $templates_dir, 'templates' );
	$loader->addPath( $templates_dir, 'emulsify-tpl' );

	$environment = new \Twig\Environment(
		$loader,
		array(
			'autoescape' => false,
			'cache'      => false,
		)
	);

	emulsify_register_smoke_twig_functions( $environment, $helpers );
	emulsify_register_smoke_wordpress_twig_shims( $environment );

	$checked  = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $templates_dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( 'twig' !== $file->getExtension() ) {
			continue;
		}

		$relative = str_replace( rtrim( $templates_dir, '/\\' ) . '/', '', $file->getPathname() );
		$template = '@templates/' . str_replace( '\\', '/', $relative );

		$environment->parse( $environment->tokenize( $environment->getLoader()->getSourceContext( $template ) ) );
		++$checked;
	}

	return $checked;
}

require_once __DIR__ . '/attribute-hardening-test-cases.php';

$hardening_failures = array();
foreach ( emulsify_attribute_hardening_cases() as $label => list( $expected, $actual ) ) {
	if ( $expected !== $actual ) {
		$hardening_failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
}
if ( $hardening_failures ) {
	fwrite( STDERR, implode( "\n", $hardening_failures ) . "\n" );
	exit( 1 );
}

$helpers = new Twig();
$private_post = (object) array( 'post_password' => 'secret' );
foreach ( array( $helpers->add_attributes( $private_post ), $helpers->add_attributes( array( 'attributes' => $private_post ), array( 'data-ok' => '1' ) ), $helpers->bem( array( 'attributes' => $private_post ), 'card' ), $helpers->bem( (object) array( 'block' => 'card', 'attributes' => $private_post ) ) ) as $bag ) {
	if ( array_key_exists( 'post_password', $bag->toArray() ) ) {
		fwrite( STDERR, "Helper must not turn a post-like object's public properties into HTML attributes.\n" );
		exit( 1 );
	}
}


$event_attributes = array(
	'onclick'     => 'x()',
	'onMouseOver' => 'y()',
	'ONFOCUS'     => 'z()',
	'on'          => 'ignored',
	'once'        => 'ignored',
	'data-onload' => 'allowed',
	'data-ok'     => '1',
);
$set_bag          = new AttributeBag();

foreach ( $event_attributes as $name => $value ) {
	$set_bag->set( $name, $value );
}

$merge_bag = new AttributeBag();
$merge_bag->merge( $event_attributes );

foreach (
	array(
		'constructor' => new AttributeBag( $event_attributes ),
		'set'         => $set_bag,
		'merge'       => $merge_bag,
	) as $operation => $event_bag
) {
	emulsify_attribute_helper_assert_same(
		'AttributeBag ' . $operation . ' drops event-handler names from its map',
		array(
			'data-onload' => 'allowed',
			'data-ok'     => '1',
		),
		$event_bag->toArray()
	);
	emulsify_attribute_helper_assert_same(
		'AttributeBag ' . $operation . ' does not serialize event-handler names',
		'data-onload="allowed" data-ok="1"',
		(string) $event_bag
	);
}

emulsify_attribute_helper_assert_same(
	'bem positional syntax',
	'class="example-card example-card--featured"',
	(string) $helpers->bem( 'example-card', array( 'featured' ) )
);

emulsify_attribute_helper_assert_same(
	'bem object syntax',
	'data-state="open" disabled class="card__title card__title--featured u-mb-0"',
	(string) $helpers->bem(
		array(
			'block'      => 'card',
			'element'    => 'title',
			'modifiers'  => array( 'featured' ),
			'extra'      => array( 'u-mb-0' ),
			'attributes' => array(
				'data-state'  => 'open',
				'disabled'    => true,
				'onclick bad' => 'ignored',
			),
		)
	)
);

emulsify_attribute_helper_assert_same(
	'add_attributes array syntax',
	'class="foo" data-label="A&amp;B" hidden',
	(string) $helpers->add_attributes(
		array(
			'class'      => array( 'foo' ),
			'data-label' => 'A&B',
			'hidden'     => true,
		)
	)
);

$bag = new AttributeBag(
	array(
		'class' => array( 'foo', 'foo' ),
		'id'    => 'example',
	)
);
$bag->merge(
	array(
		'class' => array( 'bar' ),
		'id'    => 'updated',
	)
);

emulsify_attribute_helper_assert_same(
	'AttributeBag merge syntax',
	'class="foo bar" id="updated"',
	(string) $helpers->add_attributes( $bag )
);

emulsify_attribute_helper_assert_same(
	'context-aware add_attributes syntax',
	'class="context foo"',
	(string) $helpers->add_attributes(
		array( 'attributes' => new AttributeBag( array( 'class' => array( 'context' ) ) ) ),
		array( 'class' => array( 'foo' ) )
	)
);

emulsify_attribute_helper_assert_same(
	'context-aware bem syntax',
	'class="example-card example-card--featured context"',
	(string) $helpers->bem(
		array( 'attributes' => new AttributeBag( array( 'class' => array( 'context' ) ) ) ),
		'example-card',
		array( 'featured' )
	)
);

$autoload = __DIR__ . '/../../vendor/autoload.php';

if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

$templates_dir = __DIR__ . '/../../templates';
emulsify_attribute_helper_assert_parent_templates_use_helpers( $templates_dir );

if (
	class_exists( \Twig\Environment::class )
	&& class_exists( \Twig\Loader\ArrayLoader::class )
	&& class_exists( \Twig\Loader\FilesystemLoader::class )
	&& class_exists( \Twig\TwigFunction::class )
) {
	$environment = new \Twig\Environment(
		new \Twig\Loader\ArrayLoader(
			array(
				'fixture' => '{{ bem("example-card", ["featured"]) }}|{{ add_attributes({ class: ["foo"] }) }}',
			)
		),
		array( 'autoescape' => false )
	);

	emulsify_register_smoke_twig_functions( $environment, $helpers );

	emulsify_attribute_helper_assert_same(
		'Twig fixture render',
		'class="example-card example-card--featured"|class="foo"',
		$environment->render( 'fixture' )
	);

	$parsed = emulsify_attribute_helper_parse_parent_templates( $helpers, $templates_dir );

	echo sprintf( "Attribute helper smoke checks passed with Twig fixture rendering and %d parent template parse checks.\n", $parsed );
	exit( 0 );
}

echo "Attribute helper smoke checks passed. Twig fixture rendering skipped because Twig is not installed.\n";
