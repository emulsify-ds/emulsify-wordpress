<?php
/**
 * Conservative WordPress text adapters for standalone Twig wiring checks.
 *
 * These checks cannot load WordPress. Timber supplies the actual filter
 * definitions and safety options; the callbacks below cover entity handling
 * only. In particular, stripping every tag is not a KSES implementation: the
 * full WordPress fixture verifies allowed markup, protocols and attributes.
 *
 * @package Emulsify
 */

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$arguments ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value, bool $remove_breaks = false ): string {
		$value = preg_replace( '@<(script|style)\b[^>]*?>.*?</\1>@si', '', (string) $value );
		$value = strip_tags( $value );
		return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $value ) : $value );
	}
}

/**
 * Adds Timber's real filter metadata with conservative standalone callbacks.
 *
 * @param \Twig\Environment $environment Test Twig environment.
 * @return void
 */
function emulsify_register_wordpress_text_test_filters( \Twig\Environment $environment ): void {
	$encode = static function ( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
	};
	$callbacks = array(
		'esc_html'     => $encode,
		'esc_attr'     => $encode,
		'wp_kses_post' => static function ( $value ) use ( $encode ): string {
			return $encode( strip_tags( (string) $value ) );
		},
	);
	$definitions = ( new \Timber\Twig() )->get_timber_escaper_filters();
	foreach ( $callbacks as $name => $callback ) {
		$environment->addFilter( new \Twig\TwigFilter( $name, $callback, $definitions[ $name ]['options'] ?? array( 'is_safe' => array( 'html' ) ) ) );
	}
}
