<?php
/**
 * Behavioral coverage for the parent's fallback navigation markup.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$autoload = __DIR__ . '/../../vendor/autoload.php';

if ( ! is_readable( $autoload ) || ! class_exists( DOMDocument::class ) ) {
	fwrite( STDERR, "Composer dependencies and PHP DOM are required for the fallback navigation smoke test.\n" );
	exit( 1 );
}

require_once $autoload;
require_once __DIR__ . '/wordpress-text-test-adapter.php';

// Only WordPress translation needs a stand-in outside the WordPress fixture.
if ( ! function_exists( '__' ) ) {
	/**
	 * Returns fixture translations while recording their translation domain.
	 *
	 * @param string $text   Source text.
	 * @param string $domain Translation domain.
	 * @return string Untranslated text.
	 */
	function __( string $text, string $domain = 'default' ): string {
		$GLOBALS['emulsify_navigation_translations'][] = array( $text, $domain );
		return $GLOBALS['emulsify_navigation_translation_overrides'][ $domain ][ $text ] ?? $text;
	}
}

/**
 * Parses output once, matching a browser's HTML entity decoding.
 *
 * @param string $html Rendered HTML.
 * @return DOMXPath Parsed markup queries.
 */
function emulsify_navigation_dom( string $html ): DOMXPath {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return new DOMXPath( $document );
}

/**
 * Records an assertion without stopping the other regression checks.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure explanation.
 * @param array  $errors    Collected failures.
 * @return void
 */
function emulsify_navigation_expect( bool $condition, string $message, array &$errors ): void {
	if ( ! $condition ) {
		$errors[] = $message;
	}
}

$integration = new Twig();
$loader      = new \Twig\Loader\FilesystemLoader();
$loader->addPath( __DIR__ . '/../../templates', 'templates' );
$environment = new \Twig\Environment( $loader, $integration->environment_options( array( 'cache' => false ) ) );

foreach ( $integration->functions( array() ) as $name => $definition ) {
	$environment->addFunction(
		new \Twig\TwigFunction(
			$name,
			$definition['callable'],
			$definition['options'] ?? array()
		)
	);
}

$environment->addFunction( new \Twig\TwigFunction( 'function', array( new \Timber\Twig(), 'exec_function' ) ) );
emulsify_register_wordpress_text_test_filters( $environment );
$integration->extensions( $environment );

$hostile     = '"><script>alert(1)</script>';
$urls        = array(
	'Previous' => '/?s=needle&paged=4&filter=a%26b',
	'1'        => '/?s=needle&paged=1&filter=a%26b',
	$hostile   => '/?s=needle&paged=6&filter=a%26b',
	'Next'     => '/?s=needle&paged=6&filter=a%26b',
);
$display_url = static function ( string $url ): string {
	return str_replace( '&', '&#038;', $url );
};
$pagination_context = array(
	'pagination' => array(
		'prev'  => array( 'link' => $display_url( $urls['Previous'] ) ),
		'pages' => array(
			array( 'class' => 'page-number page-numbers', 'title' => '1', 'link' => $display_url( $urls['1'] ), 'current' => false ),
			array( 'class' => 'dots', 'title' => '&hellip;' ),
			array( 'class' => 'page-number page-numbers current', 'title' => '5', 'current' => true ),
			array( 'class' => 'page-number page-numbers', 'title' => $hostile, 'link' => $display_url( $urls[ $hostile ] ), 'current' => false ),
		),
		'next'  => array( 'link' => $display_url( $urls['Next'] ) ),
	),
);
$pagination = $environment->render(
	'@templates/partials/pagination.twig',
	$pagination_context
);
$pager_dom = emulsify_navigation_dom( $pagination );
$checks    = array(
	'pagination current page'      => array(),
	'pagination literal ellipsis'  => array(),
	'pagination page prefixes'     => array(),
	'pagination URL escaping'      => array(),
	'pagination localized numbers' => array(),
	'menu current item'            => array(),
);

$current = $pager_dom->query( '//nav//*[@aria-current="page"]' );
emulsify_navigation_expect( 1 === $current->length && '5' === trim( $current->item( 0 )->textContent ), 'Only the actual current page must have aria-current="page".', $checks['pagination current page'] );
emulsify_navigation_expect( 1 === $pager_dom->query( '//li[contains(concat(" ", normalize-space(@class), " "), " pagination__item--current ")]' )->length, 'The current item must preserve its BEM modifier.', $checks['pagination current page'] );

// Current state must also work when a pagination provider supplies a link.
$pagination_context['pagination']['pages'][2]['link'] = $display_url( '/?s=needle&paged=5&filter=a%26b' );
$linked_current_dom = emulsify_navigation_dom( $environment->render( '@templates/partials/pagination.twig', $pagination_context ) );
$linked_current     = $linked_current_dom->query( '//nav//*[@aria-current="page"]' );
emulsify_navigation_expect( 1 === $linked_current->length && '5' === trim( $linked_current->item( 0 )->lastChild->textContent ), 'An explicitly current item must be marked even when it supplies a link.', $checks['pagination current page'] );

$dots = $pager_dom->query( '//nav//*[contains(concat(" ", normalize-space(@class), " "), " dots ")]' );
emulsify_navigation_expect( 1 === $dots->length && 'span' === $dots->item( 0 )->nodeName && '…' === trim( $dots->item( 0 )->textContent ), 'Timber ellipsis items must render as non-interactive spans with the literal … character.', $checks['pagination literal ellipsis'] );
emulsify_navigation_expect( false !== strpos( $pagination, '…' ) && false === strpos( $pagination, '&amp;hellip;' ), 'The ellipsis must not appear as literal entity text.', $checks['pagination literal ellipsis'] );
emulsify_navigation_expect( in_array( array( '…', 'emulsify' ), $GLOBALS['emulsify_navigation_translations'] ?? array(), true ), 'The literal ellipsis must pass through the theme translation domain.', $checks['pagination literal ellipsis'] );

$links = $pager_dom->query( '//nav//a' );
emulsify_navigation_expect( count( $urls ) === $links->length, 'Previous, numeric, and next pagination links must all render.', $checks['pagination URL escaping'] );

$numbered_links = $pager_dom->query( '//nav//a[contains(concat(" ", normalize-space(@class), " "), " page-number ")]' );
$prefix_query   = './span[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]';
emulsify_navigation_expect( 2 === $numbered_links->length, 'The fixture must contain two numbered pagination links.', $checks['pagination page prefixes'] );
foreach ( $numbered_links as $link ) {
	$prefixes = $pager_dom->query( $prefix_query, $link );
	emulsify_navigation_expect( 1 === $prefixes->length && 'Page ' === $prefixes->item( 0 )->textContent, 'Each numbered pagination link must contain one screen-reader-text span with Page and a trailing space.', $checks['pagination page prefixes'] );
}
$linked_prefixes = $linked_current_dom->query( $prefix_query, $linked_current->item( 0 ) );
emulsify_navigation_expect( 1 === $linked_prefixes->length && 'Page ' === $linked_prefixes->item( 0 )->textContent, 'A current page supplied as a numbered link must retain the Page prefix.', $checks['pagination page prefixes'] );
emulsify_navigation_expect( 2 === $pager_dom->query( '//nav//span[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]' )->length, 'Previous, next, unlinked current, and ellipsis items must not acquire Page prefix spans.', $checks['pagination page prefixes'] );
emulsify_navigation_expect( in_array( array( 'Page', 'emulsify' ), $GLOBALS['emulsify_navigation_translations'] ?? array(), true ), 'The Page prefix must use the parent theme translation domain.', $checks['pagination page prefixes'] );

$GLOBALS['emulsify_navigation_translation_overrides']['emulsify']['Page'] = $hostile;
$translated_pagination = $environment->render( '@templates/partials/pagination.twig', $pagination_context );
$translated_dom        = emulsify_navigation_dom( $translated_pagination );
$translated_prefixes   = $translated_dom->query( '//nav//span[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]' );
emulsify_navigation_expect( $translated_prefixes->length > 0 && $hostile . ' ' === $translated_prefixes->item( 0 )->textContent && 0 === $translated_dom->query( '//script' )->length, 'Translated Page prefixes must remain HTML escaped and retain their separating space.', $checks['pagination page prefixes'] );
unset( $GLOBALS['emulsify_navigation_translation_overrides'] );

$localized_pagination = $environment->render(
	'@templates/partials/pagination.twig',
	array(
		'pagination' => array(
			'pages' => array(
				array( 'title' => '1&nbsp;000', 'current' => true ),
				array( 'title' => '1&nbsp;001', 'link' => '/?paged=1001', 'current' => false ),
			),
		),
	)
);
$localized_dom     = emulsify_navigation_dom( $localized_pagination );
$localized_current = $localized_dom->query( '//nav//span[@aria-current="page"]' );
$localized_link    = $localized_dom->query( '//nav//a' );
emulsify_navigation_expect( 1 === $localized_current->length && "1\u{00A0}000" === $localized_current->item( 0 )->textContent, 'Current page numbers must render a non-breaking thousands separator.', $checks['pagination localized numbers'] );
emulsify_navigation_expect( 1 === $localized_link->length && "Page 1\u{00A0}001" === $localized_link->item( 0 )->textContent, 'Linked page numbers must retain the Page prefix and a non-breaking thousands separator.', $checks['pagination localized numbers'] );
emulsify_navigation_expect( false === strpos( $localized_pagination, '&nbsp;' ) && false === strpos( $localized_pagination, '&amp;nbsp;' ), 'Localized numbers must contain the actual non-breaking space, not literal or double-escaped entity text.', $checks['pagination localized numbers'] );

$expected_urls = array_values( $urls );
foreach ( $links as $index => $link ) {
	$title    = trim( $link->textContent );
	$actual   = $link->getAttribute( 'href' );
	$expected = $expected_urls[ $index ] ?? '';
	parse_str( (string) parse_url( $actual, PHP_URL_QUERY ), $actual_query );
	parse_str( (string) parse_url( $expected, PHP_URL_QUERY ), $expected_query );
	emulsify_navigation_expect( '' !== $expected && $actual === $expected && $actual_query === $expected_query && null === parse_url( $actual, PHP_URL_FRAGMENT ), sprintf( '%s link must decode once to %s; got %s.', $title, $expected, $actual ), $checks['pagination URL escaping'] );
}

$raw_url    = '/?s=x&paged=2&quote=" onmouseover="alert(1)';
$attributes = $environment->createTemplate( '<a {{ add_attributes({href: url, title: title}) }}>{{ title }}</a>' )->render( array( 'url' => $raw_url, 'title' => $hostile ) );
$raw_dom    = emulsify_navigation_dom( $attributes );
$raw_link   = $raw_dom->query( '//a' )->item( 0 );
emulsify_navigation_expect( $raw_url === $raw_link->getAttribute( 'href' ) && $hostile === $raw_link->getAttribute( 'title' ) && ! $raw_link->hasAttribute( 'onmouseover' ) && 0 === $raw_dom->query( '//script' )->length && false !== strpos( $attributes, '&amp;paged=' ), 'AttributeBag must continue escaping raw ampersands, quotes, and hostile titles.', $checks['pagination URL escaping'] );
emulsify_navigation_expect( 0 === $pager_dom->query( '//script' )->length, 'Pagination titles must remain HTML escaped.', $checks['pagination URL escaping'] );

$menu = $environment->render(
	'@templates/partials/menu.twig',
	array(
		'items' => array(
			array(
				'title'                 => 'Ancestor',
				'link'                  => '/ancestor/',
				'current'               => false,
				'current_item_ancestor' => true,
				'current_item_parent'   => true,
				'classes'               => array( 'current-menu-ancestor', 'current-menu-parent' ),
				'children'              => array(
					array( 'title' => 'Current', 'link' => '/current/', 'current' => true ),
				),
			),
			array( 'title' => $hostile, 'link' => '/unrelated/?a=1&b=2', 'current' => false ),
		),
	)
);
$menu_dom = emulsify_navigation_dom( $menu );
$current  = $menu_dom->query( '//ul//a[@aria-current="page"]' );
emulsify_navigation_expect( 1 === $current->length && '/current/' === $current->item( 0 )->getAttribute( 'href' ), 'Only the actual current menu link must have aria-current="page"; ancestors stay unmarked.', $checks['menu current item'] );
emulsify_navigation_expect( 1 === $menu_dom->query( '//ul[contains(concat(" ", normalize-space(@class), " "), " menu--sub ")]' )->length && 0 === $menu_dom->query( '//script' )->length, 'Nested menu BEM classes and HTML-escaped titles must remain intact.', $checks['menu current item'] );

$failed = false;

foreach ( $checks as $name => $errors ) {
	if ( $errors ) {
		$failed = true;
		fwrite( STDERR, 'FAIL ' . $name . ': ' . implode( ' ', $errors ) . "\n" );
	} else {
		echo 'PASS ' . $name . "\n";
	}
}

exit( $failed ? 1 : 0 );
