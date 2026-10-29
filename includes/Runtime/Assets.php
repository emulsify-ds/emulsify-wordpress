<?php
/**
 * Enqueues theme assets.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Runtime;

use Emulsify\Theme\Blocks\ComponentLocator;
use Emulsify\Theme\Support\AssetEnqueuer;
use Emulsify\Theme\Support\AssetManifest;
use Emulsify\Theme\Support\AssetRecord;
use Emulsify\Theme\Support\FileDiscovery;

/**
 * Global asset registration.
 */
final class Assets {

	/**
	 * Asset manifest reader.
	 *
	 * @var AssetManifest
	 */
	private $manifest;

	/**
	 * Shared component metadata index.
	 *
	 * @var ComponentLocator
	 */
	private $components;

	/**
	 * Memoized raw asset file records by theme-relative directory.
	 *
	 * @var array
	 */
	private $asset_file_records = array();

	/**
	 * Memoized component metadata asset paths.
	 *
	 * @var array|null
	 */
	private $component_asset_paths;

	/**
	 * Memoized manifest-scoped component asset paths.
	 *
	 * @var array|null
	 */
	private $manifest_component_asset_paths;

	/**
	 * Constructor.
	 *
	 * @param AssetManifest|null    $manifest   Asset manifest reader.
	 * @param ComponentLocator|null $components Shared component metadata index.
	 */
	public function __construct( ?AssetManifest $manifest = null, ?ComponentLocator $components = null ) {
		$this->manifest   = $manifest ?? new AssetManifest();
		$this->components = $components ?? new ComponentLocator();
	}

	/**
	 * Registers asset hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_assets', array( $this, 'styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );
	}

	/**
	 * Enqueues theme styles for the frontend and block editor preview.
	 *
	 * @return void
	 */
	public function styles(): void {
		$this->enqueue_styles( 'emulsify-global', 'dist/global' );
		$this->enqueue_styles( 'emulsify-component', 'dist/components' );
	}

	/**
	 * Enqueues frontend component scripts.
	 *
	 * @return void
	 */
	public function frontend_scripts(): void {
		$this->enqueue_scripts( 'emulsify-global', 'dist/global' );
		$this->enqueue_scripts( 'emulsify-component', 'dist/components' );
	}

	/**
	 * Enqueues CSS files from a built asset directory.
	 *
	 * @param string $prefix    Handle prefix.
	 * @param string $directory Theme-relative asset directory.
	 * @return void
	 */
	private function enqueue_styles( string $prefix, string $directory ): void {
		foreach ( $this->asset_files( $directory, array( 'css' ) ) as $asset ) {
			AssetEnqueuer::enqueue_style( $prefix, $asset );
		}
	}

	/**
	 * Enqueues JavaScript files from a built asset directory.
	 *
	 * @param string $prefix    Handle prefix.
	 * @param string $directory Theme-relative asset directory.
	 * @return void
	 */
	private function enqueue_scripts( string $prefix, string $directory ): void {
		foreach ( $this->asset_files( $directory, array( 'js' ) ) as $asset ) {
			AssetEnqueuer::enqueue_script( $prefix, $asset );
		}
	}

	/**
	 * Finds built assets below a theme-relative directory.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array Built asset records.
	 */
	private function asset_files( string $directory, array $extensions ): array {
		$manifest_assets = $this->manifest_asset_files( $directory, $extensions );
		$assets          = null === $manifest_assets ? $this->scanned_asset_files( $directory, $extensions ) : $manifest_assets;

		/**
		 * Filters built asset files before they are enqueued.
		 *
		 * Child themes and project plugins can add, remove, or reorder records.
		 * Asset records should include path, relative, uri, and version keys.
		 *
		 * @param array  $assets     Built asset records.
		 * @param string $directory  Theme-relative asset directory being scanned.
		 * @param array  $extensions Allowed file extensions for the current enqueue pass.
		 */
		$filtered = apply_filters( 'emulsify_theme_asset_files', $assets, $directory, $extensions );

		return is_array( $filtered ) ? $filtered : $assets;
	}

	/**
	 * Gets manifest-backed assets when a manifest declares the current scope.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array|null Manifest-backed records, or null for scanner fallback.
	 */
	private function manifest_asset_files( string $directory, array $extensions ): ?array {
		$scope = $this->manifest_scope( $directory );

		if ( null === $scope ) {
			return null;
		}

		return $this->manifest->asset_records( $scope, $extensions );
	}

	/**
	 * Maps a scanned asset directory to a manifest scope.
	 *
	 * @param string $directory Theme-relative asset directory.
	 * @return string|null Manifest scope, or null when unsupported.
	 */
	private function manifest_scope( string $directory ): ?string {
		$directory = trim( $directory, '/\\' );

		if ( 'dist/global' === $directory ) {
			return 'global';
		}

		if ( 'dist/components' === $directory ) {
			return 'components';
		}

		return null;
	}

	/**
	 * Finds built assets below a theme-relative directory through filesystem scans.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array Built asset records.
	 */
	private function scanned_asset_files( string $directory, array $extensions ): array {
		$files = array();

		foreach ( $this->raw_asset_file_records( $directory ) as $file ) {
			if ( ! $this->extension_allowed( $file, $extensions ) ) {
				continue;
			}

			$relative = $file['relative'];

			if ( $this->is_reserved_editor_asset( $directory, $relative ) ) {
				// Editor-only assets are handled by Editor\Enhancements so they
				// receive editor dependencies and configuration before enqueue.
				continue;
			}

			if ( $this->is_scoped_component_asset( $directory, $file ) ) {
				// Component metadata can opt assets into block-scoped loading. Leave
				// those files to the block registration callback instead of loading
				// them globally from every request's component scanner pass.
				continue;
			}

			$files[] = $file;
		}

		return AssetRecord::from_discovered_files( $files );
	}

	/**
	 * Gets raw built asset file records for a theme-relative directory.
	 *
	 * @param string $directory Theme-relative asset directory.
	 * @return array Raw file records.
	 */
	private function raw_asset_file_records( string $directory ): array {
		$key = trim( $directory, '/\\' );

		if ( ! array_key_exists( $key, $this->asset_file_records ) ) {
			// Metadata in asset-only filtered roots belongs to this same scan, too.
			$extensions                       = 'dist/components' === $key ? array( 'css', 'js', 'json' ) : array( 'css', 'js' );
			$this->asset_file_records[ $key ] = FileDiscovery::file_records( $this->asset_roots( $directory ), $extensions );
		}

		return $this->asset_file_records[ $key ];
	}

	/**
	 * Checks whether a raw asset record matches the requested extension set.
	 *
	 * @param array $file       Raw file discovery record.
	 * @param array $extensions Allowed file extensions.
	 * @return bool TRUE when the file extension is allowed.
	 */
	private function extension_allowed( array $file, array $extensions ): bool {
		$extensions = $this->normalize_extensions( $extensions );

		if ( empty( $extensions ) ) {
			return true;
		}

		$extension = strtolower( pathinfo( (string) ( $file['path'] ?? '' ), PATHINFO_EXTENSION ) );

		return isset( $extensions[ $extension ] );
	}

	/**
	 * Normalizes extension filters.
	 *
	 * @param array $extensions Candidate extensions.
	 * @return array Normalized extensions keyed by extension.
	 */
	private function normalize_extensions( array $extensions ): array {
		$normalized = array();

		foreach ( $extensions as $extension ) {
			if ( ! is_scalar( $extension ) ) {
				continue;
			}

			$extension = strtolower( ltrim( trim( (string) $extension ), '.' ) );

			if ( '' !== $extension ) {
				$normalized[ $extension ] = true;
			}
		}

		return $normalized;
	}

	/**
	 * Gets child theme asset roots first, then parent theme fallbacks.
	 *
	 * @param string $directory Theme-relative asset directory.
	 * @return array Asset root records.
	 */
	private function asset_roots( string $directory ): array {
		/**
		 * Filters built asset directories before files are discovered.
		 *
		 * Root records should include absolute path, public uri, and priority
		 * keys. Lower priority values are enqueued first after duplicate relative
		 * paths are resolved child-first.
		 *
		 * @param array  $directories Built asset directory records.
		 * @param string $directory   Theme-relative asset directory being scanned.
		 */
		return FileDiscovery::filtered_theme_roots( $directory, 'emulsify_theme_asset_directories' );
	}

	/**
	 * Checks whether a built asset is reserved for editor-only loading.
	 *
	 * @param string $directory Theme-relative asset directory being scanned.
	 * @param string $relative  Asset path relative to the built directory.
	 * @return bool TRUE when the editor service should own the asset.
	 */
	private function is_reserved_editor_asset( string $directory, string $relative ): bool {
		return 'dist/global' === trim( $directory, '/\\' )
			&& 0 === strpos( ltrim( $relative, '/\\' ), 'editor/' );
	}

	/**
	 * Checks whether a component asset is declared as block-scoped metadata.
	 *
	 * @param string $directory Theme-relative asset directory being scanned.
	 * @param array  $file      File discovery record.
	 * @return bool TRUE when the asset should not be globally loaded.
	 */
	private function is_scoped_component_asset( string $directory, array $file ): bool {
		if ( 'dist/components' !== trim( $directory, '/\\' ) || empty( $file['path'] ) ) {
			return false;
		}

		$root_relative = isset( $file['relative'] ) && is_scalar( $file['relative'] )
			? AssetRecord::normalize_relative_path( (string) $file['relative'] )
			: '';

		if ( '' !== $root_relative && isset( $this->manifest_component_asset_paths()[ $root_relative ] ) ) {
			return true;
		}

		$asset_paths = $this->component_asset_paths();
		$path        = (string) $file['path'];
		$real        = realpath( $path );

		return isset( $asset_paths[ false === $real ? $path : $real ] );
	}

	/**
	 * Gets manifest-scoped component asset paths.
	 *
	 * @return array Scoped paths keyed by dist/components-relative path.
	 */
	private function manifest_component_asset_paths(): array {
		if ( is_array( $this->manifest_component_asset_paths ) ) {
			return $this->manifest_component_asset_paths;
		}

		$this->manifest_component_asset_paths = $this->manifest->scoped_component_asset_paths();

		return $this->manifest_component_asset_paths;
	}

	/**
	 * Gets declared metadata asset paths from the shared component file index.
	 *
	 * Metadata-only components are included even when they cannot register a
	 * Twig block. Their declared editor files must never become global assets.
	 *
	 * @return array Declared asset paths keyed by absolute path.
	 */
	private function component_asset_paths(): array {
		if ( is_array( $this->component_asset_paths ) ) {
			return $this->component_asset_paths;
		}

		$paths          = array();
		$metadata_files = $this->components->component_metadata_files();

		// Preserve metadata scoping for roots added only to the asset directory
		// filter, without walking those roots a second time.
		foreach ( $this->raw_asset_file_records( 'dist/components' ) as $file ) {
			if ( preg_match( '/\.component\.json$/', basename( $file['path'] ) ) ) {
				$metadata_files[] = $file['path'];
			}
		}

		foreach ( array_unique( $metadata_files ) as $metadata_file ) {
			$contents = is_readable( $metadata_file ) ? file_get_contents( $metadata_file ) : false;

			if ( ! is_string( $contents ) ) {
				continue;
			}

			$metadata = json_decode( $contents, true );

			if ( ! is_array( $metadata ) || JSON_ERROR_NONE !== json_last_error() || empty( $metadata['assets'] ) || ! is_array( $metadata['assets'] ) ) {
				continue;
			}

			foreach ( $this->metadata_asset_paths( $metadata['assets'] ) as $path ) {
				$absolute = dirname( $metadata_file ) . '/' . $path;
				$real     = realpath( $absolute );
				$paths[ false === $real ? $absolute : $real ] = true;
			}
		}

		$this->component_asset_paths = $paths;

		return $paths;
	}

	/**
	 * Collects asset paths from component metadata asset sections.
	 *
	 * @param array $assets Component metadata assets.
	 * @return array Relative asset paths.
	 */
	private function metadata_asset_paths( array $assets ): array {
		$paths = array();

		foreach ( array( 'frontend', 'editor' ) as $context ) {
			if ( isset( $assets[ $context ] ) && is_array( $assets[ $context ] ) ) {
				$paths = array_merge( $paths, $this->metadata_asset_paths( $assets[ $context ] ) );
			}
		}

		foreach ( array( 'css', 'js' ) as $type ) {
			if ( empty( $assets[ $type ] ) || ! is_array( $assets[ $type ] ) ) {
				continue;
			}

			foreach ( $assets[ $type ] as $entry ) {
				$path = $this->metadata_asset_path( $entry );

				if ( '' !== $path ) {
					$paths[] = $path;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Gets one component metadata asset path.
	 *
	 * @param mixed $entry Metadata asset entry.
	 * @return string Relative asset path, or empty string when invalid.
	 */
	private function metadata_asset_path( $entry ): string {
		$entry = is_array( $entry ) ? $entry : array( 'path' => $entry );

		return AssetRecord::entry_path( $entry );
	}
}
