<?php
/**
 * Shared standalone and real WordPress attribute-security regression cases.
 *
 * @package Emulsify
 */

use Emulsify\Theme\Support\AttributeBag;

/**
 * Exercises security boundaries with the same cases in standalone and WordPress checks.
 *
 * @return array<string, array{0:mixed,1:mixed}> Expected and actual values.
 */
function emulsify_attribute_hardening_cases(): array {
	$cases = array();
	foreach ( array( 'href', 'SRC', 'action', 'formaction', 'xlink:href', 'poster', 'data' ) as $name ) {
		foreach ( array( 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'jav&#x61;script:alert(1)', 'javascript&#58;alert(1)', 'javascript&colon;alert(1)', 'jav&#97;script:alert(1)', 'java&#9;script:alert(1)', "java\tscript:alert(1)", "java\nscript:alert(1)", "java\0script:alert(1)", 'javascript:javascript:alert(1)' ) as $url ) {
			$cases[ $name . ' strips ' . json_encode( $url ) ] = array( 'alert(1)', ( new AttributeBag( array( $name => $url ) ) )->toArray()[ $name ] ?? null );
		}
	}

	foreach ( array( '/relative?a=1&b=2', '../next', '#details', 'https://example.com/path', 'mailto:hello@example.com', 'tel:+15555550123' ) as $url ) {
		$cases[ 'keeps URL ' . $url ] = array( $url, ( new AttributeBag( array( 'href' => $url ) ) )->toArray()['href'] ?? null );
	}
	$cases['disallows data URIs under WordPress default protocols'] = array( 'image/png;base64,AA==', ( new AttributeBag( array( 'src' => 'data:image/png;base64,AA==' ) ) )->toArray()['src'] ?? null );

	$unsafe_names = array( 'srcdoc', 'SRCDOC', 'x-on:click', 'X-ON:click', 'hx-on', 'hx-on:click', 'hx-on--after-request', 'data-HX-on:click', 'v-on:click', ':href', 'x-bind:href', 'v-bind:href', 'x-data', 'x-init', 'v-html', 'hx-vals', 'hx-vars', 'hx-headers', 'hx-request', 'hx-trigger', 'data-hx-vals', '@click', 'onclick', 'ONFOCUS' );
	$unsafe                           = array_fill_keys( $unsafe_names, 'alert(1)' );
	$unsafe['data-ok']                 = '1';
	$unsafe['aria-label']              = 'Safe';
	$unsafe['hx-get']                  = '/fragment';
	$unsafe['viewBox']                 = '0 0 10 10';
	$unsafe['preserveAspectRatio']     = 'xMidYMid';
	$set = new AttributeBag();
	foreach ( $unsafe as $name => $value ) {
		$set->set( $name, $value );
	}
	$merge = new AttributeBag();
	$merge->merge( $unsafe );
	foreach ( array( 'constructor' => new AttributeBag( $unsafe ), 'set' => $set, 'merge' => $merge ) as $operation => $bag ) {
		$cases[ $operation . ' rejects executable attribute names, preserves static and SVG names' ] = array(
			array( 'data-ok' => '1', 'aria-label' => 'Safe', 'hx-get' => '/fragment', 'viewBox' => '0 0 10 10', 'preserveAspectRatio' => 'xMidYMid' ),
			$bag->toArray(),
		);
	}
	$cases['class names are normalized and merged case-insensitively'] = array( array( 'class' => array( 'first-bad', 'second', 'third' ) ), ( new AttributeBag( array( 'CLASS' => array( 'first.bad' ), 'class' => 'second', 'ClAsS' => 'third' ) ) )->toArray() );
	$private_post = (object) array( 'post_password' => 'secret', 'post_title' => 'Private' );
	$merged       = new AttributeBag( array( 'data-ok' => '1' ) );
	$merged->merge( $private_post );
	$cases['plain objects never become attributes'] = array( array(), ( new AttributeBag( $private_post ) )->toArray() );
	$cases['merging objects cannot expose their public properties'] = array( array( 'data-ok' => '1' ), $merged->toArray() );
	$cases['Traversables remain supported'] = array( array( 'data-ok' => '1' ), ( new AttributeBag( new ArrayIterator( array( 'data-ok' => '1' ) ) ) )->toArray() );

	return $cases;
}
