<?php
/**
 * Shared filesystem discovery helpers.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Normalizes theme roots and discovers files in deterministic order.
 */
final class FileDiscovery {

	/**
	 * Roots whose discovery failures have already been reported this request.
	 *
	 * @var array<string, bool>
	 */
	private static array $reported_roots = array();

	/**
	 * Builds child-first theme root candidates for a theme-relative directory.
	 *
	 * @param string $directory  Theme-relative directory.
	 * @param bool   $include_uri Whether public URI values should be included.
	 * @return array Theme root candidates.
	 */
	public static function theme_roots( string $directory, bool $include_uri = false ): array {
		$directory = trim( $directory, '/\\' );
		$roots     = array();

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$root = array(
				'path'     => rtrim( get_stylesheet_directory(), '/\\' ) . '/' . $directory,
				'priority' => 0,
				'source'   => 'child',
			);

			if ( $include_uri && function_exists( 'get_stylesheet_directory_uri' ) ) {
				$root['uri'] = rtrim( get_stylesheet_directory_uri(), '/' ) . '/' . $directory;
			}

			$roots[] = $root;
		}

		if ( function_exists( 'get_template_directory' ) ) {
			$root = array(
				'path'     => rtrim( get_template_directory(), '/\\' ) . '/' . $directory,
				'priority' => 1,
				'source'   => 'parent',
			);

			if ( $include_uri && function_exists( 'get_template_directory_uri' ) ) {
				$root['uri'] = rtrim( get_template_directory_uri(), '/' ) . '/' . $directory;
			}

			$roots[] = $root;
		}

		return $roots;
	}

	/**
	 * Normalizes root records to readable, unique directories.
	 *
	 * @param array $roots   Root records or path strings.
	 * @param array $options Normalization options.
	 * @return array Normalized root records.
	 */
	public static function normalize_roots( array $roots, array $options = array() ): array {
		$require_uri    = ! empty( $options['require_uri'] );
		$default_source = array_key_exists( 'default_source', $options ) ? $options['default_source'] : 'filtered';
		$default_source = null === $default_source || is_scalar( $default_source ) ? $default_source : 'filtered';
		$default_source = null === $default_source ? null : (string) $default_source;
		$normalized     = array();
		$seen           = array();

		foreach ( $roots as $index => $root ) {
			$candidate = self::normalize_root_candidate( $root, (int) $index, $default_source, $require_uri );

			if ( null === $candidate ) {
				continue;
			}

			$real = realpath( $candidate['path'] );

			if (
				false === $real
				|| isset( $seen[ $real ] )
				|| ! is_dir( $candidate['path'] )
			) {
				continue;
			}

			if ( ! is_readable( $candidate['path'] ) ) {
				self::report_unreadable_directory( $candidate['path'], $candidate['path'] );
				continue;
			}

			$seen[ $real ] = true;
			$normalized[]  = $candidate;
		}

		return $normalized;
	}

	/**
	 * Gets file records for normalized roots.
	 *
	 * @param array $roots      Normalized root records.
	 * @param array $extensions Allowed extensions. Empty allows all files.
	 * @param bool  $recursive  Whether child directories should be scanned.
	 * @param bool  $complete   Receives whether every directory could be scanned.
	 * @return array File records.
	 */
	public static function file_records( array $roots, array $extensions = array(), bool $recursive = true, bool &$complete = true ): array {
		$records  = array();
		$complete = true;

		foreach ( $roots as $root ) {
			if ( empty( $root['path'] ) || ! is_scalar( $root['path'] ) ) {
				continue;
			}

			$path          = rtrim( (string) $root['path'], '/\\' );
			$root_complete = true;
			$files         = $recursive
				? self::recursive_files( $path, $extensions, $root_complete )
				: self::directory_files( $path, $extensions, $root_complete );
			$complete      = $complete && $root_complete;

			foreach ( $files as $file ) {
				$record = array(
					'path'        => $file,
					'priority'    => isset( $root['priority'] ) ? (int) $root['priority'] : 0,
					'relative'    => self::relative_path( $path, $file ),
					'root_path'   => $path,
					'root_source' => isset( $root['source'] ) ? (string) $root['source'] : '',
				);

				if ( isset( $root['uri'] ) ) {
					$record['root_uri'] = rtrim( (string) $root['uri'], '/' );
				}

				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Gets all files below a directory in deterministic order.
	 *
	 * @param string $directory  Directory to scan.
	 * @param array  $extensions Allowed extensions. Empty allows all files.
	 * @param bool   $complete   Receives whether every directory could be scanned.
	 * @return array Absolute file paths.
	 */
	public static function recursive_files( string $directory, array $extensions = array(), bool &$complete = true ): array {
		$files      = array();
		$extensions = self::normalize_extensions( $extensions );
		$pending    = array( $directory );
		$complete   = true;

		// Open each directory separately so a failed branch cannot hide its siblings.
		while ( $pending ) {
			$path = array_pop( $pending );

			if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
				$complete = false;
				self::report_unreadable_directory( $directory, $path );
				continue;
			}

			try {
				$iterator = new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS );

				foreach ( $iterator as $file ) {
					if ( $file->isDir() && ! $file->isLink() ) {
						$pending[] = $file->getPathname();
					} elseif ( $file->isFile() && self::extension_allowed( $file, $extensions ) ) {
						$files[] = $file->getPathname();
					}
				}
			} catch ( \UnexpectedValueException $error ) {
				$complete = false;
				self::report_unreadable_directory( $directory, $path );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Gets immediate child files from a directory in deterministic order.
	 *
	 * @param string $directory  Directory to scan.
	 * @param array  $extensions Allowed extensions. Empty allows all files.
	 * @param bool   $complete   Receives whether the directory could be scanned.
	 * @return array Absolute file paths.
	 */
	public static function directory_files( string $directory, array $extensions = array(), bool &$complete = true ): array {
		$files      = array();
		$extensions = self::normalize_extensions( $extensions );
		$complete   = true;

		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			$complete = false;
			self::report_unreadable_directory( $directory, $directory );
			return $files;
		}

		try {
			$iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && self::extension_allowed( $file, $extensions ) ) {
					$files[] = $file->getPathname();
				}
			}
		} catch ( \UnexpectedValueException $error ) {
			$complete = false;
			self::report_unreadable_directory( $directory, $directory );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Reports a failed scan once per root in debug environments only.
	 *
	 * @param string $root      Discovery root.
	 * @param string $directory Unreadable or vanished directory.
	 * @return void
	 */
	private static function report_unreadable_directory( string $root, string $directory ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || isset( self::$reported_roots[ $root ] ) ) {
			return;
		}

		self::$reported_roots[ $root ] = true;
		$message                       = sprintf( 'Skipping unreadable or missing discovery directory: %s (root: %s).', $directory, $root );

		if ( function_exists( 'wp_trigger_error' ) ) {
			wp_trigger_error( __METHOD__, $message, E_USER_WARNING );
		} else {
			error_log( $message );
		}
	}

	/**
	 * Builds a POSIX relative path.
	 *
	 * @param string $base_path Base directory.
	 * @param string $path      Absolute file path.
	 * @return string Relative path.
	 */
	public static function relative_path( string $base_path, string $path ): string {
		$relative = ltrim( str_replace( rtrim( $base_path, '/\\' ), '', $path ), '/\\' );

		return str_replace( '\\', '/', $relative );
	}

	/**
	 * Sorts records by root priority and relative path while preserving ties.
	 *
	 * @param array $records File records.
	 * @return array Sorted file records.
	 */
	public static function sort_by_priority_and_relative( array $records ): array {
		$indexed = array();

		foreach ( $records as $index => $record ) {
			$indexed[] = array(
				'index'  => (int) $index,
				'record' => $record,
			);
		}

		usort(
			$indexed,
			static function ( array $left, array $right ): int {
				$left_record  = $left['record'];
				$right_record = $right['record'];
				$priority     = ( (int) ( $left_record['priority'] ?? 0 ) ) <=> ( (int) ( $right_record['priority'] ?? 0 ) );

				if ( 0 !== $priority ) {
					return $priority;
				}

				$relative = strcmp( (string) ( $left_record['relative'] ?? '' ), (string) ( $right_record['relative'] ?? '' ) );

				return 0 === $relative ? $left['index'] <=> $right['index'] : $relative;
			}
		);

		return array_column( $indexed, 'record' );
	}

	/**
	 * Normalizes a single root candidate.
	 *
	 * @param mixed       $root           Root record or path string.
	 * @param int         $index          Root list index.
	 * @param string|null $default_source Source value for string roots.
	 * @param bool        $require_uri    Whether roots must include a URI.
	 * @return array|null Normalized candidate, or null when invalid.
	 */
	private static function normalize_root_candidate( $root, int $index, ?string $default_source, bool $require_uri ): ?array {
		if ( is_string( $root ) ) {
			$root = array(
				'path'   => $root,
				'source' => $default_source ?? (string) $index,
			);
		}

		if ( ! is_array( $root ) || empty( $root['path'] ) || ! is_scalar( $root['path'] ) ) {
			return null;
		}

		$path = rtrim( (string) $root['path'], '/\\' );

		if ( '' === $path ) {
			return null;
		}

		$candidate = array(
			'path'     => $path,
			'priority' => isset( $root['priority'] ) ? (int) $root['priority'] : $index,
			'source'   => isset( $root['source'] ) && is_scalar( $root['source'] )
				? (string) $root['source']
				: ( $default_source ?? (string) $index ),
		);

		if ( isset( $root['uri'] ) && is_scalar( $root['uri'] ) ) {
			$candidate['uri'] = rtrim( (string) $root['uri'], '/' );
		}

		if ( $require_uri && empty( $candidate['uri'] ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * Normalizes extension names for comparisons.
	 *
	 * @param array $extensions Extension list.
	 * @return array Normalized extension list.
	 */
	private static function normalize_extensions( array $extensions ): array {
		$normalized = array();

		foreach ( $extensions as $extension ) {
			if ( ! is_scalar( $extension ) ) {
				continue;
			}

			$extension = strtolower( ltrim( trim( (string) $extension ), '.' ) );

			if ( '' !== $extension ) {
				$normalized[] = $extension;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Checks whether a file extension is allowed.
	 *
	 * @param \SplFileInfo $file       File info.
	 * @param array        $extensions Normalized allowed extensions.
	 * @return bool TRUE when the file should be included.
	 */
	private static function extension_allowed( \SplFileInfo $file, array $extensions ): bool {
		return empty( $extensions ) || in_array( strtolower( $file->getExtension() ), $extensions, true );
	}
}
