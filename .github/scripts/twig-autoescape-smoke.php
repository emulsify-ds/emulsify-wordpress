<?php
/**
 * Behavioral smoke coverage for Twig HTML autoescaping.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$autoload = __DIR__ . '/../../vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer dependencies are required for the Twig autoescape smoke test.\n" );
	exit( 1 );
}

require_once $autoload;

use Emulsify\Theme\Runtime\Twig;
use Emulsify\Theme\Blocks\CoreBlockTwigRenderer;

/**
 * Fails the smoke test with a useful message.
 *
 * @param string $message Failure message.
 * @return void
 */
function emulsify_twig_autoescape_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$integration = new Twig();
$options     = $integration->environment_options(
	array(
		'autoescape' => false,
		'cache'      => false,
	)
);

if ( 'html' !== $options['autoescape'] || false !== $options['cache'] ) {
	emulsify_twig_autoescape_fail( 'Twig environment options should enable HTML autoescaping and preserve other options.' );
}

$environment = new \Twig\Environment(
	new \Twig\Loader\ArrayLoader(
		array(
			'fixture' => '{{ fields.heading }}|{{ add_attributes({class: ["x"]}) }}|{{ bem("card", ["featured"]) }}',
		)
	),
	$options
);

foreach ( $integration->functions( array() ) as $name => $definition ) {
	$environment->addFunction(
		new \Twig\TwigFunction(
			$name,
			$definition['callable'],
			$definition['options'] ?? array()
		)
	);
}

$integration->extensions( $environment );

$hostile = '"><script>alert(1)</script>';
$output  = $environment->render(
	'fixture',
	array(
		'fields' => array(
			'heading' => $hostile,
		),
	)
);
$expected = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;|class="x"|class="card card--featured"';

if ( $expected !== $output ) {
	emulsify_twig_autoescape_fail(
		sprintf(
			"Twig autoescape output was incorrect.\nExpected: %s\nActual:   %s",
			$expected,
			$output
		)
	);
}

if ( false !== strpos( $output, '<script>' ) ) {
	emulsify_twig_autoescape_fail( 'Twig rendered a literal script element from a hostile field value.' );
}

/**
 * Minimal Timber adapter delegating mapped compilation to the real Twig engine.
 */
final class Emulsify_Autoescape_Timber {
	/** @var \Twig\Environment Real Twig environment with the parent's helpers. */
	public static $environment;

	/** @return array Inherited Timber context. */
	public static function context(): array {
		return array( 'attributes' => array( 'data-global' => 'must not leak' ) );
	}

	/**
	 * @param string $template Mapped template.
	 * @param array  $context Renderer context.
	 * @return string Actual Twig output.
	 */
	public static function compile( string $template, array $context ): string {
		return self::$environment->render( $template, $context );
	}
}

class_alias( Emulsify_Autoescape_Timber::class, 'Timber\\Timber' );

/** @return string Temporary child theme root. */
function get_stylesheet_directory(): string {
	return $GLOBALS['emulsify_autoescape_block_root'];
}

/** @return string Parent theme root. */
function get_template_directory(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Supplies a project map without replacing the renderer or helper implementation.
 *
 * @param string $hook Filter name.
 * @param mixed  $value Filter value.
 * @return mixed Filtered value.
 */
function apply_filters( string $hook, $value ) {
	return 'emulsify_theme_core_block_twig_template_map' === $hook ? array( 'core/paragraph' => 'mapped.twig' ) : $value;
}

$mapped_root = sys_get_temp_dir() . '/emulsify-autoescape-block-' . uniqid( '', true );
mkdir( $mapped_root );
$GLOBALS['emulsify_autoescape_block_root'] = $mapped_root;
file_put_contents( $mapped_root . '/mapped.twig', '{% include "mapped-component.twig" %}' );
file_put_contents( $mapped_root . '/mapped-component.twig', '<div {{ bem("text") }}><section {{ add_attributes({class: "inner"}) }}>{{ content|raw }}</section></div>' );
$environment->setLoader( new \Twig\Loader\ChainLoader( array( $environment->getLoader(), new \Twig\Loader\FilesystemLoader( $mapped_root ) ) ) );
Emulsify_Autoescape_Timber::$environment = $environment;

try {
	$renderer = new CoreBlockTwigRenderer();
	$mapped   = $renderer->render_block(
		'<p id="saved-anchor" class="intro">Hello</p>',
		array(
			'blockName' => 'core/paragraph',
			'attrs'     => array(
				'onmouseover' => 'alert(document.domain)',
				'level'       => 2,
				'className'   => 'intro',
				'anchor'      => 'saved-anchor',
				'textAlign'   => 'center',
			),
		)
	);
} finally {
	unlink( $mapped_root . '/mapped.twig' );
	unlink( $mapped_root . '/mapped-component.twig' );
	rmdir( $mapped_root );
	unset( $GLOBALS['emulsify_autoescape_block_root'] );
}

$expected_mapped = '<div class="text"><section class="inner"><p id="saved-anchor" class="intro">Hello</p></section></div>';
if ( $expected_mapped !== $mapped ) {
	emulsify_twig_autoescape_fail( "Mapped block data leaked through helper context.\nExpected: " . $expected_mapped . "\nActual:   " . $mapped );
}

echo "Twig autoescape smoke checks passed; fields were escaped, helper HTML remained safe, and mapped block data did not become HTML attributes.\n";
