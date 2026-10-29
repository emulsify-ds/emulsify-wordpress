# Media embeds

The parent theme can apply privacy mode and a click-to-load poster to WordPress
YouTube and Vimeo oEmbeds. Both options are off by default, so existing embeds
keep their original HTML. Other providers, unsupported player URLs, and
malformed embed HTML are left untouched.
Embeds with an existing `srcdoc` or `sandbox` attribute also remain unchanged,
preserving another integration's document and restrictions.

## Options

Use the `emulsify_theme_media_embed_options` filter in the child theme or a
project plugin. There is no settings screen:

```php
add_filter(
	'emulsify_theme_media_embed_options',
	function ( array $options ): array {
		$options['privacy_mode'] = true;
		$options['click_to_load'] = true;
		$options['poster'] = 'neutral';

		return $options;
	}
);
```

The filter also receives the original `$url`, embed attributes `$attr`, and
`$post_id`; register the callback with four accepted arguments to use them.
The post ID is a positive `int`, or `null` outside a post (for example, an embed
rendered outside the loop). Non-string URLs normalize to `''`, and non-array
attributes normalize to `array()`. String post IDs must contain only decimal
digits and fit a positive PHP integer; whitespace and a leading `+` are rejected.

| Option | Default | Behavior |
| --- | --- | --- |
| `privacy_mode` | `false` | Uses YouTube's `www.youtube-nocookie.com` player host and adds Vimeo's `dnt=1`. Other parameters remain intact, including Vimeo's `h` for unlisted videos. |
| `click_to_load` | `false` | Replaces the player with a native **Play video** link that opens the player inside the same frame with `autoplay=1`, without JavaScript. |
| `poster` | `'provider'` | Uses a provider thumbnail when available; `'neutral'` omits third-party images. This option takes effect only when click to load is enabled. |

Privacy mode by itself still loads the player when the page is viewed. Click to
load prevents a visitor's browser from requesting the player before activation.
Provider posters still contact the thumbnail host before activation, revealing
the visitor's network address. Their images use native lazy loading and async
decoding, but the browser decides when to fetch them; lazy loading is not a
privacy guarantee. Choose a neutral poster to avoid that image request too.
The provider receives data once the player loads, and its cookies, consent
behavior, and browser autoplay restrictions still apply.

The parent transforms the `embed_oembed_html` output at render time, after
WordPress retrieves its cached oEmbed HTML. This covers classic-content embeds
and `core/embed` blocks. Changing these options does not require flushing the
oEmbed cache. Hand-written iframe HTML that bypasses WordPress oEmbed is outside
this filter.

YouTube posters use the video's provider thumbnail; playlist players use a
neutral poster because `videoseries` is not a video ID. Vimeo posters use
cached `thumbnail_url` metadata captured from WordPress oEmbed responses only
when click to load and provider posters are enabled. For that metadata capture,
the options filter receives the original URL, `array()` attributes, and a
`null` post ID; filters that depend on a post must handle this context too.

On a Vimeo cache miss, rendering immediately falls back to a neutral poster
and schedules a background WP-Cron lookup; it does not wait for Vimeo.
Successful metadata is cached for one day. A separate one-hour retry marker
backs off failed or overlapping lookups without replacing valid cached
metadata with an empty entry. If WP-Cron is disabled or delayed, the neutral
poster still provides a working play link. These server-side metadata requests
do not expose the visitor's browser to the provider.
WordPress still performs its normal oEmbed lookup when its HTML cache is cold.

## Poster behavior and overrides

The poster is a complete iframe `srcdoc` document rendered through
`@templates/partials/oembed-poster.twig`. A child can add
`templates/partials/oembed-poster.twig` to replace that document. To extend the
document, copy and adapt the fallback, or refer to its parent copy through
`@emulsify-tpl/partials/oembed-poster.twig`; using the same `@templates` path
from the override would resolve back to itself. See the
[template namespace contract](template-extension.md).

The template receives a `media_embed` map:

| Variable | Contents |
| --- | --- |
| `player_url` | Validated player URL with `autoplay=1` and the selected privacy behavior. |
| `title` | Video title from the iframe or cached Vimeo metadata, falling back to a translated provider label; used in the document and hidden link label. |
| `provider` | `YouTube` or `Vimeo`. |
| `thumbnail_url` | HTTPS image URL, or an empty string for a neutral poster. |
| `language_code` | WordPress locale used for the poster's translated strings, formatted for the document's `lang`. |
| `language_direction` | `rtl` or `ltr` for that locale, used for the document's `dir`. |
| `play_label` | Translated `Play video` text. |

Language and direction are computed for each render and follow WordPress locale
switching with `switch_to_locale()`. Poster overrides must keep both `lang` and
`dir` on the document's `<html>` element.

The fallback supplies its own inline CSS inside the frame. It adds no stylesheet
to the surrounding page. Its translated play link has a visible focus ring, a
visually hidden video title, and a document language and direction. Controls
become compact in frames 160 pixels high or shorter. Keep these accessibility
details and Twig autoescaping in an override; provider titles and URLs are not
trusted HTML.
The parent preserves the rendered document exactly across supported WordPress
HTML API versions, including nested entities inside single-quoted attributes.
Keep autoescaping enabled even with that outer `srcdoc` boundary.

If Timber is missing, an override is empty or invalid, or poster rendering
throws, the embed becomes an escaped plain link to the original video with its
title as the link text. It contains no iframe or thumbnail, so it makes no
provider request before the visitor follows it. The parent reports the failure
through WordPress's `_doing_it_wrong()` diagnostic action. With `WP_DEBUG` off,
the request continues; with `WP_DEBUG` on, WordPress also warns and the original
exception is rethrown so developers can fix the override. This behavior applies
to embed filtering in frontend, REST, and editor content rendering. The theme's
separate missing-Timber frontend error page still applies; this safeguard keeps
admin and REST requests from failing inside the embed filter too.

The link uses `referrerpolicy="strict-origin-when-cross-origin"` so the player
receives the site's origin without its page path or query. Thumbnail images use
`referrerpolicy="no-referrer"`. The containing iframe permits autoplay,
fullscreen, picture-in-picture, and encrypted media for the recognized player
origins, including after the poster navigates the frame. Other provider
permissions without an explicit origin list receive the same origins;
directives with an explicit list keep it. Activation adds an iframe navigation
to browser history; the first Back returns to the poster.

## Content Security Policy

A `srcdoc` document inherits the embedding page's Content Security Policy, as
described by the [CSP inheritance rules](https://www.w3.org/TR/CSP/).
Deployments must account for both the poster and the player:

- Allow the selected YouTube player host in `frame-src`:
  `https://www.youtube-nocookie.com` with privacy mode, or
  `https://www.youtube.com` without it. Allow `https://player.vimeo.com` for
  Vimeo. Existing accepted `https://youtube.com` embeds also need that origin
  when privacy mode is off. The initial blank/`srcdoc` poster does not require
  adding `'self'` to `frame-src`.
- Provider posters need their thumbnail hosts in `img-src`, typically
  `https://i.ytimg.com` for YouTube and the host of Vimeo's cached
  `thumbnail_url`. Neutral posters need no thumbnail host.
- The fallback's inline `<style>` must be allowed by the inherited `style-src`
  policy. A site can allow the exact CSS hash, or override the poster template
  to use a matching site nonce or an allowed stylesheet. A restrictive policy
  does not automatically exempt styles just because they are inside `srcdoc`.

The fallback includes a `<meta http-equiv="Content-Security-Policy"
content="script-src 'none'">` before its content. The poster needs no scripts;
this extra policy blocks scripts and inline event handlers in that document.
Keep it near the start of `<head>` in child overrides. It adds to the inherited
policy and applies only to the poster document; navigating to the provider
loads a new document and does not carry this meta policy into the player.

Browser verification used `style-src 'self' 'unsafe-inline'` to authorize the
fallback's inline CSS. Projects can instead use its exact CSS hash or a template
override with their existing nonce; `'unsafe-inline'` is not required by the
feature.

Test the deployed policy before enabling click to load. The parent leaves
iframes with an existing `sandbox` or `srcdoc` unchanged, so those embeds keep
the other integration's behavior instead of receiving this poster.

## Verification

With browser network tools open, check a YouTube and a Vimeo embed, including an
unlisted Vimeo video. Before activation, expect no player request; with neutral
posters, expect no provider image request either. Tab to **Play video**, activate
it with Enter, check playback and fullscreen, then use Back to return to the
poster. Confirm the hidden title distinguishes the two links for a screen
reader, and check the controls in 200 × 113 and 320 × 180 frames. Browser or
provider policies can still require another click inside the player.
