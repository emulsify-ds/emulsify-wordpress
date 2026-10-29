<?php
/**
 * Archive template.
 *
 * @package Emulsify
 */

use Timber\Timber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep WordPress route controllers in the global namespace. Services that need
// namespacing live under includes/ and feed data into these Timber templates.
$templates = array( '@templates/archive.twig', '@templates/index.twig' );

$page_title = __( 'Archive', 'emulsify' );
if ( is_day() ) {
	/* translators: %s is the archive date. */
	$page_title = sprintf( __( 'Archive: %s', 'emulsify' ), get_the_date( (string) get_option( 'date_format' ) ) );
} elseif ( is_month() ) {
	/* translators: %s is the archive month and year. */
	$page_title = sprintf( __( 'Archive: %s', 'emulsify' ), get_the_date( 'M Y' ) );
} elseif ( is_year() ) {
	/* translators: %s is the archive year. */
	$page_title = sprintf( __( 'Archive: %s', 'emulsify' ), get_the_date( 'Y' ) );
} elseif ( is_tag() ) {
	$page_title = single_tag_title( '', false );
} elseif ( is_category() ) {
	$page_title = single_cat_title( '', false );
} elseif ( is_post_type_archive() ) {
	$page_title = post_type_archive_title( '', false );
	// Like WordPress' archive hierarchy, only a single queried type gets its
	// own template. This also works when the archive has no current post.
	$archive_post_types = array_filter( (array) get_query_var( 'post_type' ) );
	$archive_post_type  = count( $archive_post_types ) === 1 ? reset( $archive_post_types ) : '';

	if ( is_string( $archive_post_type ) && '' !== $archive_post_type ) {
		array_unshift( $templates, '@templates/archive-' . $archive_post_type . '.twig' );
	}
}

$context = Timber::context(
	array(
		'title' => $page_title,
	)
);

Timber::render( $templates, $context );
