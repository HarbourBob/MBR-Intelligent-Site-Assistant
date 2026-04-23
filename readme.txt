=== MBR Intelligent Site Assistant ===
Contributors: Robert Palmer
Tags: search, chatbot, ai, site assistant, intelligent search
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted conversational site search. No external APIs, no monthly fees, no data leaves your server.

== Description ==

MBR Intelligent Site Assistant gives your WordPress site a conversational search widget. Uses BM25 ranking with intent matching, synonym expansion, and a chat-style interface.  Blisteringly fast!

Key features:

* Self-hosted — no API keys, no external services, nothing leaves your server.
* Works on every WordPress host — pure PHP, no unusual extensions needed.
* Intent matching for common questions (contact, pricing, services).
* Synonym expansion so "WP" finds "WordPress".
* Porter stemming so "building" matches "build".
* Query log and feedback for tuning.
* Catppuccin Mocha dark UI.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through the 'Plugins' menu in WordPress.
3. Go to Tools > MBR ISA Diagnostic to verify installation.

== Changelog ==

= 0.1.0 =
* Initial development release — bootstrap, database schema, tokeniser with Porter stemmer.