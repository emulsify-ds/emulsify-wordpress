<?php
/**
 * Adds optional privacy and native click-to-load behavior to video oEmbeds.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Media;

/**
 * Alters recognized players when content is rendered, outside the oEmbed cache.
 */
final class Embeds {

	/** Vimeo thumbnail transient prefix. */
	public const METADATA_PREFIX = 'emulsify_vimeo_poster_';

	/**
	 * Registers rendering and deferred metadata hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'embed_oembed_html', array( $this, 'filter_html' ), 20, 4 );
		add_filter( 'oembed_dataparse', array( $this, 'cache_oembed_data' ), 20, 3 );
		add_action( 'emulsify_theme_refresh_vimeo_poster', array( $this, 'refresh_vimeo_poster' ) );
	}

	/**
	 * Changes only supported, complete player iframes.
	 *
	 * @param mixed $html    Cached provider HTML; non-strings pass through unchanged.
	 * @param mixed $url     Original embed URL, normalized to a string.
	 * @param mixed $attr    Embed attributes, normalized to an array.
	 * @param mixed $post_id Positive post ID or numeric string, otherwise null.
	 * @return mixed Rendered embed HTML, or the unchanged non-string input.
	 * @throws \RuntimeException When click-to-load rendering requires missing Timber.
	 * @throws \UnexpectedValueException When the poster template renders no document.
	 */
	public function filter_html( $html, $url = '', $attr = array(), $post_id = 0 ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$url     = is_string( $url ) ? $url : '';
		$attr    = is_array( $attr ) ? $attr : array();
		$post_id = is_string( $post_id ) ? filter_var( ctype_digit( $post_id ) ? ltrim( $post_id, '0' ) : $post_id, FILTER_VALIDATE_INT ) : $post_id;
		$post_id = is_int( $post_id ) && $post_id > 0 ? $post_id : null;
		$options = array(
			'privacy_mode'  => false,
			'click_to_load' => false,
			'poster'        => 'provider',
		);
		/**
		 * Filters opt-in media embed behavior without changing cached provider HTML.
		 *
		 * @param array    $options Media options: privacy_mode, click_to_load, poster.
		 * @param string   $url     Original embed URL.
		 * @param array    $attr    Embed attributes.
		 * @param int|null $post_id Positive post ID, or null outside a post.
		 */
		$filtered = apply_filters( 'emulsify_theme_media_embed_options', $options, $url, $attr, $post_id );
		if ( is_array( $filtered ) ) {
			$options = array_merge( $options, $filtered );
		}
		if ( ! $options['privacy_mode'] && ! $options['click_to_load'] ) {
			return $html;
		}

		$processor = $this->player_iframe( $html );
		if ( null === $processor ) {
			return $html;
		}
		$source = $processor->get_attribute( 'src' );
		$player = is_string( $source ) ? $this->player( $source ) : null;
		if ( null === $player ) {
			return $html;
		}

		$player_url = $source;
		if ( $options['privacy_mode'] ) {
			$player_url = 'youtube' === $player['provider']
				? preg_replace( '#^https://[^/?\#]+#i', 'https://www.youtube-nocookie.com', $source )
				: $this->set_parameter( $source, 'dnt' );
			$processor->set_attribute( 'src', $player_url );
		}
		if ( $options['click_to_load'] ) {
			if ( ! class_exists( '\Timber\Timber' ) ) {
				throw new \RuntimeException( 'Timber is required to render click-to-load media embeds.' );
			}
			$metadata  = array();
			$thumbnail = '';
			if ( 'neutral' !== $options['poster'] ) {
				if ( 'youtube' === $player['provider'] ) {
					$thumbnail = 'https://i.ytimg.com/vi/' . $player['id'] . '/hqdefault.jpg';
				} else {
					$metadata  = $this->vimeo_metadata( $source, $player['id'] );
					$thumbnail = $metadata['thumbnail_url'] ?? '';
				}
			}
			$title     = $processor->get_attribute( 'title' );
			$has_title = is_string( $title ) && '' !== trim( $title );
			$title     = $has_title ? $title : ( $metadata['title'] ?? '' );
			if ( '' === trim( $title ) ) {
				$title = 'youtube' === $player['provider'] ? __( 'YouTube video', 'emulsify' ) : __( 'Vimeo video', 'emulsify' );
			}
			$language = get_bloginfo( 'language' );
			$context  = array(
				'media_embed' => array(
					'player_url'         => $this->set_parameter( $player_url, 'autoplay' ),
					'title'              => $title,
					'provider'           => 'youtube' === $player['provider'] ? 'YouTube' : 'Vimeo',
					'thumbnail_url'      => $thumbnail,
					'language_code'      => '' !== $language ? $language : 'en',
					'language_direction' => is_rtl() ? 'rtl' : 'ltr',
					'play_label'         => __( 'Play video', 'emulsify' ),
				),
			);
			$document = \Timber\Timber::compile( '@templates/partials/oembed-poster.twig', $context );
			if ( ! is_string( $document ) || '' === trim( $document ) ) {
				throw new \UnexpectedValueException( 'The media embed poster template must render a non-empty HTML document.' );
			}
			// Without src, browsers fall back to about:blank even without srcdoc support.
			$processor->remove_attribute( 'src' );
			$processor->set_attribute( 'srcdoc', $this->srcdoc_value( $document ) );
			if ( ! $has_title ) {
				$processor->set_attribute( 'title', $title );
			}
		}
		$this->player_permissions( $processor );
		return $processor->get_updated_html();
	}

	/**
	 * Preserves nested document entities across old and new HTML API escaping.
	 *
	 * WordPress 6.8 uses esc_attr(), which avoids double-encoding. Newer releases
	 * correctly escape every ampersand. Probe once rather than assuming a version
	 * so backports retain the same safe, exactly-once outer attribute boundary.
	 *
	 * @param string $document Twig-rendered HTML document.
	 * @return string Value for the srcdoc setter.
	 */
	private function srcdoc_value( string $document ): string {
		static $escapes_entities = null;
		if ( null === $escapes_entities ) {
			$probe = new \WP_HTML_Tag_Processor( '<iframe></iframe>' );
			$probe->next_tag();
			$probe->set_attribute( 'srcdoc', '&amp;' );
			$reader = new \WP_HTML_Tag_Processor( $probe->get_updated_html() );
			$reader->next_tag();
			$escapes_entities = '&amp;' === $reader->get_attribute( 'srcdoc' );
		}
		return $escapes_entities ? $document : htmlspecialchars( $document, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	}

	/**
	 * Saves metadata from WordPress' own provider fetch; no extra request is made.
	 *
	 * @param string|false $html Provider HTML, or false for an unsupported response.
	 * @param object       $data Provider oEmbed data.
	 * @param string       $url  Original embed URL.
	 * @return string|false Unchanged provider HTML for WordPress' cache.
	 */
	public function cache_oembed_data( string|false $html, $data, string $url ): string|false {
		if ( false === $html ) {
			return false;
		}
		$processor = $this->player_iframe( $html );
		$source    = null !== $processor ? $processor->get_attribute( 'src' ) : null;
		$player    = is_string( $source ) ? $this->player( $source ) : null;
		if ( null !== $player && 'vimeo' === $player['provider'] && is_object( $data ) ) {
			$canonical = $this->vimeo_url( $source, $player['id'] );
			if ( '' !== $canonical ) {
				$this->save_metadata( $canonical, $data );
			}
		}
		return $html;
	}

	/**
	 * Refreshes Vimeo metadata on WP-Cron, never on the content-rendering path.
	 *
	 * @param string $url Validated canonical Vimeo URL.
	 * @return void
	 */
	public function refresh_vimeo_poster( string $url ): void {
		if ( 1 !== preg_match( '#^https://vimeo\.com/[0-9]+(?:/[A-Za-z0-9]+)?$#D', $url ) ) {
			return;
		}
		// Cache a miss before fetching, so failures and overlapping requests back off.
		set_transient( self::METADATA_PREFIX . md5( $url ), array(), HOUR_IN_SECONDS );
		$data = _wp_oembed_get_object()->get_data( $url, array( 'discover' => false ) );
		if ( is_object( $data ) ) {
			$this->save_metadata( $url, $data );
		}
	}

	/**
	 * Parses an entire, empty iframe without repairing malformed/compound HTML.
	 *
	 * @param string $html Provider markup.
	 * @return \WP_HTML_Tag_Processor|null Positioned processor or null.
	 */
	private function player_iframe( string $html ): ?\WP_HTML_Tag_Processor {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || str_contains( $html, "\0" ) ) {
			return null;
		}
		$attribute = '[\t\n\f\r ]+([A-Za-z_:][A-Za-z0-9_.:-]*)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?';
		if ( 1 !== preg_match( '~\A[\t\n\f\r ]*<iframe((?:' . $attribute . ')*)[\t\n\f\r ]*>[\t\n\f\r ]*</iframe[\t\n\f\r ]*>[\t\n\f\r ]*\z~i', $html, $matches ) ) {
			return null;
		}
		preg_match_all( '~' . $attribute . '~', $matches[1], $attributes );
		$names = array_map( 'strtolower', $attributes[1] );
		if ( count( $names ) !== count( array_unique( $names ) ) ) {
			return null;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'tag_name' => 'IFRAME' ) ) || $processor->has_self_closing_flag()
			|| null !== $processor->get_attribute( 'srcdoc' ) || null !== $processor->get_attribute( 'sandbox' ) ) {
			return null;
		}
		return $processor;
	}

	/**
	 * Recognizes only HTTPS player hosts and provider-specific ID paths.
	 *
	 * @param string $source Player URL.
	 * @return array|null Provider and ID, or null.
	 */
	private function player( string $source ): ?array {
		$parts = wp_parse_url( $source );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] )
			|| preg_match( '/[\x00-\x20\x7f\\\\]/', $source ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		if ( in_array( $host, array( 'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com' ), true )
			&& 1 === preg_match( '#^/embed/([A-Za-z0-9_-]+)$#D', $path, $matches ) ) {
			return array(
				'provider' => 'youtube',
				'id'       => $matches[1],
			);
		}
		if ( 'player.vimeo.com' === $host && 1 === preg_match( '#^/video/([0-9]+)$#D', $path, $matches ) ) {
			return array(
				'provider' => 'vimeo',
				'id'       => $matches[1],
			);
		}
		return null;
	}

	/**
	 * Replaces one query key while preserving unrelated query bytes and fragments.
	 *
	 * @param string $url  Player URL.
	 * @param string $name Parameter name.
	 * @return string URL with the named parameter set to 1.
	 */
	private function set_parameter( string $url, string $name ): string {
		$fragment = explode( '#', $url, 2 );
		$query    = explode( '?', $fragment[0], 2 );
		$pairs    = isset( $query[1] ) && '' !== $query[1] ? explode( '&', $query[1] ) : array();
		$replaced = false;
		foreach ( $pairs as &$pair ) {
			if ( rawurldecode( explode( '=', $pair, 2 )[0] ) === $name ) {
				$pair     = $name . '=1';
				$replaced = true;
			}
		}
		unset( $pair );
		if ( ! $replaced ) {
			$pairs[] = $name . '=1';
		}
		return $query[0] . '?' . implode( '&', $pairs ) . ( isset( $fragment[1] ) ? '#' . $fragment[1] : '' );
	}

	/**
	 * Delegates player permissions after the srcdoc frame navigates cross-origin.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Positioned iframe processor.
	 * @return void
	 */
	private function player_permissions( \WP_HTML_Tag_Processor $processor ): void {
		$permissions = array( 'autoplay', 'fullscreen', 'picture-in-picture', 'encrypted-media' );
		$existing    = $processor->get_attribute( 'allow' );
		$directives  = array();
		foreach ( explode( ';', is_string( $existing ) ? $existing : '' ) as $directive ) {
			$directive = trim( $directive );
			if ( '' !== $directive && ! in_array( preg_split( '/\s+/', $directive )[0], $permissions, true ) ) {
				$directives[] = $directive;
			}
		}
		$origins = "'src' https://www.youtube.com https://youtube.com https://www.youtube-nocookie.com https://player.vimeo.com";
		foreach ( $permissions as $permission ) {
			$directives[] = $permission . ' ' . $origins;
		}
		$processor->set_attribute( 'allow', implode( '; ', $directives ) );
		$processor->set_attribute( 'allowfullscreen', true );
	}

	/**
	 * Derives a safe metadata URL, retaining an unlisted video's access hash.
	 *
	 * @param string $source Player URL.
	 * @param string $id     Numeric video ID.
	 * @return string Canonical Vimeo URL, or empty for an unsupported hash.
	 */
	private function vimeo_url( string $source, string $id ): string {
		$url   = 'https://vimeo.com/' . $id;
		$query = wp_parse_url( $source, PHP_URL_QUERY );
		foreach ( explode( '&', is_string( $query ) ? $query : '' ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( 'h' === rawurldecode( $parts[0] ) ) {
				$hash = rawurldecode( $parts[1] ?? '' );
				return 1 === preg_match( '/^[A-Za-z0-9]+$/D', $hash ) ? $url . '/' . $hash : '';
			}
		}
		return $url;
	}

	/**
	 * Reads cached metadata, scheduling a refresh and returning immediately on miss.
	 *
	 * @param string $source Player URL.
	 * @param string $id     Numeric video ID.
	 * @return array Cached metadata or an empty array for a neutral poster.
	 */
	private function vimeo_metadata( string $source, string $id ): array {
		$url = $this->vimeo_url( $source, $id );
		if ( '' === $url ) {
			return array();
		}
		$metadata = get_transient( self::METADATA_PREFIX . md5( $url ) );
		if ( is_array( $metadata ) ) {
			return $metadata;
		}
		if ( ! wp_next_scheduled( 'emulsify_theme_refresh_vimeo_poster', array( $url ) ) ) {
			wp_schedule_single_event( time(), 'emulsify_theme_refresh_vimeo_poster', array( $url ) );
		}
		return array();
	}

	/**
	 * Caches safe provider metadata and backs off from absent thumbnails.
	 *
	 * @param string $url  Canonical Vimeo URL.
	 * @param object $data Provider data.
	 * @return void
	 */
	private function save_metadata( string $url, object $data ): void {
		$metadata  = array();
		$candidate = isset( $data->thumbnail_url ) && is_string( $data->thumbnail_url ) ? $data->thumbnail_url : '';
		$parts     = wp_parse_url( $candidate );
		if ( is_array( $parts ) && 'https' === strtolower( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! preg_match( '/[\x00-\x20\x7f\\\\]/', $candidate ) ) {
			$metadata['thumbnail_url'] = $candidate;
			$metadata['title']         = isset( $data->title ) && is_string( $data->title ) ? $data->title : '';
		}
		set_transient( self::METADATA_PREFIX . md5( $url ), $metadata, empty( $metadata ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
	}
}
