<?php
/**
 * Real WordPress protocol and helper hardening checks; run with wp eval-file.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig;

require_once __DIR__ . '/attribute-hardening-test-cases.php';

$failures = array();
foreach ( emulsify_attribute_hardening_cases() as $label => list( $expected, $actual ) ) {
	if ( $expected !== $actual ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
}
$helpers      = new Twig();
$private_post = new WP_Post( (object) array( 'ID' => 1, 'post_password' => 'secret', 'post_title' => 'Private' ) );
foreach ( array( $helpers->add_attributes( $private_post ), $helpers->add_attributes( array( 'attributes' => $private_post ), array( 'data-ok' => '1' ) ), $helpers->bem( array( 'attributes' => $private_post ), 'card' ) ) as $bag ) {
	if ( array_key_exists( 'post_password', $bag->toArray() ) ) {
		$failures[] = 'A real WP_Post must not become HTML attributes.';
	}
}
if ( $failures ) {
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo "Real WordPress attribute hardening checks passed.\n";
