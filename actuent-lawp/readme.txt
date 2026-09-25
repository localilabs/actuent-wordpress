=== Actuent LAWP ===
Contributors: localilabs
Tags: ai, ai agents, chatgpt, claude, structured data
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site readable and actionable by AI agents like ChatGPT and Claude.

== Description ==

AI assistants increasingly browse the web for their users, but raw HTML is hard for them to use. Actuent LAWP publishes your site as LAWP (Locali AI Web Protocol): a small, structured description of your site, its pages, and the actions a visitor can take.

* Publishes your site at `/.well-known/lawp.json` automatically, using your site name, tagline and published pages.
* **Search action:** AI agents can search your posts and pages and get titles, links and excerpts back.
* **Contact action:** AI agents can send you a message on their user's behalf. It is emailed to your admin address. Only requests signed by Actuent are accepted, so it can't be used for spam.
* Add your own actions, such as bookings, as JSON.
* Sites with native LAWP rank higher on [Actuent](https://actuent.ai), the search engine for AI agents, and their actions become executable.

Test your setup with the [LAWP Checker](https://docs.actuent.ai/#checker).

== External services ==

This plugin connects to Actuent (https://actuent.ai), made by localilabs:

* To verify that contact requests really come from Actuent, it downloads Actuent's public signing keys from https://agents.actuent.ai/.well-known/actuent-signing-keys.json (cached for an hour). No data about your site or visitors is sent.
* AI agents using Actuent read your `/.well-known/lawp.json` and call your enabled actions.

Actuent privacy policy: https://docs.actuent.ai/privacy — Terms: https://docs.actuent.ai/terms

== Installation ==

1. Upload the `actuent-lawp` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to Settings → Actuent to choose which actions to enable.
4. Visit `https://yoursite.com/.well-known/lawp.json` to see what AI agents see.

== Frequently Asked Questions ==

= Does this send my content anywhere? =

No. It publishes a public file on your own site, the same way robots.txt or a sitemap works. AI agents fetch it from your site.

= Can I stop AI agents from contacting me? =

Yes. Untick "Let AI agents send you messages" in Settings → Actuent.

= My /.well-known/lawp.json shows a 404 =

Some hosts serve `/.well-known/` themselves. Ask your host to pass requests for `/.well-known/lawp.json` to WordPress.

== Changelog ==

= 1.1.0 =
* The contact action now asks agents for a name, email address and message (LAWP 0.3 structured input), and replies go straight to the sender. Plain-text messages from older agents still work.

= 1.0.0 =
* First release: lawp.json, search and contact actions, custom actions, settings page.
