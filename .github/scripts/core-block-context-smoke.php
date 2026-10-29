<?php
/**
 * Exercises mapped blocks with real WordPress rendering and Timber context.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Blocks\CoreBlockTwigRenderer;
use Timber\Timber;

if ( ! defined( 'ABSPATH' ) || ! class_exists( CoreBlockTwigRenderer::class ) ) {
	throw new RuntimeException( 'Run this smoke with wp eval-file in the generated WordPress fixture.' );
}

function emulsify_block_context_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$saved_globals = array();
foreach ( array( 'post', 'wp_query', 'wp_the_query', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' ) as $name ) {
	$saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
}
$saved_context = Timber::$context_cache;
$directory     = get_stylesheet_directory() . '/templates/block-context-' . uniqid();
$relative      = substr( $directory, strlen( get_stylesheet_directory() ) + 1 );
$created_posts = array();
$renderers     = array();
$filters       = array();
$block_names   = array( 'emulsify-fixture/counter', 'emulsify-fixture/recursive' );
$registered    = array();
$context_builds = 0;
$add_filter    = static function ( string $name, callable $callback, int $priority = 10, int $arguments = 1 ) use ( &$filters ): void {
	add_filter( $name, $callback, $priority, $arguments );
	$filters[] = array( $name, $callback, $priority );
};

try {
	emulsify_block_context_assert( wp_mkdir_p( $directory ), 'Could not create mapped-block Twig fixtures.' );
	Timber::$context_cache = array();
	$add_filter( 'timber/context', static function ( array $context ) use ( &$context_builds ): array { ++$context_builds; return $context; }, 999 );
	file_put_contents( $directory . '/inner.twig', '{{ inner_content|raw }}' );
	file_put_contents( $directory . '/content.twig', '{{ content|raw }}' );
	file_put_contents( $directory . '/post.twig', '<span data-context-post="{{ post.id }}"></span>' );
	file_put_contents( $directory . '/late.twig', '<p>LateCoreMapProbe</p>' );
	file_put_contents( $directory . '/recursive.twig', '<section data-context-recursive>{{ post.content|raw }}</section>' );
	foreach ( array( 'Context post A', 'Context post B' ) as $title ) {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title ), true );
		emulsify_block_context_assert( ! is_wp_error( $id ), 'Could not create mapped-block post fixture.' );
		$created_posts[] = $id;
	}
	$query = new WP_Query( array( 'p' => $created_posts[0] ) );
	$query->get_queried_object();
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post'] = get_post( $created_posts[0] );
	$the_post_calls = 0;
	$add_filter( 'the_post', static function () use ( &$the_post_calls ): void { ++$the_post_calls; } );
	foreach ( $block_names as $name ) {
		emulsify_block_context_assert( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ), 'Fixture block name already registered.' );
	}
	$dynamic_renders = 0;
	register_block_type(
		$block_names[0],
		array(
			'render_callback' => static function () use ( &$dynamic_renders ): string {
				++$dynamic_renders;
				return '<p>DynamicInnerBlockProbe</p>';
			},
		)
	);
	$registered[] = $block_names[0];
	register_block_type(
		$block_names[1],
		array(
			'uses_context'    => array( 'postId' ),
			'render_callback' => static fn(): string => '<p>RecursiveBlockProbe</p>',
		)
	);
	$registered[] = $block_names[1];
	$add_filter(
		'render_block_core/paragraph',
		static fn( string $html ): string => $html . '<!-- final child filter -->'
	);
	$add_filter(
		'pre_render_block',
		static function ( $html, array $block ) {
			return ! empty( $block['attrs']['fixtureShortCircuit'] ) ? '<p>ShortCircuitInnerProbe</p>' : $html;
		},
		10,
		2
	);
	$examples = array(
		'group' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>GroupInnerProbe</p><!-- /wp:paragraph --><!-- wp:paragraph {"fixtureShortCircuit":true} --><p>DiscardedInnerProbe</p><!-- /wp:paragraph --><!-- wp:emulsify-fixture/counter /--></div><!-- /wp:group -->',
		'quote' => '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>QuoteInnerProbe</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
		'nested' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>NestedInnerProbe</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote --></div><!-- /wp:group -->',
	);
	$baseline = array_map( 'do_blocks', $examples );
	$heading = '<!-- wp:heading --><h2>ContentOnlyProbe</h2><!-- /wp:heading -->';
	$heading_baseline = do_blocks( $heading );
	$unmapped = '<!-- wp:paragraph --><p>UnmappedBytesProbe &amp; text</p><!-- /wp:paragraph -->';
	$unmapped_baseline = do_blocks( $unmapped );
	$map      = array(
		'core/group'      => $relative . '/inner.twig',
		'core/quote'      => $relative . '/inner.twig',
		'core/post-title' => $relative . '/post.twig',
		'core/heading'    => $relative . '/content.twig',
	);
	$map_filter = static fn( array $values ): array => array_merge( $values, $map );
	$add_filter( 'emulsify_theme_core_block_twig_template_map', $map_filter );
	$add_filter( 'emulsify_theme_core_block_twig_rendering_enabled', static fn(): bool => true );
	$renderer = new CoreBlockTwigRenderer();
	$renderer->register();
	$renderers[] = $renderer;
	foreach ( $examples as $name => $source ) {
		$dynamic_renders = 0;
		$actual = do_blocks( $source );
		emulsify_block_context_assert( $actual === $baseline[ $name ], 'Mapped ' . $name . ' must retain full rendered inner content byte-for-byte, including final child filters.' );
		if ( 'group' === $name ) {
			emulsify_block_context_assert( 1 === $dynamic_renders && 1 === substr_count( $actual, 'DynamicInnerBlockProbe' ) && 1 === substr_count( $actual, 'ShortCircuitInnerProbe' ), 'Inner dynamic and short-circuited blocks must appear once and never render twice.' );
		}
	}
	emulsify_block_context_assert( do_blocks( $heading ) === $heading_baseline, 'Templates printing content must preserve their rendered output byte-for-byte.' );
	emulsify_block_context_assert( do_blocks( $unmapped ) === $unmapped_baseline, 'Unmapped blocks must preserve every output byte.' );
	$late_map = static function ( array $values ) use ( $relative ): array {
		$values['core/heading'] = $relative . '/late.twig';
		return $values;
	};
	$add_filter( 'emulsify_theme_core_block_twig_template_map', $late_map, 11 );
	emulsify_block_context_assert( '<p>LateCoreMapProbe</p>' === do_blocks( $heading ), 'A template-map filter registered after initial rendering must take effect.' );
	emulsify_block_context_assert( 0 === $the_post_calls, 'The first mapped block context must not call setup() on a singular request.' );
	for ( $index = 0; $index < 20; ++$index ) {
		$post_id = $created_posts[ $index % 2 ];
		$instance = new WP_Block( parse_blocks( '<!-- wp:post-title /-->' )[0], array( 'postId' => $post_id ) );
		$global_before = $GLOBALS['post'];
		$output = $instance->render();
		emulsify_block_context_assert( '<span data-context-post="' . $post_id . '"></span>' === $output, 'Mapped blocks must use their own postId context across repeated Query Loop post contexts.' );
		emulsify_block_context_assert( $global_before === $GLOBALS['post'], 'Mapped block context must not replace the global post.' );
	}
	emulsify_block_context_assert( 0 === $the_post_calls, 'Many mapped blocks must not call setup() or fire the_post again.' );
	emulsify_block_context_assert( 1 === $context_builds, 'Many mapped blocks must reuse a single global Timber context build.' );

	$add_filter(
		'query_loop_block_query_vars',
		static fn( array $values ): array => array_merge( $values, array( 'post__in' => $created_posts, 'orderby' => 'post__in', 'posts_per_page' => 2 ) )
	);
	$query_blocks = '<!-- wp:query {"query":{"perPage":2,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
	remove_filter( 'render_block', array( $renderer, 'render_block' ), 20 );
	$the_post_calls = 0;
	do_blocks( $query_blocks );
	$baseline_post_calls = $the_post_calls;
	add_filter( 'render_block', array( $renderer, 'render_block' ), 20, 3 );
	$the_post_calls = 0;
	$query_output = do_blocks( $query_blocks );
	foreach ( $created_posts as $post_id ) {
		emulsify_block_context_assert( 1 === substr_count( $query_output, 'data-context-post="' . $post_id . '"' ), 'Real Query Loop iterations must render each block post exactly once without context leakage.' );
	}
	emulsify_block_context_assert( $the_post_calls === $baseline_post_calls, 'Mapped Query Loop rendering must not add the_post callbacks beyond WordPress itself.' );

	remove_filter( 'render_block', array( $renderer, 'render_block' ), 20 );
	$add_filter(
		'emulsify_theme_core_block_twig_template_map',
		static function ( array $values ) use ( $relative ): array {
			$values['emulsify-fixture/recursive'] = $relative . '/recursive.twig';
			return $values;
		},
		12
	);
	$recursion_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Recursive block fixture', 'post_content' => '<!-- wp:emulsify-fixture/recursive /-->' ), true );
	emulsify_block_context_assert( ! is_wp_error( $recursion_id ), 'Could not create recursive block fixture.' );
	$created_posts[] = $recursion_id;
	$query = new WP_Query( array( 'p' => $recursion_id ) );
	$query->get_queried_object();
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post'] = get_post( $recursion_id );
	$recursion_calls = 0;
	$add_filter(
		'emulsify_theme_core_block_twig_context',
		static function ( array $context ) use ( &$recursion_calls ): array {
			// Bound a regression before it can exhaust memory in the fixture.
			if ( ++$recursion_calls > 8 ) throw new RuntimeException( 'Mapped content recursion did not stop.' );
			return $context;
		}
	);
	$recursive_renderer = new CoreBlockTwigRenderer();
	$recursive_renderer->register();
	$renderers[] = $recursive_renderer;
	$output = do_blocks( get_post_field( 'post_content', $recursion_id ) );
	emulsify_block_context_assert( 1 === substr_count( $output, 'RecursiveBlockProbe' ) && $recursion_calls <= 2, 'Mapped post.content recursion must stop with WordPress markup and retain its content once.' );
	echo "Mapped block WordPress context smoke passed.\n";
} finally {
	foreach ( $renderers as $renderer ) remove_filter( 'render_block', array( $renderer, 'render_block' ), 20 );
	foreach ( array_reverse( $filters ) as $filter ) remove_filter( $filter[0], $filter[1], $filter[2] );
	foreach ( $created_posts as $id ) wp_delete_post( $id, true );
	foreach ( $registered as $name ) unregister_block_type( $name );
	if ( is_dir( $directory ) ) {
		foreach ( glob( $directory . '/*.twig' ) as $file ) unlink( $file );
		rmdir( $directory );
	}
	Timber::$context_cache = $saved_context;
	foreach ( $saved_globals as $name => $saved ) {
		if ( $saved[0] ) $GLOBALS[ $name ] = $saved[1];
		else unset( $GLOBALS[ $name ] );
	}
}
