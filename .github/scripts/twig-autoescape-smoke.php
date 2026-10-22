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

echo "Twig autoescape smoke checks passed; field values were escaped and attribute helpers remained safe HTML.\n";
