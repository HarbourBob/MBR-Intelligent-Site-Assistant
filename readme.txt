=== MBR Intelligent Site Assistant ===
Contributors: Robert Palmer, alkesh7
Tags: search, chatbot, ai, site assistant, intelligent search
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted conversational site search. No external APIs, no monthly fees, no data leaves your server.

== Description ==

MBR Intelligent Site Assistant gives your WordPress site a conversational search widget. Uses BM25 ranking with intent matching, synonym expansion, and a chat-style interface.

A comprehensive User Guide (PDF) is bundled in the ZIP file.

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
3. Go to MBR Site Assistant → MBR ISA Diagnostics to verify installation.

== Third-Party Services ==

The plugin makes zero outbound HTTP requests on the visitor path — every query is answered from data already on your own server, and PDF text extraction runs locally in pure PHP.

The one exception is the bundled update checker, which performs a periodic version lookup against a public GitHub manifest (raw.githubusercontent.com/HarbourBob/mbr-updates) and downloads the update package from littlewebshack.com only when you choose to install an update. It transmits no visitor data, no site content, and no identifiers. If you would rather have no outbound requests at all, remove the bundled `plugin-update-checker` directory and update the plugin manually.

== Changelog ==

= 0.8.2 =
* Security: Hardened several `wp_unslash()`/sanitisation gaps flagged by a WPCS audit (`widget_position`, `REMOTE_ADDR` reads used for rate limiting and query logging). No known exploitability — these were defence-in-depth fixes, not active vulnerabilities.
* Fixed: The bundled update checker's `require_once` had no existence check, so a checkout or package missing the `plugin-update-checker` library directory would fatal on activation. Self-update registration is now skipped gracefully when the library is absent.
* Fixed: A literal `—` escape (inside a single-quoted string, where PHP does not expand it) was printed as-is in a WP-CLI reindex error message instead of an em dash.
* Fixed: Garbled `readme.txt` characters (double-encoded UTF-8 em dashes and an arrow) in the Description and Installation sections.
* Changed: `includes/class-mbr-isa-cli.php` renamed to `includes/class-mbr-isa-cli-command.php` to match its class name, per WordPress Coding Standards file-naming rules.
* Changed: Several previously-untranslated admin/REST strings ("Unauthorised", REST API error messages) now go through `__()`/`esc_html__()` with the `mbr-isa` text domain, and four `sprintf()` placeholders that were missing `translators:` comments now have them.
* Changed: Added missing PHP class-level doc comments and PHPDoc summaries across the codebase for readability.
* Note: This release is a WordPress Plugin Check (PCP) / WPCS compliance and hardening pass. No functional or database changes; no reindex required.

= 0.8.1 =
* Fixed: Search snippets from PDFs could be filled with rows of dots and stray page numbers, because table-of-contents leader runs were extracted as if they were text. Leader runs (dots, middots, bullets, dashes, underscores) and the page numbers they strand are now removed during extraction. This also stops them inflating a passage's length for BM25.
* Added: Contents pages are detected and demoted in ranking. A table of contents lists every heading in a document, which makes it the densest concentration of topic vocabulary in the file and a poor answer to any question. Such passages are scored at 40% so they surface only when nothing better matches, rather than being excluded — a visitor searching for a section title can still find it. Detection requires a run of ascending numbered entries with little connective prose, so numbered instructions and data tables are not affected. Tunable via `contents_score_penalty` in the settings option.
* Fixed: Markup could leak into page results as text — SVG icon attributes such as `fill="none" stroke="currentColor"` appearing in snippets, and words like `stroke` and `viewBox` becoming searchable terms. Passage chunking split the raw HTML, so a chunk boundary falling inside a tag left the following chunk starting mid-attribute with no opening angle bracket for strip_tags() to match. Content is now converted to plain text before it is chunked. Affects pages and posts; PDF text was never affected.
* Changed: Database schema version 3. The documents table gains an `is_contents` column. If you installed a pre-release 0.8.0 build, the upgrade runs on your next admin page load; run a full reindex afterwards.
* Improved: A full reindex now reports the number of documents and chunks actually written to the index, not the number of items attempted, and warns loudly if any failed. Previously a schema problem could make every write fail while the reindex still reported success.
* Added: WP-CLI support. `wp mbr-isa reindex` runs the full reindex from the command line, free of web-request time and memory limits — the recommended route for very large sites and large PDF libraries. `wp mbr-isa status` prints plugin version, schema version, index counts (documents, chunks, terms, postings), PDF indexing state, and the last full reindex time.
* Note: The CLI class is only loaded when WP-CLI is running; nothing changes on ordinary web requests.

= 0.8.0 =
* Added: Passage-level chunk indexing. Long documents — indexed PDFs especially — are now split into overlapping ~250-word chunks, each indexed and ranked as its own BM25 unit, then collapsed to one result per document at query time. A relevant passage deep inside a 30-page PDF now competes on equal terms with a short page, instead of being diluted across the whole document.
* Improved: Search snippets are now built from the specific passage that matched, with the snippet windowed around the first highlighted term — so the snippet shows *why* a result matched rather than just how the document begins.
* Added: `chunks` count in the index status alongside `documents` (which continues to mean distinct posts/pages/PDFs).
* Added: Settings keys `chunk_size_words` (default 250) and `chunk_overlap_words` (default 50) in the `mbr_isa_settings` option for tuning; the defaults suit most sites.
* Changed: Database schema v2 — the documents table gains a `chunk_index` column, its unique key becomes `(post_id, chunk_index)`, and the stored excerpt now holds the full passage (up to 2,000 chars) for snippet building. The upgrade runs automatically on the first admin page load after updating.
* IMPORTANT: Run a full reindex after updating — existing single-row documents keep working, but chunking only applies to content indexed under 0.8.0.

= 0.7.1 =
* Fixed: PDF extraction could emit binary garbage into the index and search snippets. Embedded font programs (FontFile/FontFile2 streams) are Flate-compressed just like page content, inflate cleanly to binary, and could contain the bytes "Tj" by coincidence — passing the content-stream check and being parsed as text. Decoded streams must now look like text before they reach the parser, and the Tj/TJ check requires the operator in showing position (after a string or array close) rather than as a raw substring.
* Fixed: Long documents — large indexed PDFs especially — were unfairly buried in search results. BM25 length normalisation was applied per field but against the *total* document length, so a short title match on a long PDF inherited the length penalty of its entire body and could not outrank short pages. Each field is now normalised against its own length and its own corpus average (per-field lengths are derived from the existing postings data — no reindex required).
* Added: The Search Tester trace now reports the average field length per field, so the per-field normalisation can be inspected.
* Note: After updating, run a full reindex if any previously-indexed PDF showed garbled snippets, so its stored excerpt is rebuilt. The ranking fix itself needs no reindex.

= 0.7.0 =
* Added: Optional PDF indexing. The assistant can now extract and index the text layer of PDF files from the Media Library, so their contents are searchable alongside posts and pages. Enable it under MBR Site Assistant → Diagnostics → Content Sources, then run a full reindex.
* Added: Pure-PHP PDF text extractor — no external libraries, binaries, or services. Handles FlateDecode, ASCII85 and ASCIIHex streams. Encrypted PDFs are skipped; scanned/image-only PDFs with no text layer fall back to Media Library metadata (title, caption, description, alt text).
* Added: "Maximum PDF size" setting (default 20 MB) to protect memory on shared hosting.
* Note: Search results for PDFs link directly to the file.
* Added: Post type selection in the Content Sources panel. Custom post types now appear as checkboxes as soon as they are registered, so enabling them no longer requires editing the settings option in code. Run a full reindex after changing the selection.
* Note: Only publicly-viewable post types are offered. Results are returned through a public endpoint that performs no capability check, so private or internal post types are deliberately not listed. Post types that are enabled but no longer registered (for example because their plugin is inactive) are shown separately and kept until you explicitly remove them.

= 0.6.2 =
* Changed: Consolidated the four separate Tools menu entries (Intents, Synonyms, Diagnostic, Appearance) into a single top-level "MBR Site Assistant" admin menu with native submenus. The Tools menu is now tidier and the four screens are grouped together in the WordPress sidebar under their own icon.
* Changed: "MBR ISA Diagnostic" renamed to "MBR ISA Diagnostics" for consistency with the other submenu labels.
* Note: Any bookmarks pointing to `tools.php?page=mbr-isa-*` will need updating to `admin.php?page=mbr-isa-*`.

= 0.6.1 =
* Fix: Links inside chat message bubbles (intent responses with HTML) now have explicit theme-aware styling, using the active preset's --mbr-isa-blue accent. Previously they inherited the host theme's link colour, which caused unreadable white-on-light-background links on the Slate Light, Ocean, and Sunset presets.
* Improved: Appearance admin save notice now includes a heads-up about caching layers (page caches, CDN caches, browser cache) since theme changes are commonly masked by stale CSS bundles.
* Improved: Live preview on the Appearance page now includes a sample link in its bot message, so admins can verify link colour readability per theme without needing to visit the front end.

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