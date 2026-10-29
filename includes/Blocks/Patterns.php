<?php
/**
 * Registers block patterns from JSON metadata.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Blocks;

use Emulsify\Theme\Support\FileDiscovery;

/**
 * Discovers child-first JSON block patterns.
 */
final class Patterns {

	/**
	 * Supported pattern category metadata filenames.
	 *
	 * @var array
	 */
	private const CATEGORY_METADATA_FILES = array( 'categories.json', '_categories.json' );

	/**
	 * Registers pattern hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ), 9 );
	}

	/**
	 * Registers JSON block patterns and their categories.
	 *
	 * @return void
	 */
	public function register_patterns(): void {
		$patterns = $this->pattern_records();

		if ( empty( $patterns ) ) {
			return;
		}

		$this->register_categories( $this->category_records( $patterns ), $patterns );

		foreach ( $patterns as $pattern ) {
			register_block_pattern( $pattern['name'], $pattern['args'] );
		}
	}

	/**
	 * Gets valid pattern records from discovered JSON files.
	 *
	 * @return array Pattern records.
	 */
	private function pattern_records(): array {
		$patterns   = array();
		$seen_names = array();

		foreach ( $this->pattern_files() as $file ) {
			$data = $this->pattern_data( $file );

			if ( null === $data ) {
				continue;
			}

			$args = $this->pattern_args( $data, $file );

			if ( null === $args ) {
				continue;
			}

			$name = $args['name'];
			unset( $args['name'] );

			if ( isset( $seen_names[ $name ] ) || $this->pattern_registered( $name ) ) {
				$this->debug(
					sprintf(
						'Skipped duplicate block pattern "%s" from %s.',
						$name,
						$file['path']
					)
				);
				continue;
			}

			$seen_names[ $name ] = true;
			$patterns[]          = array(
				'name' => $name,
				'args' => $args,
				'data' => $data,
				'file' => $file,
			);
		}

		return $patterns;
	}

	/**
	 * Discovers child-first pattern JSON files.
	 *
	 * @return array Pattern file records.
	 */
	private function pattern_files(): array {
		$files          = array();
		$seen_relative  = array();
		$seen_file_path = array();

		foreach ( FileDiscovery::file_records( $this->pattern_directories(), array( 'json' ), false ) as $file ) {
			$path     = $file['path'];
			$relative = basename( $path );
			$realpath = realpath( $path );
			$real     = false !== $realpath ? $realpath : $path;

			if ( $this->is_category_metadata_file( $relative ) ) {
				continue;
			}

			if ( isset( $seen_file_path[ $real ] ) ) {
				continue;
			}

			if ( isset( $seen_relative[ $relative ] ) ) {
				// Directories are scanned child-first. A child JSON file with the
				// same basename intentionally overrides the parent starter file.
				$this->debug(
					sprintf(
						'Skipped duplicate block pattern JSON file "%s" from %s.',
						$relative,
						$path
					)
				);
				continue;
			}

			$seen_file_path[ $real ]    = true;
			$seen_relative[ $relative ] = true;
			$files[]                    = array(
				'path'     => $path,
				'relative' => $relative,
				'source'   => $file['root_source'],
			);
		}

		return $files;
	}

	/**
	 * Gets pattern directories.
	 *
	 * @return array Pattern directory records.
	 */
	private function pattern_directories(): array {
		$directories = FileDiscovery::theme_roots( 'patterns' );

		/**
		 * Filters directories scanned for JSON block patterns.
		 *
		 * Directory records may be strings or arrays with path and source keys.
		 * Direct child and parent pattern directories are provided child-first.
		 *
		 * @param array $directories Pattern directory records.
		 */
		$filtered = apply_filters( 'emulsify_theme_pattern_directories', $directories );

		if ( is_array( $filtered ) ) {
			$directories = $filtered;
		}

		foreach ( $directories as $index => $directory ) {
			if ( is_string( $directory ) ) {
				$directories[ $index ] = array(
					'path'   => $directory,
					'source' => 'filtered',
				);
			}
		}

		return FileDiscovery::normalize_roots(
			$directories,
			array(
				'default_source' => null,
			)
		);
	}

	/**
	 * Reads and filters decoded pattern metadata.
	 *
	 * @param array $file Pattern file record.
	 * @return array|null Pattern data, or null when invalid.
	 */
	private function pattern_data( array $file ): ?array {
		$contents = file_get_contents( $file['path'] );

		if ( ! is_string( $contents ) ) {
			$this->debug( sprintf( 'Could not read block pattern JSON file: %s.', $file['path'] ) );
			return null;
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			$this->debug( sprintf( 'Could not decode block pattern JSON file: %s.', $file['path'] ) );
			return null;
		}

		/**
		 * Filters decoded JSON block pattern data before validation.
		 *
		 * @param array $data Decoded pattern data.
		 * @param array $file Pattern file record.
		 */
		$filtered = apply_filters( 'emulsify_theme_pattern_data', $data, $file );

		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Builds final block pattern registration arguments.
	 *
	 * @param array $data Pattern metadata.
	 * @param array $file Pattern file record.
	 * @return array|null Pattern registration args with a temporary name key.
	 */
	private function pattern_args( array $data, array $file ): ?array {
		$name    = $this->pattern_name( $data['name'] ?? null );
		$title   = $this->string_value( $data['title'] ?? null );
		$content = $this->string_value( $data['content'] ?? null );

		if ( '' === $name || '' === $title || '' === $content ) {
			// Invalid JSON should never break a site. Keep diagnostics behind
			// WP_DEBUG so production visitors do not see authoring mistakes.
			$this->debug( sprintf( 'Skipped invalid block pattern JSON file: %s.', $file['path'] ) );
			return null;
		}

		$args = array(
			'name'    => $name,
			'title'   => $title,
			'content' => $content,
		);

		foreach ( array( 'description', 'categories', 'keywords', 'postTypes', 'viewportWidth' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$args[ $key ] = $this->metadata_value( $key, $data[ $key ] );
			}
		}

		$args = array_filter(
			$args,
			static function ( $value ): bool {
				return null !== $value && array() !== $value && '' !== $value;
			}
		);

		/**
		 * Filters final block pattern registration arguments.
		 *
		 * The name key is passed to register_block_pattern() separately after
		 * this filter returns.
		 *
		 * @param array $args Pattern registration arguments including name.
		 * @param array $data Filtered pattern metadata.
		 * @param array $file Pattern file record.
		 */
		$filtered = apply_filters( 'emulsify_theme_pattern_args', $args, $data, $file );

		if ( is_array( $filtered ) ) {
			$args = $filtered;
		}

		$args['name']    = $this->pattern_name( $args['name'] ?? null );
		$args['title']   = $this->string_value( $args['title'] ?? null );
		$args['content'] = $this->string_value( $args['content'] ?? null );

		if ( '' === $args['name'] || '' === $args['title'] || '' === $args['content'] ) {
			$this->debug( sprintf( 'Skipped invalid filtered block pattern JSON file: %s.', $file['path'] ) );
			return null;
		}

		if ( isset( $args['categories'] ) ) {
			$args['categories'] = $this->string_list( $args['categories'], true );
		}

		if ( isset( $args['keywords'] ) ) {
			$args['keywords'] = $this->string_list( $args['keywords'] );
		}

		if ( isset( $args['postTypes'] ) ) {
			$args['postTypes'] = $this->string_list( $args['postTypes'], true );
		}

		if ( isset( $args['viewportWidth'] ) ) {
			$args['viewportWidth'] = (int) $args['viewportWidth'];
		}

		return $args;
	}

	/**
	 * Gets a normalized metadata value.
	 *
	 * @param string $key   Metadata key.
	 * @param mixed  $value Metadata value.
	 * @return mixed Normalized metadata value.
	 */
	private function metadata_value( string $key, $value ) {
		if ( 'description' === $key ) {
			return $this->string_value( $value );
		}

		if ( in_array( $key, array( 'categories', 'keywords', 'postTypes' ), true ) ) {
			return $this->string_list( $value, 'keywords' !== $key );
		}

		if ( 'viewportWidth' === $key && is_numeric( $value ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * Builds category records from valid pattern metadata.
	 *
	 * @param array $patterns Valid pattern records.
	 * @return array Category records keyed by category slug.
	 */
	private function category_records( array $patterns ): array {
		$categories = array();
		$metadata   = $this->category_metadata();

		foreach ( $patterns as $pattern ) {
			foreach ( $pattern['args']['categories'] ?? array() as $category ) {
				if ( ! is_string( $category ) || isset( $categories[ $category ] ) ) {
					continue;
				}

				$categories[ $category ] = array(
					// WordPress requires a label when registering a category. Use a
					// readable default and let projects refine it through the filter.
					'label'       => $this->label_from_slug( $category ),
					'description' => '',
				);

				if ( isset( $metadata[ $category ] ) ) {
					$categories[ $category ] = array_merge( $categories[ $category ], $metadata[ $category ] );
				}
			}
		}

		/**
		 * Filters block pattern categories discovered from JSON metadata.
		 *
		 * Category records are keyed by slug and include label and description.
		 *
		 * @param array $categories Pattern category records.
		 * @param array $patterns   Valid pattern records.
		 */
		$filtered = apply_filters( 'emulsify_theme_pattern_categories', $categories, $patterns );

		return is_array( $filtered ) ? $filtered : $categories;
	}

	/**
	 * Gets merged child-over-parent pattern category metadata.
	 *
	 * @return array Category metadata keyed by slug.
	 */
	private function category_metadata(): array {
		$metadata = array();

		foreach ( array_reverse( $this->pattern_directories() ) as $directory ) {
			if ( empty( $directory['path'] ) || ! is_scalar( $directory['path'] ) ) {
				continue;
			}

			foreach ( self::CATEGORY_METADATA_FILES as $filename ) {
				$path = rtrim( (string) $directory['path'], '/\\' ) . '/' . $filename;

				if ( ! is_readable( $path ) ) {
					continue;
				}

				foreach ( $this->category_metadata_file( $path ) as $slug => $category ) {
					$metadata[ $slug ] = array_merge( $metadata[ $slug ] ?? array(), $category );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Reads one category metadata file.
	 *
	 * @param string $path Metadata file path.
	 * @return array Category metadata keyed by slug.
	 */
	private function category_metadata_file( string $path ): array {
		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			$this->debug( sprintf( 'Could not read block pattern category metadata file: %s.', $path ) );
			return array();
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			$this->debug( sprintf( 'Could not decode block pattern category metadata file: %s.', $path ) );
			return array();
		}

		$metadata = array();

		foreach ( $data as $slug => $category ) {
			$slug = $this->category_name( $slug );

			if ( '' === $slug || ! is_array( $category ) ) {
				continue;
			}

			$record = array_filter(
				array(
					'label'       => $this->string_value( $category['label'] ?? ( $category['title'] ?? '' ) ),
					'description' => $this->string_value( $category['description'] ?? '' ),
				),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			);

			if ( ! empty( $record ) ) {
				$metadata[ $slug ] = $record;
			}
		}

		return $metadata;
	}

	/**
	 * Checks whether a pattern JSON file is category metadata.
	 *
	 * @param string $relative File basename.
	 * @return bool TRUE when the file stores category metadata.
	 */
	private function is_category_metadata_file( string $relative ): bool {
		return in_array( strtolower( $relative ), self::CATEGORY_METADATA_FILES, true );
	}

	/**
	 * Registers block pattern categories when WordPress supports them.
	 *
	 * @param array $categories Pattern category records.
	 * @param array $patterns   Valid pattern records.
	 * @return void
	 */
	private function register_categories( array $categories, array $patterns ): void {
		if ( empty( $patterns ) ) {
			return;
		}

		foreach ( $categories as $slug => $category ) {
			$name = $this->category_name( $slug );

			if ( '' === $name || $this->category_registered( $name ) ) {
				continue;
			}

			$args = is_array( $category ) ? $category : array();
			$args = array_filter(
				array(
					'label'       => $this->string_value( $args['label'] ?? $this->label_from_slug( $name ) ),
					'description' => $this->string_value( $args['description'] ?? '' ),
				),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			);

			if ( empty( $args['label'] ) ) {
				continue;
			}

			register_block_pattern_category( $name, $args );
		}
	}

	/**
	 * Checks whether WordPress already knows about a pattern.
	 *
	 * @param string $name Pattern name.
	 * @return bool TRUE when the pattern is already registered.
	 */
	private function pattern_registered( string $name ): bool {
		return \WP_Block_Patterns_Registry::get_instance()->is_registered( $name );
	}

	/**
	 * Checks whether WordPress already knows about a pattern category.
	 *
	 * @param string $name Category name.
	 * @return bool TRUE when the category is already registered.
	 */
	private function category_registered( string $name ): bool {
		return \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $name );
	}

	/**
	 * Normalizes a pattern name.
	 *
	 * @param mixed $name Pattern name candidate.
	 * @return string Pattern name, or an empty string.
	 */
	private function pattern_name( $name ): string {
		$name = $this->string_value( $name );
		$name = strtolower( trim( $name ) );

		return preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ) ? $name : '';
	}

	/**
	 * Normalizes a pattern category name.
	 *
	 * @param mixed $name Category name candidate.
	 * @return string Category name, or an empty string.
	 */
	private function category_name( $name ): string {
		if ( ! is_scalar( $name ) ) {
			return '';
		}

		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_-]+/', '-', $name );

		return trim( (string) $name, '-' );
	}

	/**
	 * Gets a scalar string value.
	 *
	 * @param mixed $value Value candidate.
	 * @return string String value, or an empty string.
	 */
	private function string_value( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Normalizes a metadata string list.
	 *
	 * @param mixed $value List candidate.
	 * @param bool  $slug  Whether values should be slug-normalized.
	 * @return array String values.
	 */
	private function string_list( $value, bool $slug = false ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();

		foreach ( $value as $item ) {
			$item = $slug ? $this->category_name( $item ) : $this->string_value( $item );

			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Creates a readable label from a slug.
	 *
	 * @param string $slug Slug.
	 * @return string Label.
	 */
	private function label_from_slug( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Logs diagnostics only when WP_DEBUG is enabled.
	 *
	 * @param string $message Diagnostic message.
	 * @return void
	 */
	private function debug( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( '[Emulsify] ' . $message );
	}
}
