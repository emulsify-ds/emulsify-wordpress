<?php
/**
 * Checks lazy context values through real Timber context and output caching.
 *
 * @package Emulsify
 */

use Timber\Loader;
use Timber\Timber;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Timber::class ) ) {
	throw new RuntimeException( 'Run this smoke with wp eval-file in the WordPress fixture.' );
}

function emulsify_wordpress_context_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$saved_context = Timber::$context_cache;
$directory     = get_stylesheet_directory() . '/templates/context-lazy-' . uniqid();
$relative      = '@templates/' . basename( $directory );
$logout_calls  = 0;
$body_calls    = 0;
$cache_keys    = array();
$count_logout  = static function ( string $url ) use ( &$logout_calls ): string {
	++$logout_calls;
	return $url;
};
$count_body = static function ( array $classes ) use ( &$body_calls ): array {
	++$body_calls;
	return $classes;
};
$record_cache_key = static function ( string $key ) use ( &$cache_keys ): string {
	$cache_keys[ $key ] = true;
	return $key;
};

try {
	emulsify_wordpress_context_assert( wp_mkdir_p( $directory ), 'Could not create real Timber context templates.' );
	file_put_contents( $directory . '/plain.twig', 'No logout URL requested.' );
	file_put_contents( $directory . '/logout.twig', '{{ wp.logout_url }}|{{ wp.logout_url }}' );
	add_filter( 'logout_url', $count_logout );
	add_filter( 'body_class', $count_body );
	add_filter( 'timber/cache/transient_key', $record_cache_key );

	Timber::$context_cache = array();
	$context              = Timber::context_global();
	emulsify_wordpress_context_assert( 0 === $logout_calls, 'Building real Timber context must not create an unused logout nonce.' );
	$initial_body_calls = $body_calls;
	Timber::context_global();
	emulsify_wordpress_context_assert( $initial_body_calls === $body_calls, 'Timber must reuse its cached global context without new body-class work.' );
	emulsify_wordpress_context_assert( 'No logout URL requested.' === Timber::compile( $relative . '/plain.twig', $context ), 'A normal Timber compile must render without a logout URL.' );
	emulsify_wordpress_context_assert( 0 === $logout_calls, 'Default uncached Timber rendering must leave an unused logout URL lazy.' );
	$rendered = Timber::compile( $relative . '/logout.twig', $context );
	emulsify_wordpress_context_assert( 1 === $logout_calls && 2 === substr_count( $rendered, 'action=logout' ), 'Repeated template reads must compute a single logout URL.' );

	Timber::$context_cache = array();
	$context              = Timber::context_global();
	$logout_calls         = 0;
	emulsify_wordpress_context_assert( 'No logout URL requested.' === Timber::compile( $relative . '/plain.twig', $context, 60, Loader::CACHE_TRANSIENT ), 'A cached Timber compile must render its template.' );
	emulsify_wordpress_context_assert( 1 === $logout_calls, 'Timber output-cache JSON serialization intentionally materializes the nonce-bearing logout URL.' );
	Timber::compile( $relative . '/plain.twig', $context, 60, Loader::CACHE_TRANSIENT );
	emulsify_wordpress_context_assert( 1 === $logout_calls, 'Repeated cache-key serialization must reuse the materialized logout URL.' );
	echo 'Real Timber context smoke passed; initial body_class calls: ' . $initial_body_calls . " (Timber and parent), lazy and serialized logout cases verified.\n";
} finally {
	remove_filter( 'logout_url', $count_logout );
	remove_filter( 'body_class', $count_body );
	remove_filter( 'timber/cache/transient_key', $record_cache_key );
	foreach ( array_keys( $cache_keys ) as $key ) {
		delete_transient( $key );
	}
	Timber::$context_cache = $saved_context;
	foreach ( array( 'plain.twig', 'logout.twig' ) as $file ) {
		if ( is_file( $directory . '/' . $file ) ) {
			unlink( $directory . '/' . $file );
		}
	}
	if ( is_dir( $directory ) ) {
		rmdir( $directory );
	}
}
