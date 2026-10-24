<?php
/**
 * Resolves project machine-name component references.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Twig;

/**
 * Wraps a Twig loader with project machine-name component support.
 */
final class ProjectComponentLoader implements \Twig\Loader\LoaderInterface {

	/**
	 * Project template prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Project at-namespace prefix.
	 *
	 * @var string
	 */
	private $at_prefix;

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
	 * Resolved project template cache.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Breadth-first grouping directories, memoized for this loader's request.
	 *
	 * @var array
	 */
	private $group_directories = array();

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
		$this->prefix    = $machine_name . ':';
		$this->at_prefix = '@' . $machine_name . '/';
		$this->roots     = array();
		$this->loader    = $loader;

		foreach ( $roots as $root ) {
			$real = realpath( $root );

			if ( false !== $real && is_dir( $real ) && is_readable( $real ) ) {
				$this->roots[] = str_replace( '\\', '/', $real );
			}
		}
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

		return $this->loader->getSourceContext( $name );
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

		return $this->loader->getCacheKey( $name );
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

		return $this->loader->isFresh( $name, $time );
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

		return $this->loader->exists( $name );
	}

	/**
	 * Finds a project component template path.
	 *
	 * @param string $name Template logical name.
	 * @return string|null Resolved template path.
	 */
	private function find_project_template( string $name ): ?string {
		if ( array_key_exists( $name, $this->cache ) ) {
			return $this->cache[ $name ];
		}

		$component = $this->project_component_name( $name );

		if ( null === $component ) {
			return null;
		}

		foreach ( $this->roots as $root ) {
			$path = $this->find_in_directory( $root, $component, $root );

			if ( null === $path ) {
				foreach ( $this->group_directories( $root ) as $directory ) {
					$path = $this->find_in_directory( $directory, $component, $root );

					if ( null !== $path ) {
						break;
					}
				}
			}

			if ( null !== $path ) {
				$this->cache[ $name ] = $path;

				return $path;
			}
		}

		$this->cache[ $name ] = null;

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
		if ( array_key_exists( $root, $this->group_directories ) ) {
			return $this->group_directories[ $root ];
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

		$this->group_directories[ $root ] = $groups;

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

		$prefix    = str_starts_with( $name, $this->prefix ) ? $this->prefix : $this->at_prefix;
		$component = str_replace( '\\', '/', substr( $name, strlen( $prefix ) ) );

		if ( '' === $component || str_contains( $component, "\0" ) || str_starts_with( $component, '/' ) || preg_match( '/^[A-Za-z]:/', $component ) ) {
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
	 * Checks whether a template name starts with the project prefix.
	 *
	 * @param string $name Template logical name.
	 * @return bool TRUE when the name targets this project namespace.
	 */
	private function is_project_reference( string $name ): bool {
		return str_starts_with( $name, $this->prefix ) || str_starts_with( $name, $this->at_prefix );
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
