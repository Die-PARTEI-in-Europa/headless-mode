=== parteieuropa.eu - Headless WordPress Manager ===
Contributors: parteieuropa
Tags: headless, rest api, decoupled, preview, gutenberg
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into a headless CMS: disable the front end, rewrite every view link to your decoupled front end, and add a signed preview endpoint.

== Description ==

Headless WordPress Manager makes it comfortable to run WordPress as a pure content back end behind a decoupled front end (React, Vue, Next, Symfony …). It keeps the editor experience intact while making sure every "view" leads to your real site, not the disabled theme.

Everything is configurable from **Settings → Headless WP** — there are no hard-coded URLs or post types.

Features (each can be toggled on or off):

* **Disable front end** — block theme requests and run in headless mode.
* **Rewrite view links** — permalinks, the admin "View" action and the block-editor preview button all point at your front end.
* **Preview REST endpoint** — `GET /wp-json/headless/v1/preview?token=…` returns rendered content (including unsaved autosave changes) behind a deterministic HMAC token.
* **Frontend URL column & meta box** — see and open the front-end URL of any entry.
* **Disable page REST cache** — send no-cache headers for `/wp/v2/pages` so stale block output is never served.

Configurable URLs:

* Front-end base URL.
* Post path prefix (e.g. `blog` → `/blog/my-post`).
* Preview path (defaults to `preview`).
* Raw-HTML post types — post types whose preview returns stored HTML untouched instead of running the block renderer.

= Consuming the API =

This plugin pairs with the **[parteieuropa/wordpress-api](https://github.com/Die-PARTEI-in-Europa/wordpress-api)** PHP SDK, which wraps the WordPress REST API (including this plugin's preview endpoint) in a typed, friendly client. You can of course use any HTTP client or framework.

== Installation ==

1. Upload the `headless-wp-plugin` folder to `/wp-content/plugins/`, or install it through the Plugins screen.
2. Activate the plugin.
3. Go to **Settings → Headless WP**, enter your front-end URL and enable the features you need.

== Frequently Asked Questions ==

= Does it change or delete my content? =

No. It only changes where links point and how the front end behaves. Your posts, pages and blocks are untouched.

= How does the preview work? =

When you click "Preview" in the editor, the plugin generates a deterministic token (HMAC of post + user, stored in a short-lived transient) and sends you to `<frontend>/<preview-path>?preview_token=…`. Your front end then calls the preview REST endpoint with that token to fetch the rendered draft.

= Can I use it without disabling the front end? =

Yes. Turn off "Disable front end" and keep only the link rewriting and preview endpoint, or any combination you like.

== Changelog ==

= 1.0.0 =
* Initial public release. Settings screen with feature toggles and configurable URLs; front-end disable; link rewriting; token preview REST endpoint; front-end URL column and meta box; page REST cache headers.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
