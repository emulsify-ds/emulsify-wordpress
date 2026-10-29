<?php
/**
 * Resolves differential fixtures through the parent's actual WordPress loader.
 *
 * @package Emulsify
 */

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/vendor/autoload.php';

function get_stylesheet_directory(): string {
	return $GLOBALS['component_contract_child'];
}

function get_template_directory(): string {
	return $GLOBALS['component_contract_parent'];
}

function apply_filters( string $hook, $value, ...$arguments ) {
	if ( 'emulsify_theme_project_component_roots' === $hook && is_string( $GLOBALS['component_contract_root_override'] ?? null ) ) {
		return array( $GLOBALS['component_contract_root_override'] );
	}
	return $value;
}

$fixtures = json_decode( file_get_contents( 'php://stdin' ), true, 512, JSON_THROW_ON_ERROR );
$results  = array();
foreach ( $fixtures as $fixture ) {
	$GLOBALS['component_contract_child']  = $fixture['child'];
	$GLOBALS['component_contract_parent'] = $fixture['parent'];
	$GLOBALS['component_contract_root_override'] = $fixture['componentRootOverride'] ?? null;
	$loader = new \Twig\Loader\FilesystemLoader();
	foreach ( $fixture['namespaces'] as $namespace => $path ) {
		$loader->addPath( $path, $namespace );
	}
	$loader = ( new \Emulsify\Theme\Runtime\Twig() )->loader_paths( $loader );
	$resolved = array();
	foreach ( $fixture['references'] as $reference ) {
		try {
			$source = $loader->getSourceContext( $reference );
			$resolved[ $reference ] = realpath( $source->getPath() ) ?: $source->getPath();
			$cache_key = $loader->getCacheKey( $reference );
			if ( ! $loader->exists( $reference ) || ! $loader->isFresh( $reference, time() + 1 ) || ( realpath( $cache_key ) ?: $cache_key ) !== $resolved[ $reference ] ) {
				throw new RuntimeException( 'Loader APIs disagree for ' . $reference );
			}
		} catch ( \Twig\Error\LoaderError $exception ) {
			$resolved[ $reference ] = null;
		}
	}
	$results[ $fixture['id'] ] = $resolved;
}

echo json_encode( $results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
