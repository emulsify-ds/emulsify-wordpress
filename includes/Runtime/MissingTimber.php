<?php
/**
 * Provides clear errors when Timber is unavailable.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Runtime;

/**
 * Missing Timber error handling.
 */
final class MissingTimber {

	/**
	 * Registers admin and runtime notices.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'template_redirect', array( $this, 'runtime_error' ), 0 );
	}

	/**
	 * Displays an admin notice for users who can act on the dependency.
	 *
	 * @return void
	 */
	public function admin_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $this->message() )
		);
	}

	/**
	 * Stops frontend rendering before Timber templates are loaded.
	 *
	 * @return void
	 */
	public function runtime_error(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
			return;
		}

		if ( is_robots() || is_favicon() || is_feed() || is_trackback() || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
			return;
		}

		wp_die(
			wp_kses_post(
				sprintf(
					'<p>%s</p><p><code>%s</code></p>',
					$this->message(),
					'composer require timber/timber'
				)
			),
			esc_html__( 'Timber is required', 'emulsify' ),
			array( 'response' => 500 )
		);
	}

	/**
	 * Gets the dependency error message.
	 *
	 * @return string Error message.
	 */
	private function message(): string {
		return __( 'Emulsify requires Timber to render WordPress templates. Install Timber with Composer or activate the Timber plugin.', 'emulsify' );
	}
}
