<?php
/**
 * Exercises PHP route controllers without depending on the main query's posts.
 *
 * @package Emulsify
 */

namespace Timber {
	final class Timber {
		public static array $context = array();
		public static array $rendered = array();

		public static function context( array $extra = array() ): array {
			return array_merge( self::$context, $extra );
		}

		public static function render( $templates, array $context ): void {
			self::$rendered = array( 'templates' => (array) $templates, 'context' => $context );
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) {
		fwrite( STDERR, "This script must be run from the command line.\n" );
		exit( 1 );
	}

	define( 'ABSPATH', __DIR__ );
	$GLOBALS['emulsify_route_state'] = array();
	$failures = array();

	function is_home(): bool { return ! empty( $GLOBALS['emulsify_route_state']['home'] ); }
	function is_front_page(): bool { return ! empty( $GLOBALS['emulsify_route_state']['front'] ); }
	function is_day(): bool { return ! empty( $GLOBALS['emulsify_route_state']['day'] ); }
	function is_month(): bool { return ! empty( $GLOBALS['emulsify_route_state']['month'] ); }
	function is_year(): bool { return ! empty( $GLOBALS['emulsify_route_state']['year'] ); }
	function is_tag(): bool { return false; }
	function is_category(): bool { return false; }
	function is_post_type_archive(): bool { return ! empty( $GLOBALS['emulsify_route_state']['post_type_archive'] ); }
	function post_password_required( $post ): bool {
		$GLOBALS['emulsify_route_state']['password_checked'][] = $post;
		return ! empty( $GLOBALS['emulsify_route_state']['protected'] );
	}
	function __( string $text, string $domain ): string { return $text; }
	function post_type_archive_title( string $prefix = '', bool $display = true ): string { return 'Books'; }
	function get_post_type() { return $GLOBALS['emulsify_route_state']['current_post_type'] ?? false; }
	function get_query_var( string $key, $default = '' ) { return $GLOBALS['emulsify_route_state']['query'][ $key ] ?? $default; }
	function get_option( string $key ) { return $GLOBALS['emulsify_route_state']['options'][ $key ] ?? ''; }
	function get_the_date( string $format = '' ): string {
		$GLOBALS['emulsify_route_state']['date_calls'][] = $format;
		return gmdate( $format ?: 'F j, Y', gmmktime( 12, 0, 0, 10, 29, 2026 ) );
	}

	function routing_render( string $controller, array $state = array() ): array {
		$GLOBALS['emulsify_route_state'] = $state;
		\Timber\Timber::$context = array( 'post' => $state['post'] ?? null, 'fixture' => 'preserved' );
		\Timber\Timber::$rendered = array();
		require dirname( __DIR__, 2 ) . '/' . $controller;
		return \Timber\Timber::$rendered;
	}

	function routing_expect( bool $condition, string $message ): void {
		global $failures;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	}

	// Front-page hierarchy is independent of whether the route contains posts.
	$post = (object) array( 'ID' => 42 );
	$front_posts = routing_render( 'index.php', array( 'front' => true, 'home' => true ) );
	routing_expect( array( '@templates/front-page.twig', '@templates/home.twig', '@templates/index.twig' ) === $front_posts['templates'], 'A posts front page must prefer front-page, home, then index.' );
	$posts_page = routing_render( 'index.php', array( 'front' => false, 'home' => true ) );
	routing_expect( array( '@templates/home.twig', '@templates/index.twig' ) === $posts_page['templates'], 'A separate posts page must never choose front-page.twig.' );
	$generic = routing_render( 'index.php' );
	routing_expect( array( '@templates/index.twig' ) === $generic['templates'], 'Other index routes must keep their generic fallback.' );
	$front_page = routing_render( 'page.php', array( 'front' => true, 'post' => $post ) );
	routing_expect( array( '@templates/front-page.twig', '@templates/page.twig' ) === $front_page['templates'], 'A static front page must prefer front-page.twig over page.twig.' );
	$ordinary_page = routing_render( 'page.php', array( 'post' => $post ) );
	routing_expect( array( '@templates/page.twig' ) === $ordinary_page['templates'], 'An ordinary page must keep page.twig.' );
	foreach ( array( false, true ) as $front ) {
		$protected = routing_render( 'page.php', array( 'front' => $front, 'protected' => true, 'post' => $post ) );
		routing_expect( array( '@templates/single-password.twig' ) === $protected['templates'], 'A protected page must bypass all normal/front-page templates.' );
		routing_expect( array( 42 ) === $GLOBALS['emulsify_route_state']['password_checked'], 'Page routing must check the current post password.' );
	}
	routing_expect( 'preserved' === $front_page['context']['fixture'] && $post === $front_page['context']['post'], 'Route selection must preserve Timber context.' );

	// Archive routing must use the query, including when no current post exists.
	foreach ( array( 'book', array( 'book' ), array( '', 'book' ) ) as $post_type ) {
		$archive = routing_render( 'archive.php', array( 'post_type_archive' => true, 'query' => array( 'post_type' => $post_type ) ) );
		routing_expect( array( '@templates/archive-book.twig', '@templates/archive.twig', '@templates/index.twig' ) === $archive['templates'], 'An empty single-type archive must choose archive-book.twig from its query.' );
		routing_expect( 'Books' === $archive['context']['title'], 'Custom post type archive titles must stay intact.' );
	}
	foreach ( array( '', array(), array( 'book', 'film' ) ) as $post_type ) {
		$archive = routing_render( 'archive.php', array( 'post_type_archive' => true, 'query' => array( 'post_type' => $post_type ) ) );
		routing_expect( array( '@templates/archive.twig', '@templates/index.twig' ) === $archive['templates'], 'Absent or multiple post types must use generic archive fallbacks, matching WordPress.' );
	}
	$archive = routing_render( 'archive.php', array( 'post_type_archive' => true, 'current_post_type' => 'film', 'query' => array( 'post_type' => 'book' ) ) );
	routing_expect( '@templates/archive-book.twig' === $archive['templates'][0], 'The queried post type must win over a stray global post.' );
	$day = routing_render( 'archive.php', array( 'day' => true, 'options' => array( 'date_format' => 'd/m/Y' ) ) );
	routing_expect( 'Archive: 29/10/2026' === $day['context']['title'], 'Day archive titles must use the configured date format, including the day of the month.' );
	routing_expect( array( 'd/m/Y' ) === $GLOBALS['emulsify_route_state']['date_calls'], 'Day archives must pass the site date format to WordPress.' );
	$month = routing_render( 'archive.php', array( 'month' => true ) );
	routing_expect( 'Archive: Oct 2026' === $month['context']['title'], 'Month archive formatting must remain unchanged.' );
	$year = routing_render( 'archive.php', array( 'year' => true ) );
	routing_expect( 'Archive: 2026' === $year['context']['title'], 'Year archive formatting must remain unchanged.' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}
	echo "Template routing smoke checks passed.\n";
}
