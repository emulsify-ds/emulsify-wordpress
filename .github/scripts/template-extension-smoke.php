<?php
/**
 * Exact-byte coverage for parent template output and child extension points.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Runtime\Twig;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$repo_root = dirname( __DIR__, 2 );
$autoload  = $repo_root . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer dependencies are required for the template extension smoke test.\n" );
	exit( 1 );
}

require_once $autoload;

$GLOBALS['emulsify_extension_repo_root'] = $repo_root;
$GLOBALS['emulsify_extension_child']     = sys_get_temp_dir() . '/emulsify-template-extension-' . uniqid( '', true );
$GLOBALS['emulsify_extension_hooks']     = array();

function get_template_directory(): string {
	return $GLOBALS['emulsify_extension_repo_root'];
}

function get_stylesheet_directory(): string {
	return $GLOBALS['emulsify_extension_child'];
}

function apply_filters( string $hook, $value, ...$arguments ) {
	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function get_the_password_form( $post = 0 ): string {
	$GLOBALS['emulsify_extension_password_form_posts'][] = $post;
	return '<form class="post-password-form" data-filtered-password-form="true" action="https://example.test/wordpress/wp-login.php?action=postpass" method="post"><label for="pwbox-' . $post . '">Password</label><input id="pwbox-' . $post . '" name="post_password" type="password"><button type="submit">Enter</button></form>';
}

function get_the_title( $post = 0 ): string {
	$GLOBALS['emulsify_extension_title_posts'][] = $post;
	return 'Protected: Secret <title> & fixture';
}

function do_action( string $hook, ...$arguments ): void {
	$GLOBALS['emulsify_extension_hooks'][] = $hook;
	echo '<!-- action:' . $hook . ' -->';
}

function wp_head(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_head';
	echo '<!-- wp_head -->';
}

function wp_body_open(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_body_open';
	echo '<!-- wp_body_open -->';
}

function wp_footer(): void {
	$GLOBALS['emulsify_extension_hooks'][] = 'wp_footer';
	echo '<!-- wp_footer -->';
}

/**
 * Builds deterministic data with fresh attribute helpers for every render.
 *
 * @param bool $has_title   Whether the document has a title.
 * @param bool $has_menu    Whether primary menu items exist.
 * @param bool $has_content Whether the fixture content exists.
 * @return array Template context.
 */
function emulsify_extension_context( bool $has_title, bool $has_menu, bool $has_content ): array {
	$items = array(
		array(
			'title'    => 'Current <link> & fixture',
			'link'     => '/current/?first=1&second=2',
			'classes'  => array( 'fixture-menu-item' ),
			'current'  => true,
			'children' => array(
				array( 'title' => 'Nested & fixture', 'link' => '/nested/', 'target' => '_blank' ),
			),
		),
		array( 'title' => 'Other & fixture', 'link' => '/other/' ),
	);
	$context = array(
		'site'            => array(
			'url'                 => 'https://example.test/?first=1&second=2',
			'name'                => 'Fixture <site> & WordPress',
			'charset'             => 'UTF-8',
			'language_attributes' => 'lang="en" dir="ltr"',
		),
		'body_class'      => 'fixture-body route-page',
		'menu'            => $has_menu ? array( 'get_items' => $items ) : null,
		'fixture_content' => $has_content ? '<p>Content &amp; fixture</p>' : '',
	);

	if ( $has_title ) {
		$context['title'] = 'Page <title> & fixture';
	}

	return $context;
}

/**
 * Captures explicitly or compares every output byte against a committed file.
 *
 * @param string $name    Golden name.
 * @param string $output  Rendered output.
 * @param bool   $capture Whether to update the golden deliberately.
 * @return void
 */
function emulsify_extension_golden( string $name, string $output, bool $capture ): void {
	$file = __DIR__ . '/../fixtures/template-extension/' . $name . '.html';

	if ( $capture ) {
		if ( false === file_put_contents( $file, $output ) ) {
			throw new RuntimeException( 'Could not capture ' . $file );
		}
	} elseif ( ! is_file( $file ) || file_get_contents( $file ) !== $output ) {
		throw new RuntimeException( $name . ': rendered bytes differ from the pre-extension golden file.' );
	}

	echo ( $capture ? 'CAPTURE ' : 'PASS ' ) . $name . ' (' . strlen( $output ) . " bytes)\n";
}

/**
 * Collects independent extension failures in one run.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure explanation.
 * @param array  $errors    Collected failures.
 * @return void
 */
function emulsify_extension_expect( bool $condition, string $message, array &$errors ): void {
	if ( ! $condition ) {
		$errors[] = $message;
	}
}

$capture   = array_slice( $argv, 1 ) === array( '--update-goldens' );
$exit_code = 0;

if ( count( $argv ) > 1 && ! $capture ) {
	fwrite( STDERR, "Only --update-goldens is supported; ordinary runs never rewrite fixtures.\n" );
	exit( 1 );
}

try {
	if ( ! mkdir( get_stylesheet_directory() . '/templates', 0777, true ) ) {
		throw new RuntimeException( 'Could not create the disposable child theme.' );
	}
	$integration = new Twig();
	$filesystem  = $integration->loader_paths( new \Twig\Loader\FilesystemLoader() );
	$fixtures    = new \Twig\Loader\ArrayLoader(
		array(
			'content-fixture.twig' => '{% extends "@emulsify-tpl/layouts/base.twig" %}{% block content %}{{ fixture_content|raw }}{% endblock %}',
		)
	);
	$environment = new \Twig\Environment(
		new \Twig\Loader\ChainLoader( array( $fixtures, $filesystem ) ),
		$integration->environment_options( array( 'cache' => false ) )
	);

	foreach ( $integration->functions( array() ) as $name => $definition ) {
		$environment->addFunction( new \Twig\TwigFunction( $name, $definition['callable'], $definition['options'] ?? array() ) );
	}
	$environment->addFunction( new \Twig\TwigFunction( 'function', array( new \Timber\Twig(), 'exec_function' ) ) );
	$environment->addFunction( new \Twig\TwigFunction( 'action', 'do_action' ) );
	$integration->extensions( $environment );
	$core = $environment->getExtension( \Twig\Extension\CoreExtension::class );
	$core->setTimezone( 'UTC' );
	$environment->addFilter(
		new \Twig\TwigFilter(
			'date',
			static function ( $date, $format = null, $timezone = null ) use ( $core ): string {
				return $core->formatDate( 'now' === $date ? new DateTimeImmutable( '2026-10-22T12:00:00Z' ) : $date, $format, $timezone );
			}
		)
	);

	foreach ( array( false, true ) as $has_title ) {
		foreach ( array( false, true ) as $has_menu ) {
			$suffix = ( $has_title ? 'title' : 'no-title' ) . '-' . ( $has_menu ? 'menu' : 'no-menu' );

			foreach ( array( false, true ) as $has_content ) {
				$template = $has_content ? 'content-fixture.twig' : '@emulsify-tpl/layouts/base.twig';
				$output   = $environment->render( $template, emulsify_extension_context( $has_title, $has_menu, $has_content ) );
				if ( ! $has_content && $environment->render( 'content-fixture.twig', emulsify_extension_context( $has_title, $has_menu, false ) ) !== $output ) {
					throw new RuntimeException( 'An empty child content block must preserve the direct base output.' );
				}
				emulsify_extension_golden( 'content-' . $suffix . '-' . ( $has_content ? 'populated' : 'empty' ), $output, $capture );
			}
		}
	}

	foreach ( array( false, true ) as $has_post ) {
		$context         = emulsify_extension_context( false, true, $has_post );
		$context['post'] = $has_post ? array( 'id' => 42, 'type' => 'page', 'title' => 'Post <title> & fixture', 'content' => $context['fixture_content'] ) : null;
		emulsify_extension_golden( $has_post ? 'page-post' : 'page-no-post', $environment->render( '@emulsify-tpl/page.twig', $context ), $capture );
	}

	$errors        = array();
	foreach ( array( 'tease.twig', 'tease-post.twig' ) as $teaser_template ) {
		foreach ( array( 'manual', 'automatic' ) as $excerpt_source ) {
			foreach ( array( false, true ) as $protected ) {
				$secret = 'protected-' . $excerpt_source . '-excerpt-secret';
				$post   = new class( $protected, $secret ) {
					public $password_required;
					public $excerpt_reads = 0;
					private $summary;

					public function __construct( bool $protected, string $summary ) {
						$this->password_required = $protected;
						$this->summary = $summary;
					}

					public function excerpt( array $options = array() ): string {
						++$this->excerpt_reads;
						return $this->summary;
					}
				};
				$teaser = $environment->render( '@emulsify-tpl/partials/' . $teaser_template, array( 'post' => $post ) );
				emulsify_extension_expect( $protected ? false === strpos( $teaser, $secret ) && 0 === $post->excerpt_reads : false !== strpos( $teaser, $secret ) && $post->excerpt_reads > 0, $teaser_template . ' must skip ' . $excerpt_source . ' excerpts entirely while a password is required and retain them after unlock.', $errors );
			}
		}
	}

	$password_context = emulsify_extension_context( false, true, false );
	$password_context['site']['link'] = 'https://example.test';
	$password_context['post'] = array( 'id' => 73, 'password_required' => true, 'content' => 'protected-content-secret', 'excerpt' => 'protected-excerpt-secret' );
	$GLOBALS['emulsify_extension_password_form_posts'] = array();
	$GLOBALS['emulsify_extension_title_posts'] = array();
	$GLOBALS['emulsify_extension_hooks'] = array();
	$password_output = $environment->render( '@emulsify-tpl/single-password.twig', $password_context );
	emulsify_extension_expect( array( 73 ) === $GLOBALS['emulsify_extension_password_form_posts'], 'The password template must request WordPress\'s form for the current post ID.', $errors );
	emulsify_extension_expect( array( 73 ) === $GLOBALS['emulsify_extension_title_posts'] && false !== strpos( $password_output, '>Protected: Secret &lt;title&gt; &amp; fixture</h1>' ), 'The password template must display WordPress\'s protected title while preserving title autoescaping.', $errors );
	emulsify_extension_expect( false !== strpos( $password_output, '<form class="post-password-form" data-filtered-password-form="true" action="https://example.test/wordpress/wp-login.php?action=postpass"' ) && false === strpos( $password_output, '&lt;form'), 'The password template must preserve trusted core/plugin form markup and the site URL instead of building a home-URL action.', $errors );
	emulsify_extension_expect( false === strpos( $password_output, 'protected-content-secret' ) && false === strpos( $password_output, 'protected-excerpt-secret' ), 'The password template must never print protected content or excerpts.', $errors );
	emulsify_extension_expect( array( 'get_header', 'wp_head', 'wp_body_open', 'wp_footer', 'get_footer' ) === $GLOBALS['emulsify_extension_hooks'], 'The password template must preserve structural WordPress hooks and their order.', $errors );
	$password_marker = '<!-- child password content -->';
	$fixtures->setTemplate( 'child-password-content.twig', '{% extends "@emulsify-tpl/single-password.twig" %}{% block content %}' . $password_marker . '{{ parent() }}{% endblock %}' );
	$password_child = $environment->render( 'child-password-content.twig', $password_context );
	emulsify_extension_expect( 1 === substr_count( $password_child, $password_marker ) && str_replace( $password_marker, '', $password_child ) === $password_output, 'Child password templates must retain the public content block and surrounding parent markup.', $errors );

	$rtl_context   = emulsify_extension_context( true, true, true );
	$rtl_context['site']['language_attributes'] = 'lang="ar" dir="rtl"';
	$rtl_output = $environment->render( 'content-fixture.twig', $rtl_context );
	emulsify_extension_expect( false !== strpos( $rtl_output, '<html lang="ar" dir="rtl">' ), 'Plain WordPress language attributes must preserve the language and RTL direction without escaping their quotes.', $errors );
	emulsify_extension_expect( false !== strpos( $rtl_output, 'Fixture &lt;site&gt; &amp; WordPress' ) && false !== strpos( $rtl_output, 'Page &lt;title&gt; &amp; fixture' ), 'Trusting the core language attribute fragment must preserve autoescaping for site names and page titles.', $errors );
	$page_expected = file_get_contents( __DIR__ . '/../fixtures/template-extension/page-post.html' );
	$child_template = get_stylesheet_directory() . '/templates/page.twig';
	$child_before   = '<!-- child page namespace start -->';
	$child_after    = '<!-- child page namespace end -->';
	$context         = emulsify_extension_context( false, true, true );
	$context['post'] = array( 'id' => 42, 'type' => 'page', 'title' => 'Post <title> & fixture', 'content' => $context['fixture_content'] );

	try {
		if ( false === file_put_contents( $child_template, '{% extends "@emulsify-tpl/page.twig" %}{% block content %}' . $child_before . '{{ parent() }}' . $child_after . '{% endblock %}' ) ) {
			throw new RuntimeException( 'Could not write the disposable child page template.' );
		}
		$output = $environment->render( '@templates/page.twig', $context );
		emulsify_extension_expect( 1 === substr_count( $output, $child_before ) && 1 === substr_count( $output, $child_after ), 'The child-first namespace must select the physical child page override exactly once.', $errors );
		emulsify_extension_expect( str_replace( array( $child_before, $child_after ), '', $output ) === $page_expected, 'The physical child page override must preserve every surrounding parent-page byte.', $errors );
	} finally {
		if ( is_file( $child_template ) ) {
			unlink( $child_template );
		}
		// Refresh filesystem lookup caches after removing the temporary override.
		$environment->setLoader( new \Twig\Loader\ChainLoader( array( $fixtures, $integration->loader_paths( new \Twig\Loader\FilesystemLoader() ) ) ) );
	}
	emulsify_extension_expect( $environment->render( '@templates/page.twig', $context ) === $page_expected, 'Removing the physical child page override must restore every parent-page byte.', $errors );

	foreach ( array( 'head', 'header', 'content', 'footer', 'site_header', 'main', 'page_title' ) as $block ) {
		$name   = 'child-page-' . $block . '.twig';
		$marker = '<!-- child:' . $block . ' -->';
		$fixtures->setTemplate( $name, '{% extends "@emulsify-tpl/page.twig" %}{% block ' . $block . ' %}' . $marker . '{{ parent() }}{% endblock %}' );
		$context         = emulsify_extension_context( false, true, true );
		$context['post'] = array( 'id' => 42, 'type' => 'page', 'title' => 'Post <title> & fixture', 'content' => $context['fixture_content'] );
		$output          = $environment->render( $name, $context );
		emulsify_extension_expect( 1 === substr_count( $output, $marker ), 'A child page must override the ' . $block . ' block exactly once.', $errors );
		emulsify_extension_expect( str_replace( $marker, '', $output ) === $page_expected, 'The ' . $block . ' override with parent() must preserve every surrounding parent-page byte.', $errors );
	}

	$replacement_cases = array(
		'site_header' => array( 'absent' => array( '<header class="site-header"', '<nav ' ), 'present' => array( '<main class="site-main"', '<header class="page-header"', '<p>Content &amp; fixture</p>', '<footer ' ) ),
		'main'        => array( 'absent' => array( '<main ', '<header class="page-header"', '<p>Content &amp; fixture</p>' ), 'present' => array( '<header class="site-header"', '<footer ', 'href="#content"' ) ),
		'page_title'  => array( 'absent' => array( '<header class="page-header"' ), 'present' => array( '<header class="site-header"', '<main class="site-main"', '<p>Content &amp; fixture</p>', '<footer ' ) ),
		'footer'      => array( 'absent' => array( '<footer ' ), 'present' => array( '<header class="site-header"', '<main class="site-main"', '<p>Content &amp; fixture</p>' ) ),
	);
	$hooks_expected = array( 'get_header', 'wp_head', 'wp_body_open', 'wp_footer', 'get_footer' );

	foreach ( $replacement_cases as $block => $fragments ) {
		$name   = 'replace-' . $block . '.twig';
		$marker = '<!-- replacement:' . $block . ' -->';
		$fixtures->setTemplate( $name, '{% extends "content-fixture.twig" %}{% block ' . $block . ' %}' . $marker . '{% endblock %}' );
		$GLOBALS['emulsify_extension_hooks'] = array();
		$output = $environment->render( $name, emulsify_extension_context( true, true, true ) );
		emulsify_extension_expect( 1 === substr_count( $output, $marker ), 'Replacing ' . $block . ' must render the child block.', $errors );
		foreach ( $fragments['absent'] as $fragment ) {
			emulsify_extension_expect( false === strpos( $output, $fragment ), 'Replacing ' . $block . ' must remove its complete wrapper/content: ' . $fragment, $errors );
		}
		foreach ( $fragments['present'] as $fragment ) {
			emulsify_extension_expect( false !== strpos( $output, $fragment ), 'Replacing ' . $block . ' must preserve its surrounding markup: ' . $fragment, $errors );
		}
		emulsify_extension_expect( $hooks_expected === $GLOBALS['emulsify_extension_hooks'], 'Replacing ' . $block . ' must preserve the structural WordPress hooks and their order.', $errors );
		emulsify_extension_expect( 1 === substr_count( $output, 'href="#content"' ), 'Replacing ' . $block . ' must preserve the skip link outside the block.', $errors );
	}

	foreach ( array( 'site_header' => 'header', 'main' => 'page_title' ) as $outer => $inner ) {
		$name         = 'nested-' . $outer . '.twig';
		$outer_marker = '<!-- outer:' . $outer . ' -->';
		$inner_marker = '<!-- inner:' . $inner . ' -->';
		$fixtures->setTemplate( $name, '{% extends "content-fixture.twig" %}{% block ' . $outer . ' %}' . $outer_marker . '{% endblock %}{% block ' . $inner . ' %}' . $inner_marker . '{% endblock %}' );
		$output = $environment->render( $name, emulsify_extension_context( true, true, true ) );
		emulsify_extension_expect( 1 === substr_count( $output, $outer_marker ) && false === strpos( $output, $inner_marker ), $inner . ' must remain nested inside ' . $outer . ' and disappear when the enclosing block is replaced.', $errors );
	}

	if ( $errors ) {
		throw new RuntimeException( implode( "\n", $errors ) );
	}
	echo "PASS protected teaser excerpts, native password forms and child inheritance, plain language attributes, RTL, other field autoescaping, physical child page lookup and restoration, child page inheritance, seven block names, replacement boundaries, nested blocks, and structural WordPress hooks.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( is_dir( get_stylesheet_directory() . '/templates' ) ) {
		rmdir( get_stylesheet_directory() . '/templates' );
	}
	if ( is_dir( get_stylesheet_directory() ) ) {
		rmdir( get_stylesheet_directory() );
	}
}

exit( $exit_code );
