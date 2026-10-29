<?php
/**
 * Registers WP-CLI commands.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Cli;

/**
 * Provides the `wp emulsify` command.
 */
final class GenerateChildThemeCommand {

	/**
	 * Bundled starter child theme directory.
	 */
	private const STARTER_SLUG = 'whisk';

	/**
	 * Project metadata source identifier for generated child themes.
	 */
	private const GENERATED_FROM = 'emulsify-wordpress';

	/**
	 * Fallback generated child theme description.
	 */
	private const FALLBACK_DESCRIPTION = 'No description was supplied during generation.';

	/**
	 * Project documentation files that receive generated token replacement.
	 *
	 * These files are copied verbatim and are the only files that may contain
	 * `%%EMULSIFY_*%%` tokens. Generation fails when a token survives.
	 */
	private const DOCUMENTATION_FILES = array(
		'README.md',
		'docs/development.md',
		'docs/support-information.md',
		'docs/upgrading.md',
	);

	/**
	 * Dependency/cache/build paths that should never be copied into a generated
	 * child theme.
	 */
	private const EXCLUDED_COPY_PATHS = array(
		'.cache',
		'.git',
		'.cli',
		'.coverage',
		'.out',
		'dist',
		'node_modules',
	);

	/**
	 * Registers the command when WP-CLI is available.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'emulsify', $this );
	}

	/**
	 * Generates an Emulsify child theme from the bundled Whisk starter.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Human-readable child theme name.
	 *
	 * [--machine-name=<slug>]
	 * : Lowercase slug for the generated theme directory, text domain, package
	 * name, and Emulsify machineName. Defaults to a slug generated from <name>.
	 *
	 * [--parent=<slug>]
	 * : Parent theme directory slug. Defaults to emulsify.
	 *
	 * [--description=<text>]
	 * : Human-readable child theme description used in the style.css Description
	 * header, package metadata, and generated project documentation. Defaults to
	 * the starter description.
	 *
	 * [--dry-run]
	 * : Show what would be created or changed without writing files.
	 *
	 * [--force]
	 * : Replace an existing destination directory only when it looks like an
	 * Emulsify-generated child theme.
	 *
	 * [--activate]
	 * : Activate the generated child theme after creation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->generate( $args, $assoc_args );
	}

	/**
	 * Generates a new child theme from whisk.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	private function generate( array $args, array $assoc_args ): void {
		$label              = $this->get_theme_label( $args );
		$machine_name       = $this->get_machine_name( $label, $assoc_args );
		$parent             = $this->get_parent_slug( $assoc_args );
		$dry_run            = $this->get_flag_value( $assoc_args, 'dry-run' );
		$force              = $this->get_flag_value( $assoc_args, 'force' );
		$activate           = $this->get_flag_value( $assoc_args, 'activate' );
		$source             = $this->join_path( get_theme_root(), $parent, self::STARTER_SLUG );
		$destination        = $this->join_path( get_theme_root(), $machine_name );
		$destination_exists = file_exists( $destination );

		\WP_CLI::log( sprintf( 'Generating child theme "%s" (%s) from Emulsify.', $label, $machine_name ) );
		\WP_CLI::log( sprintf( 'Source: %s', $source ) );
		\WP_CLI::log( sprintf( 'Destination: %s', $destination ) );

		if ( ! is_dir( $source ) ) {
			\WP_CLI::error( sprintf( 'Source directory not found: %s', $source ) );
			return;
		}

		try {
			$version     = $this->get_generated_from_version( $source );
			$description = $this->get_description( $assoc_args, $source );
			$core_range  = $this->get_core_range( $source );
		} catch ( \Throwable $exception ) {
			\WP_CLI::error( sprintf( 'Could not read generation metadata: %s', $exception->getMessage() ) );
			return;
		}

		if ( $machine_name === $parent ) {
			\WP_CLI::error( sprintf( 'The machine name "%s" would overwrite the parent theme. Choose a different --machine-name.', $machine_name ) );
		}

		if ( $destination_exists && ! $force ) {
			\WP_CLI::error( sprintf( 'Destination already exists: %s. Use --force to replace it.', $destination ) );
		}

		$config = array(
			'label'        => $label,
			'machine_name' => $machine_name,
			'parent'       => $parent,
			'version'      => $version,
			'description'  => $description,
			'core_range'   => $core_range,
		);

		if ( $destination_exists ) {
			$replacement_error = $this->get_destination_replacement_error( $destination, $parent, $machine_name );

			if ( null !== $replacement_error ) {
				\WP_CLI::error(
					sprintf(
						'Refusing to replace existing destination because it does not look like an Emulsify-generated child theme: %s (%s). Remove it manually or choose a different --machine-name.',
						$destination,
						$replacement_error
					)
				);
			}
		}

		if ( $dry_run ) {
			try {
				$metadata_updates = $this->collect_metadata_updates( $source, $config );
			} catch ( \Throwable $exception ) {
				\WP_CLI::error( sprintf( 'Could not plan the child theme: %s', $exception->getMessage() ) );
				return;
			}

			$this->report_dry_run( $source, $destination, $metadata_updates, $force, $activate, $machine_name );
			return;
		}

		$staging = $this->get_unique_sibling_path( $destination, 'tmp' );
		$staged  = false;

		try {
			$staged = $this->copy_theme( $source, $staging );

			if ( $staged ) {
				$metadata_updates = $this->collect_metadata_updates( $staging, $config );
				$staged           = $this->apply_metadata_updates( $staging, $metadata_updates );
			}
		} catch ( \Throwable $exception ) {
			$staged = false;
			\WP_CLI::warning( sprintf( 'Could not finish staging the child theme: %s', $exception->getMessage() ) );
		}

		if ( ! $staged ) {
			$this->cleanup_staging_path( $staging );
			\WP_CLI::error( sprintf( 'Failed staging child theme for destination: %s', $destination ) );
		}

		if ( $destination_exists ) {
			\WP_CLI::warning( sprintf( 'Replacing existing destination because --force was provided: %s', $destination ) );
			$this->replace_with_staged_theme( $staging, $destination );
		} elseif ( ! rename( $staging, $destination ) ) {
			$this->cleanup_staging_path( $staging );
			\WP_CLI::error( sprintf( 'Failed moving staged child theme into place at: %s', $destination ) );
		}

		if ( $activate ) {
			$this->activate_theme( $machine_name );
		} else {
			\WP_CLI::log( sprintf( 'Activate it with: wp theme activate %s', $machine_name ) );
		}

		\WP_CLI::success( sprintf( 'Child theme "%s" created at "%s".', $label, $destination ) );
	}

	/**
	 * Copies the starter theme into the child theme destination.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return bool TRUE on success.
	 */
	private function copy_theme( string $source, string $destination ): bool {
		if ( ! $this->make_directory( $destination ) ) {
			return false;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \UnexpectedValueException $exception ) {
			\WP_CLI::warning( sprintf( 'Could not read source directory: %s', $exception->getMessage() ) );
			return false;
		}

		foreach ( $iterator as $item ) {
			$path     = $item->getPathname();
			$relative = $this->relative_path( $source, $path );

			if ( $this->should_skip_copy_path( $relative ) ) {
				continue;
			}

			$target = $this->join_path( $destination, $relative );

			if ( $item->isLink() ) {
				\WP_CLI::warning( sprintf( 'Skipping symlink in starter theme: %s', $path ) );
				continue;
			}

			if ( $item->isDir() ) {
				if ( ! $this->make_directory( $target ) ) {
					\WP_CLI::warning( sprintf( 'Could not create directory: %s', $target ) );
					return false;
				}

				continue;
			}

			if ( ! $this->make_directory( dirname( $target ) ) || ! copy( $path, $target ) ) {
				\WP_CLI::warning( sprintf( 'Could not copy %s to %s.', $path, $target ) );
				return false;
			}
		}

		return true;
	}

	/**
	 * Collects targeted starter metadata updates.
	 *
	 * @param string $root   Theme root to read.
	 * @param array  $config Generation config.
	 * @return array<int, array{file:string,contents:string}>
	 *
	 * @throws \RuntimeException When generated documentation cannot be resolved.
	 */
	private function collect_metadata_updates( string $root, array $config ): array {
		$updates      = array();
		$theme_label  = $config['label'];
		$source_label = $this->sanitize_label_for_source( $theme_label );
		$machine_name = $config['machine_name'];
		$parent       = $config['parent'];
		$version      = $config['version'];
		$description  = $config['description'];
		$core_range   = $config['core_range'];

		// Update known metadata surfaces deliberately. Avoid blind recursive text
		// replacement so example prose, generated assets, and project content are
		// not mutated unexpectedly.
		$this->collect_text_update(
			$updates,
			$root,
			'style.css',
			function ( string $contents ) use ( $source_label, $machine_name, $parent, $description ): string {
				$contents = $this->replace_theme_header( $contents, 'Theme Name', $source_label );
				$contents = $this->replace_theme_header( $contents, 'Text Domain', $machine_name );
				$contents = $this->replace_theme_header( $contents, 'Description', $this->one_line( $description ) );
				return $this->replace_theme_header( $contents, 'Template', $parent );
			}
		);

		$this->collect_json_update(
			$updates,
			$root,
			'package.json',
			function ( \stdClass $data ) use ( $machine_name, $description ): \stdClass {
				$data->name        = $machine_name;
				$data->description = $this->one_line( $description );
				return $data;
			}
		);

		$this->collect_json_update(
			$updates,
			$root,
			'project.emulsify.json',
			function ( \stdClass $data ) use ( $theme_label, $machine_name, $version, $description ): \stdClass {
				if ( ! isset( $data->project ) || ! $data->project instanceof \stdClass ) {
					$data->project = new \stdClass();
				}

				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- project.platform is a machine-readable adapter key.
				$data->project->platform             = 'wordpress';
				$data->project->name                 = $theme_label;
				$data->project->machineName          = $machine_name;
				$data->project->generatedFrom        = self::GENERATED_FROM;
				$data->project->generatedFromVersion = $version;
				$data->project->description          = $this->one_line( $description );

				return $data;
			}
		);

		$this->collect_text_update(
			$updates,
			$root,
			'functions.php',
			function ( string $contents ) use ( $source_label ): string {
				return str_replace( 'Whisk child theme hooks.', $source_label . ' child theme hooks.', $contents );
			}
		);

		$this->collect_pattern_updates( $updates, $root, $machine_name );

		$this->collect_documentation_updates(
			$updates,
			$root,
			array(
				'%%EMULSIFY_THEME_NAME%%'     => $this->one_line( $theme_label ),
				'%%EMULSIFY_MACHINE_NAME%%'   => $this->one_line( $machine_name ),
				'%%EMULSIFY_DESCRIPTION%%'    => $this->one_line( $description ),
				'%%EMULSIFY_SOURCE_PROJECT%%' => self::GENERATED_FROM,
				'%%EMULSIFY_SOURCE_VERSION%%' => $this->one_line( $version ),
				'%%EMULSIFY_CORE_RANGE%%'     => $this->one_line( $core_range ),
			)
		);

		return $updates;
	}

	/**
	 * Adds generated project documentation updates.
	 *
	 * Documentation files are copied verbatim, so this is the only place their
	 * `%%EMULSIFY_*%%` tokens are resolved. A surviving token is a generation
	 * failure rather than a silent leak into a project repository.
	 *
	 * @param array  $updates      Update accumulator.
	 * @param string $root         Theme root.
	 * @param array  $replacements Token replacement map.
	 * @return void
	 *
	 * @throws \RuntimeException When a documentation file is missing, unreadable, or retains a token.
	 */
	private function collect_documentation_updates( array &$updates, string $root, array $replacements ): void {
		foreach ( self::DOCUMENTATION_FILES as $relative ) {
			$path = $this->join_path( $root, $relative );

			if ( ! is_readable( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal generator path, not rendered HTML.
				throw new \RuntimeException( sprintf( 'Expected starter documentation file is missing: %s', $relative ) );
			}

			$contents = file_get_contents( $path );

			if ( ! is_string( $contents ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal generator path, not rendered HTML.
				throw new \RuntimeException( sprintf( 'Could not read starter documentation file: %s', $relative ) );
			}

			$updated = strtr( $contents, $replacements );

			if ( preg_match( '/%%EMULSIFY_[A-Z_]+%%/', $updated, $matches ) ) {
				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal token and path, not rendered HTML.
					sprintf( 'Unable to replace documentation token %s in %s.', $matches[0], $relative )
				);
			}

			if ( $updated !== $contents ) {
				$updates[] = array(
					'file'     => $relative,
					'contents' => $updated,
				);
			}
		}
	}

	/**
	 * Adds starter pattern metadata updates.
	 *
	 * @param array  $updates      Update accumulator.
	 * @param string $root         Theme root.
	 * @param string $machine_name Generated child theme machine name.
	 * @return void
	 */
	private function collect_pattern_updates( array &$updates, string $root, string $machine_name ): void {
		$pattern_dir = $this->join_path( $root, 'patterns' );

		if ( ! is_dir( $pattern_dir ) ) {
			// Patterns are optional in Whisk. Empty generated themes should not pay
			// a filesystem or warning cost for a feature they have not adopted.
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $pattern_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$files    = array();

		foreach ( $iterator as $item ) {
			if ( ! $item->isLink() && $item->isFile() && 'json' === $item->getExtension() ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );

		foreach ( $files as $path ) {
			$relative = $this->relative_path( $root, $path );

			$this->collect_json_update(
				$updates,
				$root,
				$relative,
				function ( \stdClass $data ) use ( $machine_name ): \stdClass {
					if ( isset( $data->name ) && is_string( $data->name ) && 0 === strpos( $data->name, self::STARTER_SLUG . '/' ) ) {
						$data->name = $machine_name . '/' . substr( $data->name, strlen( self::STARTER_SLUG ) + 1 );
					}

					return $data;
				}
			);
		}
	}

	/**
	 * Adds a text file update when the callback changes the contents.
	 *
	 * @param array    $updates  Update accumulator.
	 * @param string   $root     Theme root.
	 * @param string   $relative Relative file path.
	 * @param callable $callback Content updater.
	 * @return void
	 */
	private function collect_text_update( array &$updates, string $root, string $relative, callable $callback ): void {
		$path = $this->join_path( $root, $relative );

		if ( ! is_readable( $path ) ) {
			\WP_CLI::warning( sprintf( 'Expected starter file is not readable: %s', $path ) );
			return;
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			\WP_CLI::warning( sprintf( 'Could not read starter file: %s', $path ) );
			return;
		}

		$updated = $callback( $contents );

		if ( $updated !== $contents ) {
			$updates[] = array(
				'file'     => $relative,
				'contents' => $updated,
			);
		}
	}

	/**
	 * Adds a JSON file update when the callback changes the decoded data.
	 *
	 * @param array    $updates  Update accumulator.
	 * @param string   $root     Theme root.
	 * @param string   $relative Relative file path.
	 * @param callable $callback Data updater.
	 * @return void
	 *
	 * @throws \RuntimeException When JSON is invalid or is not an object.
	 */
	private function collect_json_update( array &$updates, string $root, string $relative, callable $callback ): void {
		$path = $this->join_path( $root, $relative );

		if ( ! is_readable( $path ) ) {
			\WP_CLI::warning( sprintf( 'Expected starter JSON file is not readable: %s', $path ) );
			return;
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			\WP_CLI::warning( sprintf( 'Could not read starter JSON file: %s', $path ) );
			return;
		}

		$data = json_decode( $contents );

		if ( ! $data instanceof \stdClass ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal generator path, not rendered HTML.
			throw new \RuntimeException( sprintf( 'Starter JSON file must contain a valid JSON object: %s', $relative ) );
		}

		$updated_data = $callback( $data );
		$updated      = $this->encode_json( $updated_data );

		if ( ! is_string( $updated ) ) {
			\WP_CLI::warning( sprintf( 'Could not encode starter JSON file: %s', $path ) );
			return;
		}

		$updated .= "\n";

		if ( $updated !== $contents ) {
			$updates[] = array(
				'file'     => $relative,
				'contents' => $updated,
			);
		}
	}

	/**
	 * Applies collected metadata updates to a generated child theme.
	 *
	 * @param string $destination Destination theme root.
	 * @param array  $updates     File updates.
	 * @return bool TRUE when every update succeeds.
	 */
	private function apply_metadata_updates( string $destination, array $updates ): bool {
		foreach ( $updates as $update ) {
			$path = $this->join_path( $destination, $update['file'] );

			if ( false === file_put_contents( $path, $update['contents'] ) ) {
				\WP_CLI::warning( sprintf( 'Could not update generated file: %s', $path ) );
				return false;
			}

			\WP_CLI::log( sprintf( 'Updated %s.', $update['file'] ) );
		}

		return true;
	}

	/**
	 * Reports a dry-run plan.
	 *
	 * @param string $source           Source theme root.
	 * @param string $destination      Destination theme root.
	 * @param array  $metadata_updates Planned metadata updates.
	 * @param bool   $force            Whether force replacement was requested.
	 * @param bool   $activate         Whether activation was requested.
	 * @param string $machine_name     Generated machine name.
	 * @return void
	 */
	private function report_dry_run( string $source, string $destination, array $metadata_updates, bool $force, bool $activate, string $machine_name ): void {
		\WP_CLI::log( 'Dry run: no files will be written.' );

		if ( file_exists( $destination ) && $force ) {
			\WP_CLI::warning( sprintf( 'Would replace existing destination because --force was provided: %s', $destination ) );
		}

		\WP_CLI::log( sprintf( 'Would copy %d starter files from %s.', $this->count_copyable_files( $source ), $source ) );

		foreach ( $metadata_updates as $update ) {
			\WP_CLI::log( sprintf( 'Would update %s.', $update['file'] ) );
		}

		if ( $activate ) {
			\WP_CLI::log( sprintf( 'Would activate child theme "%s".', $machine_name ) );
		}

		\WP_CLI::success( sprintf( 'Dry run complete. Child theme would be created at "%s".', $destination ) );
	}

	/**
	 * Activates a generated child theme.
	 *
	 * @param string $machine_name Theme stylesheet slug.
	 * @return void
	 */
	private function activate_theme( string $machine_name ): void {
		if ( ! function_exists( 'switch_theme' ) ) {
			\WP_CLI::warning( sprintf( 'Could not activate "%s" because switch_theme() is unavailable.', $machine_name ) );
			return;
		}

		switch_theme( $machine_name );

		\WP_CLI::success( sprintf( 'Activated child theme "%s".', $machine_name ) );
	}

	/**
	 * Replaces a WordPress theme header value.
	 *
	 * @param string $contents File contents.
	 * @param string $field    Header field.
	 * @param string $value    Header value.
	 * @return string Updated file contents.
	 */
	private function replace_theme_header( string $contents, string $field, string $value ): string {
		$pattern = '/^(\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*).*$/mi';
		$found   = false;
		$updated = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $value, &$found ): string {
				$found = true;
				return $matches[1] . $value;
			},
			$contents,
			1
		);

		// Distinguish a missing header from a header that already holds the
		// requested value. Only the former is a problem worth reporting.
		if ( ! is_string( $updated ) || ! $found ) {
			\WP_CLI::warning( sprintf( 'Could not update "%s" in style.css.', $field ) );
			return $contents;
		}

		return $updated;
	}

	/**
	 * Counts files that would be copied from the starter.
	 *
	 * @param string $source Source theme root.
	 * @return int Copyable file count.
	 */
	private function count_copyable_files( string $source ): int {
		$count = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \UnexpectedValueException $exception ) {
			return 0;
		}

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$relative = $this->relative_path( $source, $item->getPathname() );

			if ( ! $this->should_skip_copy_path( $relative ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Gets the version to record in generated child theme metadata.
	 *
	 * The installed parent theme's style.css is runtime metadata and is included
	 * in every distribution. Release checks require it to match the repository
	 * package version, so both generation paths agree without shipping root npm
	 * tooling in the installable WordPress archive.
	 *
	 * @param string $source Starter source path.
	 * @return string Version string.
	 *
	 * @throws \RuntimeException When the parent theme declares no version.
	 */
	private function get_generated_from_version( string $source ): string {
		$version = $this->read_theme_header_value( $this->join_path( dirname( $source ), 'style.css' ), 'Version' );

		if ( is_string( $version ) && '' !== trim( $version ) ) {
			return trim( $version );
		}

		throw new \RuntimeException( 'Could not read the parent theme release version from style.css.' );
	}

	/**
	 * Gets the description to record in generated theme metadata and docs.
	 *
	 * @param array  $assoc_args Named CLI arguments.
	 * @param string $source     Starter source path.
	 * @return string Description string.
	 */
	private function get_description( array $assoc_args, string $source ): string {
		$supplied = isset( $assoc_args['description'] ) && is_string( $assoc_args['description'] )
			? $assoc_args['description']
			: '';

		if ( '' !== trim( $supplied ) ) {
			$supplied = function_exists( 'sanitize_text_field' )
				? sanitize_text_field( $supplied )
				: trim( strip_tags( $supplied ) );

			if ( '' !== trim( $supplied ) ) {
				return $this->one_line( $supplied );
			}
		}

		$starter = $this->read_theme_header_value( $this->join_path( $source, 'style.css' ), 'Description' );

		if ( is_string( $starter ) && '' !== trim( $starter ) ) {
			return $this->one_line( $starter );
		}

		return self::FALLBACK_DESCRIPTION;
	}

	/**
	 * Gets the Emulsify Core range declared by the starter.
	 *
	 * @param string $source Starter source path.
	 * @return string Semver range.
	 *
	 * @throws \RuntimeException When the starter declares no Emulsify Core range.
	 */
	private function get_core_range( string $source ): string {
		$package = $this->read_json_file( $this->join_path( $source, 'package.json' ) );
		$range   = $package['dependencies']['@emulsify/core'] ?? null;

		if ( ! is_string( $range ) || '' === trim( $range ) ) {
			throw new \RuntimeException( 'Starter package.json is missing dependencies.@emulsify/core.' );
		}

		return $this->one_line( $range );
	}

	/**
	 * Encodes generated JSON with two-space indentation.
	 *
	 * PHP's JSON_PRETTY_PRINT is fixed at four spaces, while npm, Node, and the
	 * Whisk sources all use two. Re-indenting here keeps generated JSON identical
	 * no matter which generation path a project used.
	 *
	 * @param \stdClass $data Data to encode.
	 * @return string|null Encoded JSON, or NULL on failure.
	 */
	private function encode_json( \stdClass $data ): ?string {
		// JSON_UNESCAPED_UNICODE matches JSON.stringify, which never escapes
		// non-ASCII. Without it a non-ASCII theme name or description would make
		// the two generation paths emit different bytes. Do not add
		// JSON_UNESCAPED_LINE_TERMINATORS: U+2028/U+2029 must stay escaped so the
		// re-indent below can never see a raw line break inside a string value.
		$encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$reindented = preg_replace_callback(
			'/^(?: {4})+/m',
			static function ( array $matches ): string {
				return str_repeat( ' ', (int) ( strlen( $matches[0] ) / 2 ) );
			},
			$encoded
		);

		return is_string( $reindented ) ? $reindented : $encoded;
	}

	/**
	 * Collapses whitespace so generated metadata stays readable in Markdown.
	 *
	 * Generated values are injected into Markdown table cells and prose, where a
	 * newline would break the surrounding structure.
	 *
	 * @param string $value Raw value.
	 * @return string Single-line value.
	 */
	private function one_line( string $value ): string {
		// The /u flag keeps this aligned with JavaScript's \s, which also matches
		// non-breaking and other Unicode spaces, so both generation paths collapse
		// the same characters.
		$collapsed = preg_replace( '/\s+/u', ' ', $value );

		return trim( is_string( $collapsed ) ? $collapsed : $value );
	}

	/**
	 * Gets the reason an existing destination should not be force-replaced.
	 *
	 * @param string $destination  Destination theme root.
	 * @param string $parent       Selected parent theme slug.
	 * @param string $machine_name Requested child theme machine name.
	 * @return string|null Error reason, or NULL when replacement is allowed.
	 */
	private function get_destination_replacement_error( string $destination, string $parent, string $machine_name ): ?string {
		if ( ! is_dir( $destination ) ) {
			return 'destination is not a theme directory';
		}

		$template = $this->read_theme_header_value( $this->join_path( $destination, 'style.css' ), 'Template' );

		if ( null === $template ) {
			return 'missing readable style.css Template header';
		}

		$allowed_templates = array_values( array_unique( array_filter( array( 'emulsify', $parent ) ) ) );

		if ( ! in_array( $template, $allowed_templates, true ) ) {
			return sprintf( 'style.css Template is "%s", expected "%s"', $template, implode( '" or "', $allowed_templates ) );
		}

		$project = $this->read_json_file( $this->join_path( $destination, 'project.emulsify.json' ) );

		if ( null === $project ) {
			return 'missing readable project.emulsify.json';
		}

		if ( ! isset( $project['project'] ) || ! is_array( $project['project'] ) ) {
			return 'project.emulsify.json is missing project metadata';
		}

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- project.platform is a machine-readable adapter key.
		if ( 'wordpress' !== ( $project['project']['platform'] ?? null ) ) {
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Diagnostic quotes the machine-readable project.platform value.
			return 'project.emulsify.json is missing project.platform: wordpress';
		}

		if ( ! isset( $project['project']['machineName'] ) || ! is_string( $project['project']['machineName'] ) || '' === trim( $project['project']['machineName'] ) ) {
			return 'project.emulsify.json is missing project.machineName';
		}

		if ( $machine_name !== $project['project']['machineName'] ) {
			return sprintf( 'project.emulsify.json machineName is "%s", expected "%s"', $project['project']['machineName'], $machine_name );
		}

		if ( ! isset( $project['project']['generatedFrom'] ) || ! is_string( $project['project']['generatedFrom'] ) || '' === trim( $project['project']['generatedFrom'] ) ) {
			return 'project.emulsify.json is missing project.generatedFrom';
		}

		if ( self::GENERATED_FROM !== $project['project']['generatedFrom'] ) {
			return sprintf( 'project.emulsify.json generatedFrom is "%s", expected "%s"', $project['project']['generatedFrom'], self::GENERATED_FROM );
		}

		if ( ! isset( $project['project']['generatedFromVersion'] ) || ! is_string( $project['project']['generatedFromVersion'] ) || '' === trim( $project['project']['generatedFromVersion'] ) ) {
			return 'project.emulsify.json is missing project.generatedFromVersion';
		}

		return null;
	}

	/**
	 * Replaces an existing destination with a fully staged theme.
	 *
	 * @param string $staging     Fully generated staging directory.
	 * @param string $destination Existing destination theme root.
	 * @return void
	 */
	private function replace_with_staged_theme( string $staging, string $destination ): void {
		$backup = $this->get_unique_sibling_path( $destination, 'bak' );

		if ( ! rename( $destination, $backup ) ) {
			$this->cleanup_staging_path( $staging );
			\WP_CLI::error( sprintf( 'Failed moving existing destination to a backup: %s', $destination ) );
		}

		if ( ! rename( $staging, $destination ) ) {
			$restored = rename( $backup, $destination );
			$this->cleanup_staging_path( $staging );

			if ( ! $restored ) {
				\WP_CLI::error( sprintf( 'Failed installing the staged theme and restoring the previous theme. The previous theme remains at: %s', $backup ) );
			}

			\WP_CLI::error( sprintf( 'Failed installing the staged theme. The previous theme was restored at: %s', $destination ) );
		}

		if ( ! $this->remove_path( $backup ) ) {
			\WP_CLI::warning( sprintf( 'Generated the child theme, but could not remove the previous theme backup: %s', $backup ) );
		}
	}

	/**
	 * Gets a unique temporary sibling path for an atomic theme swap.
	 *
	 * @param string $destination Destination theme root.
	 * @param string $type        Temporary path type.
	 * @return string Unique sibling path.
	 */
	private function get_unique_sibling_path( string $destination, string $type ): string {
		do {
			$path = sprintf( '%s.%s-%s', $destination, $type, bin2hex( random_bytes( 8 ) ) );
		} while ( file_exists( $path ) || is_link( $path ) );

		return $path;
	}

	/**
	 * Removes a staging path after a failed generation attempt.
	 *
	 * @param string $path Staging path.
	 * @return void
	 */
	private function cleanup_staging_path( string $path ): void {
		if ( ! $this->remove_path( $path ) ) {
			\WP_CLI::warning( sprintf( 'Could not clean up failed child theme staging path: %s', $path ) );
		}
	}

	/**
	 * Reads a WordPress theme header value.
	 *
	 * @param string $path  File path.
	 * @param string $field Header field.
	 * @return string|null Header value, or NULL when unavailable.
	 */
	private function read_theme_header_value( string $path, string $field ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return null;
		}

		if ( ! preg_match( '/^\s*(?:\*\s*)?' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi', $contents, $matches ) ) {
			return null;
		}

		return trim( $matches[1] );
	}

	/**
	 * Reads a JSON file.
	 *
	 * @param string $path File path.
	 * @return array|null Decoded JSON data, or NULL when unavailable.
	 */
	private function read_json_file( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return null;
		}

		$data = json_decode( $contents, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Removes a generated, staged, or backed-up theme path.
	 *
	 * @param string $path Path to remove.
	 * @return bool TRUE on success.
	 */
	private function remove_path( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		// Removal is scoped to computed staging/backup paths or to a destination
		// whose generated-theme lineage was validated before the atomic swap.
		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( \UnexpectedValueException $exception ) {
			\WP_CLI::warning( sprintf( 'Could not read destination for removal: %s', $exception->getMessage() ) );
			return false;
		}

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();

			if ( $item->isDir() && ! $item->isLink() ) {
				if ( ! rmdir( $item_path ) ) {
					return false;
				}
			} elseif ( ! unlink( $item_path ) ) {
				return false;
			}
		}

		return rmdir( $path );
	}

	/**
	 * Creates a directory through WordPress when available.
	 *
	 * @param string $path Directory path.
	 * @return bool TRUE on success.
	 */
	private function make_directory( string $path ): bool {
		if ( is_dir( $path ) ) {
			return true;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			return wp_mkdir_p( $path );
		}

		return mkdir( $path, 0777, true );
	}

	/**
	 * Checks whether a starter path should be skipped during copy.
	 *
	 * @param string $relative Relative starter path.
	 * @return bool TRUE when the path should be skipped.
	 */
	private function should_skip_copy_path( string $relative ): bool {
		$parts = preg_split( '#[\\\\/]#', $relative );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		foreach ( $parts as $part ) {
			if ( in_array( $part, self::EXCLUDED_COPY_PATHS, true ) ) {
				// Match any path segment so nested dependencies and generated build
				// output cannot leak into a new project child theme.
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a human theme name into a filesystem-safe slug.
	 *
	 * @param string $label Theme label.
	 * @return string Theme slug.
	 */
	private static function convert_label_to_machine_name( string $label ): string {
		$slug = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $label ) );
		$slug = preg_replace( '/-{2,}/', '-', (string) $slug );
		$slug = trim( (string) $slug, '-' );

		return $slug;
	}

	/**
	 * Gets the sanitized human-readable child theme label.
	 *
	 * @param array $args Positional CLI arguments.
	 * @return string Theme label.
	 */
	private function get_theme_label( array $args ): string {
		$label = isset( $args[0] ) && is_string( $args[0] ) && '' !== trim( $args[0] ) ? $args[0] : 'New Theme';
		$label = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $label ) : trim( strip_tags( $label ) );

		return '' !== $label ? $label : 'New Theme';
	}

	/**
	 * Restricts a theme label to characters safe inside source comments.
	 *
	 * The richer human-readable label remains available for JSON metadata, where
	 * JSON encoding handles it safely. Source files only receive letters, numbers,
	 * spaces, and hyphens so a crafted label cannot terminate a comment.
	 *
	 * @param string $label Human-readable theme label.
	 * @return string Source-safe theme label.
	 */
	private function sanitize_label_for_source( string $label ): string {
		$source_label = preg_replace( '/[^\p{L}\p{N} -]+/u', '', $label );
		$source_label = preg_replace( '/ +/', ' ', is_string( $source_label ) ? $source_label : '' );
		$source_label = trim( is_string( $source_label ) ? $source_label : '' );

		return '' !== $source_label ? $source_label : 'New Theme';
	}

	/**
	 * Gets the generated or explicitly supplied machine name.
	 *
	 * @param string $label      Theme label.
	 * @param array  $assoc_args Named CLI arguments.
	 * @return string Theme slug.
	 */
	private function get_machine_name( string $label, array $assoc_args ): string {
		$raw_machine_name = isset( $assoc_args['machine-name'] ) && is_string( $assoc_args['machine-name'] )
			? $assoc_args['machine-name']
			: $label;
		$machine_name     = self::convert_label_to_machine_name( $raw_machine_name );

		if ( '' === $machine_name && isset( $assoc_args['machine-name'] ) ) {
			\WP_CLI::error( 'Machine name cannot be empty.' );
		}

		if ( '' === $machine_name ) {
			$machine_name = 'new-theme';
		}

		if ( isset( $assoc_args['machine-name'] ) && $raw_machine_name !== $machine_name ) {
			\WP_CLI::warning( sprintf( 'Normalized --machine-name from "%s" to "%s".', $raw_machine_name, $machine_name ) );
		}

		return $machine_name;
	}

	/**
	 * Gets the parent theme slug.
	 *
	 * @param array $assoc_args Named CLI arguments.
	 * @return string Parent slug.
	 */
	private function get_parent_slug( array $assoc_args ): string {
		$parent = isset( $assoc_args['parent'] ) && is_string( $assoc_args['parent'] ) ? $assoc_args['parent'] : 'emulsify';

		if ( function_exists( 'sanitize_key' ) ) {
			$parent = sanitize_key( $parent );
		} else {
			$parent = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $parent ) );
		}

		return '' !== $parent ? $parent : 'emulsify';
	}

	/**
	 * Reads a boolean WP-CLI flag.
	 *
	 * @param array  $assoc_args Named CLI arguments.
	 * @param string $name       Flag name.
	 * @return bool TRUE when the flag is enabled.
	 */
	private function get_flag_value( array $assoc_args, string $name ): bool {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return false;
		}

		return false !== $assoc_args[ $name ] && 'false' !== $assoc_args[ $name ] && '0' !== $assoc_args[ $name ];
	}

	/**
	 * Computes a path relative to a root.
	 *
	 * @param string $root Root path.
	 * @param string $path Full path.
	 * @return string Relative path.
	 */
	private function relative_path( string $root, string $path ): string {
		return ltrim( substr( $path, strlen( rtrim( $root, '/\\' ) ) ), '/\\' );
	}

	/**
	 * Joins path segments.
	 *
	 * @param string ...$segments Path segments.
	 * @return string Joined path.
	 */
	private function join_path( string ...$segments ): string {
		$path = '';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$path = '' === $path ? rtrim( $segment, '/\\' ) : $path . '/' . trim( $segment, '/\\' );
		}

		return $path;
	}
}
