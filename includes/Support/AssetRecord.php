<?php
/**
 * Shared asset record helpers.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Normalizes built asset records and scoped context groups.
 */
final class AssetRecord {

	/**
	 * Converts discovered files to child-first, ordered asset records.
	 *
	 * @param array $files Discovered files, with service exclusions already applied.
	 * @return array Built asset records.
	 */
	public static function from_discovered_files( array $files ): array {
		$assets = array();
		$seen   = array();

		foreach ( $files as $file ) {
			$relative = $file['relative'];

			if ( isset( $seen[ $relative ] ) ) {
				continue;
			}

			$seen[ $relative ] = true;
			$assets[]          = array(
				'path'     => $file['path'],
				'priority' => $file['priority'],
				'relative' => $relative,
				'uri'      => $file['root_uri'] . '/' . $relative,
				'version'  => self::entry_version( array(), $file['path'] ),
			);
		}

		return FileDiscovery::sort_by_priority_and_relative( $assets );
	}

	/**
	 * Gets an asset entry path.
	 *
	 * @param array $entry Asset entry.
	 * @return string Relative path.
	 */
	public static function entry_path( array $entry ): string {
		foreach ( array( 'path', 'file', 'src', 'href' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ) {
				return self::normalize_relative_path( (string) $entry[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Gets the record-relative path.
	 *
	 * @param array  $entry    Asset entry.
	 * @param string $fallback Fallback relative path.
	 * @return string Relative path.
	 */
	public static function entry_relative( array $entry, string $fallback ): string {
		if ( isset( $entry['relative'] ) && is_scalar( $entry['relative'] ) ) {
			$relative = self::normalize_relative_path( (string) $entry['relative'] );

			if ( '' !== $relative ) {
				return $relative;
			}
		}

		return $fallback;
	}

	/**
	 * Gets the asset version.
	 *
	 * @param array  $entry Asset entry.
	 * @param string $path  Absolute asset path.
	 * @return string|null Version.
	 */
	public static function entry_version( array $entry, string $path ): ?string {
		foreach ( array( 'version', 'hash' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && '' !== trim( (string) $entry[ $key ] ) ) {
				return (string) $entry[ $key ];
			}
		}

		$modified = filemtime( $path );

		return false === $modified ? null : (string) $modified;
	}

	/**
	 * Gets normalized dependency handles.
	 *
	 * @param array $entry Asset entry.
	 * @return array Dependency handles.
	 */
	public static function entry_dependencies( array $entry ): array {
		$dependencies = $entry['dependencies'] ?? ( $entry['deps'] ?? array() );

		if ( ! is_array( $dependencies ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $dependency ) {
						return is_scalar( $dependency ) ? trim( (string) $dependency ) : null;
					},
					$dependencies
				),
				static function ( $dependency ): bool {
					return is_string( $dependency ) && '' !== $dependency;
				}
			)
		);
	}

	/**
	 * Gets an optional script module flag.
	 *
	 * @param array $entry Asset entry.
	 * @return bool|null Module flag, or null to use service default.
	 */
	public static function entry_module( array $entry ): ?bool {
		if ( array_key_exists( 'module', $entry ) ) {
			return ! empty( $entry['module'] );
		}

		if ( array_key_exists( 'type', $entry ) && is_scalar( $entry['type'] ) ) {
			return 'module' === strtolower( trim( (string) $entry['type'] ) );
		}

		return null;
	}

	/**
	 * Normalizes a relative asset path.
	 *
	 * @param string $path Candidate path.
	 * @return string Safe relative path.
	 */
	public static function normalize_relative_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ) );

		if (
			'' === $path
			|| false !== strpos( $path, "\0" )
			|| 0 === strpos( $path, '/' )
			|| preg_match( '#(^|/)\.\.(/|$)#', $path )
		) {
			return '';
		}

		while ( 0 === strpos( $path, './' ) ) {
			$path = substr( $path, 2 );
		}

		return $path;
	}

	/**
	 * Gets an empty scoped asset record set.
	 *
	 * @return array Empty records.
	 */
	public static function empty_context_records(): array {
		return array(
			'frontend' => array(
				'css' => array(),
				'js'  => array(),
			),
			'editor'   => array(
				'css' => array(),
				'js'  => array(),
			),
		);
	}

	/**
	 * Normalizes scoped asset record groups.
	 *
	 * @param array $records Candidate records.
	 * @return array Normalized records.
	 */
	public static function normalize_context_records( array $records ): array {
		$normalized = self::empty_context_records();

		foreach ( array( 'frontend', 'editor' ) as $context ) {
			if ( empty( $records[ $context ] ) || ! is_array( $records[ $context ] ) ) {
				continue;
			}

			foreach ( array( 'css', 'js' ) as $type ) {
				$normalized[ $context ][ $type ] = isset( $records[ $context ][ $type ] ) && is_array( $records[ $context ][ $type ] )
					? array_values( $records[ $context ][ $type ] )
					: array();
			}
		}

		return $normalized;
	}

	/**
	 * Merges two context asset record sets.
	 *
	 * @param array $base Base records.
	 * @param array $add  Records to add.
	 * @return array Merged records.
	 */
	public static function merge_context_records( array $base, array $add ): array {
		foreach ( array( 'frontend', 'editor' ) as $context ) {
			foreach ( array( 'css', 'js' ) as $type ) {
				$base[ $context ][ $type ] = array_merge(
					$base[ $context ][ $type ] ?? array(),
					$add[ $context ][ $type ] ?? array()
				);
			}
		}

		return $base;
	}

	/**
	 * Sorts context asset records.
	 *
	 * @param array $records Context records.
	 * @return array Sorted context records.
	 */
	public static function sort_context_records( array $records ): array {
		foreach ( array( 'frontend', 'editor' ) as $context ) {
			foreach ( array( 'css', 'js' ) as $type ) {
				$records[ $context ][ $type ] = FileDiscovery::sort_by_priority_and_relative( $records[ $context ][ $type ] ?? array() );
			}
		}

		return $records;
	}

	/**
	 * Checks whether any scoped asset records are present.
	 *
	 * @param array $records Scoped records.
	 * @return bool TRUE when records are present.
	 */
	public static function has_context_records( array $records ): bool {
		foreach ( array( 'frontend', 'editor' ) as $context ) {
			foreach ( array( 'css', 'js' ) as $type ) {
				if ( ! empty( $records[ $context ][ $type ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
