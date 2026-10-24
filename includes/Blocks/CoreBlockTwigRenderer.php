<?php
/**
 * Optionally renders mapped WordPress blocks through Twig templates.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Blocks;

/**
 * Opt-in core block to Twig rendering bridge.
 */
final class CoreBlockTwigRenderer {

	/**
	 * Registers block rendering hooks when explicitly enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		add_filter( 'render_block', array( $this, 'render_block' ), 20, 3 );
	}

	/**
	 * Renders a mapped block with Twig, falling back to WordPress output.
	 *
	 * @param string $block_content Rendered WordPress block content.
	 * @param array  $block         Parsed block metadata.
	 * @param mixed  $instance      WP_Block instance when available.
	 * @return string Rendered block content.
	 */
	public function render_block( string $block_content, array $block, $instance = null ): string {
		$block_name = $this->block_name( $block );

		if ( '' === $block_name ) {
			return $block_content;
		}

		$template = $this->template_for_block( $block_name, $block );

		if ( '' === $template ) {
			// Unmapped blocks keep WordPress' original HTML, which protects block
			// validation and frontend output unless a project opts in block-by-block.
			return $block_content;
		}

		if ( ! class_exists( '\Timber\Timber' ) ) {
			return $this->render_error( $block_content, $this->translate( 'Timber is not available for mapped block rendering.', 'emulsify' ), $block, $template );
		}

		try {
			$context = $this->context( $block, $block_content, $instance, $template );
			$html    = \Timber\Timber::compile( $template, $context );
		} catch ( \Throwable $throwable ) {
			// Rendering failures should not blank content. Editors get diagnostics
			// when allowed; visitors continue to receive WordPress' rendered block.
			return $this->render_error( $block_content, $throwable->getMessage(), $block, $template );
		}

		if ( ! is_string( $html ) || '' === $html ) {
			return $block_content;
		}

		return $this->cleanup_classes_enabled( $block, $template )
			? $this->cleanup_classes( $html, $block )
			: $html;
	}

	/**
	 * Checks whether Twig rendering is enabled.
	 *
	 * @return bool TRUE when enabled.
	 */
	private function enabled(): bool {
		/**
		 * Filters whether mapped core block Twig rendering should be enabled.
		 *
		 * Defaults to false so the parent theme never changes frontend block
		 * output unless a child theme or project plugin opts in.
		 *
		 * @param bool $enabled Whether mapped block Twig rendering is enabled.
		 */
		return (bool) apply_filters( 'emulsify_theme_core_block_twig_rendering_enabled', false );
	}

	/**
	 * Builds Twig context for a mapped block.
	 *
	 * @param array  $block         Parsed block metadata.
	 * @param string $block_content Rendered WordPress block content.
	 * @param mixed  $instance      WP_Block instance when available.
	 * @param string $template      Twig template being rendered.
	 * @return array Twig context.
	 */
	private function context( array $block, string $block_content, $instance, string $template ): array {
		$context = array();

		if ( class_exists( '\Timber\Timber' ) ) {
			$timber_context = \Timber\Timber::context();
			$context        = is_array( $timber_context ) ? $timber_context : array();
		}

		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		// Keep WordPress' original rendered content available to Twig templates so
		// mapped blocks can wrap or progressively replace markup instead of being
		// forced into an all-or-nothing rewrite.
		$context['block']            = $block;
		$context['block_metadata']   = $block;
		$context['block_name']       = $this->block_name( $block );
		$context['block_attributes'] = $attributes;
		$context['attributes']       = array();
		$context['content']          = $block_content;
		$context['inner_content']    = $this->inner_content( $block, $block_content );
		$context['inner_blocks']     = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$context['wp_block']         = $instance;
		$context['twig_template']    = $template;

		/**
		 * Filters Twig context for mapped block rendering.
		 *
		 * The attributes array is reserved for HTML attributes only and must
		 * never hold block data or user input. Read block_attributes for block data.
		 *
		 * @param array  $context       Twig context.
		 * @param array  $block         Parsed block metadata.
		 * @param string $block_content Rendered WordPress block content.
		 * @param string $template      Twig template being rendered.
		 * @param mixed  $instance      WP_Block instance when available.
		 */
		$filtered = apply_filters( 'emulsify_theme_core_block_twig_context', $context, $block, $block_content, $template, $instance );

		return is_array( $filtered ) ? $filtered : $context;
	}

	/**
	 * Gets rendered inner content for Twig convenience.
	 *
	 * @param array  $block         Parsed block metadata.
	 * @param string $block_content Rendered WordPress block content.
	 * @return string Inner content.
	 */
	private function inner_content( array $block, string $block_content ): string {
		if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return $block_content;
		}

		$content = '';

		foreach ( $block['innerContent'] as $piece ) {
			if ( is_scalar( $piece ) ) {
				$content .= (string) $piece;
			}
		}

		return '' === $content ? $block_content : $content;
	}

	/**
	 * Gets the mapped Twig template for a block.
	 *
	 * @param string $block_name Block name.
	 * @param array  $block      Parsed block metadata.
	 * @return string Twig template path, or an empty string when unavailable.
	 */
	private function template_for_block( string $block_name, array $block ): string {
		$map = $this->template_map();

		if ( ! isset( $map[ $block_name ] ) ) {
			return '';
		}

		$template = $this->resolve_template( $map[ $block_name ] );

		/**
		 * Filters the resolved Twig template for a block render.
		 *
		 * Return an empty string to fall back to WordPress' original block HTML.
		 *
		 * @param string $template   Resolved Twig template path.
		 * @param string $block_name Block name.
		 * @param array  $block      Parsed block metadata.
		 * @param array  $map        Normalized template map.
		 */
		$filtered = apply_filters( 'emulsify_theme_core_block_twig_template', $template, $block_name, $block, $map );

		return is_scalar( $filtered ) ? (string) $filtered : $template;
	}

	/**
	 * Gets the configured block-to-template map.
	 *
	 * @return array Block template map.
	 */
	private function template_map(): array {
		/**
		 * Filters block-to-Twig-template mappings.
		 *
		 * Keys should be block names such as "core/paragraph". Values should be
		 * child/parent theme relative Twig paths such as
		 * "dist/components/paragraph/paragraph.twig".
		 *
		 * @param array $map Block template map.
		 */
		$filtered = apply_filters( 'emulsify_theme_core_block_twig_template_map', array() );

		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$map = array();

		foreach ( $filtered as $block_name => $template ) {
			if ( ! is_scalar( $template ) ) {
				continue;
			}

			$block_name = strtolower( trim( (string) $block_name ) );
			$template   = trim( (string) $template );

			if ( '' !== $block_name && '' !== $template && preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name ) ) {
				$map[ $block_name ] = $template;
			}
		}

		return $map;
	}

	/**
	 * Resolves a mapped template path when a readable child/parent file exists.
	 *
	 * @param string $template Mapped template path.
	 * @return string Template path for Timber, or an empty string.
	 */
	private function resolve_template( string $template ): string {
		$template = str_replace( '\\', '/', trim( $template ) );
		$template = ltrim( $template, '/' );

		if ( '' === $template || false !== strpos( $template, '../' ) || '..' === $template ) {
			return '';
		}

		foreach ( $this->theme_roots() as $root ) {
			if ( is_readable( rtrim( $root, '/\\' ) . '/' . $template ) ) {
				// Return the theme-relative path because Timber resolves templates
				// through its configured child/parent loaders.
				return $template;
			}
		}

		return '';
	}

	/**
	 * Gets child and parent theme roots.
	 *
	 * @return array Theme root directories.
	 */
	private function theme_roots(): array {
		$roots = array();

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$roots[] = get_stylesheet_directory();
		}

		if ( function_exists( 'get_template_directory' ) ) {
			$roots[] = get_template_directory();
		}

		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * Checks whether class cleanup is enabled for rendered Twig output.
	 *
	 * @param array  $block    Parsed block metadata.
	 * @param string $template Twig template being rendered.
	 * @return bool TRUE when cleanup is enabled.
	 */
	private function cleanup_classes_enabled( array $block, string $template ): bool {
		/**
		 * Filters whether mapped block Twig output should remove base wp-block classes.
		 *
		 * Defaults to false. Cleanup uses WP_HTML_Tag_Processor when available
		 * and otherwise leaves rendered markup unchanged.
		 *
		 * @param bool   $enabled  Whether class cleanup is enabled.
		 * @param array  $block    Parsed block metadata.
		 * @param string $template Twig template being rendered.
		 */
		return (bool) apply_filters( 'emulsify_theme_core_block_twig_cleanup_classes_enabled', false, $block, $template );
	}

	/**
	 * Removes configured classes from Twig output using WP_HTML_Tag_Processor.
	 *
	 * @param string $html  Rendered Twig output.
	 * @param array  $block Parsed block metadata.
	 * @return string Filtered HTML.
	 */
	private function cleanup_classes( string $html, array $block ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$classes = $this->cleanup_class_names( $block );

		if ( empty( $classes ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			// Prefer WordPress' HTML API over regular expressions so class cleanup
			// does not corrupt nested markup or attributes.
			foreach ( $classes as $class_name ) {
				$processor->remove_class( $class_name );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Gets class names to remove from Twig output when cleanup is enabled.
	 *
	 * @param array $block Parsed block metadata.
	 * @return array Class names.
	 */
	private function cleanup_class_names( array $block ): array {
		$block_name = $this->block_name( $block );
		$slug       = false === strpos( $block_name, '/' ) ? $block_name : substr( $block_name, strpos( $block_name, '/' ) + 1 );
		$classes    = array( 'wp-block' );
		$slug_class = $this->sanitize_html_class( $slug );
		$name_class = $this->sanitize_html_class( str_replace( '/', '-', $block_name ) );

		if ( '' !== $slug_class ) {
			$classes[] = 'wp-block-' . $slug_class;
		}

		if ( '' !== $name_class ) {
			$classes[] = 'wp-block-' . $name_class;
		}

		/**
		 * Filters class names removed from mapped block Twig output.
		 *
		 * This filter is only used when class cleanup is enabled separately.
		 *
		 * @param array $classes Class names to remove.
		 * @param array $block   Parsed block metadata.
		 */
		$filtered = apply_filters( 'emulsify_theme_core_block_twig_cleanup_class_names', array_values( array_unique( $classes ) ), $block );

		if ( ! is_array( $filtered ) ) {
			return array_values( array_unique( $classes ) );
		}

		$normalized = array();

		foreach ( $filtered as $class_name ) {
			if ( is_scalar( $class_name ) ) {
				$class_name = trim( (string) $class_name );

				if ( '' !== $class_name ) {
					$normalized[] = $class_name;
				}
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Gets a parsed block name.
	 *
	 * @param array $block Parsed block metadata.
	 * @return string Block name.
	 */
	private function block_name( array $block ): string {
		return isset( $block['blockName'] ) && is_scalar( $block['blockName'] )
			? strtolower( trim( (string) $block['blockName'] ) )
			: '';
	}

	/**
	 * Returns original content and appends diagnostics for editors/admins only.
	 *
	 * @param string $block_content Original block content.
	 * @param string $message       Diagnostic message.
	 * @param array  $block         Parsed block metadata.
	 * @param string $template      Twig template being rendered.
	 * @return string Original content plus optional diagnostic markup.
	 */
	private function render_error( string $block_content, string $message, array $block, string $template ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log(
				sprintf(
					'[Emulsify] Core block Twig render failed for %s with %s: %s',
					$this->block_name( $block ),
					$template,
					$message
				)
			);
		}

		if ( ! $this->can_show_diagnostics() ) {
			return $block_content;
		}

		return $block_content . sprintf(
			'<p class="emulsify-block-render-error"><strong>%s</strong> %s</p>',
			$this->esc_html__( 'Emulsify block render error:', 'emulsify' ),
			$this->esc_html( $message )
		);
	}

	/**
	 * Checks whether diagnostics can be shown to the current user.
	 *
	 * @return bool TRUE for editors/admins.
	 */
	private function can_show_diagnostics(): bool {
		return function_exists( 'current_user_can' )
			&& ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ) );
	}

	/**
	 * Sanitizes an HTML class.
	 *
	 * @param string $class_name Raw class name.
	 * @return string Safe class name.
	 */
	private function sanitize_html_class( string $class_name ): string {
		if ( function_exists( 'sanitize_html_class' ) ) {
			return sanitize_html_class( $class_name );
		}

		return trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $class_name ), '-' );
	}

	/**
	 * Escapes translated text.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string Escaped translated text.
	 */
	private function esc_html__( string $text, string $domain ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Internal translation proxy receives literal strings from this class.
		return function_exists( 'esc_html__' ) ? esc_html__( $text, $domain ) : $this->esc_html( $text );
	}

	/**
	 * Translates text when WordPress is available.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string Translated text.
	 */
	private function translate( string $text, string $domain ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Internal translation proxy receives literal strings from this class.
		return function_exists( '__' ) ? __( $text, $domain ) : $text;
	}

	/**
	 * Escapes HTML text.
	 *
	 * @param string $text Text.
	 * @return string Escaped text.
	 */
	private function esc_html( string $text ): string {
		return function_exists( 'esc_html' ) ? esc_html( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
