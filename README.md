# MBR Intelligent Site Assistant

A self-hosted, conversational site search widget for WordPress. No external APIs, no monthly fees, nothing leaves your server.

![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-4c9a2a)
![Version 0.3.2](https://img.shields.io/badge/version-0.3.2-8839ef)

---

## What it is

A WordPress plugin that adds a chat-style search widget to your site. Visitors type questions in plain English; the plugin matches the question against a list of known intents (contact, pricing, etc.) and, failing that, ranks your own posts and pages with BM25 and returns the best matches with highlighted snippets.

## What it isn't

Despite the name, this is **not** an LLM. There's no OpenAI call, no Claude call, no Anthropic, no vector database, no "AI" in the generative sense. The "intelligent" bit is classical information retrieval done well — a Porter stemmer, an inverted index, field-weighted BM25 scoring, substring-and-regex intent matching, and stem-level synonym expansion. The output is deterministic, explainable, and built entirely from content you've already written.

That's the whole pitch. If you want a chat assistant that:

- never hallucinates,
- never costs you a penny per query,
- never sends a visitor's question to a third party,
- and keeps working if your internet drops,

this plugin does that. If you want something that holds a free-form conversation or summarises your content in novel prose, you want a different plugin.

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Using the widget](#using-the-widget)
- [Configuration](#configuration)
  - [Widget settings](#widget-settings)
  - [Intents](#intents)
  - [Synonyms](#synonyms)
  - [Indexable post types](#indexable-post-types)
- [How it works](#how-it-works)
- [REST API](#rest-api)
- [Privacy and data](#privacy-and-data)
- [Developer reference](#developer-reference)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)
- [Author and support](#author-and-support)

---

## Features

- **Self-hosted.** No API keys, no outbound HTTP, no subscriptions. Runs on any shared host that can run WordPress.
- **BM25 ranking** with separate weights for title, excerpt, and body fields.
- **Porter stemmer** so "building" matches "build", "databases" matches "database", etc.
- **Intent matching** — map common question phrasings to canned answers that bypass search entirely. Ships with five defaults; fully customisable.
- **Synonym expansion** at stem level — "WP", "WordPress" treated as one. Ten sensible defaults out of the box.
- **Automatic indexing** on `save_post`, `deleted_post`, `trashed_post`. No cron, no manual re-scraping.
- **Floating bubble or inline via shortcode** — or both, on the same page.
- **Per-IP rate limiting** with SHA-256 hashing (raw IPs never stored).
- **Query log** with thumbs-up / thumbs-down feedback so you can spot gaps in coverage.
- **Admin diagnostic page** with a tokeniser tester, raw search tester, and full-pipeline chat tester.
- **Confidence-aware responses** — the widget hedges its framing when it isn't sure and stays quiet when it has nothing to show.
- **Clean uninstall** — all custom tables and options removed on delete (opt-out available).

---

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later
- MySQL / MariaDB (no special extensions)
- A theme that calls `wp_footer()` (required only for the floating widget; the shortcode works anywhere)

---

## Installation

### From the release ZIP

1. Download the latest `mbr-intelligent-site-assistant.zip` from the [releases page](https://littlewebshack.com) on Little Web Shack.
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and activate.
3. Go to **Tools → MBR ISA Diagnostic** to verify installation and run your first index.

### From source

```bash
git clone https://github.com/harbourbob/mbr-intelligent-site-assistant.git
cd mbr-intelligent-site-assistant
# Copy the whole directory into wp-content/plugins/ on your site
```

No build step. No dependencies. Activate via the WordPress admin and you're done.

---

## Quick start

After activation:

1. **Run a full reindex.** Go to **Tools → MBR ISA Diagnostic → Index Status** and click **Run Full Reindex**. This populates the index with your existing published posts and pages. New and edited posts are indexed automatically from that point on.
2. **Test the pipeline.** Scroll to the **Chat Tester** at the bottom of the diagnostic page and ask a question. This runs exactly what the public widget would run, and shows the raw JSON payload.
3. **Configure and enable the widget.** Under **Widget Settings**, tick *Floating widget*, set a title and greeting, and save. The chat bubble now appears on every page.

That's it. The plugin is live.

---

## Using the widget

### Floating bubble

With *Floating widget* ticked in settings, a chat bubble renders in the bottom-right (or bottom-left) corner of every page. Clicking it opens the chat panel. The widget is automatically skipped on feeds, embeds, favicons, and `robots.txt`.

### Inline shortcode

For a dedicated "Ask" page, or to embed the chat inside a widget area, use:

```
[mbr_isa_chat]
```

Supported attributes (all optional, and all override the global widget settings for that one instance):

| Attribute     | Purpose                                                          |
| ------------- | ---------------------------------------------------------------- |
| `title`       | Override the header text of the chat panel                        |
| `greeting`    | Override the first assistant message                              |
| `placeholder` | Override the input placeholder                                    |
| `height`      | Panel height as a CSS unit (e.g. `500px`, `80vh`)                 |

Example:

```
[mbr_isa_chat title="Ask us anything" greeting="What are you looking for?" height="600px"]
```

Running both the floating bubble and one or more inline shortcodes on the same page is fine — each instance is independent.

---

## Configuration

All configuration is currently stored in WordPress options. A proper admin UI for intents and synonyms is on the roadmap; in the meantime they're set programmatically or via any options-editor plugin.

### Widget settings

Stored in the `mbr_isa_settings` option. Defaults are seeded on activation.

Editable via **Tools → MBR ISA Diagnostic → Widget Settings**:

- Floating widget on/off
- Position (bottom-right / bottom-left)
- Widget title
- Greeting message
- Input placeholder

Other keys in the option array (edit programmatically for now):

| Key                    | Default            | Purpose                                           |
| ---------------------- | ------------------ | ------------------------------------------------- |
| `enabled_post_types`   | `['post', 'page']` | Which post types get indexed                      |
| `bm25_k1`              | `1.2`              | BM25 term-frequency saturation                    |
| `bm25_b`               | `0.75`             | BM25 length-normalisation                         |
| `field_weight_title`   | `3.0`              | Title-match score multiplier                      |
| `field_weight_excerpt` | `1.5`              | Excerpt-match score multiplier                    |
| `field_weight_body`    | `1.0`              | Body-match score multiplier                       |
| `log_queries`          | `true`             | Whether to log queries to the DB                  |
| `rate_limit_per_min`   | `30`               | Max `/ask` requests per minute, per IP hash       |

### Intents

An intent pairs one or more trigger phrases with a canned response. If any trigger matches the raw query as a substring (or regex, if prefixed with `re:`), the intent fires immediately — no search, no ranking.

The plugin ships with five defaults (`contact`, `pricing`, `services`, `hours`, `help`) which you can override by writing your own list to the `mbr_isa_intents` option:

```php
update_option( 'mbr_isa_intents', [
    [
        'id'         => 'contact',
        'label'      => 'Contact',
        'triggers'   => [ 'contact', 'email address', 'get in touch', 'how do I reach' ],
        'response'   => 'You can reach me at hello@example.com or via the contact form in the main menu.',
        'confidence' => 1.0,
    ],
    [
        'id'         => 'shipping',
        'label'      => 'Shipping',
        'triggers'   => [ 'shipping', 'delivery', 're:\bdispatch(ed)?\b' ],
        'response'   => 'Orders ship within 2 working days. Tracking details are emailed once the parcel leaves us.',
        'confidence' => 1.0,
    ],
] );
```

Triggers are case-insensitive substring matches by default. Prefix with `re:` to treat the rest as a regular expression (handy for word-boundary matches — `re:\bhelp\b` won't fire on "helping").

If two intents both match, the one with the higher `confidence` value wins.

### Synonyms

A synonym group is a list of terms treated as equivalent. Matching happens on *stems*, so "shops" and "shop" are already equivalent before synonyms kick in. You write synonyms as humans would type them; the plugin stems them internally.

The plugin ships with ten default groups covering common web-industry vocabulary (`wp` / `wordpress`, `shop` / `store` / `ecommerce`, `price` / `pricing` / `quote`, etc.). Override by writing your own list to `mbr_isa_synonyms`:

```php
update_option( 'mbr_isa_synonyms', [
    [ 'wp', 'wordpress' ],
    [ 'delivery', 'shipping', 'dispatch' ],
    [ 'vegan', 'plant-based', 'plant based' ],
    [ 'refund', 'return', 'money back' ],
] );
```

**Intent vs synonym: which do I add?** Add a **synonym** when your visitors use different vocabulary to the same thing on your site. Add an **intent** when the question has a canned answer that doesn't correspond to any single page.

### Indexable post types

By default, only `post` and `page` are indexed. To include custom post types (products, portfolios, docs, etc.):

```php
$s = get_option( 'mbr_isa_settings', [] );
$s['enabled_post_types'] = [ 'post', 'page', 'product', 'portfolio' ];
update_option( 'mbr_isa_settings', $s );

// Then run a full reindex from Tools → MBR ISA Diagnostic.
```

---

## How it works

Each query runs through a five-stage pipeline:

1. **Intent match.** The raw query is checked against every intent's triggers. First match wins (highest confidence on ties). If an intent fires, the pipeline stops here.
2. **Tokenise.** Lowercase, strip punctuation, split on whitespace, drop stopwords, reduce survivors to Porter stems.
3. **Expand.** Each stem is looked up in the synonym map; equivalent stems are added to the token list.
4. **Rank.** The expanded tokens are scored against the inverted index using BM25, per field, with title and excerpt weighted higher than body.
5. **Format.** Results are classified as high / medium / low / no confidence based on the top score and its lead over the runner-up. The widget's framing message and the number of results shown are chosen accordingly.

Confidence levels:

| Level    | Rule                                          | Results shown | Typical framing                                    |
| -------- | --------------------------------------------- | ------------- | -------------------------------------------------- |
| `high`   | Top score ≥ 1.5 **and** ≥ 1.5× the runner-up  | 1             | "Here is what I found."                            |
| `medium` | Top score ≥ 1.0                               | Up to 3       | "These look relevant."                             |
| `low`    | Top score > 0 but below 1.0                   | Up to 3       | "I'm not sure, but these might help."              |
| `none`   | No results                                    | 0             | "I couldn't find anything. Try a different phrasing." |

---

## REST API

The widget talks to two endpoints under the `mbr-isa/v1` namespace. Both are public (no authentication) but nonce-protected when called from the widget and per-IP rate limited.

### POST `/wp-json/mbr-isa/v1/ask`

Request:

```json
{
  "query": "how do I contact you",
  "session_id": "optional-32-char-session-id"
}
```

Intent-hit response:

```json
{
  "type": "intent",
  "intent_id": "contact",
  "intent_label": "Contact",
  "message": "You can get in touch via the contact form ...",
  "results": [],
  "suggestions": ["Ask me something else about this site"],
  "session_id": "...",
  "query_id": 1234
}
```

Search-hit response:

```json
{
  "type": "search_results",
  "confidence": "medium",
  "message": "These look relevant:",
  "results": [
    {
      "title": "...",
      "url": "...",
      "snippet": "... <mark>matching term</mark> ...",
      "score": 2.14
    }
  ],
  "suggestions": [],
  "session_id": "...",
  "query_id": 1235
}
```

### POST `/wp-json/mbr-isa/v1/feedback`

Records a thumbs-up (`1`), thumbs-down (`-1`), or neutral (`0`) against a previously-logged query. Feedback is only accepted within one hour of the query being created — anything older returns `410 Gone`.

```json
{
  "query_id": 1234,
  "feedback": 1
}
```

### Error codes

| Status | Code                         | Meaning                                             |
| ------ | ---------------------------- | --------------------------------------------------- |
| `400`  | `mbr_isa_invalid_query_id`   | `query_id` missing or not a positive integer        |
| `400`  | `mbr_isa_invalid_feedback`   | Feedback value not in `{-1, 0, 1}`                  |
| `404`  | `mbr_isa_not_found`          | `query_id` doesn't exist in the log                 |
| `410`  | `mbr_isa_feedback_expired`   | More than an hour since the query was created       |
| `429`  | `mbr_isa_rate_limited`       | Per-IP rate limit hit                               |
| `500`  | `mbr_isa_db_error`           | Database write failed                               |

---

## Privacy and data

This section matters, because "self-hosted" is one of the reasons you'd pick this plugin.

**No outbound HTTP.** The plugin makes no external network calls during normal operation. Every query is answered from data on your own server.

**What gets stored:**

- The inverted index (derived from content you've already published).
- A log of every query, in `wp_mbrisa_queries`: the query text, matched intent (if any), result count, top score, optional feedback value, the session ID, a SHA-256 hash of the requester's IP (salted with `wp_salt()`), and a timestamp.

**What doesn't get stored:**

- Raw IP addresses — they're hashed immediately and the originals are never written.
- User accounts, cookies, emails, or any personally identifying information.

**Rate limiting:** per-IP-hash sliding window stored in WordPress transients. Default is 30 `/ask` requests per minute and 20 `/feedback` requests per minute. The `/ask` limit is configurable via `rate_limit_per_min` in the settings option.

**On uninstall:** all four custom tables, all plugin options, and all transients are dropped. To keep the data across a reinstall, set `mbr_isa_keep_data_on_uninstall` to a truthy value *before* deleting the plugin.

---

## Developer reference

### Database tables

All prefixed with your WordPress table prefix + `mbrisa_`:

| Table              | Purpose                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------ |
| `mbrisa_terms`     | Term dictionary, with document frequency for BM25 IDF                                      |
| `mbrisa_documents` | One row per indexed post: title, URL, token count, content hash, display excerpt            |
| `mbrisa_postings`  | Inverted index — term-in-doc occurrences with per-field term frequencies                    |
| `mbrisa_queries`   | Query log with intent match, result count, top score, feedback, session ID, IP hash         |

### Options

| Option                          | Purpose                                                     |
| ------------------------------- | ----------------------------------------------------------- |
| `mbr_isa_version`               | Installed plugin version string                              |
| `mbr_isa_db_version`            | Schema version (used by future migrations)                  |
| `mbr_isa_settings`              | Main settings array (see table above)                       |
| `mbr_isa_intents`               | Intent definitions (empty → use built-in defaults)          |
| `mbr_isa_synonyms`              | Synonym groups (empty → use built-in defaults)              |
| `mbr_isa_index_status`          | Cached counts shown on the diagnostic page                  |
| `mbr_isa_keep_data_on_uninstall`| If truthy, skip all cleanup on delete                       |

### File layout

```
mbr-intelligent-site-assistant/
├── mbr-intelligent-site-assistant.php   Main plugin bootstrap
├── readme.txt
├── uninstall.php                        Full-cleanup routine
├── assets/
│   ├── css/chat-widget.css              Widget styling
│   └── js/chat-widget.js                Widget controller
├── data/
│   └── stopwords-en.php                 English stopword list
└── includes/
    ├── class-mbr-isa-activator.php      Creates tables, seeds options
    ├── class-mbr-isa-deactivator.php    Deactivation hooks
    ├── class-mbr-isa-tokeniser.php      Clean / split / stem
    ├── class-mbr-isa-bm25.php           BM25 scoring
    ├── class-mbr-isa-indexer.php        Index build, search, field weights
    ├── class-mbr-isa-synonyms.php       Synonym expansion
    ├── class-mbr-isa-intents.php        Intent matching
    ├── class-mbr-isa-responder.php      Confidence, messages, snippets
    ├── class-mbr-isa-query-handler.php  Orchestrates the pipeline
    ├── class-mbr-isa-rate-limiter.php   Per-IP throttling
    ├── class-mbr-isa-rest.php           REST route registration
    ├── class-mbr-isa-frontend.php       Shortcode + floating widget render
    └── class-mbr-isa.php                Singleton orchestrator + admin page
```

### JavaScript integration

The widget exposes a configuration object at `window.mbrAisa`, populated by `wp_localize_script`:

```js
window.mbrAisa = {
  restUrl: 'https://example.com/wp-json/mbr-isa/v1/ask',
  feedbackUrl: 'https://example.com/wp-json/mbr-isa/v1/feedback',
  nonce: '...',
  strings: { sending: 'Searching…', networkErr: '...', /* ... */ }
};
```

For manual re-initialisation (e.g. after an AJAX page swap in a headless theme):

```js
window.MbrAisaChat.init();
```

> **Note.** The JavaScript-side identifiers retain the earlier `mbrAisa` / `MbrAisaChat` camelCase names for backward compatibility with existing front-end integration code. All PHP-side identifiers use `mbr_isa`.

---

## Troubleshooting

**The widget doesn't appear.**
Check *Floating widget* is enabled under Widget Settings. Confirm your theme calls `wp_footer()`. Check browser console for JS errors. Confirm `window.mbrAisa` is defined in the page.

**Queries return no results even though the content exists.**
Run a full reindex. This is the most common cause on a fresh install. Check Index Status shows a non-zero document count. Use the Tokeniser Tester to compare how your query and the page title tokenise. If the query uses different vocabulary, add a synonym group.

**An intent is firing when it shouldn't.**
Triggers are substrings — `help` will match "help me". Tighten with a regex: `re:\bhelp\b`. Use the Chat Tester to see which intent ID is firing.

**"Too many requests" error.**
You've hit the per-IP rate limit. Wait a minute, or raise `rate_limit_per_min` in the settings option.

**Reindex times out on a large site.**
The reindex is synchronous and batches 50 posts at a time. For very large sites, raise PHP's `max_execution_time` temporarily, or trigger the reindex via WP-CLI where time limits don't apply.

**A database table is missing after activation.**
Deactivate and reactivate the plugin. Check the WordPress database user has `CREATE TABLE` permission.

For more detail see the [full user guide](docs/mbr-intelligent-site-assistant-user-guide.pdf).

---

## Known limitations

These are known gaps at v0.3.2, not bugs:

- **No admin UI for intents and synonyms yet** — they're edited via the options API or any options-editor plugin. A proper UI is on the roadmap.
- **Synchronous reindex** — fine for up to a few thousand posts, but will want a background-job version for larger sites.
- **English only** — the stopword list and Porter stemmer are English. Multi-language support is not yet implemented.
- **No Gutenberg block** — the shortcode works inside a shortcode block, but there's no native block with inspector controls yet.
- **No fuzzy / typo tolerance** — "contct" won't match "contact". Consider adding a synonym if a particular misspelling is common.

---

## Changelog

### 0.3.2

- Plugin renamed from *MBR AI Site Assistant* to *MBR Intelligent Site Assistant* to more accurately describe what it does (not an LLM).
- PHP class prefix, constants, options, DB tables, text domain, CSS classes, and file/folder names all updated to match the new name.
- Existing installs will need a fresh install because the `mbrisa_*` tables and `mbr_isa_*` options are new.

### 0.1.0

- Initial development release — bootstrap, database schema, tokeniser with Porter stemmer.

---

## Contributing

Issues and pull requests welcome. Before opening a PR:

- Keep to WordPress coding standards (spacing, `wp_unslash` + sanitise on input, prepared statements for all SQL, escape on output).
- No external dependencies without a very good reason — "pure PHP, works on any host" is a design constraint.
- UK English in user-facing strings (the text domain is `mbr-isa`).
- Add a line to the changelog for any user-visible change.

If you're reporting a ranking or matching issue, please include the query, the expected result, and the Chat Tester's raw JSON response payload — the trace makes these much quicker to diagnose.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) or <https://www.gnu.org/licenses/gpl-2.0.html>.

---

## Author and support

Built by [Robert Palmer](https://littlewebshack.com) at Little Web Shack. Distributed free and open source with no paid tier, no premium upsell, and no nagging — just a plugin.

If it's saved you time, a donation at [buymeacoffee.com/robertpalmer](https://buymeacoffee.com/robertpalmer) is very welcome. If it's saved you a lot of time, a kind word on your next WordPress project is worth even more.

For support questions, please use the issues tab on this repository.
