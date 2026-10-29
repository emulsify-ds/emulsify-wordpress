<?php
/**
 * Smoke checks for the WP-CLI child theme generator.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/**
		 * Captured messages.
		 *
		 * @var array<int, array{type:string,message:string}>
		 */
		public static $messages = array();

		public static function add_command( $name, $callable ): void {}

		public static function log( string $message ): void {
			self::$messages[] = array(
				'type'    => 'log',
				'message' => $message,
			);
		}

		public static function warning( string $message ): void {
			self::$messages[] = array(
				'type'    => 'warning',
				'message' => $message,
			);
		}

		public static function success( string $message ): void {
			self::$messages[] = array(
				'type'    => 'success',
				'message' => $message,
			);
		}

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root(): string {
		return $GLOBALS['emulsify_theme_root'];
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		$failure = $GLOBALS['emulsify_mkdir_failure'] ?? null;

		if ( is_callable( $failure ) && $failure( $target ) ) {
			return false;
		}

		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'switch_theme' ) ) {
	function switch_theme( string $stylesheet ): void {
		$GLOBALS['emulsify_activated_theme'] = $stylesheet;
	}
}

/**
 * Reads the expected generated source version from root package metadata.
 *
 * Root package.json is the single source of truth for the release version, so
 * this smoke derives the value instead of restating it.
 *
 * @return string Expected generatedFromVersion.
 */
function emulsify_cli_smoke_expected_version(): string {
	static $version = null;

	if ( null !== $version ) {
		return $version;
	}

	$contents = file_get_contents( dirname( __DIR__, 2 ) . '/package.json' );
	$data     = is_string( $contents ) ? json_decode( $contents, true ) : null;

	if ( ! is_array( $data ) || ! isset( $data['version'] ) || ! is_string( $data['version'] ) || '' === trim( $data['version'] ) ) {
		throw new RuntimeException( 'Could not read the release version from root package.json.' );
	}

	$version = trim( $data['version'] );

	return $version;
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_cli_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Recursively copies test fixtures.
 *
 * @param string $source      Source path.
 * @param string $destination Destination path.
 * @return void
 */
function emulsify_cli_smoke_copy( string $source, string $destination ): void {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$path     = $item->getPathname();
		$relative = ltrim( substr( $path, strlen( rtrim( $source, '/\\' ) ) ), '/\\' );

		if ( in_array( 'node_modules', preg_split( '#[\\\\/]#', $relative ), true ) ) {
			continue;
		}

		$target = $destination . '/' . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) ) {
				throw new RuntimeException( sprintf( 'Could not create fixture directory: %s', $target ) );
			}

			continue;
		}

		if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
			throw new RuntimeException( sprintf( 'Could not create fixture directory: %s', dirname( $target ) ) );
		}

		if ( ! copy( $path, $target ) ) {
			throw new RuntimeException( sprintf( 'Could not copy fixture file: %s', $path ) );
		}
	}
}

/**
 * Recursively removes a path.
 *
 * @param string $path Path to remove.
 * @return void
 */
function emulsify_cli_smoke_remove( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $path );
}

/**
 * Reads and decodes a JSON fixture file.
 *
 * @param string $path JSON file path.
 * @return array Decoded JSON.
 */
function emulsify_cli_smoke_json( string $path ): array {
	$data = json_decode( file_get_contents( $path ), true );

	emulsify_cli_smoke_assert( is_array( $data ), sprintf( 'Could not decode JSON file: %s', $path ) );

	return $data;
}

/**
 * Runs PHP's syntax checker against a generated file when process execution is available.
 *
 * @param string $path PHP file path.
 * @return void
 */
function emulsify_cli_smoke_lint_php( string $path ): void {
	if ( ! function_exists( 'exec' ) || '' === PHP_BINARY ) {
		return;
	}

	$output    = array();
	$exit_code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ), $output, $exit_code );

	emulsify_cli_smoke_assert(
		0 === $exit_code,
		sprintf( "Generated PHP failed syntax validation:\n%s", implode( "\n", $output ) )
	);
}

$repo_root = dirname( __DIR__, 2 );
$work_root = sys_get_temp_dir() . '/emulsify-child-theme-generator-' . uniqid( '', true );
$theme_root = $work_root . '/themes';
$parent_root = $theme_root . '/emulsify';

$GLOBALS['emulsify_theme_root']      = $theme_root;
$GLOBALS['emulsify_activated_theme'] = null;
$GLOBALS['emulsify_mkdir_failure']   = null;

try {
	if ( ! mkdir( $parent_root . '/whisk', 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create fixture root: %s', $parent_root ) );
	}

	emulsify_cli_smoke_copy( $repo_root . '/whisk', $parent_root . '/whisk' );

	// The installable archive omits root npm tooling, so the generator reads the
	// synchronized release version from the parent theme's runtime metadata.
	if ( ! copy( $repo_root . '/style.css', $parent_root . '/style.css' ) ) {
		throw new RuntimeException( 'Could not copy the parent theme style.css fixture.' );
	}

	file_put_contents(
		$parent_root . '/whisk/patterns/smoke-pattern.json',
		json_encode(
			array(
				'name'        => 'whisk/smoke-pattern',
				'title'       => 'Smoke Pattern',
				'description' => 'Temporary pattern fixture for child-theme generation smoke coverage.',
				'categories'  => array( 'text' ),
				'content'     => '<!-- wp:paragraph --><p>Smoke pattern content</p><!-- /wp:paragraph -->',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);

	if ( ! mkdir( $parent_root . '/whisk/.cache', 0777, true ) ) {
		throw new RuntimeException( 'Could not create ignored cache fixture.' );
	}
	file_put_contents( $parent_root . '/whisk/.cache/sentinel.txt', 'ignored' );

	require_once $repo_root . '/includes/Cli/GenerateChildThemeCommand.php';

	$cli = new Emulsify\Theme\Cli\GenerateChildThemeCommand();

	$cli( array( 'Acme Theme' ), array( 'machine-name' => 'acme-child' ) );

	$destination = $theme_root . '/acme-child';
	$style       = file_get_contents( $destination . '/style.css' );
	$package     = emulsify_cli_smoke_json( $destination . '/package.json' );
	$project     = emulsify_cli_smoke_json( $destination . '/project.emulsify.json' );
	$functions   = file_get_contents( $destination . '/functions.php' );
	$smoke_pattern = emulsify_cli_smoke_json( $destination . '/patterns/smoke-pattern.json' );

	emulsify_cli_smoke_assert( is_dir( $destination ), 'Expected generated child theme directory to exist.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/node_modules' ), 'Generated child theme should not copy node_modules.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/config/acf-json/.gitkeep' ), 'Generated child theme should copy the ACF Local JSON convention directory.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/assets/images/.gitkeep' ), 'Generated child theme should copy the empty theme image asset directory.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/assets/icons/.gitkeep' ), 'Generated child theme should copy the empty theme icon asset directory.' );
	emulsify_cli_smoke_assert( false !== strpos( $style, 'Theme Name: Acme Theme' ), 'style.css should update Theme Name.' );
	emulsify_cli_smoke_assert( false !== strpos( $style, 'Text Domain: acme-child' ), 'style.css should update Text Domain.' );
	emulsify_cli_smoke_assert( false !== strpos( $style, 'Template: emulsify' ), 'style.css should keep the parent Template slug.' );
	emulsify_cli_smoke_assert( 'acme-child' === $package['name'], 'package.json should update name.' );
	emulsify_cli_smoke_assert( 'wordpress' === $project['project']['platform'], 'project.emulsify.json should preserve the WordPress platform adapter.' );
	emulsify_cli_smoke_assert( 'Acme Theme' === $project['project']['name'], 'project.emulsify.json should update project name.' );
	emulsify_cli_smoke_assert( 'acme-child' === $project['project']['machineName'], 'project.emulsify.json should update machineName.' );
	emulsify_cli_smoke_assert( 'emulsify-wordpress' === $project['project']['generatedFrom'], 'project.emulsify.json should identify the generated child theme source.' );
	emulsify_cli_smoke_assert( emulsify_cli_smoke_expected_version() === $project['project']['generatedFromVersion'], 'project.emulsify.json should record the generated child theme source version.' );
	emulsify_cli_smoke_assert( ! file_exists( $destination . '/templates' ), 'Fresh generated child themes should inherit parent templates without a local templates directory.' );
	emulsify_cli_smoke_assert( false !== strpos( $functions, 'Acme Theme child theme hooks.' ), 'functions.php should update visible Whisk label.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/src/components/.gitkeep' ), 'Generated child theme should keep the empty component source placeholder.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/patterns/.gitkeep' ), 'Generated child theme should keep the empty pattern placeholder.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/src/components/button' ), 'Generated child theme should not include the removed starter button component.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/src/editor' ), 'Generated child theme should not include assumed editor enhancement source modules.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/src/foundation' ), 'Generated child theme should not include an assumed foundation source directory.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/src/layout' ), 'Generated child theme should not include an assumed layout source directory.' );
	emulsify_cli_smoke_assert( ! is_file( $destination . '/src/foundation.scss' ), 'Generated child theme should not include an assumed foundation Sass entry.' );
	emulsify_cli_smoke_assert( ! is_file( $destination . '/src/layout.scss' ), 'Generated child theme should not include an assumed layout Sass entry.' );
	emulsify_cli_smoke_assert( ! is_file( $destination . '/src/tokens.scss' ), 'Generated child theme should not include an assumed tokens Sass entry.' );
	emulsify_cli_smoke_assert( ! is_file( $destination . '/theme.json' ), 'Generated child theme should not include an empty child theme.json by default.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/dist' ), 'Generated child theme should not copy ignored build output directories.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/.out' ), 'Generated child theme should not copy ignored Storybook output directories.' );
	emulsify_cli_smoke_assert( ! is_dir( $destination . '/.cache' ), 'Generated child theme should not copy ignored cache directories.' );
	emulsify_cli_smoke_assert( 'acme-child/smoke-pattern' === $smoke_pattern['name'], 'Generated child theme should update copied pattern namespaces when patterns exist.' );
	emulsify_cli_smoke_assert( false !== strpos( $smoke_pattern['content'], 'Smoke pattern content' ), 'Generated child theme should copy optional pattern content when patterns exist.' );

	$hostile_label       = 'Hostile */ echo 1; /* Theme';
	$hostile_destination = $theme_root . '/hostile-child';

	$cli( array( $hostile_label ), array( 'machine-name' => 'hostile-child' ) );

	$hostile_style     = file_get_contents( $hostile_destination . '/style.css' );
	$hostile_functions = file_get_contents( $hostile_destination . '/functions.php' );
	$hostile_project   = emulsify_cli_smoke_json( $hostile_destination . '/project.emulsify.json' );
	$starter_functions = file_get_contents( $parent_root . '/whisk/functions.php' );

	emulsify_cli_smoke_assert( false !== strpos( $hostile_style, 'Theme Name: Hostile echo 1 Theme' ), 'style.css should use a source-safe theme label.' );
	emulsify_cli_smoke_assert( false === strpos( $hostile_style, $hostile_label ), 'style.css should not contain a crafted comment terminator.' );
	emulsify_cli_smoke_assert( false !== strpos( $hostile_functions, 'Hostile echo 1 Theme child theme hooks.' ), 'functions.php should use a source-safe theme label.' );
	emulsify_cli_smoke_assert( false === strpos( $hostile_functions, '*/ echo 1; /*' ), 'functions.php should not contain the attempted docblock breakout.' );
	emulsify_cli_smoke_assert( substr_count( $starter_functions, '*/' ) === substr_count( $hostile_functions, '*/' ), 'functions.php should not gain an extra comment terminator.' );
	emulsify_cli_smoke_assert( $hostile_label === $hostile_project['project']['name'], 'project.emulsify.json should preserve the richer label through JSON encoding.' );
	emulsify_cli_smoke_lint_php( $hostile_destination . '/functions.php' );

	$failed_without_force = false;

	try {
		$cli( array( 'Acme Theme' ), array( 'machine-name' => 'acme-child' ) );
	} catch ( RuntimeException $exception ) {
		$failed_without_force = false !== strpos( $exception->getMessage(), 'Destination already exists' );
	}

	emulsify_cli_smoke_assert( $failed_without_force, 'Existing destination should fail without --force.' );

	file_put_contents( $destination . '/remove-me.txt', 'stale' );

	$cli( array( 'Acme Theme' ), array( 'machine-name' => 'acme-child', 'force' => true ) );

	$replaced_project = emulsify_cli_smoke_json( $destination . '/project.emulsify.json' );

	emulsify_cli_smoke_assert( ! file_exists( $destination . '/remove-me.txt' ), '--force should replace the existing destination.' );
	emulsify_cli_smoke_assert( is_file( $destination . '/project.emulsify.json' ), '--force should replace a generated Emulsify child theme with fresh project metadata.' );
	emulsify_cli_smoke_assert( 'emulsify-wordpress' === $replaced_project['project']['generatedFrom'], '--force should keep generated child theme source metadata.' );
	emulsify_cli_smoke_assert( emulsify_cli_smoke_expected_version() === $replaced_project['project']['generatedFromVersion'], '--force should keep generated child theme source version metadata.' );

	$unrelated_destination = $theme_root . '/unrelated-theme';
	$unrelated_refused     = false;

	if ( ! mkdir( $unrelated_destination, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create unrelated theme fixture: %s', $unrelated_destination ) );
	}

	file_put_contents( $unrelated_destination . '/style.css', "/*\n * Theme Name: Unrelated Theme\n * Template: twentytwentysix\n */\n" );
	file_put_contents( $unrelated_destination . '/keep-me.txt', 'unrelated' );

	try {
		$cli( array( 'Unrelated Theme' ), array( 'machine-name' => 'unrelated-theme', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$unrelated_refused = false !== strpos( $exception->getMessage(), 'Refusing to replace existing destination because it does not look like an Emulsify-generated child theme' );
	}

	emulsify_cli_smoke_assert( $unrelated_refused, '--force should refuse to replace an unrelated theme directory.' );
	emulsify_cli_smoke_assert( is_file( $unrelated_destination . '/keep-me.txt' ), '--force refusal should not delete unrelated theme files.' );

	$foreign_destination = $theme_root . '/foreign-theme';
	$foreign_refused     = false;

	if ( ! mkdir( $foreign_destination, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create foreign generated theme fixture: %s', $foreign_destination ) );
	}

	file_put_contents( $foreign_destination . '/style.css', "/*\n * Theme Name: Foreign Theme\n * Template: emulsify\n */\n" );
	file_put_contents(
		$foreign_destination . '/project.emulsify.json',
		json_encode(
			array(
				'project' => array(
					'platform'             => 'wordpress',
					'name'                 => 'Foreign Theme',
					'machineName'          => 'foreign-theme',
					'generatedFrom'        => 'foreign-generator',
					'generatedFromVersion' => '1.0.0',
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	file_put_contents( $foreign_destination . '/keep-me.txt', 'foreign' );

	try {
		$cli( array( 'Foreign Theme' ), array( 'machine-name' => 'foreign-theme', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$foreign_refused = false !== strpos( $exception->getMessage(), 'generatedFrom is "foreign-generator"' );
	}

	emulsify_cli_smoke_assert( $foreign_refused, '--force should refuse to replace a theme generated by a different source.' );
	emulsify_cli_smoke_assert( is_file( $foreign_destination . '/keep-me.txt' ), '--force generatedFrom refusal should not delete existing theme files.' );

	$missing_lineage_destination = $theme_root . '/missing-lineage';
	$missing_lineage_refused     = false;

	if ( ! mkdir( $missing_lineage_destination, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create missing-lineage theme fixture: %s', $missing_lineage_destination ) );
	}

	file_put_contents( $missing_lineage_destination . '/style.css', "/*\n * Theme Name: Missing Lineage\n * Template: emulsify\n */\n" );
	file_put_contents(
		$missing_lineage_destination . '/project.emulsify.json',
		json_encode(
			array(
				'project' => array(
					'platform'    => 'wordpress',
					'name'        => 'Missing Lineage',
					'machineName' => 'missing-lineage',
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	file_put_contents( $missing_lineage_destination . '/keep-me.txt', 'missing-lineage' );

	try {
		$cli( array( 'Missing Lineage' ), array( 'machine-name' => 'missing-lineage', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$missing_lineage_refused = false !== strpos( $exception->getMessage(), 'missing project.generatedFrom' );
	}

	emulsify_cli_smoke_assert( $missing_lineage_refused, '--force should require generatedFrom lineage metadata.' );
	emulsify_cli_smoke_assert( is_file( $missing_lineage_destination . '/keep-me.txt' ), '--force missing-lineage refusal should not delete existing theme files.' );

	$missing_version_destination = $theme_root . '/missing-version';
	$missing_version_refused     = false;

	if ( ! mkdir( $missing_version_destination, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create missing-version theme fixture: %s', $missing_version_destination ) );
	}

	file_put_contents( $missing_version_destination . '/style.css', "/*\n * Theme Name: Missing Version\n * Template: emulsify\n */\n" );
	file_put_contents(
		$missing_version_destination . '/project.emulsify.json',
		json_encode(
			array(
				'project' => array(
					'platform'      => 'wordpress',
					'name'          => 'Missing Version',
					'machineName'   => 'missing-version',
					'generatedFrom' => 'emulsify-wordpress',
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	file_put_contents( $missing_version_destination . '/keep-me.txt', 'missing-version' );

	try {
		$cli( array( 'Missing Version' ), array( 'machine-name' => 'missing-version', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$missing_version_refused = false !== strpos( $exception->getMessage(), 'missing project.generatedFromVersion' );
	}

	emulsify_cli_smoke_assert( $missing_version_refused, '--force should require generatedFromVersion lineage metadata.' );
	emulsify_cli_smoke_assert( is_file( $missing_version_destination . '/keep-me.txt' ), '--force missing-version refusal should not delete existing theme files.' );

	$mismatch_destination = $theme_root . '/mismatch-theme';
	$mismatch_refused     = false;

	if ( ! mkdir( $mismatch_destination, 0777, true ) ) {
		throw new RuntimeException( sprintf( 'Could not create machine-name mismatch fixture: %s', $mismatch_destination ) );
	}

	file_put_contents( $mismatch_destination . '/style.css', "/*\n * Theme Name: Mismatch Theme\n * Template: emulsify\n */\n" );
	file_put_contents(
		$mismatch_destination . '/project.emulsify.json',
		json_encode(
			array(
				'project' => array(
					'platform'             => 'wordpress',
					'name'                 => 'Mismatch Theme',
					'machineName'          => 'different-machine-name',
					'generatedFrom'        => 'emulsify-wordpress',
					'generatedFromVersion' => emulsify_cli_smoke_expected_version(),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	file_put_contents( $mismatch_destination . '/keep-me.txt', 'mismatch' );

	try {
		$cli( array( 'Mismatch Theme' ), array( 'machine-name' => 'mismatch-theme', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$mismatch_refused = false !== strpos( $exception->getMessage(), 'machineName is "different-machine-name", expected "mismatch-theme"' );
	}

	emulsify_cli_smoke_assert( $mismatch_refused, '--force should require project.machineName to match the requested machine name.' );
	emulsify_cli_smoke_assert( is_file( $mismatch_destination . '/keep-me.txt' ), '--force machine-name refusal should not delete existing theme files.' );

	$rollback_destination = $theme_root . '/rollback-child';
	$rollback_failed      = false;

	$cli( array( 'Rollback Theme' ), array( 'machine-name' => 'rollback-child' ) );
	file_put_contents( $rollback_destination . '/keep-me.txt', 'original-theme' );

	$GLOBALS['emulsify_mkdir_failure'] = static function ( string $target ): bool {
		$normalized = str_replace( '\\', '/', $target );
		return false !== strpos( $normalized, '/rollback-child.tmp-' ) && str_ends_with( $normalized, '/config' );
	};

	try {
		$cli( array( 'Rollback Theme' ), array( 'machine-name' => 'rollback-child', 'force' => true ) );
	} catch ( RuntimeException $exception ) {
		$rollback_failed = false !== strpos( $exception->getMessage(), 'Failed staging child theme' );
	} finally {
		$GLOBALS['emulsify_mkdir_failure'] = null;
	}

	emulsify_cli_smoke_assert( $rollback_failed, 'A mid-copy failure should abort generation.' );
	emulsify_cli_smoke_assert( 'original-theme' === file_get_contents( $rollback_destination . '/keep-me.txt' ), 'A mid-copy failure should leave the original theme intact.' );
	emulsify_cli_smoke_assert( array() === glob( $rollback_destination . '.tmp-*' ), 'A mid-copy failure should remove the partial staging directory.' );
	emulsify_cli_smoke_assert( array() === glob( $rollback_destination . '.bak-*' ), 'A mid-copy failure should not leave a backup directory.' );

	WP_CLI::$messages                       = array();
	$GLOBALS['emulsify_activated_theme']    = null;
	$dry_run_destination                    = $theme_root . '/dry-run-child';

	$cli( array( 'Dry Run Theme' ), array( 'machine-name' => 'dry-run-child' ) );
	file_put_contents( $dry_run_destination . '/keep-me.txt', 'dry-run' );
	WP_CLI::$messages = array();

	$cli( array( 'Dry Run Theme' ), array( 'machine-name' => 'dry-run-child', 'dry-run' => true, 'force' => true, 'activate' => true ) );

	emulsify_cli_smoke_assert( is_file( $dry_run_destination . '/keep-me.txt' ), '--dry-run --force should not delete an existing child theme directory.' );
	emulsify_cli_smoke_assert( null === $GLOBALS['emulsify_activated_theme'], '--dry-run should not activate the child theme.' );
	emulsify_cli_smoke_assert(
		(bool) array_filter(
			WP_CLI::$messages,
			static function ( array $message ): bool {
				return false !== strpos( $message['message'], 'Would replace existing destination because --force was provided' );
			}
		),
		'--dry-run --force should report that it would replace the existing destination.'
	);
	emulsify_cli_smoke_assert(
		(bool) array_filter(
			WP_CLI::$messages,
			static function ( array $message ): bool {
				return false !== strpos( $message['message'], 'Dry run complete' );
			}
		),
		'--dry-run should report completion.'
	);

	$invalid_machine_name_failed = false;

	try {
		$cli( array( 'Invalid Theme' ), array( 'machine-name' => '!!!' ) );
	} catch ( RuntimeException $exception ) {
		$invalid_machine_name_failed = false !== strpos( $exception->getMessage(), 'Machine name cannot be empty' );
	}

	emulsify_cli_smoke_assert( $invalid_machine_name_failed, 'Invalid --machine-name values should fail.' );

	$cli( array( 'Active Theme' ), array( 'machine-name' => 'active-child', 'activate' => true ) );

	emulsify_cli_smoke_assert( 'active-child' === $GLOBALS['emulsify_activated_theme'], '--activate should call switch_theme() with the child slug.' );

	$external_directory = $work_root . '/external-starter-directory';
	mkdir( $external_directory );
	file_put_contents( $external_directory . '/secret.txt', 'outside starter' );
	symlink( $external_directory, $parent_root . '/whisk/linked-directory' );
	symlink( $external_directory . '/secret.txt', $parent_root . '/whisk/linked-file.txt' );
	WP_CLI::$messages = array();
	$cli( array( 'Symlink Theme' ), array( 'machine-name' => 'symlink-child' ) );
	emulsify_cli_smoke_assert( ! file_exists( $theme_root . '/symlink-child/linked-directory' ), 'A symlinked starter directory must be skipped rather than copied as an empty directory.' );
	emulsify_cli_smoke_assert( ! file_exists( $theme_root . '/symlink-child/linked-file.txt' ), 'A symlinked starter file must not copy an external file.' );
	$symlink_warnings = array_filter( WP_CLI::$messages, static fn( array $message ): bool => 'warning' === $message['type'] && str_contains( $message['message'], 'Skipping symlink' ) );
	emulsify_cli_smoke_assert( 2 === count( $symlink_warnings ), 'Each skipped starter symlink must report a warning.' );

	echo "Child theme generator smoke checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	emulsify_cli_smoke_remove( $work_root );
}
