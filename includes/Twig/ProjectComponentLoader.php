<?php
/**
 * Resolves explicit component references with Emulsify Core candidate order.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Twig;

use Emulsify\Theme\Support\ProjectConfig;

/**
 * Wraps a Twig loader with component aliases and grouped lookup.
 */
final class ProjectComponentLoader implements \Twig\Loader\LoaderInterface {

	/**
	 * Project identity for request-local resolution caches.
	 *
	 * @var string
	 */
	private $machine_name;

	/**
	 * Component root paths.
	 *
	 * @var array
	 */
	private $roots;

	/**
	 * Wrapped Twig loader.
	 *
	 * @var \Twig\Loader\LoaderInterface
	 */
	private $loader;

	/**
	 * Canonical ordered roots and machine-name cache identity.
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * Resolved project template cache.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Breadth-first grouping directories shared by loaders for this request.
	 *
	 * @var array
	 */
	private static $group_directories = array();

	/**
	 * Directory names excluded by Emulsify Core source discovery.
	 *
	 * @var array
	 */
	private const SKIP_DIRECTORIES = array( 'node_modules', '.git', '.cache', '.vite', '.out', '.coverage', 'dist' );

	/**
	 * Constructs the project component loader wrapper.
	 *
	 * @param string                       $machine_name Active project machine name.
	 * @param array                        $roots        Component root paths.
	 * @param \Twig\Loader\LoaderInterface $loader       Wrapped Twig loader.
	 */
	public function __construct( string $machine_name, array $roots, \Twig\Loader\LoaderInterface $loader ) {
		$this->machine_name = $machine_name;
		$this->loader       = $loader;
		$this->set_roots( $roots );
	}

	/**
	 * Clears component hits, misses and groups for a new logical request.
	 *
	 * @return void
	 */
	public static function clear_request_cache(): void {
		self::$cache             = array();
		self::$group_directories = array();
	}

	/**
	 * Refreshes canonical roots when a later Twig filter changes components.
	 *
	 * @param array $roots Component paths in namespace order.
	 * @return void
	 */
	private function set_roots( array $roots ): void {
		$this->roots = array();
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real && is_dir( $real ) && is_readable( $real ) ) {
				$path = str_replace( '\\', '/', $real );
				if ( ! in_array( $path, $this->roots, true ) ) {
					$this->roots[] = $path;
				}
			}
		}
		$this->cache_key = serialize( array( $this->machine_name, $this->roots ) );
	}

	/**
	 * Proxies filesystem namespace registration to the wrapped loader.
	 *
	 * @param string $path      Filesystem path.
	 * @param string $namespace Twig namespace.
	 * @return void
	 */
	public function addPath( string $path, string $namespace = '__main__' ): void {
		if ( method_exists( $this->loader, 'addPath' ) ) {
			$this->loader->addPath( $path, $namespace );
			if ( 'components' === $namespace ) {
				$this->set_roots( $this->getPaths( 'components' ) );
			}
		}
	}

	/**
	 * Proxies prepended filesystem namespace registration when available.
	 *
	 * @param string $path      Filesystem path.
	 * @param string $namespace Twig namespace.
	 * @return void
	 */
	public function prependPath( string $path, string $namespace = '__main__' ): void {
		if ( method_exists( $this->loader, 'prependPath' ) ) {
			$this->loader->prependPath( $path, $namespace );
			if ( 'components' === $namespace ) {
				$this->set_roots( $this->getPaths( 'components' ) );
			}
		}
	}

	/**
	 * Proxies filesystem namespace paths when available.
	 *
	 * @param string $namespace Twig namespace.
	 * @return array Namespace paths.
	 */
	public function getPaths( string $namespace = '__main__' ): array {
		if ( method_exists( $this->loader, 'getPaths' ) ) {
			return $this->loader->getPaths( $namespace );
		}

		return array();
	}

	/**
	 * Proxies filesystem namespace names when available.
	 *
	 * @return array Namespace names.
	 */
	public function getNamespaces(): array {
		if ( method_exists( $this->loader, 'getNamespaces' ) ) {
			return $this->loader->getNamespaces();
		}

		return array();
	}

	/**
	 * Returns source for a template logical name.
	 *
	 * @param string $name Template logical name.
	 * @return \Twig\Source Template source.
	 * @throws \Twig\Error\LoaderError When project template resolution fails.
	 */
	public function getSourceContext( string $name ): \Twig\Source {
		$path = $this->find_project_template( $name );

		if ( null !== $path ) {
			$contents = file_get_contents( $path );

			if ( false === $contents ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered HTML.
				throw new \Twig\Error\LoaderError( sprintf( 'Unable to read project component template "%s".', $name ) );
			}

			return new \Twig\Source( $contents, $name, $path );
		}

		if ( $this->is_project_reference( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered HTML.
			throw new \Twig\Error\LoaderError( $this->project_loader_error( $name ) );
		}

		return $this->loader->getSourceContext( $this->delegate_name( $name ) );
	}

	/**
	 * Gets the cache key for a template name.
	 *
	 * @param string $name Template logical name.
	 * @return string Cache key.
	 * @throws \Twig\Error\LoaderError When project template resolution fails.
	 */
	public function getCacheKey( string $name ): string {
		$path = $this->find_project_template( $name );

		if ( null !== $path ) {
			return $path;
		}

		if ( $this->is_project_reference( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered HTML.
			throw new \Twig\Error\LoaderError( $this->project_loader_error( $name ) );
		}

		return $this->loader->getCacheKey( $this->delegate_name( $name ) );
	}

	/**
	 * Checks whether a template is fresh.
	 *
	 * @param string $name Template logical name.
	 * @param int    $time Cached template timestamp.
	 * @return bool TRUE when the source is fresh.
	 * @throws \Twig\Error\LoaderError When project template resolution fails.
	 */
	public function isFresh( string $name, int $time ): bool {
		$path = $this->find_project_template( $name );

		if ( null !== $path ) {
			$modified = filemtime( $path );

			return false !== $modified && $modified <= $time;
		}

		if ( $this->is_project_reference( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered HTML.
			throw new \Twig\Error\LoaderError( $this->project_loader_error( $name ) );
		}

		return $this->loader->isFresh( $this->delegate_name( $name ), $time );
	}

	/**
	 * Checks whether a template exists.
	 *
	 * @param string $name Template logical name.
	 * @return bool TRUE when the template exists.
	 */
	public function exists( string $name ) {
		if ( $this->is_project_reference( $name ) ) {
			return null !== $this->find_project_template( $name );
		}

		return $this->loader->exists( $this->delegate_name( $name ) );
	}

	/**
	 * Finds a project component template path.
	 *
	 * @param string $name Template logical name.
	 * @return string|null Resolved template path.
	 */
	private function find_project_template( string $name ): ?string {
		// Namespace ownership is checked before any shared hit or miss is reused.
		$component = $this->project_component_name( $name );
		if ( null === $component ) {
			return null;
		}
		$cacheable = ProjectConfig::request_cache_enabled();
		$key       = $this->cache_key . "\0" . $name;
		if ( $cacheable && array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		foreach ( $this->roots as $root ) {
			// Core tries a literal unregistered reference before stripping its prefix.
			$path = null === $this->registered_reference( $name )
				? $this->find_in_directory( $root, str_replace( '\\', '/', $name ), $root )
				: null;
			if ( null === $path ) {
				$path = $this->find_in_directory( $root, $component, $root );
			}

			if ( null === $path ) {
				foreach ( $this->group_directories( $root ) as $directory ) {
					$path = $this->find_in_directory( $directory, $component, $root );

					if ( null !== $path ) {
						break;
					}
				}
			}

			if ( null !== $path ) {
				if ( $cacheable ) {
					self::$cache[ $key ] = $path;
				}

				return $path;
			}
		}

		if ( $cacheable ) {
			self::$cache[ $key ] = null;
		}

		return null;
	}

	/**
	 * Checks Core's ordered file candidates below one base directory.
	 *
	 * @param string $directory Base directory.
	 * @param string $component Component-relative template name.
	 * @param string $root      Owning component root.
	 * @return string|null Canonical template path.
	 */
	private function find_in_directory( string $directory, string $component, string $root ): ?string {
		$without_twig = (string) preg_replace( '/\.twig$/i', '', $component );
		$stem         = basename( $without_twig );
		$candidates   = array_unique(
			array(
				$component,
				$component . '.twig',
				$component . '.html.twig',
				$without_twig . '/' . $stem . '.twig',
				$without_twig . '/' . $stem . '.html.twig',
			)
		);

		foreach ( $candidates as $candidate ) {
			$path = $this->normalize_path( rtrim( $directory, '/' ) . '/' . $candidate );

			if ( ! $this->is_within_root( $root, $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$real = realpath( $path );

			if ( false !== $real && $this->is_within_root( $root, str_replace( '\\', '/', $real ) ) ) {
				return $real;
			}
		}

		return null;
	}

	/**
	 * Discovers groups in Core's breadth-first, UTF-16 sibling order.
	 *
	 * Directory symlinks are not traversed, matching Node's Dirent.isDirectory().
	 *
	 * @param string $root Canonical component root.
	 * @return array Group directory paths.
	 */
	private function group_directories( string $root ): array {
		$key       = serialize( array( $this->machine_name, $root ) );
		$cacheable = ProjectConfig::request_cache_enabled();
		if ( $cacheable && array_key_exists( $key, self::$group_directories ) ) {
			return self::$group_directories[ $key ];
		}

		$groups  = array();
		$pending = array( $root );

		for ( $index = 0; isset( $pending[ $index ] ); ++$index ) {
			$directory = $pending[ $index ];

			try {
				$entries = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
			} catch ( \UnexpectedValueException $exception ) {
				continue;
			}

			$children = array();

			foreach ( $entries as $entry ) {
				$name = $entry->getFilename();

				if ( $entry->isLink() || ! $entry->isDir() || in_array( $name, self::SKIP_DIRECTORIES, true ) ) {
					continue;
				}

				$path = str_replace( '\\', '/', $entry->getPathname() );
				$real = realpath( $path );

				if ( false !== $real && $this->is_within_root( $root, $path ) && $this->is_within_root( $root, str_replace( '\\', '/', $real ) ) ) {
					$children[ mb_convert_encoding( $name, 'UTF-16BE', 'UTF-8' ) ] = $path;
				}
			}

			ksort( $children, SORT_STRING );

			foreach ( $children as $child ) {
				$groups[]  = $child;
				$pending[] = $child;
			}
		}

		if ( $cacheable ) {
			self::$group_directories[ $key ] = $groups;
		}

		return $groups;
	}

	/**
	 * Checks a normalized absolute path against a canonical root boundary.
	 *
	 * @param string $root Component root.
	 * @param string $path Candidate lexical or real path.
	 * @return bool TRUE when the path stays inside the root.
	 */
	private function is_within_root( string $root, string $path ): bool {
		$prefix = rtrim( $root, '/' ) . '/';

		return $path === $root || (
			str_starts_with( $path, $prefix )
			&& ! str_starts_with( substr( $path, strlen( $prefix ) ), '..' )
		);
	}

	/**
	 * Resolves lexical dot segments after each candidate suffix is appended.
	 *
	 * @param string $path Absolute path with POSIX separators.
	 * @return string Normalized absolute path.
	 */
	private function normalize_path( string $path ): string {
		$parts = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}

		return ( str_starts_with( $path, '/' ) ? '/' : '' ) . implode( '/', $parts );
	}

	/**
	 * Gets the safe project component name from a template reference.
	 *
	 * @param string $name Template logical name.
	 * @return string|null Component name.
	 */
	private function project_component_name( string $name ): ?string {
		if ( ! $this->is_project_reference( $name ) ) {
			return null;
		}

		$registered = $this->registered_reference( $name );
		if ( null !== $registered ) {
			$component = $registered['path'];
		} else {
			preg_match( '#^(?:@[^/:]+/|@?[^/:]+:)(.+)$#D', $name, $matches );
			$component = $matches[1];
		}
		$component = str_replace( '\\', '/', $component );

		if ( '' === $component || str_contains( $name, "\0" ) || str_starts_with( $component, '/' ) || preg_match( '/^[A-Za-z]:/', $component ) ) {
			return null;
		}

		$depth = 0;

		foreach ( explode( '/', $component ) as $part ) {
			if ( '..' === $part ) {
				if ( 0 === $depth ) {
					return null;
				}

				--$depth;
			} elseif ( '' !== $part && '.' !== $part ) {
				++$depth;
			}
		}

		if ( 0 === $depth && 1 !== preg_match( '/[^.\/]/', $component ) ) {
			return null;
		}

		return $component;
	}

	/**
	 * Checks explicit component aliases without claiming registered namespaces.
	 *
	 * @param string $name Template logical name.
	 * @return bool TRUE when component resolution owns this reference.
	 */
	private function is_project_reference( string $name ): bool {
		$registered = $this->registered_reference( $name );
		if ( null !== $registered ) {
			return 'components' === $registered['namespace'];
		}
		return ! str_starts_with( $name, '.' ) && 1 === preg_match( '#^(?:@[^/:]+/|@?[^/:]+:)(.+)$#D', $name );
	}

	/**
	 * Parses Core's component aliases and the wrapped loader's namespaces.
	 *
	 * @param string $name Template logical name.
	 * @return array|null Registered namespace and relative path.
	 */
	private function registered_reference( string $name ): ?array {
		$namespaces = array_merge( array( 'components' ), $this->getNamespaces() );
		foreach ( array( '#^@([^/]+)/(.+)$#D', '#^([^:]+)::(.+)$#D', '#^([^:/.]+):(.+)$#D' ) as $pattern ) {
			if ( 1 === preg_match( $pattern, $name, $matches ) && in_array( $matches[1], $namespaces, true ) ) {
				return array(
					'namespace' => $matches[1],
					'path'      => $matches[2],
				);
			}
		}
		if ( str_starts_with( $name, 'components/' ) ) {
			return array(
				'namespace' => 'components',
				'path'      => substr( $name, strlen( 'components/' ) ),
			);
		}
		return null;
	}

	/**
	 * Converts registered namespace aliases for Twig's native loader.
	 *
	 * @param string $name Template logical name.
	 * @return string Native Twig template name.
	 */
	private function delegate_name( string $name ): string {
		$registered = $this->registered_reference( $name );
		return null !== $registered ? '@' . $registered['namespace'] . '/' . $registered['path'] : $name;
	}

	/**
	 * Gets the loader error for a project reference.
	 *
	 * @param string $name Template logical name.
	 * @return string Error message.
	 */
	private function project_loader_error( string $name ): string {
		if ( null === $this->project_component_name( $name ) ) {
			return sprintf( 'Project component template name "%s" is invalid.', $name );
		}

		return sprintf( 'Project component template "%s" is not defined.', $name );
	}
}
