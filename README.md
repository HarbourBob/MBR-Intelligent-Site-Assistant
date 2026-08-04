<div align="center">

# MBR Intelligent Site Assistant

### A self-hosted conversational site search for WordPress.

**No external APIs · No monthly fees · No visitor data leaves your server**

[![Version](https://img.shields.io/badge/version-0.8.2-blue.svg)](https://littlewebshack.com)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net)
[![WP-CLI](https://img.shields.io/badge/WP--CLI-supported-7a7a7a.svg)](https://wp-cli.org)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)
[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/robertpalmer)

</div>

---

A WordPress chat widget that answers visitor questions in plain English using your own published content — no language model, no third-party API, no per-query billing. Pure PHP, BM25 ranking, passage-level indexing, intent matching, synonym expansion, and a conversational UI scoped entirely to your server.

> Most "AI chatbot for WordPress" plugins are wrappers around someone else's API. Every visitor question gets sent to OpenAI, Anthropic, or one of the dozen newer entrants. You pay per query. Your visitors' words leave your server.
>
> This plugin does the opposite.

## Why this exists

Site search on WordPress is, by default, not great. The built-in search ranks by date, doesn't understand synonyms, and treats "WP" and "WordPress" as different words. The mainstream alternative is to bolt on a hosted AI service that solves the linguistics but introduces a recurring bill, a dependency, and a privacy footnote.

There's a middle path: a small, local search engine that handles the linguistics properly without leaving the box. BM25 (the algorithm Wikipedia uses) for ranking. A Porter stemmer so "running" matches "ran". Synonym groups so "shop" finds "store". Intent matching so common questions get pre-written answers without a search at all. And a conversational widget on top, because that is what visitors expect now.

That's what this plugin is.

## Features

**Search and matching**
- BM25 ranking with title / excerpt / body field weighting, each field length-normalised against its own average
- Passage-level chunking — long documents are split into overlapping windows and ranked passage by passage, then collapsed to one result per document
- Porter stemmer with English stopword filtering
- Configurable synonym expansion ("WP" finds "WordPress", "shop" finds "store")
- Intent matching for canned answers to common questions
- Confidence scoring (high / medium / low / none) drives the framing message
- Substring and regex triggers for intents
- Snippets built from the passage that actually matched, windowed around the highlighted term

**Content sources**
- Published posts and pages out of the box
- Any public custom post type, tickable from the admin — no code, and types appear automatically once registered
- **PDF indexing** — extracts the text layer from PDFs in your Media Library so their contents are searchable alongside posts and pages, with results linking straight to the file
- Pure-PHP extraction (FlateDecode, ASCII85, ASCIIHex) with no external libraries, binaries, or services
- Contents pages detected and demoted, so the chapter that answers a question outranks the list of chapter titles

**Widget**
- Floating chat bubble or inline shortcode embed
- Both modes can run simultaneously, with shared session storage
- Five colour-coordinated themes (Mocha, Slate Light, Ocean, Sunset, Forest)
- Optional glassmorphism effect — translucent panel with backdrop blur
- HTML responses for intents (links, bold, italic, line breaks, lists)
- Conditional asset loading — JS and CSS only enqueued where the widget actually appears

**Admin and operations**
- Diagnostic dashboard with index status, tokeniser tester, search tester, full-pipeline chat tester
- Dedicated admin pages for managing intents, synonyms, and appearance
- Content Sources panel for post types, PDF indexing, and file size limits
- Per-intent disabled toggle for A/B testing without losing configuration
- Live theme preview rendered with the actual widget CSS over an animated gradient stage
- Seven-day query log with thumbs-up / thumbs-down feedback
- Recent-queries list highlighting zero-result and thumbs-down queries
- **WP-CLI commands** for reindexing and status checks, free of web-request time and memory limits
- Self-hosted automatic updates through the normal WordPress Plugins screen

**Privacy**
- No outbound HTTP requests on the visitor path
- IP addresses hashed with SHA-256 + WordPress salt before storage
- Per-IP-hash sliding window rate limiting on REST endpoints
- Public REST API under `mbr-isa/v1` namespace, nonce-protected from the widget
- Clean uninstall — drops all custom tables and options unless you explicitly opt to keep data

## What it isn't

This isn't a large language model, and it does not generate text. It retrieves and surfaces content you have already written, and answers a configurable list of canned questions. If a visitor asks about something not on your site, it will politely say it could not find anything and invite them to rephrase.

That is a deliberate trade-off. The plugin produces predictable, auditable responses with no hallucination risk — and it does it without sending your visitors' questions to an outside service. If you need a generative assistant that can free-associate beyond your content, this is not the plugin for you.

PDF indexing follows the same principle: it reads the text already inside your PDFs. It performs no OCR on scanned images, and it does not decrypt protected files.

## Quick start

1. Download the latest ZIP from [littlewebshack.com](https://littlewebshack.com).
2. In your WordPress admin: **Plugins → Add New → Upload Plugin**, select the ZIP, install, and activate.
3. Go to **MBR Site Assistant → Diagnostics** and click **Run Full Reindex**. This reads your published posts and pages and builds the search index.
4. Use the **Chat Tester** at the bottom of the page to confirm the pipeline works end-to-end.
5. Tick **Floating widget** under **Widget Settings** and save. The chat bubble will now appear site-wide.

That's the minimum. From there:

- Open **Content Sources** to add custom post types, or tick **Index PDF files** to make your PDFs searchable. Reindex afterwards so existing content is picked up.
- Click **Manage intents →** to write canned answers for common questions.
- Click **Manage synonyms →** to fix any vocabulary mismatches between your content and how visitors describe it.
- Click **Appearance →** to pick a theme and (optionally) enable the glass effect.

The full [user guide](docs/mbr-intelligent-site-assistant-user-guide.pdf) covers every section in detail.

## Command line

If your host provides SSH with WP-CLI, two commands are available:

```bash
# Rebuild the index — no max_execution_time, no PHP-FPM memory ceiling
wp mbr-isa reindex

# Version, schema, index counts, PDF state, last full reindex
wp mbr-isa status
```

`wp mbr-isa reindex` is the recommended route on large sites and large PDF libraries, where the admin button can hit a gateway timeout. Both commands accept the standard global flags, so `--url=` works on multisite and aliases work for remote servers.

## PDF indexing

Tick **Index PDF files** under **Content Sources** and reindex. From then on PDFs are indexed on upload, re-indexed when the underlying file is replaced, and removed from the index when deleted.

Extraction is entirely local — pure PHP using only the standard zlib extension. It handles the common stream encodings and reconstructs readable text including word spacing in justified documents and accented or non-Latin characters. Media Library metadata (title, caption, description, alt text) is folded in as well.

Worth knowing:

- **Text layer required.** Born-digital PDFs extract reliably. Files built with heavily subsetted fonts lacking a Unicode mapping may yield partial text.
- **No OCR.** Scanned or image-only PDFs contain no text layer. Such files fall back to whatever Media Library metadata exists — so give them descriptive titles.
- **Encrypted PDFs are skipped.** They are not decrypted or indexed.
- **Size cap.** Files above the configured maximum (default 20 MB) are skipped, to protect memory on shared hosting.

## Themes

Five colour-coordinated presets, switchable from the admin without touching CSS:

| Preset | Kind | Best for |
|---|---|---|
| **Mocha** | Dark | Default. Catppuccin Mocha — deep purple with mauve accents. Suits design-led and dark-mode sites. |
| **Slate Light** | Light | Clean neutral greys with indigo accent. Works on almost any site. |
| **Ocean** | Light | Soft light blues with teal accent. Friendly and calm — good for service businesses, healthcare, education. |
| **Sunset** | Light | Warm peach base with coral accents. Distinctive — suits hospitality, food, lifestyle. |
| **Forest** | Dark | Deep green with sage accent. The dark counterpart to Mocha for sites that want dark but not purple. |

Glassmorphism layers over any preset, producing a frosted-glass look on light themes and a smoked-glass look on dark themes. Best on pages with rich backgrounds (hero images, gradients) where the backdrop blur has something interesting to act on.

## How it works

Each query runs through six stages:

1. **Intent match.** The raw query is checked against configured intent triggers (substring or regex). A match short-circuits the rest of the pipeline and returns the intent's canned response.
2. **Tokenisation.** Lowercase, strip punctuation, split on whitespace, drop English stopwords, stem with Porter.
3. **Synonym expansion.** Each stem is looked up in the synonym map; matching terms add their siblings.
4. **BM25 ranking.** The expanded token list is scored against the inverted index, with title matches weighted higher than body matches. Each passage chunk is scored as its own document.
5. **Collapse.** Chunks are reduced to the best-scoring passage per document, so the same page never appears twice.
6. **Formatting.** Confidence is classified, a framing message is chosen, and a snippet is built around the matched terms within the winning passage.

### Passage chunking

Indexing a thirty-page PDF as a single document doesn't work well: one relevant paragraph is diluted across thousands of unrelated words, and the snippet always comes from the start of the file rather than the part that matched.

Instead, long content is split into overlapping ~250-word windows. Each window is indexed and ranked as its own BM25 unit, so a relevant passage competes on its own merits, and the snippet is drawn from the passage that caused the match. Short pages and posts stay a single chunk and are unaffected. Chunk size and overlap are tunable via `chunk_size_words` and `chunk_overlap_words`.

The index is built via the **Run Full Reindex** button (or `wp mbr-isa reindex`) and kept fresh automatically on `save_post`, `deleted_post`, and `trashed_post`, plus the attachment hooks when PDF indexing is enabled. Revisions and autosaves are ignored.

## Privacy

The plugin makes **zero outbound HTTP requests** on the visitor path. This is a core design constraint, not a setting. Every query is answered from data on your own server. PDF text is extracted locally too, in pure PHP, with no cloud OCR or extraction service.

What's stored:
- An inverted index of your post content and extracted PDF text (derived from content you've already published)
- The passage text for each chunk, so snippets can be built without re-reading the source file
- A query log: query text, matched intent, top score, result count, optional feedback, session ID, timestamp, and a SHA-256 hash of the requester's IP

What's never stored:
- Raw IP addresses
- Visitor identities, accounts, emails, or cookies
- Anything sent to a third party

The one exception to "no outbound requests" is the update checker, which performs a periodic server-side version lookup against a public GitHub manifest and downloads the package from littlewebshack.com when you choose to update. It transmits no visitor data, no site content, and no identifiers. If you'd rather have no outbound requests at all, remove the bundled update checker and update manually.

If you delete the plugin, all four custom tables and every option are dropped by default. To preserve your data across a reinstall, set `mbr_isa_keep_data_on_uninstall` to a truthy value before deleting.

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later
- Standard MySQL or MariaDB
- zlib and mbstring for PDF indexing — both ship with virtually every PHP install
- No outbound network access
- No API keys

## REST API

Two public endpoints under the `mbr-isa/v1` namespace:

- `POST /wp-json/mbr-isa/v1/ask` — accepts `{ query, session_id? }`, returns the structured chat response (intent hit or search results, with confidence, snippets, suggestions)
- `POST /wp-json/mbr-isa/v1/feedback` — records a thumbs-up (`1`), thumbs-down (`-1`), or neutral (`0`) against a previously-logged `query_id`

Both endpoints are public (no auth) but nonce-protected when called from the widget, and rate-limited per IP-hash. Results describe documents rather than chunks — collapse happens before the response is built. Full request and response shapes are in the [user guide](docs/mbr-intelligent-site-assistant-user-guide.pdf).

> **Note for sites running REST hardening plugins:** if you have a "disable REST API for non-admin / logged-out users" setting enabled (common in security and performance plugins), allowlist the `mbr-isa/v1` namespace or the chat widget will be blocked at the REST layer.

## Upgrading

Version 0.8.x introduces database schema v3. The migration runs automatically on the first admin page load after updating — nothing is deleted, and existing rows keep working.

**Run a full reindex afterwards.** Content indexed under an earlier version remains as single-row documents; chunking only applies to content indexed under 0.8.x. On a large site, `wp mbr-isa reindex` is the tidiest way to do it.

If the DB schema version under **Diagnostics → Plugin Status** still reads below 3 after loading an admin page, deactivate and reactivate the plugin to run the activator directly. On hosts with OPcache and `validate_timestamps=0`, flush OPcache after uploading plugin files.

## Documentation

The full [user guide (PDF)](docs/mbr-intelligent-site-assistant-user-guide.pdf) covers everything in this README in greater depth, plus:

- The diagnostic dashboard, section by section
- Every admin page, field by field
- Indexing behaviour, PDF extraction limits, and passage chunking in detail
- The WP-CLI commands
- The full REST API reference with request and response shapes
- All settings keys with defaults and purpose
- The complete file layout
- A troubleshooting chapter

## Support

This plugin is free and open source. If it has saved you time, a small donation is very welcome:

[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-FFDD00?logo=buymeacoffee&logoColor=black&style=for-the-badge)](https://buymeacoffee.com/robertpalmer)

For bug reports, feature suggestions, or support questions, open an issue on this repository or get in touch through [littlewebshack.com](https://littlewebshack.com).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full terms.

## Author

Built and maintained by **Robert Palmer** — freelance WordPress developer at [Little Web Shack](https://littlewebshack.com) and [Made by Robert](https://madebyrobert.co.uk). Distributed independently of WordPress.org.

---

<div align="center">

If you're already using this plugin and have feedback to share, [open an issue](../../issues) — I read every one.

</div>
