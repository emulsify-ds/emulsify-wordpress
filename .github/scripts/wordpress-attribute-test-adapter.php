<?php
/**
 * Standalone adapters for WordPress's URL-attribute APIs.
 *
 * These cover the shared protocol test vectors without bootstrapping WordPress.
 * attribute-hardening-wordpress-smoke.php repeats those vectors against the real
 * core functions; this adapter is not a replacement for the WordPress fixture.
 *
 * @package Emulsify
 */

if ( ! function_exists( 'wp_allowed_protocols' ) ) {
	function wp_allowed_protocols(): array {
		return array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes(): array {
		return array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' );
	}
}

if ( ! function_exists( 'wp_kses_bad_protocol' ) ) {
	function wp_kses_bad_protocol( string $content, array $allowed_protocols ): string {
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );
		for ( $iteration = 0; $iteration < 6; ++$iteration ) {
			$parts = preg_split( '/:|&#0*58;?|&#x0*3a;?|&colon;/i', $content, 2 );
			if ( count( $parts ) < 2 || preg_match( '%/\?%', $parts[0] ) ) {
				return $content;
			}
			$scheme = strtolower( preg_replace( '/\s/', '', html_entity_decode( $parts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( in_array( $scheme, $allowed_protocols, true ) ) {
				return $scheme . ':' . trim( $parts[1] );
			}
			$content = trim( $parts[1] );
		}
		return '';
	}
}
