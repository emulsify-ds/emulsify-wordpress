<?php
/**
 * Loads and starts the Emulsify theme runtime.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme;

use Emulsify\Theme\Acf\LocalJson;
use Emulsify\Theme\Blocks\CoreBlockTwigRenderer;
use Emulsify\Theme\Blocks\Patterns;
use Emulsify\Theme\Cli\GenerateChildThemeCommand;
use Emulsify\Theme\Editor\Enhancements;
use Emulsify\Theme\Editor\Policy;
use Emulsify\Theme\Media\Embeds;
use Emulsify\Theme\Runtime\Assets;
use Emulsify\Theme\Runtime\Context;
use Emulsify\Theme\Runtime\MissingTimber;
use Emulsify\Theme\Runtime\Setup;
use Emulsify\Theme\Runtime\TimberIntegration;
use Emulsify\Theme\Runtime\Twig;
use Emulsify\Theme\Support\AssetManifest;

/**
 * Coordinates theme services.
 */
final class Bootstrap {

	/**
	 * Absolute path to the parent theme directory.
	 *
	 * @var string
	 */
	private $theme_dir;

	/**
	 * Starts the theme runtime.
	 *
	 * @param string $theme_dir Absolute path to the parent theme directory.
	 * @return void
	 */
	public static function init( string $theme_dir ): void {
		$bootstrap = new self( $theme_dir );
		$bootstrap->register();
	}

	/**
	 * Constructor.
	 *
	 * @param string $theme_dir Absolute path to the parent theme directory.
	 */
	private function __construct( string $theme_dir ) {
		$this->theme_dir = rtrim( $theme_dir, '/\\' );
	}

	/**
	 * Registers all theme services.
	 *
	 * @return void
	 */
	private function register(): void {
		$this->load_vendor_autoload();
		$this->load_classes();

		$asset_manifest = new AssetManifest();
		$components     = new Blocks\ComponentLocator();

		// These services use WordPress APIs directly and must stay available even
		// when Timber is missing. The MissingTimber service owns frontend failure
		// handling later in this method.
		( new Setup() )->register();
		( new Assets( $asset_manifest, $components ) )->register();
		( new LocalJson() )->register();
		( new CoreBlockTwigRenderer() )->register();
		( new Enhancements( $asset_manifest ) )->register();
		( new Policy() )->register();
		( new Patterns() )->register();
		( new Blocks\Registry( $asset_manifest, $components ) )->register();
		( new GenerateChildThemeCommand() )->register();
		( new Embeds() )->register();

		$timber = new TimberIntegration();

		if ( $timber->register() ) {
			// Timber-dependent services are registered only after Timber has
			// initialized, which keeps admin, CLI, and non-template requests usable
			// in partially installed environments.
			( new Context() )->register();
			( new Twig() )->register();
			return;
		}

		( new MissingTimber() )->register();
	}

	/**
	 * Loads Composer dependencies when the theme was installed with Composer.
	 *
	 * @return void
	 */
	private function load_vendor_autoload(): void {
		$autoload = $this->theme_dir . '/vendor/autoload.php';

		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		}
	}

	/**
	 * Loads theme service classes.
	 *
	 * @return void
	 */
	private function load_classes(): void {
		spl_autoload_register(
			function ( string $class ): void {
				$this->autoload_runtime_class( $class );
			}
		);
	}

	/**
	 * Loads runtime classes when Composer autoloading is unavailable.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	private function autoload_runtime_class( string $class ): void {
		$file = $this->runtime_class_file( $class );

		if ( null !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Resolves runtime class files for PSR-4 paths.
	 *
	 * @param string $class Fully qualified class name.
	 * @return string|null Runtime class file path, or null for another namespace.
	 */
	private function runtime_class_file( string $class ): ?string {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return null;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', '/', $relative_class );

		return $this->theme_dir . '/includes/' . $relative_path . '.php';
	}
}
