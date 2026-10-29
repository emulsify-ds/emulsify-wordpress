<?php
/**
 * Isolates absent Timber and WP_DEBUG constants for poster error handling.
 *
 * Minimal service doubles exercise only the failure boundary. The surrounding
 * WordPress smoke separately exercises the real HTML API, Twig and diagnostics.
 *
 * @package Emulsify
 */

namespace Timber {
	if ( 'missing' !== ( $argv[2] ?? '' ) ) {
		class Timber {
			public static function compile( $template, $context ) {
				if ( 'error' === $GLOBALS['media_failure'] ) {
					throw new \LogicException( 'Broken child poster.' );
				}
				return '';
			}
		}
	}
}

namespace {
	define( 'WP_DEBUG', '1' === ( $argv[3] ?? '' ) );
	$GLOBALS['media_failure'] = $argv[2] ?? '';
	$GLOBALS['media_diagnostics'] = 0;

	class WP_HTML_Tag_Processor {
		private DOMElement $iframe;
		public function __construct( string $html ) {
			$document = new DOMDocument();
			$document->loadHTML( $html );
			$this->iframe = $document->getElementsByTagName( 'iframe' )->item( 0 );
		}
		public function next_tag( $query = null ): bool { return true; }
		public function has_self_closing_flag(): bool { return false; }
		public function get_attribute( string $name ) {
			return $this->iframe->hasAttribute( $name ) ? $this->iframe->getAttribute( $name ) : null;
		}
	}
	function apply_filters( $hook, $value, ...$args ) {
		return 'emulsify_theme_media_embed_options' === $hook ? array_merge( $value, array( 'click_to_load' => true, 'poster' => 'neutral' ) ) : $value;
	}
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function __( $text, $domain = '' ) { return $text; }
	function get_bloginfo( $show ) { return 'en-US'; }
	function is_rtl() { return false; }
	function esc_url( $url ) {
		return 'https' === parse_url( $url, PHP_URL_SCHEME ) ? htmlspecialchars( $url, ENT_QUOTES, 'UTF-8', false ) : '';
	}
	function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ); }
	function _doing_it_wrong( $function, $message, $version ) { ++$GLOBALS['media_diagnostics']; }
	function wp_trigger_error( $function, $message, $level = E_USER_NOTICE ) { ++$GLOBALS['media_diagnostics']; }

	require $argv[1];
	$title = 'Clip\' <img src=x onerror=alert(1)> & "quoted"';
	$url = 'https://www.youtube.com/watch?v=abcdefghijk&feature=fixture';
	$html = '<iframe src="https://www.youtube.com/embed/abcdefghijk" title="' . esc_html( $title ) . '"></iframe>';
	$thrown = null;
	try {
		$output = ( new \Emulsify\Theme\Media\Embeds() )->filter_html( $html, $url );
	} catch ( \Throwable $error ) {
		$thrown = $error;
	}
	if ( 1 !== $GLOBALS['media_diagnostics'] ) {
		throw new \RuntimeException( 'A poster failure must report exactly one WordPress diagnostic.' );
	}
	if ( WP_DEBUG ) {
		$expected = array( 'empty' => \UnexpectedValueException::class, 'error' => \LogicException::class, 'missing' => \RuntimeException::class )[ $GLOBALS['media_failure'] ];
		if ( ! $thrown instanceof $expected ) {
			throw new \RuntimeException( 'Debug mode must rethrow the original poster failure.' );
		}
	} else {
		if ( null !== $thrown ) {
			throw new \RuntimeException( 'Production poster failures must return a link, not throw.', 0, $thrown );
		}
		$document = new \DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8">' . $output );
		$anchors = $document->getElementsByTagName( 'a' );
		if ( 1 !== $anchors->length || $url !== $anchors->item( 0 )->getAttribute( 'href' ) || $title !== $anchors->item( 0 )->textContent
			|| 0 !== $document->getElementsByTagName( 'iframe' )->length || 0 !== $document->getElementsByTagName( 'img' )->length
			|| 0 !== ( new \DOMXPath( $document ) )->query( '//@*[starts-with(name(), "on")]' )->length ) {
			throw new \RuntimeException( 'The fallback must contain only the escaped video link and title, with no provider request.' );
		}
	}
	echo "PASS isolated poster failure.\n";
}
