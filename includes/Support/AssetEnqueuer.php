<?php
/**
 * Shared asset enqueue helpers.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Builds handles and enqueues asset records.
 */
final class AssetEnqueuer {

	/**
	 * Builds a WordPress-safe asset handle.
	 *
	 * @param string       $prefix Handle prefix.
	 * @param string|array $record Relative path or asset record.
	 * @return string Asset handle.
	 */
	public static function handle( string $prefix, $record ): string {
		if ( is_array( $record ) && ! empty( $record['handle'] ) && is_scalar( $record['handle'] ) ) {
			return self::sanitize_key( (string) $record['handle'] );
		}

		if ( is_array( $record ) ) {
			$relative = isset( $record['relative'] ) && is_scalar( $record['relative'] )
				? (string) $record['relative']
				: basename( (string) ( $record['path'] ?? 'asset' ) );
		} else {
			$relative = is_scalar( $record ) ? (string) $record : 'asset';
		}

		$name = preg_replace( '/\.(css|js)$/', '', $relative );
		$name = preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) $name );

		return self::sanitize_key( $prefix . '-' . trim( (string) $name, '-' ) );
	}

	/**
	 * Enqueues a style record.
	 *
	 * @param string $prefix Handle prefix.
	 * @param array  $record Asset record.
	 * @return void
	 */
	public static function enqueue_style( string $prefix, array $record ): void {
		if ( empty( $record['uri'] ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style(
			self::handle( $prefix, $record ),
			(string) $record['uri'],
			self::dependencies( $record ),
			$record['version'] ?? null
		);
	}

	/**
	 * Enqueues a script record.
	 *
	 * @param string $prefix Handle prefix.
	 * @param array  $record Asset record.
	 * @return void
	 */
	public static function enqueue_script( string $prefix, array $record ): void {
		if ( empty( $record['uri'] ) ) {
			return;
		}

		$handle = self::handle( $prefix, $record );

		if ( self::is_module_script( $record ) ) {
			wp_enqueue_script_module(
				$handle,
				(string) $record['uri'],
				self::dependencies( $record ),
				$record['version'] ?? null
			);
			return;
		}

		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			(string) $record['uri'],
			self::dependencies( $record ),
			$record['version'] ?? null,
			array(
				'in_footer' => true,
			)
		);
	}

	/**
	 * Gets dependency handles from an asset record.
	 *
	 * @param array $record Asset record.
	 * @return array Dependency handles.
	 */
	public static function dependencies( array $record ): array {
		return isset( $record['dependencies'] ) && is_array( $record['dependencies'] ) ? $record['dependencies'] : array();
	}

	/**
	 * Checks whether a script should be enqueued as a module.
	 *
	 * @param array $record Asset record.
	 * @return bool TRUE when the script is a module.
	 */
	public static function is_module_script( array $record ): bool {
		return ! array_key_exists( 'module', $record ) || null === $record['module'] ? true : (bool) $record['module'];
	}

	/**
	 * Sanitizes a WordPress asset handle.
	 *
	 * @param string $handle Raw handle.
	 * @return string Sanitized handle.
	 */
	private static function sanitize_key( string $handle ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $handle ) : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', $handle ) );
	}
}
