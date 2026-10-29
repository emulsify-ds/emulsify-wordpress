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

/** Records independent regressions so the pre-fix run reports each problem. */
function emulsify_media_smoke_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$GLOBALS['emulsify_media_regressions'][] = $message;
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
	return '<iframe width="640" height="' . $height . '" src="' . esc_attr( $source ) . '" title="' . esc_attr( $title ) . '" class="fixture-video" loading="lazy" allow="clipboard-write; accelerometer; gyroscope; camera \'none\'; autoplay" allowfullscreen></iframe>';
}

$options       = array();
$embed_context = null;
$options_hook  = static function ( array $defaults, $url, $attr, $post_id ) use ( &$options, &$embed_context ): array {
	$embed_context = array( $url, $attr, $post_id );
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
$GLOBALS['emulsify_media_regressions'] = array();
$service       = new Embeds();
$canonical     = 'https://vimeo.com/123456789/abc123';
$metadata_key  = Embeds::METADATA_PREFIX . md5( $canonical );
$original_data = get_transient( $metadata_key );
$backoff_key   = 'emulsify_vimeo_poster_backoff_' . md5( $canonical );
$original_backoff = get_transient( $backoff_key );
$created_posts = array();
$original_post = $GLOBALS['post'] ?? null;
$original_embed_post = $GLOBALS['wp_embed']->post_ID;
$original_embed_url  = $GLOBALS['wp_embed']->last_url;
$original_embed_attr = $GLOBALS['wp_embed']->last_attr;
$child_dir     = get_stylesheet_directory();
$override      = $child_dir . '/templates/partials/oembed-poster.twig';
$original_file = is_file( $override ) ? file_get_contents( $override ) : null;
$created_dirs  = array();
$hostile_title = 'Fixture <img src=x onerror=alert(1)> & "title"';

add_filter( 'emulsify_theme_media_embed_options', $options_hook, 999, 4 );
add_filter( 'gettext', $translation, 999, 3 );
add_filter( 'pre_http_request', $block_http, 999 );

try {
	emulsify_media_smoke_assert( false === apply_filters( 'oembed_dataparse', false, (object) array( 'type' => 'unsupported' ), 'https://video.example.test/unsupported' ), 'Unsupported oEmbed data must retain WordPress\'s false result.' );
	foreach ( $fixtures as $name => $fixture ) {
		$html = " \n" . emulsify_media_smoke_markup( $fixture['source'] ) . "\n ";
		emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), 0 ) === $html, 'Disabled options must preserve the exact ' . $name . ' bytes.' );
		foreach ( array( null, false ) as $missing_post_id ) {
			$options = array();
			emulsify_media_smoke_assert( apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), $missing_post_id ) === $html, 'Missing post IDs must preserve disabled ' . $name . ' embeds byte for byte.' );
			emulsify_media_smoke_assert( array( $fixture['url'], array(), null ) === $embed_context, 'The options filter must receive a null post ID outside a post.' );
			$options = array( 'privacy_mode' => true, 'click_to_load' => true, 'poster' => 'neutral' );
			$output  = apply_filters( 'embed_oembed_html', $html, $fixture['url'], array(), $missing_post_id );
			$srcdoc  = emulsify_media_smoke_attribute( $output, 'srcdoc' );
			emulsify_media_smoke_assert( is_string( $srcdoc ) && '' !== $srcdoc, 'Missing post IDs must allow ' . $name . ' poster rendering.' );
			$document = emulsify_media_smoke_document( $srcdoc );
			$player   = $document->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'href' );
			emulsify_media_smoke_assert( str_contains( $player, 'autoplay=1' ) && str_contains( $player, 'youtube' === $name ? 'www.youtube-nocookie.com' : 'dnt=1' ), 'Missing post IDs must retain privacy and activation parameters.' );
		}
		$options = array();
	}

	// The public filter can be applied by code other than WP_Embed. Preserve
	// non-string results and normalize optional context before exposing it.
	// Core's earlier callbacks require strings, so test our pass-through directly.
	foreach ( array( null, false, 42, array( 'provider' => 'unavailable' ), (object) array( 'provider' => 'unavailable' ) ) as $value ) {
		emulsify_media_smoke_assert( $value === $service->filter_html( $value, null, false, null ), 'Non-string embed results must pass through unchanged.' );
	}
	foreach ( array(
		array( 42, 42 ),
		array( '42', 42 ),
		array( '0042', 42 ),
		array( PHP_INT_MAX, PHP_INT_MAX ),
		array( (string) PHP_INT_MAX . '0', null ),
		array( 'invalid', null ),
		array( '-1', null ),
		array( '1.5', null ),
		array( '', null ),
		array( -1, null ),
		array( 0, null ),
	) as list( $post_id, $expected_id ) ) {
		$html = emulsify_media_smoke_markup( $fixtures['youtube']['source'] );
		emulsify_media_smoke_assert( $html === apply_filters( 'embed_oembed_html', $html, null, false, $post_id ), 'Invalid optional context must not change disabled embeds.' );
		emulsify_media_smoke_assert( array( '', array(), $expected_id ) === $embed_context, 'The options filter must receive normalized URLs, attributes, and positive post IDs.' );
	}
	foreach ( array( true, 1.0, 1.5, array(), (object) array() ) as $post_id ) {
		apply_filters( 'embed_oembed_html', $html, '', array(), $post_id );
		emulsify_media_smoke_assert( null === $embed_context[2], 'Non-integer post IDs must normalize to null.' );
	}

	// Simulate an Arabic interface without installing language packs. Restore it
	// before a second render to catch language or direction cached per request.
	$default_language  = get_bloginfo( 'language' );
	$default_direction = $GLOBALS['wp_locale']->text_direction;
	$rtl_locale        = static function (): string {
		return 'ar';
	};
	$options = array( 'click_to_load' => true, 'poster' => 'neutral' );
	emulsify_media_smoke_assert( 'ar' !== $default_language && 'ltr' === $default_direction, 'The locale fixture must start with a distinct left-to-right site default.' );
	try {
		add_filter( 'locale', $rtl_locale, 999 );
		$GLOBALS['wp_locale']->text_direction = 'rtl';
		$output   = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
		$document = emulsify_media_smoke_document( (string) emulsify_media_smoke_attribute( $output, 'srcdoc' ) );
		emulsify_media_smoke_assert( 'ar' === $document->documentElement->getAttribute( 'lang' ), 'Poster documents must declare the Arabic interface language rather than the site default.' );
		emulsify_media_smoke_assert( 'rtl' === $document->documentElement->getAttribute( 'dir' ), 'Poster documents must declare the right-to-left interface direction.' );
	} finally {
		remove_filter( 'locale', $rtl_locale, 999 );
		$GLOBALS['wp_locale']->text_direction = $default_direction;
	}
	$output   = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
	$document = emulsify_media_smoke_document( (string) emulsify_media_smoke_attribute( $output, 'srcdoc' ) );
	emulsify_media_smoke_assert( $default_language === $document->documentElement->getAttribute( 'lang' ) && 'ltr' === $document->documentElement->getAttribute( 'dir' ), 'A second poster must return to the default language and left-to-right direction after locale restoration.' );
	$options = array();

	// Cached data comes from WordPress's oEmbed parse pipeline, including the
	// unlisted video's h value rather than merging distinct videos by ID.
	$metadata = (object) array(
		'type'          => 'video',
		'thumbnail_url' => 'https://i.vimeocdn.com/video/emulsify-fixture.jpg',
		'title'         => $hostile_title,
	);
	$vimeo_html = emulsify_media_smoke_markup( $fixtures['vimeo']['source'], $hostile_title );
	delete_transient( $metadata_key );
	foreach ( array( array(), array( 'privacy_mode' => true ), array( 'click_to_load' => true, 'poster' => 'neutral' ) ) as $disabled_cache_options ) {
		$options = $disabled_cache_options;
		$service->cache_oembed_data( $vimeo_html, $metadata, $canonical );
		emulsify_media_smoke_check( false === get_transient( $metadata_key ), 'Disabled or neutral posters must not write provider metadata.' );
		delete_transient( $metadata_key );
	}
	$null_data = static function () { return null; };
	add_filter( 'oembed_dataparse', $null_data, 19 );
	try {
		emulsify_media_smoke_check( null === apply_filters( 'oembed_dataparse', $vimeo_html, $metadata, $canonical ), 'Earlier null oEmbed results must pass through unchanged.' );
	} catch ( Throwable $error ) {
		emulsify_media_smoke_check( false, 'Earlier null oEmbed result threw ' . get_class( $error ) );
	} finally {
		remove_filter( 'oembed_dataparse', $null_data, 19 );
	}
	$options = array( 'click_to_load' => true, 'poster' => 'provider' );
	emulsify_media_smoke_assert( $service->cache_oembed_data( $vimeo_html, $metadata, $canonical ) === $vimeo_html, 'Metadata capture must not edit the cached oEmbed HTML.' );
	emulsify_media_smoke_check( array( $canonical, array(), null ) === $embed_context, 'Metadata options must receive the original URL, empty attributes and no post ID.' );
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
				emulsify_media_smoke_check( 1 === $xpath->query( '//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-security-policy" and @content="script-src \'none\'"]' )->length, 'Parent posters must prohibit scripts with a meta CSP.' );
				$images = $document->getElementsByTagName( 'img' );
				emulsify_media_smoke_assert( ( 'provider' === $poster ? 1 : 0 ) === $images->length, 'Only provider posters may request thumbnails.' );
				if ( 'provider' === $poster ) {
					emulsify_media_smoke_check( 'lazy' === $images->item( 0 )->getAttribute( 'loading' ) && 'async' === $images->item( 0 )->getAttribute( 'decoding' ), 'Provider thumbnails must be lazy-loaded and asynchronously decoded.' );
					emulsify_media_smoke_assert( '' === $images->item( 0 )->getAttribute( 'alt' ) && 'no-referrer' === $images->item( 0 )->getAttribute( 'referrerpolicy' ), 'Provider thumbnails must be decorative and omit the referrer.' );
				} else {
					emulsify_media_smoke_assert( 0 === $xpath->query( '//*[@src] | //link | //object | //embed' )->length, 'Neutral posters must not request third-party resources.' );
				}
				$styles = $document->getElementsByTagName( 'style' );
				emulsify_media_smoke_assert( 1 === $styles->length && 1 === preg_match( '/:focus(?:-visible)?\b[^}]*outline\s*:/s', $styles->item( 0 )->textContent ), 'The isolated poster needs an inline visible focus ring.' );
				emulsify_media_smoke_assert( 1 === preg_match( '/@media[^\{]*max-height\s*:\s*160px/i', $styles->item( 0 )->textContent ), 'Posters must supply compact controls for frames at most 160px high.' );
				$allow = emulsify_media_smoke_attribute( $output, 'allow' );
				emulsify_media_smoke_check( is_string( $allow ) && str_contains( $allow, "camera 'none'" ), 'Explicit provider permission origin lists must remain unchanged.' );
				foreach ( array( 'clipboard-write', 'accelerometer', 'gyroscope' ) as $permission ) {
					emulsify_media_smoke_check( is_string( $allow ) && 1 === preg_match( '/(?:^|;\s*)' . preg_quote( $permission, '/' ) . '\s+[^;]*https:\/\/' . preg_quote( (string) wp_parse_url( $expected, PHP_URL_HOST ), '/' ) . '(?:\s|;|$)/', $allow ), 'Kept iframe permissions must retain their provider origin: ' . $permission );
				}
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
	delete_transient( $backoff_key );
	$service->refresh_vimeo_poster( $canonical );
	emulsify_media_smoke_check( false === get_transient( $metadata_key ) && false !== get_transient( $backoff_key ), 'Failed metadata lookups must use a separate bounded backoff marker.' );
	$failed_requests = $http_requests;
	$service->refresh_vimeo_poster( $canonical );
	emulsify_media_smoke_check( $failed_requests === $http_requests, 'A failed refresh must back off repeated provider requests.' );
	set_transient( $metadata_key, array( 'thumbnail_url' => $metadata->thumbnail_url, 'title' => 'Keep cached title' ), DAY_IN_SECONDS );
	$cached_before_refresh = get_transient( $metadata_key );
	delete_transient( $backoff_key );
	$service->refresh_vimeo_poster( $canonical );
	emulsify_media_smoke_check( $cached_before_refresh === get_transient( $metadata_key ), 'Failed refreshes must keep valid existing Vimeo metadata.' );
	delete_transient( $backoff_key );
	$successful_refresh = static function () use ( $metadata_key, $cached_before_refresh ) {
		emulsify_media_smoke_check( $cached_before_refresh === get_transient( $metadata_key ), 'Cached metadata must remain available while a refresh fetch runs.' );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'thumbnail_url' => 'https://i.vimeocdn.com/video/refreshed-fixture.jpg', 'title' => 'Refreshed fixture' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
	};
	add_filter( 'pre_http_request', $successful_refresh, 1000 );
	try {
		$service->refresh_vimeo_poster( $canonical );
		$refreshed = get_transient( $metadata_key );
		emulsify_media_smoke_check( is_array( $refreshed ) && 'Refreshed fixture' === ( $refreshed['title'] ?? '' ), 'A successful background refresh must replace old metadata.' );
	} finally {
		remove_filter( 'pre_http_request', $successful_refresh, 1000 );
	}

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
	// WP_Embed prefers its post_ID property over the global post. Clear both to
	// exercise the real no-post shortcode path, with local provider responses.
	$post_before_no_post       = $GLOBALS['post'];
	$embed_post_before_no_post = $GLOBALS['wp_embed']->post_ID;
	try {
		$GLOBALS['post']              = null;
		$GLOBALS['wp_embed']->post_ID = null;
		foreach ( $fixtures as $name => $fixture ) {
			foreach ( array( false, true ) as $enabled ) {
				$options = $enabled ? array( 'privacy_mode' => true, 'click_to_load' => true, 'poster' => 'neutral' ) : array();
				$rendered = $GLOBALS['wp_embed']->shortcode( array(), $fixture['url'] );
				emulsify_media_smoke_assert( is_string( $rendered ) && '' !== $rendered, 'A no-post shortcode must return ' . $name . ' embed markup.' );
				emulsify_media_smoke_assert( null === $embed_context[2], 'The no-post shortcode must pass a null ID to the options filter.' );
				if ( $enabled ) {
					emulsify_media_smoke_assert( is_string( emulsify_media_smoke_attribute( $rendered, 'srcdoc' ) ), 'A no-post shortcode must render the enabled poster.' );
				} else {
					emulsify_media_smoke_assert( $fixture['source'] === emulsify_media_smoke_attribute( $rendered, 'src' ), 'A no-post shortcode must retain the disabled player.' );
				}
			}
		}
	} finally {
		$GLOBALS['post']              = $post_before_no_post;
		$GLOBALS['wp_embed']->post_ID = $embed_post_before_no_post;
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
	// Numeric references inside a single-quoted child attribute must survive
	// the outer srcdoc boundary unchanged, including on WordPress 6.8.
	$roundtrip_title = "Clip' onfocus='alert(document.domain)' autofocus='";
	$roundtrip_template = '<!doctype html><html lang="en"><head><title>Roundtrip</title></head><body><a title=\'{{ media_embed.title }}\'>\' " A & B &amp; &#39; &#60;b&#62;</a></body></html>';
	$roundtrip = str_replace( '{{ media_embed.title }}', htmlspecialchars( $roundtrip_title, ENT_QUOTES, 'UTF-8' ), $roundtrip_template );
	file_put_contents( $override, $roundtrip_template );
	$options = array( 'click_to_load' => true, 'poster' => 'neutral' );
	$output = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'], $roundtrip_title ), $fixtures['youtube']['url'], array(), 0 );
	$actual_roundtrip = emulsify_media_smoke_attribute( $output, 'srcdoc' );
	emulsify_media_smoke_check( $roundtrip === $actual_roundtrip, 'srcdoc must round-trip every document byte, including numeric character references.' );
	$roundtrip_dom = emulsify_media_smoke_document( (string) $actual_roundtrip );
	emulsify_media_smoke_check( 0 === ( new DOMXPath( $roundtrip_dom ) )->query( '//@onfocus|//@autofocus' )->length, 'Single-quoted child attributes must not acquire injected handlers or autofocus.' );
	$clear_roundtrip = static function ( \Twig\Environment $environment ): \Twig\Environment {
		$environment->removeCache( '@templates/partials/oembed-poster.twig' );
		return $environment;
	};
	add_filter( 'timber/twig', $clear_roundtrip, 999 );
	$custom = '<!doctype html><html lang="en"><head><title>Child poster</title></head><body><p data-child-media-poster="true">Child override</p></body></html>';
	emulsify_media_smoke_assert( false !== file_put_contents( $override, $custom ), 'Unable to write the temporary child poster override.' );
	$options = array( 'click_to_load' => true, 'poster' => 'neutral' );
	$output = apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
	emulsify_media_smoke_assert( $custom === emulsify_media_smoke_attribute( $output, 'srcdoc' ), 'Child @templates poster overrides must replace the parent fallback.' );
	remove_filter( 'timber/twig', $clear_roundtrip, 999 );

	// Invalidate the already-compiled child template after changing it in this
	// request. A normal request starts with the project's template already saved.
	$refresh_template = static function ( \Twig\Environment $environment ): \Twig\Environment {
		$environment->removeCache( '@templates/partials/oembed-poster.twig' );
		return $environment;
	};
	$diagnostics = 0;
	$diagnostic = static function ( $function ) use ( &$diagnostics ): void {
		if ( str_contains( $function, 'Embeds::filter_html' ) ) {
			++$diagnostics;
		}
	};
	$suppress_expected_notice = static function ( $trigger, $function ) {
		return str_contains( $function, 'Embeds::filter_html' ) ? false : $trigger;
	};
	add_action( 'doing_it_wrong_run', $diagnostic );
	add_filter( 'doing_it_wrong_trigger_error', $suppress_expected_notice, 999, 2 );
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

	// An invalid child Twig document must rethrow in the debug fixture, too.
	file_put_contents( $override, '{% if %}' );
	add_filter( 'timber/twig', $refresh_template, 999 );
	$syntax_rejected = false;
	try {
		apply_filters( 'embed_oembed_html', emulsify_media_smoke_markup( $fixtures['youtube']['source'] ), $fixtures['youtube']['url'], array(), 0 );
	} catch ( \Twig\Error\SyntaxError $exception ) {
		$syntax_rejected = true;
	} finally {
		remove_filter( 'timber/twig', $refresh_template, 999 );
		file_put_contents( $override, $custom );
	}
	emulsify_media_smoke_check( $syntax_rejected, 'Invalid child Twig must rethrow its original error in debug mode.' );
	emulsify_media_smoke_check( 2 === $diagnostics, 'Both debug poster failures must emit a WordPress diagnostic before rethrowing.' );
	remove_action( 'doing_it_wrong_run', $diagnostic );
	remove_filter( 'doing_it_wrong_trigger_error', $suppress_expected_notice, 999 );
	// Isolate missing Timber and the non-debug constant without changing the
	// fixture's WP_DEBUG setting or unloading its real Timber classes.
	foreach ( array( 'empty', 'error', 'missing' ) as $failure ) {
		foreach ( array( '0', '1' ) as $debug ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/media-embed-failure-smoke.php', get_template_directory() . '/includes/Media/Embeds.php', $failure, $debug ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			emulsify_media_smoke_assert( is_resource( $process ), 'Unable to launch isolated poster failure smoke.' );
			$failure_output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			emulsify_media_smoke_check( 0 === proc_close( $process ), 'Isolated ' . $failure . ' poster behavior with WP_DEBUG=' . $debug . ': ' . trim( $failure_output ) );
		}
	}
	if ( ! empty( $GLOBALS['emulsify_media_regressions'] ) ) {
		throw new RuntimeException( "Media regressions:\n" . implode( "\n", array_unique( $GLOBALS['emulsify_media_regressions'] ) ) );
	}

	echo "PASS media embeds: missing post IDs and no-post shortcodes, normalized public filter context, off defaults, raw parameter preservation, privacy, translated srcdoc posters with Arabic RTL and restored LTR locales, safe URLs, asynchronous metadata, classic/block filters, child overrides, and fail-closed poster errors.\n";
} finally {
	remove_filter( 'emulsify_theme_media_embed_options', $options_hook, 999 );
	remove_filter( 'gettext', $translation, 999 );
	remove_filter( 'pre_http_request', $block_http, 999 );
	if ( isset( $provider_result ) ) {
		remove_filter( 'pre_oembed_result', $provider_result, 999 );
	}
	$GLOBALS['post'] = $original_post;
	$GLOBALS['wp_embed']->post_ID = $original_embed_post;
	$GLOBALS['wp_embed']->last_url = $original_embed_url;
	$GLOBALS['wp_embed']->last_attr = $original_embed_attr;
	foreach ( $created_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	wp_clear_scheduled_hook( 'emulsify_theme_refresh_vimeo_poster', array( $canonical ) );
	if ( false === $original_data ) {
		delete_transient( $metadata_key );
	} else {
		set_transient( $metadata_key, $original_data, DAY_IN_SECONDS );
	}
	if ( false === $original_backoff ) {
		delete_transient( $backoff_key );
	} else {
		set_transient( $backoff_key, $original_backoff, HOUR_IN_SECONDS );
	}
	if ( isset( $diagnostic ) ) {
		remove_action( 'doing_it_wrong_run', $diagnostic );
		remove_filter( 'doing_it_wrong_trigger_error', $suppress_expected_notice, 999 );
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
