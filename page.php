<?php
/**
 * The template for displaying all pages.
 *
 * @package Emulsify
 */

use Timber\Timber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Page rendering is intentionally thin: route data comes from Timber context,
// while markup and extension points live in Twig.
$context   = Timber::context();
$wp_post   = $context['post'] ?? null;
$templates = array( '@templates/page.twig' );

if ( $wp_post && post_password_required( $wp_post->ID ) ) {
	$templates = array( '@templates/single-password.twig' );
} elseif ( is_front_page() ) {
	array_unshift( $templates, '@templates/front-page.twig' );
}

Timber::render( $templates, $context );
