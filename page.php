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
$context  = Timber::context();
$wp_post  = $context['post'] ?? null;
$template = '@templates/page.twig';

if ( $wp_post && post_password_required( $wp_post->ID ) ) {
	$template = '@templates/single-password.twig';
}

Timber::render( $template, $context );
