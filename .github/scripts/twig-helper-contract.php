<?php
/**
 * Characterizes actual parent helpers and their autoescaped Twig registration.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig;
use Emulsify\Theme\Support\AttributeBag;

$autoload = __DIR__ . '/../../vendor/autoload.php';

if ( PHP_SAPI !== 'cli' || ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Run this CLI check after composer install.\n" );
	exit( 1 );
}

require_once $autoload;
require_once __DIR__ . '/wordpress-attribute-test-adapter.php';

try {
	$corpus      = json_decode( file_get_contents( __DIR__ . '/../fixtures/twig-helper-contract.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( 1 !== ( $corpus['schemaVersion'] ?? null ) || ! is_array( $corpus['cases'] ?? null ) || empty( $corpus['cases'] ) ) {
		throw new RuntimeException( 'Twig helper schema 1 requires a nonempty cases array.' );
	}
	$integration = new Twig();
	$functions   = $integration->functions( array() );
	$environment = new \Twig\Environment( new \Twig\Loader\ArrayLoader(), $integration->environment_options( array( 'cache' => false ) ) );

	foreach ( $functions as $name => $definition ) {
		$environment->addFunction( new \Twig\TwigFunction( $name, $definition['callable'], $definition['options'] ?? array() ) );
	}

	foreach ( $corpus['cases'] as $fixture ) {
		if ( ! is_array( $fixture['args'] ?? null ) ) {
			throw new RuntimeException( $fixture['id'] . ': helper arguments must be an array.' );
		}
		$values  = $fixture['context'] ?? array();
		$context = array_merge( $fixture['contextVars'] ?? array(), array( 'attributes' => 'bag' === ( $fixture['contextKind'] ?? '' ) ? new AttributeBag( $values ) : $values ) );
		$args    = $fixture['args'];
		if ( 'object' === ( $fixture['argumentKind'] ?? '' ) ) {
			$args[0] = (object) $args[0];
		}
		$html    = array();
		for ( $index = 0; $index < ( $fixture['repeat'] ?? 1 ); ++$index ) {
			$bag    = call_user_func_array( $functions[ $fixture['helper'] ]['callable'], array_merge( array( $context ), $args ) );
			$html[] = (string) $bag;
		}
		$result  = array(
			'attributes'   => (object) $bag->toArray(),
			'html'         => implode( '|', $html ),
			'contextAfter' => (object) ( $context['attributes'] instanceof AttributeBag ? $context['attributes']->toArray() : $context['attributes'] ),
		);
		$expected                 = $fixture['php'];
		$expected['attributes']   = (object) $expected['attributes'];
		$expected['contextAfter'] = (object) $expected['contextAfter'];

		if ( json_encode( $result, JSON_THROW_ON_ERROR ) !== json_encode( $expected, JSON_THROW_ON_ERROR ) ) {
			throw new RuntimeException( $fixture['id'] . ': PHP helper output changed: ' . json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
		}

		$names = array();
		foreach ( $args as $index => $value ) {
			$name             = 'argument_' . $index;
			$names[]          = $name;
			$context[ $name ] = $value;
		}
		$call     = '{{ ' . $fixture['helper'] . '(' . implode( ', ', $names ) . ') }}';
		$template = implode( '|', array_fill( 0, $fixture['repeat'] ?? 1, $call ) );
		$html     = $environment->createTemplate( $template )->render( $context );

		if ( $expected['html'] !== $html ) {
			throw new RuntimeException( $fixture['id'] . ': registered Twig rendering changed: ' . $html );
		}
	}

	echo 'PASS ' . count( $corpus['cases'] ) . ' PHP Twig helper cases and registered autoescaped renders on PHP ' . PHP_VERSION . ".\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
}
