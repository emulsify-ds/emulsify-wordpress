<?php
/**
 * Provides an on-demand WordPress logout URL.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Keeps nonce creation out of context that never uses the URL.
 */
final class LogoutUrl implements \Stringable, \JsonSerializable {

	/**
	 * Computed URL, including its nonce.
	 *
	 * @var string|null
	 */
	private ?string $url = null;

	/**
	 * Gets the logout URL when requested by a template.
	 *
	 * @return string Logout URL.
	 */
	public function __toString(): string {
		return $this->url ??= wp_logout_url();
	}

	/**
	 * Preserves nonce-sensitive values in Timber's output cache keys.
	 *
	 * @return string Logout URL.
	 */
	public function jsonSerialize(): string {
		return (string) $this;
	}
}
