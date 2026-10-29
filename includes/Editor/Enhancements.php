<?php
/**
 * Enqueues optional block editor enhancements.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Editor;

use Emulsify\Theme\Support\AssetEnqueuer;
use Emulsify\Theme\Support\AssetManifest;
use Emulsify\Theme\Support\AssetRecord;
use Emulsify\Theme\Support\FileDiscovery;

/**
 * Optional editor enhancement runtime.
 */
final class Enhancements {

	/**
	 * Asset manifest reader.
	 *
	 * @var AssetManifest
	 */
	private $manifest;

	/**
	 * Memoized editor enhancement configuration.
	 *
	 * @var array|null
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param AssetManifest|null $manifest Asset manifest reader.
	 */
	public function __construct( ?AssetManifest $manifest = null ) {
		$this->manifest = $manifest ?? new AssetManifest();
	}

	/**
	 * Registers editor enhancement hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_filter( 'render_block_data', array( $this, 'render_block_data' ), 20, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), 20, 3 );
	}

	/**
	 * Enqueues built child-theme editor assets when available.
	 *
	 * @return void
	 */
	public function editor_assets(): void {
		$config = $this->config();

		// The parent ships the enqueue/runtime support only. A child theme or
		// selected component system decides whether any editor bundle exists.
		foreach ( $this->asset_files( 'dist/global/editor', array( 'css' ) ) as $asset ) {
			AssetEnqueuer::enqueue_style( 'emulsify-editor', $asset );
		}

		foreach ( $this->asset_files( 'dist/global/editor', array( 'js' ) ) as $asset ) {
			$handle = AssetEnqueuer::handle( 'emulsify-editor', $asset );

			wp_enqueue_script(
				$handle,
				$asset['uri'],
				$this->editor_script_dependencies( $asset ),
				$asset['version'],
				array(
					'in_footer' => true,
				)
			);

			wp_add_inline_script(
				$handle,
				// Expose the normalized PHP config before the editor bundle runs so
				// modules can stay declarative and filter-driven.
				'window.emulsifyEditorEnhancements = ' . $this->json_encode( $config ) . ';',
				'before'
			);
		}
	}

	/**
	 * Adds a file-caption class to parsed File blocks when configured.
	 *
	 * @param array $parsed_block Parsed block data.
	 * @param array $source_block Source block data.
	 * @param mixed $parent_block Parent block instance.
	 * @return array Filtered parsed block data.
	 */
	public function render_block_data( array $parsed_block, array $source_block = array(), $parent_block = null ): array {
		unset( $source_block, $parent_block );

		$config = $this->config();

		if ( ! $this->file_caption_enabled( $config ) || ! $this->is_file_block( $parsed_block ) ) {
			return $parsed_block;
		}

		$settings  = $config['fileCaption'];
		$attribute = $this->string_option( $settings['attribute'] ?? '', 'emulsifyShowMediaCaption' );

		if ( empty( $parsed_block['attrs'][ $attribute ] ) ) {
			return $parsed_block;
		}

		$class                              = $this->string_option( $settings['blockClassName'] ?? '', 'has-media-caption' );
		$parsed_block['attrs']              = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		$parsed_block['attrs']['className'] = $this->append_class( $parsed_block['attrs']['className'] ?? '', $class );

		return $parsed_block;
	}

	/**
	 * Appends the media caption to rendered File blocks when configured.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block         Parsed block data.
	 * @param mixed  $instance      Block instance.
	 * @return string Filtered block content.
	 */
	public function render_block( string $block_content, array $block, $instance = null ): string {
		unset( $instance );

		$config = $this->config();

		if ( ! $this->file_caption_enabled( $config ) || ! $this->is_file_block( $block ) ) {
			return $block_content;
		}

		$settings  = $config['fileCaption'];
		$attribute = $this->string_option( $settings['attribute'] ?? '', 'emulsifyShowMediaCaption' );

		if ( empty( $block['attrs'][ $attribute ] ) || false !== strpos( $block_content, 'wp-block-file__media-caption' ) ) {
			return $block_content;
		}

		$attachment_id = $this->attachment_id( $block['attrs'] ?? array() );

		if ( $attachment_id <= 0 || ! function_exists( 'wp_get_attachment_caption' ) ) {
			return $block_content;
		}

		$caption = wp_get_attachment_caption( $attachment_id );

		if ( ! is_scalar( $caption ) || '' === trim( (string) $caption ) ) {
			return $block_content;
		}

		$caption_class = $this->string_option( $settings['captionClassName'] ?? '', 'wp-block-file__media-caption' );
		$caption_html  = sprintf(
			'<p class="%s">%s</p>',
			$this->esc_attr( $caption_class ),
			$this->esc_html( (string) $caption )
		);

		if ( preg_match( '/<\/div>\s*$/i', $block_content ) ) {
			// core/file normally renders a wrapper div. Append inside that wrapper
			// so the caption inherits block spacing and editor/frontend styling.
			$filtered = preg_replace( '/<\/div>\s*$/i', $caption_html . '</div>', $block_content, 1 );

			return is_string( $filtered ) ? $filtered : $block_content;
		}

		return $block_content . $caption_html;
	}

	/**
	 * Gets editor enhancement configuration.
	 *
	 * @return array Editor enhancement config.
	 */
	private function config(): array {
		if ( is_array( $this->config ) ) {
			return $this->config;
		}

		$config = array(
			'columnsEqualHeight' => array(
				'enabled'   => false,
				'attribute' => 'emulsifyEqualizeHeights',
				'className' => 'is-equal-height',
			),
			'fileCaption'        => array(
				'enabled'          => false,
				'attribute'        => 'emulsifyShowMediaCaption',
				'blockClassName'   => 'has-media-caption',
				'captionClassName' => 'wp-block-file__media-caption',
			),
			'embedVariations'    => array(
				'enabled'               => false,
				'allowedVariationNames' => array(),
			),
			'placement'          => array(
				'enabled'    => false,
				'blocks'     => array(),
				'singleton'  => true,
				'requireTop' => true,
			),
		);

		/**
		 * Filters optional editor enhancement configuration.
		 *
		 * Defaults keep every starter module disabled. Child themes can enable
		 * individual modules by returning nested configuration changes.
		 *
		 * @param array $config Editor enhancement configuration.
		 */
		$filtered = apply_filters( 'emulsify_theme_editor_enhancements_config', $config );

		if ( is_array( $filtered ) ) {
			$config = $this->merge_config( $config, $filtered );
		}

		$this->config = $this->normalize_config( $config );

		return $this->config;
	}

	/**
	 * Recursively merges editor configuration.
	 *
	 * @param array $base     Base config.
	 * @param array $override Override config.
	 * @return array Merged config.
	 */
	private function merge_config( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if (
				array_key_exists( $key, $base )
				&& is_array( $base[ $key ] )
				&& is_array( $value )
				&& ! array_is_list( $base[ $key ] )
				&& ! array_is_list( $value )
			) {
				$base[ $key ] = $this->merge_config( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Normalizes editor enhancement configuration.
	 *
	 * @param array $config Raw config.
	 * @return array Normalized config.
	 */
	private function normalize_config( array $config ): array {
		foreach ( array( 'columnsEqualHeight', 'fileCaption', 'embedVariations', 'placement' ) as $module ) {
			$config[ $module ]            = isset( $config[ $module ] ) && is_array( $config[ $module ] ) ? $config[ $module ] : array();
			$config[ $module ]['enabled'] = ! empty( $config[ $module ]['enabled'] );
		}

		$config['embedVariations']['allowedVariationNames'] = $this->string_list( $config['embedVariations']['allowedVariationNames'] ?? array() );
		$config['placement']['blocks']                      = $this->string_list( $config['placement']['blocks'] ?? array() );
		$config['placement']['singleton']                   = ! empty( $config['placement']['singleton'] );
		$config['placement']['requireTop']                  = ! empty( $config['placement']['requireTop'] );

		return $config;
	}

	/**
	 * Finds editor assets below a theme-relative directory.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array Built asset records.
	 */
	private function asset_files( string $directory, array $extensions ): array {
		$manifest_assets = $this->manifest_asset_files( $directory, $extensions );
		$assets          = null === $manifest_assets ? $this->scanned_asset_files( $directory, $extensions ) : $manifest_assets;

		/**
		 * Filters built editor asset files before they are enqueued.
		 *
		 * Asset records should include path, relative, uri, and version keys.
		 *
		 * @param array  $assets     Built editor asset records.
		 * @param string $directory  Theme-relative asset directory being scanned.
		 * @param array  $extensions Allowed file extensions for the current enqueue pass.
		 */
		$filtered = apply_filters( 'emulsify_theme_editor_asset_files', $assets, $directory, $extensions );

		return is_array( $filtered ) ? $filtered : $assets;
	}

	/**
	 * Gets manifest-backed editor assets when available.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array|null Manifest-backed records, or null for scanner fallback.
	 */
	private function manifest_asset_files( string $directory, array $extensions ): ?array {
		if ( 'dist/global/editor' !== trim( $directory, '/\\' ) ) {
			return null;
		}

		return $this->manifest->asset_records( 'editor', $extensions );
	}

	/**
	 * Finds editor assets below a theme-relative directory through filesystem scans.
	 *
	 * @param string $directory  Theme-relative asset directory.
	 * @param array  $extensions Allowed file extensions.
	 * @return array Built asset records.
	 */
	private function scanned_asset_files( string $directory, array $extensions ): array {
		return AssetRecord::from_discovered_files( FileDiscovery::file_records( $this->asset_roots( $directory ), $extensions ) );
	}

	/**
	 * Gets child theme editor asset roots first, then parent fallbacks.
	 *
	 * @param string $directory Theme-relative asset directory.
	 * @return array Asset root records.
	 */
	private function asset_roots( string $directory ): array {
		/**
		 * Filters built editor asset directories before discovery.
		 *
		 * Root records should include absolute path, public uri, and priority
		 * keys. Lower priority values are enqueued first after duplicate relative
		 * paths are resolved child-first.
		 *
		 * @param array  $directories Built editor asset directory records.
		 * @param string $directory   Theme-relative asset directory being scanned.
		 */
		return FileDiscovery::filtered_theme_roots( $directory, 'emulsify_theme_editor_asset_directories' );
	}

	/**
	 * Gets WordPress script dependencies for editor modules.
	 *
	 * @param array $asset Asset record.
	 * @return array Script handles.
	 */
	private function editor_script_dependencies( array $asset = array() ): array {
		$dependencies = array(
			'wp-block-editor',
			'wp-blocks',
			'wp-components',
			'wp-compose',
			'wp-data',
			'wp-dom-ready',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
			'wp-notices',
		);

		return array_values(
			array_unique(
				array_merge(
					$dependencies,
					AssetEnqueuer::dependencies( $asset )
				)
			)
		);
	}

	/**
	 * Checks whether the File block caption module is enabled.
	 *
	 * @param array $config Editor enhancement config.
	 * @return bool TRUE when enabled.
	 */
	private function file_caption_enabled( array $config ): bool {
		return ! empty( $config['fileCaption']['enabled'] );
	}

	/**
	 * Checks for a core/file parsed block.
	 *
	 * @param array $block Parsed block data.
	 * @return bool TRUE when the block is core/file.
	 */
	private function is_file_block( array $block ): bool {
		return isset( $block['blockName'] ) && 'core/file' === $block['blockName'];
	}

	/**
	 * Gets the attachment ID from File block attributes.
	 *
	 * @param array $attributes Block attributes.
	 * @return int Attachment ID.
	 */
	private function attachment_id( array $attributes ): int {
		foreach ( array( 'id', 'fileId' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) {
				return (int) $attributes[ $key ];
			}
		}

		return 0;
	}

	/**
	 * Appends a CSS class to an existing class string.
	 *
	 * @param mixed  $current Existing class value.
	 * @param string $class   Class to append.
	 * @return string Class string.
	 */
	private function append_class( $current, string $class ): string {
		$classes   = is_scalar( $current ) ? preg_split( '/\s+/', (string) $current ) : array();
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = $class;

		return trim( implode( ' ', array_unique( array_filter( $classes ) ) ) );
	}

	/**
	 * Normalizes a string option with a fallback.
	 *
	 * @param mixed  $value    Option value.
	 * @param string $fallback Fallback value.
	 * @return string Normalized string.
	 */
	private function string_option( $value, string $fallback ): string {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$value = trim( (string) $value );

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Normalizes a list of strings.
	 *
	 * @param mixed $values Value list.
	 * @return array Normalized strings.
	 */
	private function string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$strings = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$strings[] = $value;
			}
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * JSON encodes a value for inline script output.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON string.
	 */
	private function json_encode( $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value );
		} else {
			$encoded = json_encode( $value );
		}

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Escapes an HTML attribute.
	 *
	 * @param string $value Raw attribute.
	 * @return string Escaped attribute.
	 */
	private function esc_attr( string $value ): string {
		return function_exists( 'esc_attr' ) ? esc_attr( $value ) : htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escapes HTML text.
	 *
	 * @param string $value Raw text.
	 * @return string Escaped text.
	 */
	private function esc_html( string $value ): string {
		return function_exists( 'esc_html' ) ? esc_html( $value ) : htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
