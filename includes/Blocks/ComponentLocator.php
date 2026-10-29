<?php
/**
 * Locates built component block artifacts.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Blocks;

use Emulsify\Theme\Support\AssetRecord;
use Emulsify\Theme\Support\Diagnostics;
use Emulsify\Theme\Support\FileDiscovery;

/**
 * Finds component metadata in child and parent theme build output.
 *
 * Discovery is memoized on this object for the lifetime of the current PHP
 * request. The registry shares one locator between ACF/Twig and native block
 * registration, so both paths reuse the same deterministic child-first file
 * index. Persistent caching defaults to on outside active development.
 */
final class ComponentLocator {

	/**
	 * Theme-relative component build directory.
	 *
	 * @var string
	 */
	private const COMPONENTS_DIRECTORY = 'dist/components';

	/**
	 * Theme-relative built asset manifest path.
	 *
	 * @var string
	 */
	private const DEFAULT_MANIFEST_PATH = 'dist/emulsify-assets.json';

	/**
	 * Transient key prefix for optional persistent discovery caching.
	 *
	 * @var string
	 */
	private const CACHE_TRANSIENT_PREFIX = 'emulsify_component_discovery_';

	/**
	 * Root-relative cache payload format.
	 *
	 * @var int
	 */
	private const CACHE_SCHEMA = 2;

	/**
	 * Default persistent cache lifetime in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_CACHE_TTL = 86400;

	/**
	 * Memoized component root records.
	 *
	 * @var array|null
	 */
	private $component_roots;

	/**
	 * Memoized root identities and build signals for this locator.
	 *
	 * @var array|null
	 */
	private $component_root_state;

	/**
	 * Memoized component file records.
	 *
	 * @var array|null
	 */
	private $component_files;

	/**
	 * Memoized ACF/Twig component records.
	 *
	 * @var array|null
	 */
	private $acf_components;

	/**
	 * Memoized native block directory records.
	 *
	 * @var array|null
	 */
	private $native_block_directories;

	/**
	 * Duplicate component records skipped during discovery.
	 *
	 * @var array
	 */
	private $skipped_duplicates = array();

	/**
	 * Clears the optional persistent discovery cache for the current theme state.
	 *
	 * @return bool TRUE when WordPress deleted a transient, otherwise false.
	 */
	public static function clear_discovery_cache(): bool {
		return ( new self() )->delete_component_files_cache();
	}

	/**
	 * Gets components that can be registered as ACF/Twig blocks.
	 *
	 * @return array Component records.
	 */
	public function acf_components(): array {
		if ( is_array( $this->acf_components ) ) {
			return $this->acf_components;
		}

		$components = array();
		$seen_paths = array();
		$seen_slugs = array();

		foreach ( $this->component_files() as $file ) {
			$path = $file['path'];

			if ( ! preg_match( '/\.component\.json$/', basename( $path ) ) ) {
				continue;
			}

			$directory = dirname( $path );
			$relative  = FileDiscovery::relative_path( $file['root_path'], $directory );
			$key       = '' === $relative ? '.' : $relative;
			$record    = $this->component_record( $file, $directory, $relative, $path );

			if ( isset( $seen_paths[ $key ] ) ) {
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'acf_component_path',
					$key,
					$seen_paths[ $key ],
					$record,
					'Duplicate ACF/Twig component path.'
				);
				continue;
			}

			$seen_paths[ $key ] = $record;
			$template           = $this->twig_template( $file['root_path'], $directory, $path );

			if ( '' === $template ) {
				// A metadata file without a matching Twig template is not a renderable
				// ACF/Twig component; native block.json registration is handled separately.
				continue;
			}

			$slug = $this->slug( $relative, $path );

			if ( isset( $seen_slugs[ $slug ] ) ) {
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'acf_component_slug',
					$slug,
					$seen_slugs[ $slug ],
					$record,
					'Duplicate ACF/Twig component slug.'
				);
				continue;
			}

			$seen_slugs[ $slug ] = $record;

			$components[] = array(
				'path'          => $directory,
				'metadata_path' => $path,
				'relative'      => $relative,
				'root_path'     => $file['root_path'],
				'root_uri'      => $file['root_uri'] ?? '',
				'slug'          => $slug,
				'source'        => $file['root_source'],
				'template'      => $template,
			);
		}

		$this->acf_components = $components;

		return $this->acf_components;
	}

	/**
	 * Gets component directories that contain native block metadata.
	 *
	 * @return array Component directory records.
	 */
	public function native_block_directories(): array {
		if ( is_array( $this->native_block_directories ) ) {
			return $this->native_block_directories;
		}

		$directories = array();
		$seen_names  = array();
		$seen_paths  = array();

		foreach ( $this->component_files() as $file ) {
			$path = $file['path'];

			if ( 'block.json' !== basename( $path ) ) {
				continue;
			}

			$directory = dirname( $path );
			$relative  = FileDiscovery::relative_path( $file['root_path'], $directory );
			$key       = '' === $relative ? '.' : $relative;
			$name      = $this->native_block_name( $path );
			$record    = $this->component_record( $file, $directory, $relative, $path );

			if ( '' !== $name ) {
				$record['name'] = $name;
			}

			if ( isset( $seen_paths[ $key ] ) ) {
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'native_component_path',
					$key,
					$seen_paths[ $key ],
					$record,
					'Duplicate native block component path.'
				);
				continue;
			}

			if ( '' !== $name && isset( $seen_names[ $name ] ) ) {
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'native_block_name',
					$name,
					$seen_names[ $name ],
					$record,
					'Duplicate native block name.'
				);
				continue;
			}

			$seen_paths[ $key ] = $record;

			if ( '' !== $name ) {
				$seen_names[ $name ] = $record;
			}

			$directories[] = array(
				'path'          => $directory,
				'relative'      => $relative,
				'root_path'     => $file['root_path'],
				'root_uri'      => $file['root_uri'] ?? '',
				'source'        => $file['root_source'],
				'metadata_path' => $path,
				'name'          => $name,
			);
		}

		$this->native_block_directories = $directories;

		return $this->native_block_directories;
	}

	/**
	 * Gets duplicate records skipped during discovery.
	 *
	 * @param string|null $type_prefix Optional duplicate type prefix filter.
	 * @return array Skipped duplicate records.
	 */
	public function skipped_duplicates( ?string $type_prefix = null ): array {
		if ( null === $type_prefix ) {
			return $this->skipped_duplicates;
		}

		return array_values(
			array_filter(
				$this->skipped_duplicates,
				static function ( array $duplicate ) use ( $type_prefix ): bool {
					return isset( $duplicate['type'] ) && 0 === strpos( $duplicate['type'], $type_prefix );
				}
			)
		);
	}

	/**
	 * Gets all component files from child and parent roots.
	 *
	 * The file index is built once per locator instance. The shared registry
	 * locator means ACF/Twig metadata discovery and native block discovery reuse
	 * the same recursive filesystem scan during a request.
	 *
	 * @return array Component file records.
	 */
	private function component_files(): array {
		if ( is_array( $this->component_files ) ) {
			return $this->component_files;
		}

		$cached = $this->cached_component_files();

		if ( is_array( $cached ) ) {
			$this->component_files = $cached;
			return $this->component_files;
		}

		$complete              = true;
		$this->component_files = FileDiscovery::file_records( $this->component_roots(), array(), true, $complete );

		if ( $complete ) {
			$this->cache_component_files( $this->component_files );
		}

		return $this->component_files;
	}

	/**
	 * Gets cached component file records when optional caching is enabled.
	 *
	 * @return array|null Component file records, or null when scanning should run.
	 */
	private function cached_component_files(): ?array {
		if ( ! $this->persistent_cache_enabled() || ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$cached = get_transient( $this->component_files_cache_key() );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		$files = null;

		if (
			self::CACHE_SCHEMA === ( $cached['schema'] ?? null )
			&& $this->component_roots_signature() === ( $cached['roots'] ?? null )
			&& isset( $cached['files'] ) && is_array( $cached['files'] )
		) {
			$files = $this->normalize_cached_component_files( $cached['files'] );
		}

		if ( null === $files ) {
			$this->delete_component_files_cache();
		}

		return $files;
	}

	/**
	 * Stores component file records in the optional persistent cache.
	 *
	 * @param array $files Component file records.
	 * @return void
	 */
	private function cache_component_files( array $files ): void {
		if ( ! $this->persistent_cache_enabled() || ! function_exists( 'set_transient' ) ) {
			return;
		}

		$roots    = $this->component_roots();
		$relative = array();

		foreach ( $files as $file ) {
			foreach ( $roots as $index => $root ) {
				if ( $file['root_path'] === $root['path'] ) {
					$relative[] = array(
						'root'     => $index,
						'relative' => $file['relative'],
					);
					break;
				}
			}
		}

		set_transient(
			$this->component_files_cache_key(),
			array(
				'schema' => self::CACHE_SCHEMA,
				'roots'  => $this->component_roots_signature(),
				'files'  => $relative,
			),
			$this->component_files_cache_ttl()
		);
	}

	/**
	 * Deletes the optional persistent component file cache.
	 *
	 * @return bool TRUE when WordPress deleted a transient, otherwise false.
	 */
	private function delete_component_files_cache(): bool {
		if ( ! function_exists( 'delete_transient' ) ) {
			return false;
		}

		return (bool) delete_transient( $this->component_files_cache_key() );
	}

	/**
	 * Checks whether optional persistent discovery caching is enabled.
	 *
	 * @return bool TRUE when persistent caching should be used.
	 */
	private function persistent_cache_enabled(): bool {
		$enabled = ! $this->is_development_environment();

		/**
		 * Filters whether component discovery should use persistent caching.
		 *
		 * The default is true in production and staging environments, and false
		 * in local, development, or WP_DEBUG environments. Return a boolean value
		 * to override the default either way.
		 *
		 * @param bool             $enabled     Whether persistent caching is enabled.
		 * @param ComponentLocator $locator     Component locator instance.
		 * @param string           $environment Current WordPress environment type.
		 */
		$filtered = apply_filters( 'emulsify_theme_component_discovery_cache_enabled', $enabled, $this, $this->environment_type() );

		return (bool) $filtered;
	}

	/**
	 * Builds the transient cache key for the current theme state.
	 *
	 * @return string Transient key.
	 */
	private function component_files_cache_key(): string {
		clearstatcache( true );
		$stylesheet           = $this->stylesheet();
		$template             = $this->template();
		$stylesheet_directory = function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '';
		$template_directory   = function_exists( 'get_template_directory' ) ? get_template_directory() : '';
		$key_parts            = array(
			'stylesheet'           => $stylesheet,
			'template'             => $template,
			'stylesheet_version'   => $this->theme_version( $stylesheet, $stylesheet_directory ),
			'template_version'     => $this->theme_version( $template, $template_directory ),
			'environment'          => $this->environment_type(),
			'manifest'             => $this->manifest_file_signature(),
			'stylesheet_directory' => array( $stylesheet_directory, realpath( $stylesheet_directory ) ),
			'template_directory'   => array( $template_directory, realpath( $template_directory ) ),
			'roots'                => $this->component_root_state(),
		);

		/**
		 * Filters the component discovery persistent cache key parts.
		 *
		 * Defaults include theme directories, canonical component roots and root
		 * build timestamps/identity. Custom builds that modify nested directories
		 * in place should add a build token here or clear the discovery cache.
		 *
		 * @param array            $key_parts Cache key parts.
		 * @param ComponentLocator $locator   Component locator instance.
		 */
		$filtered = apply_filters( 'emulsify_theme_component_discovery_cache_key_parts', $key_parts, $this );

		if ( is_array( $filtered ) ) {
			$key_parts = $filtered;
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $key_parts ) : json_encode( $key_parts );

		if ( ! is_string( $encoded ) ) {
			$encoded = serialize( $key_parts );
		}

		return self::CACHE_TRANSIENT_PREFIX . md5( $encoded );
	}

	/**
	 * Gets ordered root identities and stable filesystem build signals.
	 *
	 * Core production builds recreate dist/components. Statting each root avoids
	 * walking the tree on warm requests; access times deliberately stay out.
	 *
	 * @return array Root state.
	 */
	private function component_root_state(): array {
		if ( is_array( $this->component_root_state ) ) {
			return $this->component_root_state;
		}

		$this->component_root_state = array();

		foreach ( $this->component_roots() as $root ) {
			clearstatcache( true, $root['path'] );
			$stat                         = @stat( $root['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A build can remove the root after normalization.
			$this->component_root_state[] = array(
				'path'      => $root['path'],
				'canonical' => realpath( $root['path'] ),
				'source'    => $root['source'],
				'priority'  => $root['priority'],
				'build'     => is_array( $stat ) ? array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'mtime', 'ctime', 'size' ) ) ) : null,
			);
		}

		return $this->component_root_state;
	}

	/**
	 * Binds relative cache records to the active roots even with a custom key.
	 *
	 * @return string Root state fingerprint.
	 */
	private function component_roots_signature(): string {
		return md5( serialize( $this->component_root_state() ) );
	}

	/**
	 * Gets the optional persistent cache lifetime.
	 *
	 * @return int Cache lifetime in seconds.
	 */
	private function component_files_cache_ttl(): int {
		$ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : self::DEFAULT_CACHE_TTL;

		/**
		 * Filters the component discovery persistent cache lifetime.
		 *
		 * @param int              $ttl     Cache lifetime in seconds.
		 * @param ComponentLocator $locator Component locator instance.
		 */
		$filtered = apply_filters( 'emulsify_theme_component_discovery_cache_ttl', $ttl, $this );

		return is_numeric( $filtered ) ? max( 0, (int) $filtered ) : $ttl;
	}

	/**
	 * Normalizes cached file records and rejects invalid cache payloads.
	 *
	 * @param array $files Cached component file records.
	 * @return array|null Normalized records, or null for invalid cache data.
	 */
	private function normalize_cached_component_files( array $files ): ?array {
		$normalized = array();
		$roots      = $this->component_roots();

		foreach ( $files as $file ) {
			if (
				! is_array( $file )
				|| ! isset( $file['root'], $file['relative'] )
				|| ! is_int( $file['root'] ) || ! isset( $roots[ $file['root'] ] )
				|| ! is_string( $file['relative'] ) || '' === $file['relative']
				|| AssetRecord::normalize_relative_path( $file['relative'] ) !== $file['relative']
			) {
				return null;
			}

			$root = $roots[ $file['root'] ];
			$path = $root['path'] . '/' . $file['relative'];
			clearstatcache( true, $path );
			$canonical      = realpath( $path );
			$canonical_root = realpath( $root['path'] );

			if (
				false === $canonical || false === $canonical_root
				|| 0 !== strpos( $canonical, rtrim( $canonical_root, '/\\' ) . DIRECTORY_SEPARATOR )
				|| ! is_file( $path ) || ! is_readable( $path )
			) {
				return null;
			}

			$record = array(
				'path'        => $path,
				'priority'    => $root['priority'],
				'relative'    => $file['relative'],
				'root_path'   => $root['path'],
				'root_source' => $root['source'],
			);

			if ( isset( $root['uri'] ) ) {
				$record['root_uri'] = $root['uri'];
			}

			$normalized[] = $record;
		}

		return $normalized;
	}

	/**
	 * Checks whether active development should keep the default cache disabled.
	 *
	 * @return bool TRUE when the environment looks like active development.
	 */
	private function is_development_environment(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		return in_array( $this->environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Gets the current WordPress environment type.
	 *
	 * @return string Environment type.
	 */
	private function environment_type(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$environment = wp_get_environment_type();

			if ( is_scalar( $environment ) && '' !== trim( (string) $environment ) ) {
				return (string) $environment;
			}
		}

		return defined( 'WP_ENVIRONMENT_TYPE' ) && is_scalar( WP_ENVIRONMENT_TYPE ) ? (string) WP_ENVIRONMENT_TYPE : 'production';
	}

	/**
	 * Gets the active child stylesheet slug.
	 *
	 * @return string Stylesheet slug.
	 */
	private function stylesheet(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			$stylesheet = get_stylesheet();

			if ( is_scalar( $stylesheet ) && '' !== trim( (string) $stylesheet ) ) {
				return (string) $stylesheet;
			}
		}

		return function_exists( 'get_stylesheet_directory' ) ? basename( get_stylesheet_directory() ) : '';
	}

	/**
	 * Gets the active parent template slug.
	 *
	 * @return string Template slug.
	 */
	private function template(): string {
		if ( function_exists( 'get_template' ) ) {
			$template = get_template();

			if ( is_scalar( $template ) && '' !== trim( (string) $template ) ) {
				return (string) $template;
			}
		}

		return function_exists( 'get_template_directory' ) ? basename( get_template_directory() ) : '';
	}

	/**
	 * Gets a theme version from WordPress metadata or style.css.
	 *
	 * @param string $stylesheet Theme stylesheet slug.
	 * @param string $directory  Theme directory.
	 * @return string Theme version.
	 */
	private function theme_version( string $stylesheet, string $directory ): string {
		if ( '' !== $stylesheet && function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $stylesheet );

			if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
				$version = $theme->get( 'Version' );

				if ( is_scalar( $version ) && '' !== trim( (string) $version ) ) {
					return (string) $version;
				}
			}
		}

		return $this->style_css_version( $directory );
	}

	/**
	 * Reads a Version header from a theme style.css file.
	 *
	 * @param string $directory Theme directory.
	 * @return string Version header, or empty string.
	 */
	private function style_css_version( string $directory ): string {
		$path = rtrim( $directory, '/\\' ) . '/style.css';

		if ( '' === $directory || ! is_readable( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) || ! preg_match( '/^\s*\*?\s*Version:\s*(.+)$/mi', $contents, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Gets a child/parent manifest file signature for cache invalidation.
	 *
	 * @return string Manifest signature.
	 */
	private function manifest_file_signature(): string {
		$relative_path = $this->manifest_relative_path();

		if ( '' === $relative_path ) {
			return '';
		}

		$signatures = array();
		$themes     = array(
			'child'  => function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
			'parent' => function_exists( 'get_template_directory' ) ? get_template_directory() : '',
		);

		foreach ( $themes as $source => $directory ) {
			if ( ! is_scalar( $directory ) || '' === trim( (string) $directory ) ) {
				continue;
			}

			$path = rtrim( (string) $directory, '/\\' ) . '/' . $relative_path;

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$mtime        = filemtime( $path );
			$signatures[] = $source . ':' . $relative_path . ':' . ( false === $mtime ? '' : (string) $mtime );
		}

		return implode( '|', $signatures );
	}

	/**
	 * Gets the theme-relative asset manifest path.
	 *
	 * @return string Manifest path, or empty string when disabled.
	 */
	private function manifest_relative_path(): string {
		$relative_path = self::DEFAULT_MANIFEST_PATH;

		/**
		 * Filters the theme-relative asset manifest path.
		 *
		 * This mirrors asset loading so changing the manifest path also changes
		 * the optional component discovery cache key.
		 *
		 * @param string $relative_path Theme-relative manifest path.
		 */
		$filtered = apply_filters( 'emulsify_theme_asset_manifest_path', $relative_path );

		if ( ! is_scalar( $filtered ) ) {
			return '';
		}

		return AssetRecord::normalize_relative_path( (string) $filtered );
	}

	/**
	 * Gets child theme component roots first, then parent theme fallbacks.
	 *
	 * Matching relative component directories in the child theme win because
	 * child roots are indexed first and later parent records with the same
	 * relative path are skipped by the discovery methods.
	 *
	 * @return array Component root records.
	 */
	private function component_roots(): array {
		if ( is_array( $this->component_roots ) ) {
			return $this->component_roots;
		}

		$candidates = FileDiscovery::theme_roots( self::COMPONENTS_DIRECTORY, true );

		/**
		 * Filters built component discovery roots before scanning.
		 *
		 * Root records should include an absolute path to a dist/components
		 * directory and an optional source label. The default order is child
		 * theme first, then parent theme fallback.
		 *
		 * @param array $candidates Component root records.
		 */
		$filtered = apply_filters( 'emulsify_theme_component_roots', $candidates );

		if ( is_array( $filtered ) ) {
			$candidates = $filtered;
		}

		// Release symlinks can change in a long-running WP-CLI process too.
		clearstatcache( true );
		$this->component_roots = FileDiscovery::normalize_roots(
			$candidates,
			array(
				'default_source' => 'filtered',
			)
		);

		return $this->component_roots;
	}

	/**
	 * Builds a debug record for a component artifact.
	 *
	 * @param array  $file          Component file record.
	 * @param string $directory     Absolute component directory path.
	 * @param string $relative      Component-relative directory path.
	 * @param string $metadata_path Absolute metadata path.
	 * @return array Component debug record.
	 */
	private function component_record( array $file, string $directory, string $relative, string $metadata_path ): array {
		return array(
			'path'          => $directory,
			'relative'      => $relative,
			'root_path'     => $file['root_path'],
			'root_uri'      => $file['root_uri'] ?? '',
			'source'        => $file['root_source'],
			'metadata_path' => $metadata_path,
		);
	}

	/**
	 * Gets the declared block name from a native block.json file.
	 *
	 * @param string $path Absolute block.json path.
	 * @return string Native block name, or an empty string when unavailable.
	 */
	private function native_block_name( string $path ): string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		// A build can remove metadata between the readability check and the read.
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected deploy race after the guarded check above.

		if ( ! is_string( $contents ) ) {
			return '';
		}

		$metadata = json_decode( $contents, true );

		if ( ! is_array( $metadata ) || JSON_ERROR_NONE !== json_last_error() || empty( $metadata['name'] ) || ! is_string( $metadata['name'] ) ) {
			return '';
		}

		return trim( $metadata['name'] );
	}

	/**
	 * Finds the Twig template for a component metadata file.
	 *
	 * @param string $root_path     Absolute component root path.
	 * @param string $directory     Absolute component directory path.
	 * @param string $metadata_path Absolute component metadata path.
	 * @return string Theme-relative Twig template path.
	 */
	private function twig_template( string $root_path, string $directory, string $metadata_path ): string {
		$metadata_slug = preg_replace( '/\.component\.json$/', '', basename( $metadata_path ) );
		$candidates    = array(
			$directory . '/' . $metadata_slug . '.twig',
			$directory . '/' . basename( $directory ) . '.twig',
		);

		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $this->theme_relative_path( $root_path, $candidate );
			}
		}

		return '';
	}

	/**
	 * Builds a component slug from its path.
	 *
	 * @param string $relative      Component-relative directory path.
	 * @param string $metadata_path Absolute component metadata path.
	 * @return string Component slug.
	 */
	private function slug( string $relative, string $metadata_path ): string {
		$slug = '' === $relative ? preg_replace( '/\.component\.json$/', '', basename( $metadata_path ) ) : $relative;

		return sanitize_title( str_replace( '/', '-', (string) $slug ) );
	}

	/**
	 * Builds a theme-relative path for Timber rendering.
	 *
	 * @param string $root_path Absolute component root path.
	 * @param string $path      Absolute template path.
	 * @return string Theme-relative template path.
	 */
	private function theme_relative_path( string $root_path, string $path ): string {
		$relative = FileDiscovery::relative_path( $root_path, $path );

		return self::COMPONENTS_DIRECTORY . '/' . $relative;
	}
}
