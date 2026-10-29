<?php
/**
 * Inspect actual DOM text and attribute values in the WordPress HTTP fixture.
 *
 * @package Emulsify
 */

$fixture = json_decode( file_get_contents( 'php://stdin' ), true, 512, JSON_THROW_ON_ERROR );
$document = new DOMDocument();
libxml_use_internal_errors( true );
$document->loadHTML( '<?xml encoding="UTF-8">' . $fixture['html'] );
libxml_clear_errors();
$xpath = new DOMXPath( $document );

foreach ( $fixture['assertions'] as $assertion ) {
	$nodes = $xpath->query( $assertion['xpath'] );
	if ( false === $nodes || 0 === $nodes->length ) {
		throw new RuntimeException( $fixture['name'] . ': missing ' . $assertion['xpath'] );
	}
	foreach ( $nodes as $node ) {
		$actual = isset( $assertion['attribute'] ) ? $node->getAttribute( $assertion['attribute'] ) : trim( $node->textContent );
		if ( isset( $assertion['contains'] ) ) {
			$matches = str_contains( $actual, $assertion['contains'] );
		} else {
			$matches = $actual === $assertion['text'];
		}
		if ( ! $matches ) {
			throw new RuntimeException( $fixture['name'] . ': ' . $assertion['xpath'] . ' visible value was ' . json_encode( $actual ) . '; expected ' . json_encode( $assertion['text'] ?? $assertion['contains'] ) );
		}
		if ( preg_match( '/&(?:amp|quot|lt|gt|#\d+|#x[0-9a-f]+);/i', $actual ) ) {
			throw new RuntimeException( $fixture['name'] . ': literal HTML entity in DOM value.' );
		}
		$allowed = $assertion['elements'] ?? array();
		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if ( ! in_array( strtolower( $element->tagName ), $allowed, true ) ) {
				throw new RuntimeException( $fixture['name'] . ': unexpected ' . $element->tagName . ' inside ' . $assertion['xpath'] );
			}
			foreach ( $element->attributes as $attribute ) {
				if ( str_starts_with( strtolower( $attribute->name ), 'on' ) ) {
					throw new RuntimeException( $fixture['name'] . ': executable event attribute survived.' );
				}
				if ( in_array( strtolower( $attribute->name ), array( 'src', 'href' ), true ) && preg_match( '/^\s*(?:javascript|vbscript|data):/i', $attribute->value ) ) {
					throw new RuntimeException( $fixture['name'] . ': unsafe URL protocol survived.' );
				}
			}
		}
		if ( isset( $assertion['elementCount'] ) && $node->getElementsByTagName( '*' )->length !== $assertion['elementCount'] ) {
			throw new RuntimeException( $fixture['name'] . ': safe inline title markup was lost.' );
		}
		if ( isset( $assertion['attribute'] ) ) {
			foreach ( $node->attributes as $attribute ) {
				if ( ! in_array( $attribute->name, array( 'src', 'alt' ), true ) ) {
					throw new RuntimeException( $fixture['name'] . ': image attribute breakout: ' . $attribute->name );
				}
			}
		}
	}
}

echo "DOM text and attributes passed.\n";
