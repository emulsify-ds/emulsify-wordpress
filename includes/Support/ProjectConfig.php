<?php
/**
 * Reads Emulsify project configuration from the active child theme.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Provides project.emulsify.json helpers.
 */
final class ProjectConfig {

	/**
	 * Decoded config keyed by canonical child theme path for this request.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Enables immutable-request memoization, bypassing mutable CLI workers.
	 *
	 * @return bool TRUE when request-local caches can be shared.
	 */
	public static function request_cache_enabled(): bool {
		/**
		 * Filters request-local component and project-config memoization.
		 *
		 * CLI batches opting in must clear caches after filesystem changes or
		 * between jobs. Ordinary web requests share caches without persistent IO.
		 *
		 * @param bool $enabled Whether request memoization is enabled.
		 */
		return (bool) apply_filters( 'emulsify_theme_twig_request_cache_enabled', 'cli' !== PHP_SAPI );
	}

	/**
	 * Clears decoded metadata at a logical request or worker-job boundary.
	 *
	 * @return void
	 */
	public static function clear_request_cache(): void {
		self::$cache = array();
	}

	/**
	 * Reads active child theme Emulsify project metadata.
	 *
	 * @return array Project config, or an empty array when unavailable.
	 */
	public static function read(): array {
		$real      = realpath( get_stylesheet_directory() );
		$theme_dir = false !== $real ? $real : get_stylesheet_directory();
		$path      = $theme_dir . '/project.emulsify.json';
		$cacheable = self::request_cache_enabled();
		if ( $cacheable && array_key_exists( $path, self::$cache ) ) {
			return self::$cache[ $path ];
		}
		$data = array();
		if ( is_readable( $path ) ) {
			$contents = file_get_contents( $path );
			$decoded  = false !== $contents ? json_decode( $contents, true ) : null;
			$data     = is_array( $decoded ) ? $decoded : array();
		}
		if ( $cacheable ) {
			self::$cache[ $path ] = $data;
		}
		return $data;
	}

	/**
	 * Reads the active child theme project machine name.
	 *
	 * @return string Project machine name, or empty string when unavailable.
	 */
	public static function machine_name(): string {
		$data = self::read();

		if ( empty( $data['project']['machineName'] ) ) {
			return '';
		}

		$machine_name = trim( (string) $data['project']['machineName'] );

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $machine_name ) ) {
			return '';
		}

		return $machine_name;
	}

	/**
	 * Gets configured project structure implementation records.
	 *
	 * @return array Structure implementation records.
	 */
	public static function structure_implementations(): array {
		$config = self::read();

		return ! empty( $config['variant']['structureImplementations'] ) && is_array( $config['variant']['structureImplementations'] )
			? $config['variant']['structureImplementations']
			: array();
	}

	/**
	 * Resolves a project config path to a safe theme-relative path.
	 *
	 * @param string $theme_dir Theme root.
	 * @param string $path      Project-configured path.
	 * @return string Resolved path, or empty string when invalid.
	 */
	public static function resolve_theme_relative_path( string $theme_dir, string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ) );

		if (
			'' === $path
			|| false !== strpos( $path, "\0" )
			|| 0 === strpos( $path, '/' )
			|| preg_match( '#(^|/)\.\.(/|$)#', $path )
		) {
			// project.emulsify.json paths are child-theme relative. Reject
			// absolute paths and traversal so configuration cannot expose
			// arbitrary server files as Twig namespaces.
			return '';
		}

		return rtrim( $theme_dir, '/\\' ) . '/' . ltrim( $path, '/' );
	}
}
