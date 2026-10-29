<?php
/**
 * Coordinates block integrations.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Blocks;

use Emulsify\Theme\Support\AssetManifest;

/**
 * Registers optional block integrations.
 */
final class Registry {

	/**
	 * Asset manifest reader.
	 *
	 * @var AssetManifest
	 */
	private $manifest;

	/**
	 * Shared component metadata index.
	 *
	 * @var ComponentLocator
	 */
	private $components;

	/**
	 * Constructor.
	 *
	 * @param AssetManifest|null    $manifest   Asset manifest reader.
	 * @param ComponentLocator|null $components Shared component metadata index.
	 */
	public function __construct( ?AssetManifest $manifest = null, ?ComponentLocator $components = null ) {
		$this->manifest   = $manifest ?? new AssetManifest();
		$this->components = $components ?? new ComponentLocator();
	}

	/**
	 * Registers block integrations.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'switch_theme',
			static function (): void {
				ComponentLocator::clear_discovery_cache();
			}
		);

		// Share one locator so ACF/Twig and native block registration use the same
		// request-local child-first component index and duplicate diagnostics.
		( new AcfBlocks( $this->components, $this->manifest ) )->register();
		( new NativeBlocks( $this->components ) )->register();
	}
}
