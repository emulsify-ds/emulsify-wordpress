<?php
/**
 * Exercises optional video embeds in an active generated WordPress child theme.
 *
 * Run with wp eval-file in the disposable WordPress fixture. Provider responses
 * are local data; this smoke never needs a YouTube or Vimeo network request.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Media\Embeds;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) || ! class_exists( Embeds::class ) ) {
	throw new RuntimeException( 'Run the media embed smoke with wp eval-file after activating a generated child theme.' );
}

/**
 * Asserts one media behavior.
 *
 * @param bool   $condition Expected condition.
 * @param string $message Failure description.
 * @return void
 */
function emulsify_media_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Gets an iframe attribute through WordPress's real HTML parser.
 *
 * @param string $html Rendered markup.
 * @param string $name Attribute name.
 * @return mixed Decoded attribute value.
 */
function emulsify_media_smoke_attribute( string $html, string $name ) {
	$processor = new WP_HTML_Tag_Processor( $html );
	emulsify_media_smoke_assert( $processor->next_tag( 'IFRAME' ), 'Expected an iframe in transformed video markup.' );
	return $processor->get_attribute( $name );
}

/**
 * Parses a srcdoc document without loading external resources.
 *
 * @param string $html HTML document.
 * @return DOMDocument Parsed document.
 */
function emulsify_media_smoke_document( string $html ): DOMDocument {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	emulsify_media_smoke_assert( $loaded, 'The poster must be a parseable HTML document.' );
	return $document;
}

/**
 * Builds local provider iframe markup with normal WordPress URL escaping.
 *
 * @param string $source Player URL.
 * @param string $title Player title.
 * @param int    $height Frame height.
 * @return string Provider markup.
 */
function emulsify_media_smoke_markup( string $source, string $title = 'Fixture video', int $height = 360 ): string {
	return '<iframe width="640" height="' . $height . '" src="' . esc_attr( $source ) . '" title="' . esc_attr( $title ) . '" class="fixture-video" loading="lazy" allow="clipboard-write; autoplay" allowfullscreen></iframe>';
}

$options       = array();
$options_hook  = static function ( array $defaults ) use ( &$options ): array {
	return array_merge( $defaults, $options );
};
$translation   = static function ( string $translation, string $text, string $domain ): string {
	return 'emulsify' === $domain && 'Play video' === $text ? 'Lire la vidéo' : $translation;
};
$http_requests = 0;
$block_http    = static function () use ( &$http_requests ) {
	++$http_requests;
	return new WP_Error( 'emulsify_fixture_network', 'The media embed smoke blocks provider requests.' );
};
$fixtures      = array(
	'youtube' => array(
		'url'    => 'https://www.youtube.com/watch?v=abcdefghijk',
		'source' => 'https://www.youtube.com/embed/abcdefghijk?feature=oembed&x.y=a%20b&x.y=second&list[]=one&list[]=two&autoplay=0#chapter',
	),
	'vimeo'   => array(
		'url'    => 'https://vimeo.com/123456789/abc123',
		'source' => 'https://player.vimeo.com/video/123456789?h=abc123&x.y=a%20b&x.y=second&list[]=one&list[]=two&dnt=0#chapter',
	),
);
$service       = new Embeds();
$canonical     = 'https://vimeo.com/123456789/abc123';
$metadata_key  = Embeds::METADATA_PREFIX . md5( $canonical );
$original_data = get_transient( $metadata_key );
$created_posts = array();
$original_post = $GLOBALS['post'] ?? null;
$original_embed_post = $GLOBALS['wp_embed']->post_ID;
$child_dir     = get_stylesheet_directory();
$override      = $child_dir . '/templates/partials/oembed-poster.twig';
$original_file = is_file( $override ) ? file_get_contents( $override ) : null;
$created_dirs  = array();
$hostile_title = 'Fixture <img src=x onerror=alert(1)> & "title"';

add_filter( 'emulsify_theme_media_embed_options', $options_hook, 999 );
add_filter( 'gettext', $translation, 999, 3 );
add_filter( 'pre_http_request', $block_http, 999 );

try {
	emulsify_media_smoke_assert( false === apply_filters( 'oembed_dataparse', false, (object) array( 'type' => 'unsupported' ), 'https://video.example.test/unsupported' ), 'Unsupported oEmbed data must retain WordPress\'s false result.' );
	foreach ( $fixtures as $name => $fixture ) {
		$html = " \n" . emulsify_media_smoke_markup( $fixture['source'] ) . "\n ";
		emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), 0 ) === $html, 'Disabled options must preserve the exact ' . $name . ' bytes.' );
	}

	// Cached data comes from WordPress's oEmbed parse pipeline, including the
	// unlisted video's h value rather than merging distinct videos by ID.
	$metadata = (object) array(
		'thumbnail_url' => 'https://i.vimeocdn.com/video/emulsify-fixture.jpg',
		'title'         => $hostile_title,
	);
	$vimeo_html = emulsify_media_smoke_markup( $fixtures['vimeo']['source'], $hostile_title );
	emulsify_media_smoke_assert( $service->cache_oembed_data( $vimeo_html, $metadata, $canonical ) === $vimeo_html, 'Metadata capture must not edit the cached oEmbed HTML.' );
	$cached = get_transient( $metadata_key );
	emulsify_media_smoke_assert( is_array( $cached ) && $metadata->thumbnail_url === ( $cached['thumbnail_url'] ?? null ), 'Vimeo metadata must be cached under its unlisted video URL.' );

	foreach ( $fixtures as $name => $fixture ) {
		$html        = emulsify_media_smoke_markup( $fixture['source'], $hostile_title );
		$privacy_url = 'youtube' === $name ? str_replace( 'www.youtube.com', 'www.youtube-nocookie.com', $fixture['source'] ) : str_replace( 'dnt=0', 'dnt=1', $fixture['source'] );
		$options     = array( 'privacy_mode' => true );
		$private     = apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), 0 );
		// The native src setter URL-encodes literal query brackets. Keep that
		// security boundary while comparing every remaining query byte exactly.
		emulsify_media_smoke_assert( esc_url_raw( $privacy_url ) === emulsify_media_smoke_attribute( $private, 'src' ), 'Privacy must preserve every unrelated query pair, Vimeo h, and fragment through WordPress URL normalization for ' . $name . '.' );
		emulsify_media_smoke_assert( null === emulsify_media_smoke_attribute( $private, 'srcdoc' ), 'Privacy alone must retain the real player for ' . $name . '.' );
		emulsify_media_smoke_assert( 'fixture-video' === emulsify_media_smoke_attribute( $private, 'class' ) && 'lazy' === emulsify_media_smoke_attribute( $private, 'loading' ), 'Privacy must preserve unrelated iframe attributes.' );

		foreach ( array( false, true ) as $privacy ) {
			foreach ( array( 'provider', 'neutral' ) as $poster ) {
				$options = array( 'privacy_mode' => $privacy, 'click_to_load' => true, 'poster' => $poster );
				$output  = apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), 0 );
				$srcdoc  = emulsify_media_smoke_attribute( $output, 'srcdoc' );
				emulsify_media_smoke_assert( is_string( $srcdoc ) && '' !== $srcdoc, 'Click-to-load must use a srcdoc poster.' );
				$source = emulsify_media_smoke_attribute( $output, 'src' );
				emulsify_media_smoke_assert( null === $source || '' === $source || 'about:blank' === $source, 'A poster must not request a player before activation.' );
				$document = emulsify_media_smoke_document( $srcdoc );
				$xpath    = new DOMXPath( $document );
				$anchors  = $document->getElementsByTagName( 'a' );
				emulsify_media_smoke_assert( 1 === $anchors->length, 'The poster must have exactly one native play link.' );
				$anchor   = $anchors->item( 0 );
				$expected = $privacy ? $privacy_url : $fixture['source'];
				$expected = false !== strpos( $expected, 'autoplay=0' ) ? str_replace( 'autoplay=0', 'autoplay=1', $expected ) : str_replace( '#chapter', '&autoplay=1#chapter', $expected );
				emulsify_media_smoke_assert( $expected === $anchor->getAttribute( 'href' ), 'Play must enable autoplay while preserving query bytes and Vimeo h.' );
				emulsify_media_smoke_assert( 'strict-origin-when-cross-origin' === $anchor->getAttribute( 'referrerpolicy' ), 'Play links must use the strict origin referrer policy.' );
				emulsify_media_smoke_assert( ! $anchor->hasAttribute( 'target' ) || '_self' === $anchor->getAttribute( 'target' ), 'Play must navigate the same frame.' );
				emulsify_media_smoke_assert( false !== strpos( $anchor->textContent, 'Lire la vidéo' ) && false !== strpos( $anchor->textContent, $hostile_title ), 'Play labels must be translated and retain the safely escaped video title. Actual: ' . $anchor->textContent );
				emulsify_media_smoke_assert( 1 === $xpath->query( '//a//span[contains(@class, "visually-hidden")]' )->length, 'The complete video title must be visually hidden inside the accessible link label.' );
				emulsify_media_smoke_assert( get_bloginfo( 'language' ) === $document->documentElement->getAttribute( 'lang' ), 'The poster document must declare the WordPress language.' );
				emulsify_media_smoke_assert( 0 === $xpath->query( '//iframe | //script | //noscript | //@*[starts-with(name(), "on")]' )->length, 'Posters must contain no nested player, script, or injected event handler.' );
				$images = $document->getElementsByTagName( 'img' );
				emulsify_media_smoke_assert( ( 'provider' === $poster ? 1 : 0 ) === $images->length, 'Only provider posters may request thumbnails.' );
				if ( 'provider' === $poster ) {
					emulsify_media_smoke_assert( '' === $images->item( 0 )->getAttribute( 'alt' ) && 'no-referrer' === $images->item( 0 )->getAttribute( 'referrerpolicy' ), 'Provider thumbnails must be decorative and omit the referrer.' );
				} else {
					emulsify_media_smoke_assert( 0 === $xpath->query( '//*[@src] | //link | //object | //embed' )->length, 'Neutral posters must not request third-party resources.' );
				}
				$styles = $document->getElementsByTagName( 'style' );
				emulsify_media_smoke_assert( 1 === $styles->length && 1 === preg_match( '/:focus(?:-visible)?\b[^}]*outline\s*:/s', $styles->item( 0 )->textContent ), 'The isolated poster needs an inline visible focus ring.' );
				emulsify_media_smoke_assert( 1 === preg_match( '/@media[^\{]*max-height\s*:\s*160px/i', $styles->item( 0 )->textContent ), 'Posters must supply compact controls for frames at most 160px high.' );
				$allow = emulsify_media_smoke_attribute( $output, 'allow' );
				foreach ( array( 'autoplay', 'fullscreen', 'picture-in-picture', 'encrypted-media' ) as $permission ) {
					emulsify_media_smoke_assert( is_string( $allow ) && 1 === preg_match( '/(?:^|;\s*)' . preg_quote( $permission, '/' ) . '\s+[^;]*https:\/\/' . preg_quote( (string) wp_parse_url( $expected, PHP_URL_HOST ), '/' ) . '(?:\s|;|$)/', $allow ), 'The iframe must delegate ' . $permission . ' to the player origin.' );
				}
			}
		}

		$options = array();
		emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), 0 ) === $html, 'Changing options must affect cached oEmbed HTML immediately, without a cache flush.' );
	}

	$options = array( 'privacy_mode' => true, 'click_to_load' => true, 'poster' => 'neutral' );
	foreach ( $fixtures as $name => $fixture ) {
		$untitled = '<iframe src="' . esc_attr( $fixture['source'] ) . '"></iframe>';
		$fallback = apply_filters( 'embed_oembed_html', $untitled, $fixture['url'], array(), 0 );
		$expected_title = 'youtube' === $name ? 'YouTube video' : 'Vimeo video';
		emulsify_media_smoke_assert( $expected_title === emulsify_media_smoke_attribute( $fallback, 'title' ), 'Untitled posters must give their outer iframe a translated provider fallback title.' );
		$fallback_document = emulsify_media_smoke_document( (string) emulsify_media_smoke_attribute( $fallback, 'srcdoc' ) );
		emulsify_media_smoke_assert( $expected_title === $fallback_document->getElementsByTagName( 'title' )->item( 0 )->textContent && false !== strpos( $fallback_document->getElementsByTagName( 'a' )->item( 0 )->textContent, $expected_title ), 'Untitled poster documents and play links must retain the same accessible fallback title.' );
	}
	foreach ( array(
		'https://youtube.com.evil.example/embed/abcdefghijk',
		'https://evil.example/www.youtube.com/embed/abcdefghijk',
		'//www.youtube.com/embed/abcdefghijk',
		'http://www.youtube.com/embed/abcdefghijk',
		'https://www.youtube.com@evil.example/embed/abcdefghijk',
		'https://user:pass@www.youtube.com/embed/abcdefghijk',
		'https://www.youtube.com:444/embed/abcdefghijk',
		'https://www.youtube.com/watch?v=abcdefghijk',
		'https://player.vimeo.com.evil.example/video/123456789',
		'https://video.example.test/embed/123456789',
	) as $source ) {
		$html = emulsify_media_smoke_markup( $source );
		emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixtures['youtube']['url'], array(), 0 ) === $html, 'Unsupported and lookalike player URLs must remain untouched: ' . $source );
	}
	$uppercase = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( 'https://WWW.YOUTUBE.COM/embed/abcdefghijk' ), $fixtures['youtube']['url'], array(), 0 );
	$uppercase_doc = emulsify_media_smoke_document( (string) emulsify_media_smoke_attribute( $uppercase, 'srcdoc' ) );
	emulsify_media_smoke_assert( 'https://www.youtube-nocookie.com/embed/abcdefghijk?autoplay=1' === $uppercase_doc->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'href' ), 'Recognized hostnames must be case insensitive.' );
	foreach ( array(
		'<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe><p>Extra markup</p>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk" SRC="https://www.youtube.com/embed/other"></iframe>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk"><iframe>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk"',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe></iframe>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk">Unexpected content</iframe>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk" sandbox></iframe>',
		'<iframe src="https://www.youtube.com/embed/abcdefghijk" srcdoc="Existing document"></iframe>',
		'<p>Not an iframe</p>',
	) as $html ) {
		emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixtures['youtube']['url'], array(), 0 ) === $html, 'Malformed or compound HTML must pass through unchanged: ' . $html );
	}

	// Provider metadata is optional. A miss must return immediately and queue one
	// bounded background lookup; cached failures must not queue on every render.
	$options = array( 'click_to_load' => true, 'poster' => 'provider' );
	delete_transient( $metadata_key );
	wp_clear_scheduled_hook( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) );
	$missing = apply_filters( 'embed_oembed_html', $vimeo_html, $canonical, array(), 0 );
	emulsify_media_smoke_assert( 0 === emulsify_media_smoke_document( (string) emulsify_media_smoke_attribute( $missing, 'srcdoc' ) )->getElementsByTagName( 'img' )->length, 'Missing Vimeo metadata must fall back to a neutral poster.' );
	emulsify_media_smoke_assert( false !== wp_next_scheduled( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) ), 'Missing Vimeo metadata must schedule a background lookup.' );
	$first_cron = wp_next_scheduled( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) );
	apply_filters( 'embed_oembed_html', $vimeo_html, $canonical, array(), 0 );
	emulsify_media_smoke_assert( $first_cron === wp_next_scheduled( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) ), 'Repeated renders must not duplicate the metadata lookup.' );
	emulsify_media_smoke_assert( 0 === $http_requests, 'Rendering must never synchronously fetch provider metadata.' );
	wp_clear_scheduled_hook( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) );
	set_transient( $metadata_key, array(), HOUR_IN_SECONDS );
	apply_filters( 'embed_oembed_html', $vimeo_html, $canonical, array(), 0 );
	emulsify_media_smoke_assert( false === wp_next_scheduled( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) ), 'Negative metadata caches must prevent repeated lookup scheduling.' );
	delete_transient( $metadata_key );
	$service->refresh_vimeo_poster( $canonical );
	emulsify_media_smoke_assert( array() === get_transient( $metadata_key ), 'Provider failures must produce a bounded negative cache.' );

	// Exercise WordPress's real content pipeline rather than only calling the
	// filter: both a classic URL and a core/embed block must be transformed.
	$provider_result = static function ( $result, string $url ) use ( $fixtures ) {
		foreach ( $fixtures as $fixture ) {
			if ( $url === $fixture['url'] ) {
				return emulsify_media_smoke_markup( $fixture['source'] );
			}
		}
		return $result;
	};
	add_filter( 'pre_oembed_result', $provider_result, 999, 2 );
	foreach ( $fixtures as $name => $fixture ) {
		foreach ( array( 'classic', 'block' ) as $type ) {
			$content = 'classic' === $type ? "\n" . $fixture['url'] . "\n" : '<!-- wp:embed ' . wp_json_encode( array( 'url' => $fixture['url'], 'type' => 'video', 'providerNameSlug' => $name ) ) . ' --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" . $fixture['url'] . "\n" . '</div></figure><!-- /wp:embed -->';
			$post_id = wp_insert_post( array( 'post_title' => 'Media smoke ' . $name . ' ' . $type, 'post_content' => $content, 'post_status' => 'publish' ), true );
			emulsify_media_smoke_assert( ! is_wp_error( $post_id ), 'The media smoke must be able to seed a temporary post.' );
			$created_posts[]       = $post_id;
			$GLOBALS['post']       = get_post( $post_id );
			$GLOBALS['wp_embed']->post_ID = $post_id;
			$options              = array( 'privacy_mode' => true, 'click_to_load' => true, 'poster' => 'neutral' );
			$rendered             = apply_filters( 'the_content', $content );
			emulsify_media_smoke_assert( is_string( emulsify_media_smoke_attribute( $rendered, 'srcdoc' ) ), 'The real ' . $type . ' content pipeline must transform ' . $name . ' embeds.' );
			$options = array();
			$disabled = apply_filters( 'the_content', $content );
			emulsify_media_smoke_assert( null === emulsify_media_smoke_attribute( $disabled, 'srcdoc' ) && $fixture['source'] === emulsify_media_smoke_attribute( $disabled, 'src' ), 'Disabling options must immediately restore the real ' . $type . ' cached player without a cache flush.' );
		}
	}
	remove_filter( 'pre_oembed_result', $provider_result, 999 );

	// A project can override this document through the same child-first Twig
	// namespace as other parent fallbacks. Preserve any existing project file.
	foreach ( array( $child_dir . '/templates', $child_dir . '/templates/partials' ) as $directory ) {
		if ( ! is_dir( $directory ) ) {
			emulsify_media_smoke_assert( mkdir( $directory ), 'Unable to create the temporary child template directory.' );
			$created_dirs[] = $directory;
		}
	}
	$custom = '<!doctype html><html lang="en"><head><title>Child poster</title></head><body><p data-child-media-poster="true">Child override</p></body></html>';
	emulsify_media_smoke_assert( false !== file_put_contents( $override, $custom ), 'Unable to write the temporary child poster override.' );
	$options = array( 'click_to_load' => true, 'poster' => 'neutral' );
	$output = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
	emulsify_media_smoke_assert( $custom === emulsify_media_smoke_attribute( $output, 'srcdoc' ), 'Child @templates poster overrides must replace the parent fallback.' );

	// Invalidate the already-compiled child template after changing it in this
	// request. A normal request starts with the project's template already saved.
	$refresh_template = static function ( \Twig\Environment $environment ): \Twig\Environment {
		$environment->removeCache( '@templates/partials/oembed-poster.twig' );
		return $environment;
	};
	emulsify_media_smoke_assert( false !== file_put_contents( $override, '{# Intentionally empty project override. #}' ), 'Unable to write the temporary empty poster override.' );
	add_filter( 'timber/twig', $refresh_template, 999 );
	$empty_rejected = false;
	try {
		apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
	} catch ( UnexpectedValueException $exception ) {
		$empty_rejected = true;
	} finally {
		remove_filter( 'timber/twig', $refresh_template, 999 );
		file_put_contents( $override, $custom );
	}
	emulsify_media_smoke_assert( $empty_rejected, 'An empty project poster must fail closed instead of silently loading a third-party player.' );

	echo "PASS media embeds: off defaults, raw parameter preservation, privacy, translated srcdoc posters, safe URLs, asynchronous metadata, classic/block filters, child overrides, and fail-closed poster errors.\n";
} finally {
	remove_filter( 'emulsify_theme_media_embed_options', $options_hook, 999 );
	remove_filter( 'gettext', $translation, 999 );
	remove_filter( 'pre_http_request', $block_http, 999 );
	if ( isset( $provider_result ) ) {
		remove_filter( 'pre_oembed_result', $provider_result, 999 );
	}
	$GLOBALS['post'] = $original_post;
	$GLOBALS['wp_embed']->post_ID = $original_embed_post;
	foreach ( $created_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	wp_clear_scheduled_hook( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) );
	if ( false === $original_data ) {
		delete_transient( $metadata_key );
	} else {
		set_transient( $metadata_key, $original_data, DAY_IN_SECONDS );
	}
	if ( null !== $original_file ) {
		file_put_contents( $override, $original_file );
	} elseif ( is_file( $override ) ) {
		unlink( $override );
	}
	foreach ( array_reverse( $created_dirs ) as $directory ) {
		rmdir( $directory );
	}
}
