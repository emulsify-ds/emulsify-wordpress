<?php
/**
 * Generates a child theme through the WP-CLI generator without WordPress.
 *
 * The cross-path parity check needs the PHP generation path to run in the same
 * fast job as the Node path, so this harness supplies the minimal WordPress and
 * WP-CLI surface the command touches.
 *
 * Usage: php generation-parity-harness.php <theme-root> <label> <machine-name> <description>
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( $argc < 5 ) {
	fwrite( STDERR, "Usage: generation-parity-harness.php <theme-root> <label> <machine-name> <description>\n" );
	exit( 2 );
}

$GLOBALS['emulsify_theme_root'] = $argv[1];
$label                          = $argv[2];
$machine_name                   = $argv[3];
$description                    = $argv[4];

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		public static function add_command( $name, $callable ): void {}

		public static function log( string $message ): void {}

		public static function warning( string $message ): void {
			fwrite( STDERR, sprintf( "warning: %s\n", $message ) );
		}

		public static function success( string $message ): void {}

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root(): string {
		return (string) $GLOBALS['emulsify_theme_root'];
	}
}

// An installed fixture may supply the real formatting.php without booting a
// database. Standalone CI retains WordPress's text-field sanitization below,
// including percent-octet removal; trim(strip_tags()) hid generator drift.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$arguments ) {
		return $value;
	}
}
if ( ! function_exists( 'is_utf8_charset' ) ) {
	function is_utf8_charset(): bool {
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name ) {
		return 'blog_charset' === $name ? 'UTF-8' : false;
	}
}
if ( ! function_exists( '_canonical_charset' ) ) {
	function _canonical_charset( string $charset ): string {
		return is_utf8_charset() ? 'UTF-8' : $charset;
	}
}
$wordpress_formatting = getenv( 'EMULSIFY_WP_FORMATTING_FILE' );
if ( is_string( $wordpress_formatting ) && '' !== $wordpress_formatting ) {
	foreach ( array( 'compat-utf8.php', 'utf8.php', 'kses.php' ) as $dependency ) {
		$dependency_path = dirname( $wordpress_formatting ) . '/' . $dependency;
		if ( is_file( $dependency_path ) ) {
			require_once $dependency_path;
		}
	}
	require_once $wordpress_formatting;
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		$value = preg_replace( '@<(script|style)\b[^>]*?>.*?</\1>@si', '', $value );
		$value = preg_replace_callback(
			'%<[^>]*?((?=<)|>|$)%',
			static fn( array $matches ): string => str_contains( $matches[0], '>' ) ? $matches[0] : htmlspecialchars( $matches[0], ENT_QUOTES, 'UTF-8', false ),
			$value
		);
		$value = str_replace( "<\n", "&lt;\n", strip_tags( $value ) );
		$value = trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
		while ( preg_match( '/%[a-f0-9]{2}/i', $value, $match ) ) {
			$value = trim( preg_replace( '/ +/', ' ', str_replace( $match[0], '', $value ) ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'switch_theme' ) ) {
	function switch_theme( string $stylesheet ): void {}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/Cli/GenerateChildThemeCommand.php';

$command = new \Emulsify\Theme\Cli\GenerateChildThemeCommand();

try {
	$command(
		array( $label ),
		array(
			'machine-name' => $machine_name,
			'description'  => $description,
		)
	);
} catch ( \Throwable $exception ) {
	fwrite( STDERR, sprintf( "Generation failed: %s\n", $exception->getMessage() ) );
	exit( 1 );
}
