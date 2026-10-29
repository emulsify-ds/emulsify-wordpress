<?php
/**
 * Smoke checks for parent theme runtime class loading.
 *
 * @package Emulsify
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

/**
 * Fails the smoke script when an assertion is false.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function emulsify_bootstrap_loader_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Runs a PHP snippet in a fresh process.
 *
 * @param string $code PHP code without an opening tag.
 * @return void
 */
function emulsify_bootstrap_loader_run_php( string $code ): void {
	$script = tempnam( sys_get_temp_dir(), 'emulsify-bootstrap-loader-' );

	emulsify_bootstrap_loader_assert( false !== $script, 'Could not create temporary PHP smoke script.' );
	emulsify_bootstrap_loader_assert( false !== file_put_contents( $script, "<?php\n" . $code ), 'Could not write temporary PHP smoke script.' );

	$output = array();
	$status = 0;

	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );
	unlink( $script );

	if ( 0 !== $status ) {
		throw new RuntimeException( implode( "\n", $output ) );
	}
}

$repo_root = dirname( __DIR__, 2 );
$autoload  = $repo_root . '/vendor/autoload.php';
$classes   = array(
	'Emulsify\\Theme\\Bootstrap',
	'Emulsify\\Theme\\Runtime\\Setup',
	'Emulsify\\Theme\\Runtime\\Assets',
	'Emulsify\\Theme\\Runtime\\Context',
	'Emulsify\\Theme\\Runtime\\Twig',
	'Emulsify\\Theme\\Runtime\\TimberIntegration',
	'Emulsify\\Theme\\Runtime\\MissingTimber',
	'Emulsify\\Theme\\Blocks\\Registry',
	'Emulsify\\Theme\\Blocks\\ComponentLocator',
	'Emulsify\\Theme\\Blocks\\AcfBlocks',
	'Emulsify\\Theme\\Blocks\\NativeBlocks',
	'Emulsify\\Theme\\Blocks\\CoreBlockTwigRenderer',
	'Emulsify\\Theme\\Blocks\\Patterns',
	'Emulsify\\Theme\\Editor\\AllowedBlockTypes',
	'Emulsify\\Theme\\Editor\\BlockNames',
	'Emulsify\\Theme\\Editor\\BlockSupportOverrides',
	'Emulsify\\Theme\\Editor\\Enhancements',
	'Emulsify\\Theme\\Editor\\PatternGovernance',
	'Emulsify\\Theme\\Editor\\Policy',
	'Emulsify\\Theme\\Editor\\PolicyOptions',
	'Emulsify\\Theme\\Editor\\UserPatternPermissions',
	'Emulsify\\Theme\\Acf\\LocalJson',
	'Emulsify\\Theme\\Cli\\GenerateChildThemeCommand',
	'Emulsify\\Theme\\Support\\AttributeBag',
	'Emulsify\\Theme\\Support\\AssetRecord',
	'Emulsify\\Theme\\Support\\LogoutUrl',
	'Emulsify\\Theme\\Support\\AssetEnqueuer',
	'Emulsify\\Theme\\Support\\Diagnostics',
	'Emulsify\\Theme\\Support\\AssetManifest',
	'Emulsify\\Theme\\Support\\FileDiscovery',
	'Emulsify\\Theme\\Support\\ProjectConfig',
);

emulsify_bootstrap_loader_assert( is_readable( $autoload ), 'Run composer install or composer dump-autoload before the Bootstrap loader smoke test.' );

$classes_export = var_export( $classes, true );
$repo_export    = var_export( $repo_root, true );

emulsify_bootstrap_loader_run_php(
	<<<PHP
\$repo_root = {$repo_export};
\$classes = {$classes_export};
require_once \$repo_root . '/vendor/autoload.php';

foreach ( \$classes as \$class ) {
	if ( ! class_exists( \$class, true ) ) {
		throw new RuntimeException( sprintf( 'Composer autoload did not load %s.', \$class ) );
	}
}
PHP
);

emulsify_bootstrap_loader_run_php(
	<<<PHP
\$repo_root = {$repo_export};
\$classes = {$classes_export};
require_once \$repo_root . '/includes/Bootstrap.php';

\$reflection = new ReflectionClass( 'Emulsify\\\\Theme\\\\Bootstrap' );
\$bootstrap = \$reflection->newInstanceWithoutConstructor();
\$theme_dir = \$reflection->getProperty( 'theme_dir' );
\$theme_dir->setAccessible( true );
\$theme_dir->setValue( \$bootstrap, \$repo_root );
\$load_classes = \$reflection->getMethod( 'load_classes' );
\$load_classes->setAccessible( true );
\$load_classes->invoke( \$bootstrap );

foreach ( \$classes as \$class ) {
	if ( ! class_exists( \$class, true ) ) {
		throw new RuntimeException( sprintf( 'Fallback loader did not load %s.', \$class ) );
	}
}
PHP
);

echo "Bootstrap loader smoke checks passed.\n";
