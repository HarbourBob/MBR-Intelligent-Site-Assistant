=== MBR Intelligent Site Assistant ===
Contributors: Robert Palmer
Tags: search, chatbot, ai, site assistant, intelligent search
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted conversational site search. No external APIs, no monthly fees, no data leaves your server.

== Description ==

MBR Intelligent Site Assistant gives your WordPress site a conversational search widget. Uses BM25 ranking with intent matching, synonym expansion, and a chat-style interface.

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

= 0.6.0 =
* New: Tools > MBR ISA Appearance admin page for choosing the chat-widget colour scheme without touching CSS.
  - Five colour-coordinated presets: Mocha (default Catppuccin dark), Slate Light, Ocean, Sunset, Forest.
  - Glassmorphism toggle layers on top of any preset — translucent panel with a backdrop blur, designed for sites with rich page backgrounds (hero images, gradients).
  - Live interactive preview rendered with the actual widget CSS over a moving gradient stage, so the preview communicates exactly how the widget will look on the front end.
  - Preset cards show the palette as colour swatches alongside the name and a dark/light badge.
* Improved: Diagnostic page gains a third quick-access button alongside intents and synonyms.

= 0.5.1 =
* Improved: Synonym test panel now shows the original (unstemmed) word in brackets next to each token chip when it differs from the stem, so admins don't mistake "websit (website)" for a typo. Visitors never see the stemmed form — it only surfaces in the admin tester.

= 0.5.0 =
* New: Tools > MBR ISA Synonyms admin page for managing synonym groups without editing code.
  - One form per group, mirroring the intents admin UX.
  - Add, edit, delete groups; reset to defaults.
  - Test panel: type a query and see which extra tokens get added by synonym expansion (with the stemmed form shown so admins can see what the index actually uses).
  - Both newline-separated and comma-separated input accepted on save.
* Improved: Diagnostic page now has quick-access buttons for both intents and synonyms managers.

= 0.4.0 =
* New: Tools > MBR ISA Intents admin page for managing intents without editing code.
  - One form per intent so an in-progress edit can never lose another's edits.
  - Add, edit, delete, and disable/enable intents.
  - Per-intent enabled/disabled toggle (keep an intent in the list without firing it).
  - Test panel: type a query and see which intent (if any) would match.
  - Reset to defaults button.
  - Inline regex validation for "re:" triggers — bad patterns rejected on save.
* New: Intent responses now support basic HTML (links, bold, line breaks, lists, etc.).
  Sanitised on save with wp_kses_post(). The widget renders message_html when present
  and falls back to plain-text message otherwise.
* Improved: Diagnostic page now links to the new intents manager.

= 0.1.0 =
* Initial development release — bootstrap, database schema, tokeniser with Porter stemmer.