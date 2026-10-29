<?php
/**
 * Registers Twig loader paths and helpers.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Runtime;

use Emulsify\Theme\Support\AttributeBag;
use Emulsify\Theme\Support\FileDiscovery;
use Emulsify\Theme\Support\ProjectConfig;
use Emulsify\Theme\Twig\ProjectComponentLoader;
use Emulsify\Theme\Twig\SwitchExtension;

/**
 * Twig integration for Timber.
 */
final class Twig {

	/**
	 * Registers Timber Twig hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'timber/loader/loader', array( $this, 'loader_paths' ) );
		add_filter( 'timber/twig/environment/options', array( $this, 'environment_options' ) );
		add_filter( 'timber/twig/functions', array( $this, 'functions' ) );
		add_filter( 'timber/twig', array( $this, 'extensions' ) );
	}

	/**
	 * Enables HTML autoescaping for every Timber render path.
	 *
	 * Twig context can contain editor-controlled ACF fields and block attributes.
	 * Escaping by default prevents child templates from turning those values into
	 * executable markup unless an author explicitly marks trusted HTML as raw.
	 *
	 * @param array $options Existing Twig environment options.
	 * @return array Updated Twig environment options.
	 */
	public function environment_options( array $options ): array {
		$options['autoescape'] = 'html';

		return $options;
	}

	/**
	 * Adds theme paths and namespaces to the Twig loader.
	 *
	 * @param mixed $loader Timber loader.
	 * @return mixed Updated Timber loader.
	 */
	public function loader_paths( $loader ) {
		if ( ! is_object( $loader ) || ! method_exists( $loader, 'addPath' ) ) {
			return $loader;
		}

		$namespaces = array_merge(
			array(
				array(
					'namespace' => 'templates',
					'path'      => get_stylesheet_directory() . '/templates',
				),
				array(
					'namespace' => 'templates',
					'path'      => get_template_directory() . '/templates',
				),
				array(
					'namespace' => 'emulsify-tpl',
					'path'      => get_template_directory() . '/templates',
				),
			),
			$this->project_structure_namespaces(),
			array(
				// @components remains a compatibility namespace for component
				// systems and existing projects that have not moved to
				// project_machine_name:component references.
				array(
					'namespace' => 'components',
					'path'      => get_stylesheet_directory() . '/src/components',
				),
				array(
					'namespace' => 'components',
					'path'      => get_stylesheet_directory() . '/components',
				),
				array(
					'namespace' => 'components',
					'path'      => get_template_directory() . '/src/components',
				),
				array(
					'namespace' => 'components',
					'path'      => get_template_directory() . '/components',
				),
			)
		);

		/**
		 * Filters Twig namespace paths before they are added to Timber.
		 *
		 * Namespace records should include path and namespace keys. Existing
		 * child-first order is preserved unless a filter intentionally changes it.
		 *
		 * @param array $namespaces Twig namespace path records.
		 * @param mixed $loader     Timber loader instance.
		 */
		$filtered = apply_filters( 'emulsify_theme_twig_namespaces', $namespaces, $loader );

		if ( is_array( $filtered ) ) {
			$namespaces = $filtered;
		}

		foreach ( $namespaces as $namespace ) {
			if ( ! is_array( $namespace ) || empty( $namespace['path'] ) || empty( $namespace['namespace'] ) ) {
				continue;
			}

			$this->add_path( $loader, (string) $namespace['path'], (string) $namespace['namespace'] );
		}

		return $this->with_project_component_loader( $loader );
	}

	/**
	 * Registers Twig functions.
	 *
	 * @param array $functions Existing Timber functions.
	 * @return array Updated Timber functions.
	 */
	public function functions( array $functions ): array {
		// Timber injects context even when Twig supplies no arguments. Pad that
		// case so it cannot be mistaken for the direct PHP single-argument form.
		$functions['bem'] = array(
			'callable' => function ( array $context, ...$arguments ): AttributeBag {
				if ( empty( $arguments ) ) {
					$arguments[] = '';
				}
				return $this->bem( $context, ...$arguments );
			},
			'options'  => array(
				'needs_context' => true,
				'is_safe'       => array( 'html' ),
			),
		);

		$functions['add_attributes'] = array(
			'callable' => function ( array $context, ...$arguments ): AttributeBag {
				if ( empty( $arguments ) ) {
					$arguments[] = array();
				}
				return $this->add_attributes( $context, ...$arguments );
			},
			'options'  => array(
				'needs_context' => true,
				'is_safe'       => array( 'html' ),
			),
		);

		return $functions;
	}

	/**
	 * Registers custom Twig extensions.
	 *
	 * @param \Twig\Environment $environment Timber Twig environment.
	 * @return \Twig\Environment Updated Twig environment.
	 */
	public function extensions( \Twig\Environment $environment ): \Twig\Environment {
		$environment->addExtension( new SwitchExtension() );

		return $environment;
	}

	/**
	 * Builds a BEM class attribute.
	 *
	 * @param mixed ...$arguments Core-style BEM arguments.
	 * @return AttributeBag HTML attributes.
	 */
	public function bem( ...$arguments ): AttributeBag {
		$context    = $this->shift_twig_context( $arguments );
		$options    = $this->normalize_bem_options(
			$arguments[0] ?? '',
			$arguments[1] ?? array(),
			$arguments[2] ?? '',
			$arguments[3] ?? array(),
			$arguments[4] ?? array()
		);
		$base_class = trim( (string) $options['base_class'] );
		$blockname  = trim( (string) $options['blockname'] );
		$classes    = array();

		if ( '' !== $base_class ) {
			$class_prefix = '' !== $blockname ? $blockname . '__' . $base_class : $base_class;
			$classes[]    = $class_prefix;

			foreach ( $this->normalize_list( $options['modifiers'] ) as $modifier ) {
				$classes[] = $class_prefix . '--' . $modifier;
			}
		}

		$classes = array_merge( $classes, $this->normalize_list( $options['extra'] ) );

		$attribute_bag = new AttributeBag( $options['attributes'] );
		$attribute_bag->addClass( $classes );
		$attribute_bag->merge( $this->attributes_from_context( $context ) );

		return $attribute_bag;
	}

	/**
	 * Builds HTML attributes from an associative array.
	 *
	 * @param mixed ...$arguments Attribute arguments.
	 * @return AttributeBag HTML attributes.
	 */
	public function add_attributes( ...$arguments ): AttributeBag {
		$context        = $this->shift_twig_context( $arguments );
		$attribute_bag  = $this->attributes_from_context( $context );
		$additional     = $arguments[0] ?? array();
		$legacy_context = array();

		if ( $this->is_twig_context( $additional ) && isset( $arguments[1] ) ) {
			$legacy_context = $additional;
			$additional     = $arguments[1];
		}

		if ( ! empty( $legacy_context ) ) {
			$attribute_bag->merge( $this->attributes_from_context( $legacy_context ) );
		}

		$attribute_bag->merge( $additional );

		return $attribute_bag;
	}

	/**
	 * Adds a Twig path when it exists.
	 *
	 * @param mixed  $loader    Timber loader.
	 * @param string $path      Filesystem path.
	 * @param string $namespace Twig namespace.
	 * @return void
	 */
	private function add_path( $loader, string $path, string $namespace ): void {
		if ( is_dir( $path ) && is_readable( $path ) ) {
			$loader->addPath( $path, $namespace );
		}
	}

	/**
	 * Gets Twig namespace records declared by project.emulsify.json.
	 *
	 * Emulsify Core already supports variant.structureImplementations for
	 * component-system roots. Honor the same config here so Storybook/Core and
	 * WordPress runtime Twig resolution stay aligned.
	 *
	 * @return array Twig namespace path records.
	 */
	private function project_structure_namespaces(): array {
		$records   = array();
		$theme_dir = rtrim( get_stylesheet_directory(), '/\\' );

		foreach ( ProjectConfig::structure_implementations() as $implementation ) {
			if ( ! is_array( $implementation ) || empty( $implementation['name'] ) || empty( $implementation['directory'] ) ) {
				continue;
			}

			$namespace = ltrim( (string) $implementation['name'], '@' );

			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $namespace ) ) {
				continue;
			}

			$resolved = ProjectConfig::resolve_theme_relative_path( $theme_dir, (string) $implementation['directory'] );

			if ( '' === $resolved ) {
				continue;
			}

			$records[] = array(
				'namespace' => $namespace,
				'path'      => $resolved,
			);
		}

		return $records;
	}

	/**
	 * Wraps the Timber loader with project machine-name component support.
	 *
	 * @param mixed $loader Timber loader.
	 * @return mixed Updated Timber loader.
	 */
	private function with_project_component_loader( $loader ) {
		if ( ! $this->can_wrap_twig_loader( $loader ) ) {
			return $loader;
		}

		$machine_name = ProjectConfig::machine_name();

		$roots = $this->project_component_roots( $machine_name, $loader );

		if ( empty( $roots ) ) {
			return $loader;
		}

		return new ProjectComponentLoader( $machine_name, $roots, $loader );
	}

	/**
	 * Checks whether the current loader can be wrapped safely.
	 *
	 * @param mixed $loader Timber loader.
	 * @return bool TRUE when Twig loader classes are available.
	 */
	private function can_wrap_twig_loader( $loader ): bool {
		return interface_exists( '\Twig\Loader\LoaderInterface' )
			&& class_exists( '\Twig\Source' )
			&& class_exists( '\Twig\Error\LoaderError' )
			&& is_a( $loader, '\Twig\Loader\LoaderInterface' )
			&& method_exists( $loader, 'getNamespaces' )
			&& method_exists( $loader, 'getPaths' );
	}

	/**
	 * Gets project component root paths in child-first order.
	 *
	 * @param string $machine_name Active project machine name.
	 * @param mixed  $loader       Timber loader.
	 * @return array Component root paths.
	 */
	private function project_component_roots( string $machine_name, $loader ): array {
		$roots  = array();
		$child  = rtrim( get_stylesheet_directory(), '/\\' ) . '/';
		$parent = rtrim( get_template_directory(), '/\\' ) . '/';
		foreach ( $loader->getPaths( 'components' ) as $path ) {
			$source  = str_starts_with( $path, $child ) ? 'child' : ( str_starts_with( $path, $parent ) ? 'parent' : 'filtered' );
			$roots[] = array(
				'path'   => $path,
				'source' => $source,
			);
		}

		/**
		 * Filters roots used for machineName:component Twig references.
		 *
		 * Roots can be path strings or arrays with a path key. The default order
		 * mirrors @components: active child paths first, then parent paths.
		 *
		 * @param array  $roots        Project component root records.
		 * @param string $machine_name Active project machine name.
		 * @param mixed  $loader       Timber loader instance.
		 */
		$filtered = apply_filters( 'emulsify_theme_project_component_roots', $roots, $machine_name, $loader );

		if ( is_array( $filtered ) ) {
			$roots = $filtered;
		}

		return $this->normalize_project_component_roots( $roots );
	}

	/**
	 * Normalizes component root records to readable unique paths.
	 *
	 * @param array $roots Component root records.
	 * @return array Component root paths.
	 */
	private function normalize_project_component_roots( array $roots ): array {
		return array_column( FileDiscovery::normalize_roots( $roots ), 'path' );
	}

	/**
	 * Normalizes positional and object-style BEM arguments.
	 *
	 * @param mixed $base_class Base class or options object.
	 * @param mixed $modifiers  Modifier values.
	 * @param mixed $blockname  Block name.
	 * @param mixed $extra      Extra classes.
	 * @param mixed $attributes Extra attributes.
	 * @return array Normalized BEM options.
	 */
	private function normalize_bem_options( $base_class, $modifiers, $blockname, $extra, $attributes ): array {
		if ( ! is_array( $base_class ) && ! is_object( $base_class ) ) {
			return array(
				'base_class' => $base_class,
				'modifiers'  => $modifiers,
				'blockname'  => $blockname,
				'extra'      => $extra,
				'attributes' => $attributes,
			);
		}

		$options              = is_array( $base_class ) ? $base_class : get_object_vars( $base_class );
		$has_bem_object_shape = $this->has_non_empty_option( $options, 'block' ) && $this->has_non_empty_option( $options, 'element' );

		return array(
			'base_class' => $this->first_option( $options, array( 'baseClass', 'base_class', 'base' ), $has_bem_object_shape ? $options['element'] : ( $options['block'] ?? '' ) ),
			'modifiers'  => $options['modifiers'] ?? array(),
			'blockname'  => $this->first_option( $options, array( 'blockname', 'blockName' ), $has_bem_object_shape ? $options['block'] : ( $options['element'] ?? '' ) ),
			'extra'      => $options['extra'] ?? array(),
			'attributes' => $options['attributes'] ?? array(),
		);
	}

	/**
	 * Removes a Timber context argument from a Twig callback argument list.
	 *
	 * @param array $arguments Function arguments.
	 * @return array Timber context.
	 */
	private function shift_twig_context( array &$arguments ): array {
		if ( count( $arguments ) > 1 && is_array( $arguments[0] ) ) {
			return array_shift( $arguments );
		}

		return array();
	}

	/**
	 * Checks whether a value looks like a Timber context array.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool TRUE when the value looks like Timber context.
	 */
	private function is_twig_context( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( array( 'attributes', 'site', 'theme', 'post', 'wp' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds an AttributeBag from Timber context attributes.
	 *
	 * @param array $context Timber context.
	 * @return AttributeBag Context attributes.
	 */
	private function attributes_from_context( array $context ): AttributeBag {
		return new AttributeBag( $context['attributes'] ?? array() );
	}

	/**
	 * Gets the first non-empty option value.
	 *
	 * @param array $options Option map.
	 * @param array $keys    Candidate keys.
	 * @param mixed $default Default value.
	 * @return mixed Option value.
	 */
	private function first_option( array $options, array $keys, $default = null ) {
		foreach ( $keys as $key ) {
			if ( $this->has_non_empty_option( $options, $key ) ) {
				return $options[ $key ];
			}
		}

		return $default;
	}

	/**
	 * Checks whether an option key contains a non-empty value.
	 *
	 * @param array  $options Option map.
	 * @param string $key     Option key.
	 * @return bool TRUE when set and non-empty.
	 */
	private function has_non_empty_option( array $options, string $key ): bool {
		return array_key_exists( $key, $options ) && null !== $options[ $key ] && '' !== $options[ $key ] && false !== $options[ $key ];
	}

	/**
	 * Normalizes class-like values into a flat list.
	 *
	 * @param mixed $value Class value.
	 * @return array Class list.
	 */
	private function normalize_list( $value ): array {
		$items = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$items = array_merge( $items, $this->normalize_list( $item ) );
			}
			return $items;
		}

		if ( is_scalar( $value ) ) {
			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$items[] = $value;
			}
		}

		return $items;
	}
}
