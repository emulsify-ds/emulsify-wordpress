<?php
/**
 * Registers optional ACF blocks rendered by Timber.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Blocks;

use Emulsify\Theme\Support\AssetEnqueuer;
use Emulsify\Theme\Support\AssetManifest;
use Emulsify\Theme\Support\AssetRecord;
use Emulsify\Theme\Support\Diagnostics;
use Emulsify\Theme\Support\FileDiscovery;

/**
 * ACF/Twig block integration.
 */
final class AcfBlocks {

	/**
	 * Component locator.
	 *
	 * @var ComponentLocator
	 */
	private $components;

	/**
	 * Asset manifest reader.
	 *
	 * @var AssetManifest
	 */
	private $manifest;

	/**
	 * Duplicate ACF block registrations skipped by this registrar.
	 *
	 * @var array
	 */
	private $skipped_duplicates = array();

	/**
	 * Constructor.
	 *
	 * @param ComponentLocator|null $components Component locator.
	 * @param AssetManifest|null    $manifest   Asset manifest reader.
	 */
	public function __construct( ?ComponentLocator $components = null, ?AssetManifest $manifest = null ) {
		$this->components = $components ?? new ComponentLocator();
		$this->manifest   = $manifest ?? new AssetManifest();
	}

	/**
	 * Registers optional ACF hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'acf_register_block_type' ) ) {
			// ACF block registration is optional; no hooks are registered when ACF is
			// unavailable so non-ACF projects keep the same runtime behavior.
			return;
		}

		add_action( 'acf/init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Registers component metadata as ACF blocks.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'acf_register_block_type' ) ) {
			return;
		}

		$seen_names = array();

		foreach ( $this->components->acf_components() as $component ) {
			$metadata = $this->metadata( $component['metadata_path'] );

			if ( null === $metadata ) {
				continue;
			}

			/**
			 * Filters ACF/Twig component metadata before block arguments are built.
			 *
			 * Metadata is read from the component *.component.json file. Defaults
			 * such as name, title, and render_callback are merged after this filter.
			 *
			 * @param array $metadata  Component metadata.
			 * @param array $component Component discovery record.
			 */
			$filtered_metadata = apply_filters( 'emulsify_theme_acf_block_metadata', $metadata, $component );

			if ( is_array( $filtered_metadata ) ) {
				$metadata = $filtered_metadata;
			}

			$args = $this->block_args( $component, $metadata );

			/**
			 * Filters final ACF block registration arguments.
			 *
			 * Duplicate block names are checked after this filter so child themes
			 * and project plugins can intentionally alter the final ACF block name.
			 *
			 * @param array $args      ACF block registration arguments.
			 * @param array $component Component discovery record.
			 * @param array $metadata  Filtered component metadata.
			 */
			$filtered_args = apply_filters( 'emulsify_theme_acf_block_args', $args, $component, $metadata );

			if ( is_array( $filtered_args ) ) {
				$args = $filtered_args;
			}

			$args['name'] = $this->normalize_block_name(
				$args['name'] ?? '',
				isset( $component['slug'] ) ? (string) $component['slug'] : 'block'
			);
			$args         = $this->with_asset_records( $args, $component, $metadata );
			$name         = $this->block_name( $args );

			if ( '' !== $name && isset( $seen_names[ $name ] ) ) {
				// Duplicate names can happen after filters alter metadata. Register
				// the first child-first record and report the skipped one in debug.
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'acf_block_name',
					$name,
					$seen_names[ $name ],
					$this->component_record( $component, $args ),
					'Duplicate ACF block name after metadata defaults were merged.'
				);
				continue;
			}

			if ( '' !== $name && $this->acf_block_registered( $name ) ) {
				$this->skipped_duplicates[] = Diagnostics::duplicate_record(
					'acf_registered_block_name',
					$name,
					array(
						'name'   => $name,
						'source' => 'existing',
					),
					$this->component_record( $component, $args ),
					'ACF block name is already registered.'
				);
				continue;
			}

			if ( '' !== $name ) {
				$seen_names[ $name ] = $this->component_record( $component, $args );
			}

			acf_register_block_type( $args );
		}

		Diagnostics::report_duplicates(
			array_merge(
				$this->components->skipped_duplicates( 'acf' ),
				$this->skipped_duplicates
			),
			function_exists( '__' ) ? __( 'Emulsify skipped duplicate ACF block definitions.', 'emulsify' ) : 'Emulsify skipped duplicate ACF block definitions.'
		);
	}

	/**
	 * Gets duplicate ACF block records skipped by this registrar.
	 *
	 * @return array Skipped duplicate records.
	 */
	public function skipped_duplicates(): array {
		return $this->skipped_duplicates;
	}

	/**
	 * Renders an ACF block with Timber.
	 *
	 * @param array  $block      Block settings and attributes.
	 * @param string $content    Inner block content.
	 * @param bool   $is_preview TRUE during editor preview render.
	 * @param mixed  $post_id    Current post ID.
	 * @return void
	 */
	public function render_block( $block, $content = '', $is_preview = false, $post_id = 0 ): void {
		if ( ! is_array( $block ) ) {
			$this->render_error( __( 'Block rendering requires valid block metadata.', 'emulsify' ) );
			return;
		}

		if ( ! class_exists( '\Timber\Timber' ) ) {
			$this->render_error( __( 'Block rendering requires Timber.', 'emulsify' ) );
			return;
		}

		$template = $this->template( $block );

		if ( '' === $template ) {
			$this->render_error( __( 'Block rendering error: no Twig template defined.', 'emulsify' ) );
			return;
		}

		$context               = \Timber\Timber::context();
		$context['block']      = $block;
		$context['content']    = is_scalar( $content ) ? (string) $content : '';
		$context['fields']     = $this->fields();
		$context['is_preview'] = (bool) $is_preview;

		try {
			// ACF expects render_callback output directly. Timber::render echoes the
			// template, matching ACF's callback contract.
			\Timber\Timber::render( $template, $context );
		} catch ( \Throwable $throwable ) {
			$this->render_error( $throwable->getMessage() );
		}
	}

	/**
	 * Reads component metadata.
	 *
	 * @param string $path Absolute metadata path.
	 * @return array|null Component metadata, or null when invalid.
	 */
	private function metadata( string $path ): ?array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// A build can remove metadata between the readability check and the read.
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected deploy race after the guarded check above.

		if ( ! is_string( $contents ) ) {
			return null;
		}

		$metadata = json_decode( $contents, true );

		return is_array( $metadata ) && JSON_ERROR_NONE === json_last_error() ? $metadata : null;
	}

	/**
	 * Builds ACF block registration arguments.
	 *
	 * @param array $component Component record.
	 * @param array $metadata  Component metadata.
	 * @return array ACF block arguments.
	 */
	private function block_args( array $component, array $metadata ): array {
		$defaults = array(
			'name'  => 'emulsify-' . $component['slug'],
			'title' => ucwords( str_replace( '-', ' ', $component['slug'] ) ),
			'mode'  => 'preview',
		);

		$args                    = array_merge( $defaults, $metadata );
		$args['name']            = $this->normalize_block_name( $args['name'] ?? '', $component['slug'] );
		$args['render_callback'] = array( $this, 'render_block' );
		$args['twig_template']   = $component['template'];

		return $args;
	}

	/**
	 * Adds a scoped asset callback when a component declares block assets.
	 *
	 * @param array $args      ACF block registration arguments.
	 * @param array $component Component record.
	 * @param array $metadata  Component metadata.
	 * @return array ACF block registration arguments.
	 */
	private function with_asset_records( array $args, array $component, array $metadata ): array {
		$asset_records = $this->block_asset_records( $component, $metadata, $args );

		/**
		 * Filters scoped ACF/Twig block asset records before registration.
		 *
		 * Records are grouped by frontend/editor context and css/js type. Return
		 * an empty record set to leave the block without scoped asset enqueueing.
		 *
		 * @param array $asset_records Scoped asset records.
		 * @param array $component     Component discovery record.
		 * @param array $metadata      Component metadata.
		 * @param array $args          ACF block registration arguments.
		 */
		$filtered = apply_filters( 'emulsify_theme_acf_block_asset_records', $asset_records, $component, $metadata, $args );

		if ( is_array( $filtered ) ) {
			$asset_records = AssetRecord::normalize_context_records( $filtered );
		}

		if ( ! AssetRecord::has_context_records( $asset_records ) ) {
			return $args;
		}

		$existing_callback      = isset( $args['enqueue_assets'] ) && is_callable( $args['enqueue_assets'] ) ? $args['enqueue_assets'] : null;
		$args['enqueue_assets'] = $this->enqueue_assets_callback( $asset_records, $existing_callback, $this->block_name( $args ) );

		return $args;
	}

	/**
	 * Gets scoped block asset records.
	 *
	 * @param array $component Component record.
	 * @param array $metadata  Component metadata.
	 * @param array $args      ACF block registration arguments.
	 * @return array Scoped asset records.
	 */
	private function block_asset_records( array $component, array $metadata, array $args ): array {
		$manifest_records = $this->manifest->scoped_asset_records(
			$this->asset_identifiers( $component, $metadata, $args )
		);

		if ( is_array( $manifest_records ) ) {
			return AssetRecord::normalize_context_records( $manifest_records );
		}

		$metadata_records = $this->metadata_asset_records( $component, $metadata );

		return is_array( $metadata_records ) ? $metadata_records : AssetRecord::empty_context_records();
	}

	/**
	 * Gets identifiers used to match manifest scoped assets.
	 *
	 * @param array $component Component record.
	 * @param array $metadata  Component metadata.
	 * @param array $args      ACF block registration arguments.
	 * @return array Asset identifiers.
	 */
	private function asset_identifiers( array $component, array $metadata, array $args ): array {
		$identifiers = array(
			$args['name'] ?? '',
			'acf/' . ( $args['name'] ?? '' ),
			$component['relative'] ?? '',
			$component['slug'] ?? '',
		);

		if ( isset( $metadata['name'] ) && is_scalar( $metadata['name'] ) ) {
			$metadata_name = trim( (string) $metadata['name'] );
			$normalized    = $this->normalize_block_name( $metadata_name, isset( $component['slug'] ) ? (string) $component['slug'] : 'block' );
			$identifiers[] = $metadata_name;
			$identifiers[] = $normalized;
			$identifiers[] = 'acf/' . $normalized;
		}

		return array_values(
			array_unique(
				array_filter(
					$identifiers,
					static function ( $identifier ): bool {
						return is_scalar( $identifier ) && '' !== trim( (string) $identifier );
					}
				)
			)
		);
	}

	/**
	 * Gets component metadata asset records.
	 *
	 * @param array $component Component record.
	 * @param array $metadata  Component metadata.
	 * @return array|null Scoped asset records, or null when undeclared.
	 */
	private function metadata_asset_records( array $component, array $metadata ): ?array {
		if ( empty( $metadata['assets'] ) || ! is_array( $metadata['assets'] ) ) {
			return null;
		}

		$base_path = ! empty( $component['path'] ) && is_scalar( $component['path'] )
			? rtrim( (string) $component['path'], '/\\' )
			: dirname( (string) ( $component['metadata_path'] ?? '' ) );
		$base_uri  = $this->component_base_uri( $component, $base_path );

		if ( '' === $base_uri || '' === $base_path ) {
			return AssetRecord::empty_context_records();
		}

		return $this->metadata_context_records( $metadata['assets'], $base_path, $base_uri, $component );
	}

	/**
	 * Gets frontend/editor records from component metadata.
	 *
	 * @param array  $assets    Component metadata assets.
	 * @param string $base_path Component base path.
	 * @param string $base_uri  Component base URI.
	 * @param array  $component Component record.
	 * @return array Scoped asset records.
	 */
	private function metadata_context_records( array $assets, string $base_path, string $base_uri, array $component ): array {
		$records = AssetRecord::empty_context_records();

		if ( isset( $assets['frontend'] ) || isset( $assets['editor'] ) ) {
			if ( isset( $assets['frontend'] ) && is_array( $assets['frontend'] ) ) {
				$records['frontend'] = $this->metadata_type_records( $assets['frontend'], $base_path, $base_uri, $component );
			}

			if ( isset( $assets['editor'] ) && is_array( $assets['editor'] ) ) {
				$records['editor'] = $this->metadata_type_records( $assets['editor'], $base_path, $base_uri, $component );
			}

			return $records;
		}

		$records['frontend'] = $this->metadata_type_records( $assets, $base_path, $base_uri, $component );

		return $records;
	}

	/**
	 * Gets css/js records from component metadata.
	 *
	 * @param array  $assets    Metadata asset section.
	 * @param string $base_path Component base path.
	 * @param string $base_uri  Component base URI.
	 * @param array  $component Component record.
	 * @return array Asset records grouped by type.
	 */
	private function metadata_type_records( array $assets, string $base_path, string $base_uri, array $component ): array {
		$records = array(
			'css' => array(),
			'js'  => array(),
		);

		foreach ( array( 'css', 'js' ) as $type ) {
			if ( empty( $assets[ $type ] ) || ! is_array( $assets[ $type ] ) ) {
				continue;
			}

			foreach ( $assets[ $type ] as $entry ) {
				$record = $this->metadata_asset_record( $entry, $type, $base_path, $base_uri, $component );

				if ( null !== $record ) {
					$records[ $type ][] = $record;
				}
			}
		}

		$records['css'] = FileDiscovery::sort_by_priority_and_relative( $records['css'] );
		$records['js']  = FileDiscovery::sort_by_priority_and_relative( $records['js'] );

		return $records;
	}

	/**
	 * Normalizes one component metadata asset record.
	 *
	 * @param mixed  $entry     Metadata asset entry.
	 * @param string $type      Asset type.
	 * @param string $base_path Component base path.
	 * @param string $base_uri  Component base URI.
	 * @param array  $component Component record.
	 * @return array|null Asset record, or null when invalid.
	 */
	private function metadata_asset_record( $entry, string $type, string $base_path, string $base_uri, array $component ): ?array {
		$data     = is_array( $entry ) ? $entry : array( 'path' => $entry );
		$relative = AssetRecord::entry_path( $data );

		if ( '' === $relative || strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) !== $type ) {
			return null;
		}

		$path = $base_path . '/' . $relative;

		if ( ! is_readable( $path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Emulsify] Asset not readable: %s', $path ) );
			}

			return null;
		}

		return array(
			'path'         => $path,
			'priority'     => 0,
			'relative'     => AssetRecord::entry_relative( $data, $relative ),
			'uri'          => rtrim( $base_uri, '/' ) . '/' . $relative,
			'version'      => AssetRecord::entry_version( $data, $path ),
			'dependencies' => AssetRecord::entry_dependencies( $data ),
			'module'       => AssetRecord::entry_module( $data ),
			'source'       => isset( $component['source'] ) ? (string) $component['source'] : '',
		);
	}

	/**
	 * Builds a component base URI from discovery metadata.
	 *
	 * @param array  $component Component record.
	 * @param string $base_path Component base path.
	 * @return string Component base URI, or empty string when unavailable.
	 */
	private function component_base_uri( array $component, string $base_path ): string {
		if ( empty( $component['root_uri'] ) || empty( $component['root_path'] ) ) {
			return '';
		}

		$relative = FileDiscovery::relative_path( (string) $component['root_path'], $base_path );

		return rtrim( (string) $component['root_uri'], '/' ) . ( '' === $relative ? '' : '/' . $relative );
	}

	/**
	 * Builds the ACF enqueue callback for scoped block assets.
	 *
	 * @param array         $asset_records     Scoped asset records.
	 * @param callable|null $existing_callback Existing enqueue callback.
	 * @param string        $block_name        ACF block name.
	 * @return callable Enqueue callback.
	 */
	private function enqueue_assets_callback( array $asset_records, ?callable $existing_callback, string $block_name ): callable {
		return function ( ...$callback_args ) use ( $asset_records, $existing_callback, $block_name ): void {
			if ( is_callable( $existing_callback ) ) {
				call_user_func_array( $existing_callback, $callback_args );
			}

			$this->enqueue_asset_records( $asset_records['frontend'] ?? array(), $block_name, 'frontend' );

			if ( $this->is_editor_context() ) {
				$this->enqueue_asset_records( $asset_records['editor'] ?? array(), $block_name, 'editor' );
			}
		};
	}

	/**
	 * Enqueues a grouped asset record set.
	 *
	 * @param array  $records    Asset records grouped by css/js.
	 * @param string $block_name ACF block name.
	 * @param string $context    Asset context.
	 * @return void
	 */
	private function enqueue_asset_records( array $records, string $block_name, string $context ): void {
		foreach ( $records['css'] ?? array() as $record ) {
			$this->enqueue_style_record( $record, $block_name, $context );
		}

		foreach ( $records['js'] ?? array() as $record ) {
			$this->enqueue_script_record( $record, $block_name, $context );
		}
	}

	/**
	 * Enqueues a style record.
	 *
	 * @param array  $record     Asset record.
	 * @param string $block_name ACF block name.
	 * @param string $context    Asset context.
	 * @return void
	 */
	private function enqueue_style_record( array $record, string $block_name, string $context ): void {
		AssetEnqueuer::enqueue_style( 'emulsify-acf-' . $context . '-' . $block_name, $record );
	}

	/**
	 * Enqueues a script record.
	 *
	 * @param array  $record     Asset record.
	 * @param string $block_name ACF block name.
	 * @param string $context    Asset context.
	 * @return void
	 */
	private function enqueue_script_record( array $record, string $block_name, string $context ): void {
		AssetEnqueuer::enqueue_script( 'emulsify-acf-' . $context . '-' . $block_name, $record );
	}

	/**
	 * Checks whether the current request is an editor/admin context.
	 *
	 * @return bool TRUE when editor/admin-only assets should load.
	 */
	private function is_editor_context(): bool {
		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * Normalizes an ACF PHP block name to an ACF-safe un-namespaced slug.
	 *
	 * ACF's PHP registration API expects names like "testimonial"; WordPress
	 * exposes the final editor block as "acf/testimonial".
	 *
	 * @param mixed  $name     Candidate ACF block name.
	 * @param string $fallback Component slug fallback.
	 * @return string ACF-safe block name.
	 */
	private function normalize_block_name( $name, string $fallback ): string {
		$candidate = is_scalar( $name ) ? (string) $name : '';

		if ( '' === trim( $candidate ) ) {
			$candidate = 'emulsify-' . $fallback;
		}

		$normalized = strtolower( trim( $candidate ) );
		$normalized = preg_replace( '/[^a-z0-9-]+/', '-', $normalized );
		$normalized = trim( (string) $normalized, '-' );

		if ( '' === $normalized ) {
			$normalized = 'emulsify-' . $fallback;
			$normalized = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $normalized ) );
			$normalized = trim( (string) $normalized, '-' );
		}

		if ( '' === $normalized || ! preg_match( '/^[a-z]/', $normalized ) ) {
			$normalized = 'emulsify-' . $normalized;
		}

		return $normalized;
	}

	/**
	 * Gets a final ACF block name from registration arguments.
	 *
	 * @param array $args ACF block registration arguments.
	 * @return string Block name, or an empty string when unavailable.
	 */
	private function block_name( array $args ): string {
		return ! empty( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
	}

	/**
	 * Checks whether ACF already knows about a block name.
	 *
	 * @param string $name ACF block name.
	 * @return bool TRUE when the block is already registered.
	 */
	private function acf_block_registered( string $name ): bool {
		return function_exists( 'acf_get_block_type' ) && is_array( acf_get_block_type( $name ) );
	}

	/**
	 * Builds a debug record for an ACF component registration.
	 *
	 * @param array $component Component record.
	 * @param array $args      ACF block registration arguments.
	 * @return array Debug record.
	 */
	private function component_record( array $component, array $args ): array {
		return array(
			'name'          => $this->block_name( $args ),
			'relative'      => isset( $component['relative'] ) ? $component['relative'] : '',
			'source'        => isset( $component['source'] ) ? $component['source'] : '',
			'metadata_path' => isset( $component['metadata_path'] ) ? $component['metadata_path'] : '',
			'template'      => isset( $component['template'] ) ? $component['template'] : '',
		);
	}

	/**
	 * Gets ACF field values for the block being rendered.
	 *
	 * @return array ACF field values.
	 */
	private function fields(): array {
		if ( ! function_exists( 'get_fields' ) ) {
			return array();
		}

		$fields = get_fields();

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Gets the block Twig template.
	 *
	 * The top-level value comes from the registered ACF block definition. A
	 * legacy value persisted in block data is considered only as a fallback, and
	 * every candidate must match a template found by component discovery.
	 *
	 * @param array $block Block settings and attributes.
	 * @return string Template path.
	 */
	private function template( array $block ): string {
		$template = '';

		if ( ! empty( $block['twig_template'] ) && is_string( $block['twig_template'] ) ) {
			$template = $block['twig_template'];
		} elseif (
			! empty( $block['data'] )
			&& is_array( $block['data'] )
			&& ! empty( $block['data']['twig_template'] )
			&& is_string( $block['data']['twig_template'] )
		) {
			$template = $block['data']['twig_template'];
		}

		foreach ( $this->components->acf_components() as $component ) {
			if ( isset( $component['template'] ) && is_string( $component['template'] ) && $template === $component['template'] ) {
				return $template;
			}
		}

		return '';
	}

	/**
	 * Renders an editor-only block error.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function render_error( string $message ): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_posts' ) ) {
			// Block errors are authoring diagnostics. Avoid showing implementation
			// details to normal frontend visitors.
			return;
		}

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Emulsify block error:', 'emulsify' ),
			esc_html( $message )
		);
	}
}
