<?php
/**
 * Smoke checks for mapped core block Twig rendering.
 *
 * @package Emulsify
 */

namespace Timber {
	/**
	 * Minimal Timber stub for renderer smoke coverage.
	 */
	final class Timber {
		/**
		 * Context returned by Timber::context().
		 *
		 * @var array
		 */
		public static $context = array();

		/**
		 * Compile calls received by the stub.
		 *
		 * @var array
		 */
		public static $compiled = array();

		/**
		 * Gets Timber context.
		 *
		 * @return array Context.
		 */
		public static function context(): array {
			return self::$context;
		}

		/**
		 * Compiles a template.
		 *
		 * @param string $template Template path.
		 * @param array  $context  Twig context.
		 * @return string Rendered HTML.
		 */
		public static function compile( string $template, array $context ): string {
			self::$compiled[] = compact( 'template', 'context' );

			if ( false !== strpos( $template, 'throws.twig' ) ) {
				throw new \RuntimeException( 'Template failed.' );
			}

			return sprintf(
				'<p class="wp-block wp-block-paragraph mapped">%s:%s:%s</p>',
				$template,
				$context['block_name'] ?? '',
				$context['block_attributes']['className'] ?? ''
			);
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) {
		fwrite( STDERR, "This script must be run from the command line.\n" );
		exit( 1 );
	}

	$GLOBALS['emulsify_core_block_twig_smoke_filters'] = array();
	$GLOBALS['emulsify_core_block_twig_smoke_caps']    = array();

	/**
	 * Minimal WP_HTML_Tag_Processor stub for class cleanup coverage.
	 */
	class WP_HTML_Tag_Processor {
		/**
		 * HTML being processed.
		 *
		 * @var string
		 */
		private $html;

		/**
		 * Whether the single smoke fixture tag has been processed.
		 *
		 * @var bool
		 */
		private $processed = false;

		/**
		 * Constructor.
		 *
		 * @param string $html HTML.
		 */
		public function __construct( string $html ) {
			$this->html = $html;
		}

		/**
		 * Advances to the next tag.
		 *
		 * @return bool TRUE once for the fixture.
		 */
		public function next_tag(): bool {
			if ( $this->processed ) {
				return false;
			}

			$this->processed = true;

			return true;
		}

		/**
		 * Removes a CSS class from class attributes.
		 *
		 * @param string $class_name Class name.
		 * @return void
		 */
		public function remove_class( string $class_name ): void {
			$this->html = preg_replace_callback(
				'/class="([^"]*)"/',
				static function ( array $matches ) use ( $class_name ): string {
					$classes = array_values(
						array_filter(
							preg_split( '/\s+/', trim( $matches[1] ) ),
							static function ( string $candidate ) use ( $class_name ): bool {
								return $candidate !== $class_name;
							}
						)
					);

					return empty( $classes ) ? '' : 'class="' . implode( ' ', $classes ) . '"';
				},
				$this->html
			);
		}

		/**
		 * Gets updated HTML.
		 *
		 * @return string Updated HTML.
		 */
		public function get_updated_html(): string {
			return $this->html;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['emulsify_core_block_twig_smoke_filters'][ $hook ][ $priority ][] = array(
				'accepted_args' => $accepted_args,
				'callback'      => $callback,
			);

			ksort( $GLOBALS['emulsify_core_block_twig_smoke_filters'][ $hook ] );

			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$arguments ) {
			if ( empty( $GLOBALS['emulsify_core_block_twig_smoke_filters'][ $hook ] ) ) {
				return $value;
			}

			foreach ( $GLOBALS['emulsify_core_block_twig_smoke_filters'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$value = call_user_func_array(
						$callback['callback'],
						array_slice(
							array_merge( array( $value ), $arguments ),
							0,
							$callback['accepted_args']
						)
					);
				}
			}

			return $value;
		}
	}

	if ( ! function_exists( 'get_stylesheet_directory' ) ) {
		function get_stylesheet_directory(): string {
			return $GLOBALS['emulsify_core_block_twig_smoke_child'];
		}
	}

	if ( ! function_exists( 'get_template_directory' ) ) {
		function get_template_directory(): string {
			return $GLOBALS['emulsify_core_block_twig_smoke_parent'];
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability ): bool {
			return in_array( $capability, $GLOBALS['emulsify_core_block_twig_smoke_caps'], true );
		}
	}

	if ( ! function_exists( 'sanitize_html_class' ) ) {
		function sanitize_html_class( string $class_name ): string {
			return trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $class_name ), '-' );
		}
	}

	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( string $text, string $domain ): string {
			unset( $domain );

			return esc_html( $text );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain ): string {
			unset( $domain );

			return $text;
		}
	}

	if ( ! function_exists( '__return_true' ) ) {
		function __return_true(): bool {
			return true;
		}
	}

	/**
	 * Fails the smoke script when an assertion is false.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	function emulsify_core_block_twig_smoke_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * Writes a fixture file.
	 *
	 * @param string $path     File path.
	 * @param string $contents File contents.
	 * @return void
	 */
	function emulsify_core_block_twig_smoke_write( string $path, string $contents ): void {
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			throw new \RuntimeException( sprintf( 'Could not create fixture directory: %s', $directory ) );
		}

		if ( false === file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( sprintf( 'Could not write fixture file: %s', $path ) );
		}
	}

	/**
	 * Recursively removes a path.
	 *
	 * @param string $path Path to remove.
	 * @return void
	 */
	function emulsify_core_block_twig_smoke_remove( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $path );
	}

	$repo_root = dirname( __DIR__, 2 );
	$work_root = sys_get_temp_dir() . '/emulsify-core-block-twig-' . uniqid( '', true );
	$child     = $work_root . '/child-theme';
	$parent    = $work_root . '/parent-theme';

	$GLOBALS['emulsify_core_block_twig_smoke_child']  = $child;
	$GLOBALS['emulsify_core_block_twig_smoke_parent'] = $parent;

	try {
		require_once $repo_root . '/includes/Blocks/CoreBlockTwigRenderer.php';

		emulsify_core_block_twig_smoke_write( $child . '/dist/components/paragraph/paragraph.twig', 'paragraph twig' );
		emulsify_core_block_twig_smoke_write( $child . '/dist/components/error/throws.twig', 'throws twig' );

		$original = '<p class="wp-block-paragraph">Original</p>';
		$block    = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(
				'className' => 'intro',
				'level'     => 2,
				'anchor'    => 'saved-anchor',
			),
			'innerContent' => array( 'Original' ),
			'innerBlocks'  => array(),
		);

		$disabled = new \Emulsify\Theme\Blocks\CoreBlockTwigRenderer();
		$disabled->register();

		emulsify_core_block_twig_smoke_assert(
			empty( $GLOBALS['emulsify_core_block_twig_smoke_filters']['render_block'] ),
			'Disabled renderer should not hook render_block.'
		);
		emulsify_core_block_twig_smoke_assert(
			$original === apply_filters( 'render_block', $original, $block, null ),
			'Disabled renderer should preserve default frontend output.'
		);

		add_filter( 'emulsify_theme_core_block_twig_rendering_enabled', '__return_true' );
		add_filter(
			'emulsify_theme_core_block_twig_template_map',
			static function ( array $map ): array {
				$map['core/paragraph'] = 'dist/components/paragraph/paragraph.twig';
				$map['core/heading']   = 'dist/components/heading/missing.twig';
				$map['core/code']      = 'dist/components/error/throws.twig';

				return $map;
			}
		);
		add_filter(
			'emulsify_theme_core_block_twig_context',
			static function ( array $context ): array {
				$context['smoke_context'] = true;
				$context['legacy_level']  = $context['attributes']['level'] ?? 'empty';

				return $context;
			}
		);

		\Timber\Timber::$context = array(
			'site'       => 'Smoke Site',
			'attributes' => array( 'data-global' => 'must not leak' ),
		);

		$enabled = new \Emulsify\Theme\Blocks\CoreBlockTwigRenderer();
		$enabled->register();

		emulsify_core_block_twig_smoke_assert(
			! empty( $GLOBALS['emulsify_core_block_twig_smoke_filters']['render_block'] ),
			'Enabled renderer should hook render_block.'
		);

		$mapped = apply_filters( 'render_block', $original, $block, null );

		emulsify_core_block_twig_smoke_assert(
			array() === \Timber\Timber::$compiled[0]['context']['attributes'],
			'Mapped blocks must start with an empty HTML attributes array.'
		);
		foreach ( array( 'block_attributes', 'block', 'block_metadata' ) as $key ) {
			$actual = \Timber\Timber::$compiled[0]['context'][ $key ];
			emulsify_core_block_twig_smoke_assert(
				$block['attrs'] === ( 'block_attributes' === $key ? $actual : $actual['attrs'] ),
				'Mapped block data must remain available through ' . $key . '.'
			);
		}
		emulsify_core_block_twig_smoke_assert(
			'empty' === \Timber\Timber::$compiled[0]['context']['legacy_level'],
			'Legacy array reads must be empty without throwing or falling back to core markup.'
		);
		emulsify_core_block_twig_smoke_assert(
			false !== strpos( $mapped, 'dist/components/paragraph/paragraph.twig:core/paragraph:intro' ),
			'Mapped block should render through Timber compile with block_attributes.'
		);
		emulsify_core_block_twig_smoke_assert(
			true === \Timber\Timber::$compiled[0]['context']['smoke_context'],
			'Mapped block context should pass through the context filter.'
		);
		emulsify_core_block_twig_smoke_assert(
			'Original' === \Timber\Timber::$compiled[0]['context']['inner_content'],
			'Mapped block should expose rendered inner content.'
		);

		$fallback = apply_filters(
			'render_block',
			'<h2>Fallback</h2>',
			array(
				'blockName' => 'core/heading',
				'attrs'     => array(),
			),
			null
		);

		emulsify_core_block_twig_smoke_assert(
			'<h2>Fallback</h2>' === $fallback,
			'Mapped blocks with missing templates should preserve original output.'
		);

		$unmapped = apply_filters(
			'render_block',
			'<div>Unmapped</div>',
			array(
				'blockName' => 'core/image',
				'attrs'     => array(),
			),
			null
		);

		emulsify_core_block_twig_smoke_assert(
			'<div>Unmapped</div>' === $unmapped,
			'Unmapped blocks should preserve original output.'
		);

		$errored = apply_filters(
			'render_block',
			'<pre>Code</pre>',
			array(
				'blockName' => 'core/code',
				'attrs'     => array(),
			),
			null
		);

		emulsify_core_block_twig_smoke_assert(
			'<pre>Code</pre>' === $errored,
			'Template errors should preserve original output for normal visitors.'
		);

		$GLOBALS['emulsify_core_block_twig_smoke_caps'] = array( 'edit_posts' );
		$editor_error                                  = apply_filters(
			'render_block',
			'<pre>Code</pre>',
			array(
				'blockName' => 'core/code',
				'attrs'     => array(),
			),
			null
		);

		emulsify_core_block_twig_smoke_assert(
			false !== strpos( $editor_error, 'Emulsify block render error:' ) && false !== strpos( $editor_error, 'Template failed.' ),
			'Template errors should expose diagnostics to editors.'
		);

		add_filter( 'emulsify_theme_core_block_twig_cleanup_classes_enabled', '__return_true' );
		$cleaned = apply_filters( 'render_block', $original, $block, null );

		emulsify_core_block_twig_smoke_assert(
			false === strpos( $cleaned, 'wp-block' ) && false !== strpos( $cleaned, 'mapped' ),
			'Class cleanup should remove base wp-block classes only when enabled.'
		);

		echo "Core block Twig renderer smoke checks passed.\n";
	} finally {
		emulsify_core_block_twig_smoke_remove( $work_root );
	}
}
