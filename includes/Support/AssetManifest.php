<?php
/**
 * Optional built asset manifest reader.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Reads child-first asset manifests and normalizes asset records.
 */
final class AssetManifest {

	/**
	 * Default theme-relative manifest path.
	 *
	 * @var string
	 */
	private const DEFAULT_PATH = 'dist/emulsify-assets.json';

	/**
	 * Memoized manifest record.
	 *
	 * @var array|null
	 */
	private $manifest;

	/**
	 * Whether manifest resolution has already run for this instance.
	 *
	 * @var bool
	 */
	private $manifest_resolved = false;

	/**
	 * Gets manifest-backed asset records for a runtime scope.
	 *
	 * A null return means no usable manifest data was found for the scope and
	 * callers should keep their existing filesystem scanner fallback.
	 *
	 * @param string $scope      Asset scope: global, editor, or components.
	 * @param array  $extensions Allowed file extensions.
	 * @return array|null Asset records, or null when scanner fallback should run.
	 */
	public function asset_records( string $scope, array $extensions ): ?array {
		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return null;
		}

		$records = $this->scope_records( $scope, $extensions, $this->asset_data( $manifest ), $manifest );

		if ( null === $records ) {
			return null;
		}

		return FileDiscovery::sort_by_priority_and_relative( $records );
	}

	/**
	 * Gets manifest-backed asset records for specific block/component keys.
	 *
	 * A null return means the manifest does not declare any matching scoped
	 * assets and callers may use component metadata or scanner fallbacks.
	 *
	 * @param array $identifiers Block and component identifiers.
	 * @return array|null Asset records grouped by frontend/editor and css/js.
	 */
	public function scoped_asset_records( array $identifiers ): ?array {
		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return null;
		}

		$assets      = $this->asset_data( $manifest );
		$identifiers = $this->normalize_identifiers( $identifiers );
		$records     = AssetRecord::empty_context_records();
		$declared    = false;

		foreach ( $identifiers as $identifier ) {
			if ( isset( $assets['blocks'] ) && is_array( $assets['blocks'] ) && array_key_exists( $identifier, $assets['blocks'] ) ) {
				$declared = true;
				$records  = AssetRecord::merge_context_records(
					$records,
					$this->context_section_records( $assets['blocks'][ $identifier ], $manifest, $identifier )
				);
			}

			if ( isset( $assets['components'] ) && is_array( $assets['components'] ) && array_key_exists( $identifier, $assets['components'] ) ) {
				$declared = true;
				$records  = AssetRecord::merge_context_records(
					$records,
					$this->context_section_records( $assets['components'][ $identifier ], $manifest, $identifier )
				);
			}
		}

		return $declared ? AssetRecord::sort_context_records( $records ) : null;
	}

	/**
	 * Gets manifest-scoped component asset paths.
	 *
	 * These paths are relative to dist/components and are used by the recursive
	 * scanner to avoid globally loading files that a block/component manifest
	 * entry will load only when the block renders.
	 *
	 * @return array Scoped component asset paths keyed by relative path.
	 */
	public function scoped_component_asset_paths(): array {
		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return array();
		}

		$assets = $this->asset_data( $manifest );
		$paths  = array();

		if ( isset( $assets['blocks'] ) && is_array( $assets['blocks'] ) ) {
			foreach ( $assets['blocks'] as $section ) {
				foreach ( $this->context_section_paths( $section ) as $path ) {
					$paths[ $path ] = true;
				}
			}
		}

		if ( isset( $assets['components'] ) && is_array( $assets['components'] ) && ! $this->is_asset_section( $assets['components'] ) ) {
			foreach ( $assets['components'] as $section ) {
				foreach ( $this->context_section_paths( $section ) as $path ) {
					$paths[ $path ] = true;
				}
			}
		}

		return $paths;
	}

	/**
	 * Gets records for a manifest asset scope.
	 *
	 * @param string $scope      Asset scope.
	 * @param array  $extensions Allowed file extensions.
	 * @param array  $assets     Manifest asset data.
	 * @param array  $manifest   Active manifest record.
	 * @return array|null Asset records, or null when the scope is undeclared.
	 */
	private function scope_records( string $scope, array $extensions, array $assets, array $manifest ): ?array {
		if ( 'components' === $scope ) {
			$declared = false;
			$records  = array();

			if ( array_key_exists( 'components', $assets ) ) {
				$declared = true;
				$records  = $this->is_asset_section( $assets['components'] )
					? array_merge( $records, $this->section_records( $assets['components'], $extensions, $manifest ) )
					: $records;
			}

			return $declared ? $records : null;
		}

		if ( ! array_key_exists( $scope, $assets ) ) {
			return null;
		}

		return $this->section_records( $assets[ $scope ], $extensions, $manifest );
	}

	/**
	 * Gets normalized records from a manifest section.
	 *
	 * @param mixed  $section    Manifest section.
	 * @param array  $extensions Allowed file extensions.
	 * @param array  $manifest   Active manifest record.
	 * @param string $block_name Optional block name for block-specific entries.
	 * @return array Asset records.
	 */
	private function section_records( $section, array $extensions, array $manifest, string $block_name = '' ): array {
		if ( ! is_array( $section ) ) {
			return array();
		}

		$records = array();

		foreach ( $extensions as $extension ) {
			if ( ! is_scalar( $extension ) ) {
				continue;
			}

			$key = strtolower( ltrim( trim( (string) $extension ), '.' ) );

			if ( '' === $key || empty( $section[ $key ] ) || ! is_array( $section[ $key ] ) ) {
				continue;
			}

			foreach ( $section[ $key ] as $entry ) {
				$record = $this->asset_record( $entry, $key, $manifest, $block_name );

				if ( null !== $record ) {
					$records[] = $record;
				}
			}
		}

		return $records;
	}

	/**
	 * Gets frontend/editor records from a manifest section.
	 *
	 * @param mixed  $section    Manifest section.
	 * @param array  $manifest   Active manifest record.
	 * @param string $block_name Optional block/component identifier.
	 * @return array Context asset records.
	 */
	private function context_section_records( $section, array $manifest, string $block_name = '' ): array {
		$records = AssetRecord::empty_context_records();

		if ( ! is_array( $section ) ) {
			return $records;
		}

		if ( isset( $section['frontend'] ) || isset( $section['editor'] ) ) {
			if ( isset( $section['frontend'] ) && is_array( $section['frontend'] ) ) {
				$records['frontend']['css'] = $this->section_records( $section['frontend'], array( 'css' ), $manifest, $block_name );
				$records['frontend']['js']  = $this->section_records( $section['frontend'], array( 'js' ), $manifest, $block_name );
			}

			if ( isset( $section['editor'] ) && is_array( $section['editor'] ) ) {
				$records['editor']['css'] = $this->section_records( $section['editor'], array( 'css' ), $manifest, $block_name );
				$records['editor']['js']  = $this->section_records( $section['editor'], array( 'js' ), $manifest, $block_name );
			}

			return $records;
		}

		$records['frontend']['css'] = $this->section_records( $section, array( 'css' ), $manifest, $block_name );
		$records['frontend']['js']  = $this->section_records( $section, array( 'js' ), $manifest, $block_name );

		return $records;
	}

	/**
	 * Gets scoped asset paths from a frontend/editor manifest section.
	 *
	 * @param mixed $section Manifest section.
	 * @return array Component-relative paths.
	 */
	private function context_section_paths( $section ): array {
		if ( ! is_array( $section ) ) {
			return array();
		}

		$paths = array();

		if ( isset( $section['frontend'] ) || isset( $section['editor'] ) ) {
			foreach ( array( 'frontend', 'editor' ) as $context ) {
				if ( isset( $section[ $context ] ) && is_array( $section[ $context ] ) ) {
					$paths = array_merge( $paths, $this->section_paths( $section[ $context ] ) );
				}
			}

			return array_values( array_unique( $paths ) );
		}

		return $this->section_paths( $section );
	}

	/**
	 * Gets scoped asset paths from a css/js manifest section.
	 *
	 * @param array $section Manifest section.
	 * @return array Component-relative paths.
	 */
	private function section_paths( array $section ): array {
		$paths = array();

		foreach ( array( 'css', 'js' ) as $type ) {
			if ( empty( $section[ $type ] ) || ! is_array( $section[ $type ] ) ) {
				continue;
			}

			foreach ( $section[ $type ] as $entry ) {
				$data = is_array( $entry ) ? $entry : array( 'path' => $entry );
				$path = $this->component_relative_path( AssetRecord::entry_path( $data ) );

				if ( '' !== $path ) {
					$paths[] = $path;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Converts a manifest entry path into a dist/components-relative path.
	 *
	 * @param string $path Manifest entry path.
	 * @return string Component-relative path.
	 */
	private function component_relative_path( string $path ): string {
		if ( 0 === strpos( $path, 'components/' ) ) {
			return substr( $path, strlen( 'components/' ) );
		}

		return $path;
	}

	/**
	 * Gets manifest asset data.
	 *
	 * @param array $manifest Active manifest record.
	 * @return array Asset data.
	 */
	private function asset_data( array $manifest ): array {
		return isset( $manifest['data']['assets'] ) && is_array( $manifest['data']['assets'] )
			? $manifest['data']['assets']
			: $manifest['data'];
	}

	/**
	 * Checks whether a section directly declares css/js records.
	 *
	 * @param mixed $section Manifest section.
	 * @return bool TRUE when the section is a broad asset section.
	 */
	private function is_asset_section( $section ): bool {
		return is_array( $section ) && ( array_key_exists( 'css', $section ) || array_key_exists( 'js', $section ) );
	}

	/**
	 * Normalizes scoped asset identifiers.
	 *
	 * @param array $identifiers Candidate identifiers.
	 * @return array Normalized identifiers.
	 */
	private function normalize_identifiers( array $identifiers ): array {
		$normalized = array();

		foreach ( $identifiers as $identifier ) {
			if ( ! is_scalar( $identifier ) ) {
				continue;
			}

			$identifier = trim( (string) $identifier );

			if ( '' !== $identifier ) {
				$normalized[] = $identifier;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalizes a manifest asset entry.
	 *
	 * @param mixed  $entry      Manifest asset entry.
	 * @param string $extension  Expected file extension.
	 * @param array  $manifest   Active manifest record.
	 * @param string $block_name Optional block name for block-specific entries.
	 * @return array|null Asset record, or null when invalid.
	 */
	private function asset_record( $entry, string $extension, array $manifest, string $block_name = '' ): ?array {
		$data     = is_array( $entry ) ? $entry : array( 'path' => $entry );
		$relative = AssetRecord::entry_path( $data );

		if ( '' === $relative || strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) !== $extension ) {
			return null;
		}

		$path = $manifest['base_path'] . '/' . $relative;

		if ( ! is_readable( $path ) ) {
			$this->debug_log( sprintf( 'Asset not readable: %s', $path ) );
			return null;
		}

		$record = array(
			'path'          => $path,
			'priority'      => $manifest['priority'],
			'relative'      => AssetRecord::entry_relative( $data, $relative ),
			'uri'           => $manifest['base_uri'] . '/' . $relative,
			'version'       => AssetRecord::entry_version( $data, $path ),
			'dependencies'  => AssetRecord::entry_dependencies( $data ),
			'module'        => AssetRecord::entry_module( $data ),
			'source'        => $manifest['source'],
			'manifest_path' => $manifest['path'],
		);

		if ( '' !== $block_name ) {
			$record['block'] = $block_name;
		}

		return $record;
	}

	/**
	 * Gets the first readable, valid child-first manifest.
	 *
	 * @return array|null Active manifest record, or null when unavailable.
	 */
	private function manifest(): ?array {
		if ( $this->manifest_resolved ) {
			return $this->manifest;
		}

		$this->manifest_resolved = true;
		$this->manifest          = $this->resolve_manifest();

		return $this->manifest;
	}

	/**
	 * Resolves the first readable, valid child-first manifest.
	 *
	 * @return array|null Active manifest record, or null when unavailable.
	 */
	private function resolve_manifest(): ?array {
		foreach ( $this->manifest_candidates() as $candidate ) {
			if ( ! file_exists( $candidate['path'] ) ) {
				continue;
			}

			if ( ! is_file( $candidate['path'] ) || ! is_readable( $candidate['path'] ) ) {
				$this->debug_log( sprintf( 'Asset manifest not readable: %s', $candidate['path'] ) );
				continue;
			}

			$contents = file_get_contents( $candidate['path'] );

			if ( ! is_string( $contents ) ) {
				$this->debug_log( sprintf( 'Asset manifest could not be read: %s', $candidate['path'] ) );
				continue;
			}

			$data = json_decode( $contents, true );

			if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
				$this->debug_log(
					sprintf(
						'Asset manifest contains invalid JSON: %s (%s)',
						$candidate['path'],
						json_last_error_msg()
					)
				);
				continue;
			}

			/**
			 * Filters parsed asset manifest data before records are created.
			 *
			 * Return a non-array value to ignore the manifest and fall back to
			 * the recursive scanner for the current request.
			 *
			 * @param array $data      Parsed manifest data.
			 * @param array $candidate Manifest file candidate.
			 */
			$filtered = apply_filters( 'emulsify_theme_asset_manifest_data', $data, $candidate );

			if ( ! is_array( $filtered ) ) {
				return null;
			}

			$candidate['data'] = $filtered;

			return $candidate;
		}

		return null;
	}

	/**
	 * Logs asset diagnostics during active WordPress debugging.
	 *
	 * @param string $message Diagnostic message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( '[Emulsify] ' . $message );
	}

	/**
	 * Gets child-first manifest candidates.
	 *
	 * @return array Manifest file candidates.
	 */
	private function manifest_candidates(): array {
		$relative_path = self::relative_path();

		if ( '' === $relative_path ) {
			return array();
		}

		$candidates = array();

		if ( function_exists( 'get_stylesheet_directory' ) && function_exists( 'get_stylesheet_directory_uri' ) ) {
			$candidates[] = $this->candidate(
				get_stylesheet_directory(),
				get_stylesheet_directory_uri(),
				$relative_path,
				0,
				'child'
			);
		}

		if ( function_exists( 'get_template_directory' ) && function_exists( 'get_template_directory_uri' ) ) {
			$candidates[] = $this->candidate(
				get_template_directory(),
				get_template_directory_uri(),
				$relative_path,
				1,
				'parent'
			);
		}

		return $candidates;
	}

	/**
	 * Gets the filtered theme-relative manifest path for assets and cache keys.
	 *
	 * @return string Manifest path, or empty string when disabled.
	 */
	public static function relative_path(): string {
		/**
		 * Filters the theme-relative asset manifest path.
		 *
		 * Return an empty value to disable manifest loading and always use the
		 * recursive scanner fallback.
		 *
		 * @param string $relative_path Theme-relative manifest path.
		 */
		$filtered = apply_filters( 'emulsify_theme_asset_manifest_path', self::DEFAULT_PATH );

		return is_scalar( $filtered ) ? AssetRecord::normalize_relative_path( (string) $filtered ) : '';
	}

	/**
	 * Builds a manifest candidate record.
	 *
	 * @param string $theme_path    Theme root path.
	 * @param string $theme_uri     Theme root URI.
	 * @param string $relative_path Theme-relative manifest path.
	 * @param int    $priority      Root priority.
	 * @param string $source        Root source.
	 * @return array Manifest candidate record.
	 */
	private function candidate( string $theme_path, string $theme_uri, string $relative_path, int $priority, string $source ): array {
		$path = rtrim( $theme_path, '/\\' ) . '/' . $relative_path;
		$uri  = rtrim( $theme_uri, '/' ) . '/' . $relative_path;

		return array(
			'path'      => $path,
			'base_path' => dirname( $path ),
			'base_uri'  => rtrim( str_replace( '\\', '/', dirname( $uri ) ), '/' ),
			'priority'  => $priority,
			'source'    => $source,
		);
	}
}
