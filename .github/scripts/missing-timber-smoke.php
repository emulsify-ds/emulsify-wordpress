<?php
/**
 * Checks that missing Timber blocks theme pages, not WordPress's native routes.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\MissingTimber;

require_once __DIR__ . '/../../includes/Runtime/MissingTimber.php';

$GLOBALS['emulsify_missing_timber_flags']   = array();
$GLOBALS['emulsify_missing_timber_queries'] = array();
$GLOBALS['emulsify_missing_timber_hooks']   = array();

function add_action( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['emulsify_missing_timber_hooks'][ $hook ] = array( $callback, $priority );
}
function is_admin(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['admin'] );
}
function wp_doing_ajax(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['ajax'] );
}
function wp_is_json_request(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['json'] );
}
function is_robots(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['robots'] );
}
function is_favicon(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['favicon'] );
}
function is_feed(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['feed'] );
}
function is_trackback(): bool {
	return ! empty( $GLOBALS['emulsify_missing_timber_flags']['trackback'] );
}
function get_query_var( string $name, $default = '' ) {
	return $GLOBALS['emulsify_missing_timber_queries'][ $name ] ?? $default;
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function wp_kses_post( string $html ): string {
	return $html;
}
function wp_die( string $message, string $title, array $arguments ): void {
	throw new RuntimeException( $message, $arguments['response'] ?? 0 );
}

$handler  = new MissingTimber();
$handler->register();
$failures = array();
if ( 0 !== $GLOBALS['emulsify_missing_timber_hooks']['template_redirect'][1] ) {
	$failures[] = 'The missing Timber handler must run before theme rendering.';
}

$cases = array(
	'theme HTML request'          => array( array(), array(), true ),
	'unrelated query string'      => array( array(), array( 'search' => 'sitemap', 'example' => 'feed' ), true ),
	'empty sitemap query'         => array( array(), array( 'sitemap' => '', 'sitemap-stylesheet' => '' ), true ),
	'admin request'               => array( array( 'admin' => true ), array(), false ),
	'AJAX request'                => array( array( 'ajax' => true ), array(), false ),
	'JSON request'                => array( array( 'json' => true ), array(), false ),
	'robots.txt request'          => array( array( 'robots' => true ), array(), false ),
	'favicon.ico request'         => array( array( 'favicon' => true ), array(), false ),
	'feed request'                => array( array( 'feed' => true ), array(), false ),
	'trackback request'           => array( array( 'trackback' => true ), array(), false ),
	'sitemap index request'       => array( array(), array( 'sitemap' => 'index' ), false ),
	'sitemap provider request'    => array( array(), array( 'sitemap' => 'posts', 'sitemap-subtype' => 'page' ), false ),
	'sitemap stylesheet request'  => array( array(), array( 'sitemap-stylesheet' => 'sitemap' ), false ),
	'sitemap index stylesheet'    => array( array(), array( 'sitemap-stylesheet' => 'index' ), false ),
);
foreach ( $cases as $label => list( $flags, $query, $should_die ) ) {
	$GLOBALS['emulsify_missing_timber_flags']   = $flags;
	$GLOBALS['emulsify_missing_timber_queries'] = $query;
	$died = false;
	try {
		$handler->runtime_error();
	} catch ( RuntimeException $error ) {
		$died = true;
		if ( 500 !== $error->getCode() || ! str_contains( $error->getMessage(), 'composer require timber/timber' ) ) {
			$failures[] = $label . ' did not show the actionable missing Timber error.';
		}
	}
	if ( $should_die !== $died ) {
		$failures[] = $label . ( $should_die ? ' must stop HTML rendering.' : ' must remain available without Timber.' );
	}
}
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Missing Timber smoke checks passed (' . count( $cases ) . " request cases).\n";
