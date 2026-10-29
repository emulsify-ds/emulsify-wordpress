<?php
/**
 * The main template file
 *
 * @package Emulsify
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 */

use Timber\Timber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$templates = array( '@templates/index.twig' );

if ( is_home() ) {
	array_unshift( $templates, '@templates/home.twig' );
}

if ( is_front_page() ) {
	// A separate posts page is home, but only the site's front page gets this.
	array_unshift( $templates, '@templates/front-page.twig' );
}

$context = Timber::context();

Timber::render( $templates, $context );
