# Changelog

All notable changes to MBR Intelligent Site Assistant are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Dates are DD/MM/YYYY.

## [Unreleased]

## [0.9.21] - 03/09/2026

Ten low-severity findings from an external re-test of 0.9.20, six new and four
carried. No change to indexing, ranking, the visitor path or the schema, so
**no reindex is required**.

### Fixed

- **Feedback recording was not atomic.** The endpoint read the `feedback`
  column, checked it was still NULL, and then wrote — with nothing holding the
  row in between, so two submissions arriving together could both pass the
  check and the second overwrite the first. That is the "one rating per answer"
  rule, added in 0.9.7, failing at exactly the moment it is meant to apply. The
  window is small and the prize is changing your own rating, so this is an
  integrity fault rather than a vulnerability, but the write now carries
  `AND feedback IS NULL` in its WHERE clause and the database decides which
  request arrived first. A losing race returns 409, which the widget already
  treats as success. Raised by an external audit of 0.9.21.
- **The documentation claimed the inline-asset extraction was finished; four
  blocks remained.** 0.9.20 moved the Diagnostics blocks out and then said in
  five places that the job was done, while the intents, synonyms and Appearance
  screens still carried 417 lines of inline CSS and script between them — more
  than had been extracted. An administrator following the changelog and setting
  a strict admin Content-Security-Policy would have found the Appearance
  preview dead and three screens unstyled, having been told the opposite. The
  extraction is now genuinely complete: the three remaining `<style>` blocks
  moved into `assets/css/mbr-isa-admin.css`, and the Appearance screen's
  script into a new `assets/js/mbr-isa-appearance.js` with its preset list and
  glass bounds passed through `wp_localize_script()`. No admin screen carries
  inline CSS or script now, and the claims that said so early have been
  corrected to match.
- **The reindex button pointed at controls that had moved.** The Status tab
  told you it indexes "the post types selected below"; the tab split put those
  checkboxes on the Content tab, so the one instruction on that screen sent
  you somewhere that no longer existed.
- **`languages/mbr-isa.pot` had not been regenerated since 0.9.10.** The 0.9.8
  audit found that no template shipped at all; that was fixed in 0.9.10 and has
  been quietly regressing ever since, so every string added across ten releases
  was untranslatable. The visible casualty was the new tab strip — only Status
  and Feedback existed as msgids, picked up from elsewhere, so a translated
  site showed two translated tabs and five English ones. The template is
  regenerated against 0.9.21 and now carries 325 strings with current line
  references.
- **`MBR_ISA_PDF_Extractor::normalise()` collapsed whitespace twice.** Two
  identical passes sat three lines apart with nothing between them, so the
  first was redundant work over the full extracted text of every PDF — and the
  second is strictly better, having the byte-wise fallback the first lacked.
  Its comment also referred to a "contents-page rule below" that is not in that
  function. Predates 0.9.20; found while verifying the fix next to it.
- **A stylesheet rule matched nothing.** `[id^="mbr-isa-synonym-"]` was added
  in 0.9.20 alongside the intents anchors, but the synonyms screen uses that
  string as a class and has no matching id. Rather than drop the selector, the
  synonyms screen now has the same anchored-save behaviour the intents screen
  gained in 0.9.20 — saving returns you to the card you were working on, and
  to the blank add form after an addition — which makes the rule true.
- **Four dead `global $wpdb;` declarations** left by the tab split, in
  `render_tab_status()`, `render_tab_content()`, `render_tab_alt_text()` and
  `render_tab_testers()`. Harmless at runtime and misleading in the way an
  unused import is: they said those functions talk to the database, and they
  do not.

### Changed

- `includes/class-mbr-isa-frontend.php` was the only tab-indented file in the
  tree; it now uses four spaces like the other twenty. Whitespace only — no tab
  appeared anywhere except as leading indentation, which was checked before
  converting.

### Notes

- Two carried findings remain open and are unchanged: the bundled Parsedown and
  `Puc/*/Vcs/` trees still ship while being unreachable in generic-JSON-manifest
  mode, and `phpcs:ignore` coverage on interpolated table names is still thin
  enough that a real finding would be lost in the noise.

## [0.9.20] - 28/08/2026

An admin usability release. No change to indexing, ranking or the visitor path,
so **no reindex is required** and the schema is unchanged at v6.

### Changed

- **The Diagnostics screen is now tabbed.** It had grown a section at a time
  into a single long scroll of unrelated controls — plugin status, content
  sources, the alt text worklist, privacy, widget wording, feedback statistics
  and three testers, one after another. They are now seven tabs: Status,
  Content, Alt Text, Privacy, Widget, Feedback and Testers. Each renders on its
  own, so only the active tab's queries run: the feedback statistics and the
  alt text worklist are the expensive ones and neither is touched unless you
  are looking at it. Every save returns you to the tab that owns the setting.
- **Adding an intent returns you to the form rather than the top of the page.**
  Saving redirected to the top of the intents screen, so adding several in a
  row meant scrolling back to the bottom each time. A save now returns to the
  card it concerns — the blank "add" form after an addition, so the next one
  can be typed straight away, or the edited intent's own card otherwise.
  Validation errors deliberately keep you at the top, where the notice
  explaining the failure is.
- **Admin CSS and JavaScript moved out of the markup.** They were inline —
  seven `<style>` and `<script>` blocks across five screens by 0.9.18, none of
  them cacheable and all refused by a strict admin Content-Security-Policy,
  with the cost of extracting them rising every release. They now ship as
  `assets/css/mbr-isa-admin.css` and `assets/js/mbr-isa-admin.js`, with the
  values PHP used to print into the page passed through
  `wp_localize_script()` as `window.mbrIsaAdmin`. This was the outstanding low
  finding from the 0.9.18 re-test that was flagged as worth scheduling.
- The four `$_GET` display flags on the Diagnostics screen now go through
  `wp_unslash()` and `sanitize_key()`, closing the last part of the
  superglobal finding that 0.9.19 left partially fixed.

## [0.9.19] - 28/08/2026

Six low-severity fixes from the 0.9.18 re-test, all in code added between
0.9.9 and 0.9.18. No schema change and **no reindex required** — nothing here
alters what is written into the index.

### Changed

- **mbstring is now declared as a requirement rather than half-guarded.** Six
  scattered `function_exists()` guards promised a graceful degradation the rest
  of the code could not deliver: the indexer, the responder and the tokeniser
  all call `mb_*` unconditionally, so without the extension the plugin fatalled
  on the first index write or the first search regardless. The guards also cost
  a lookup per token of every document. There is now a runtime check beside the
  PHP version guard, with an admin notice, and the guards are gone. A plugin
  header cannot express an extension requirement, so a notice is the only
  honest place to state it.
- **The full passage no longer travels to the visitor.** A result carried the
  whole stored 2,000-character passage in `excerpt` alongside the 240-character
  `snippet` the widget actually renders, plus `doc_id`, `post_id`,
  `chunk_index`, `page_top` and `score` — roughly 6 KB of dead payload on a
  three-result response, on an unauthenticated endpoint, and more of each
  document than the windowing chose to show. Nothing private was exposed; every
  row had already passed the visibility gate. `excerpt` is now dropped with
  `extras`.

### Fixed

- **A PDF whose text was invalid UTF-8 still lost all of it.** The fallback for
  a failed unicode scrub ran against a variable the failure had already emptied,
  so the retry scrubbed an empty string and the document was reported as having
  no text layer — indistinguishable from a genuine scan. This is the same defect
  corrected thirty lines below in the same function in 0.9.9; one of the two
  instances was missed. Subsetted and CID fonts are the usual source.
- **`strip_markup()` decoded HTML entities twice.** Two identical
  `html_entity_decode()` calls three lines apart meant double-encoded content
  was decoded back into markup in the stored passage: a page displaying an
  escaped code sample as `&amp;lt;script&amp;gt;` was indexed as a literal
  `<script>`. Not exploitable through this plugin — snippets are escaped before
  `<mark>` is injected and the admin screens escape too — but it turned inert
  text into markup-shaped text in a field the public REST response carried.
- **`intent_supplementary_max` could never return more than one result.** The
  supplementary path reuses the full formatting path, and inherited its
  confidence cap along with it — at high confidence `result_limit_high` is 1, so
  the caller's maximum of 3 was unreachable. The better the supplementary hits
  scored, the fewer were shown, which is the exact case the setting exists for.
  The maximum is now passed in rather than applied to what comes back.
- **The contents-page demotion fetched every contents row in the index.** The
  0.9.9 index on `is_contents` fixed the full table scan, but the query still
  returned every contents chunk in the corpus into PHP on every visitor query,
  while the loop beneath can only use those the query already matched. A
  candidate pool is 25 to 400 documents; a documentation library can hold
  thousands of contents chunks. The query is now scoped to the matched set.

### Credits

- The passage chunker (`class-mbr-isa-chunker.php`) and the WP-CLI commands
  (`class-mbr-isa-cli.php`) contain code contributed by **James Wilson**
  (Director of Technology, Cogora), who also carried out the 0.9.8 audit and
  this 0.9.18 re-test.

## [0.9.18] - 25/08/2026

Three fixes and two additions. **Full reindex required.**

### Added

- **High confidence now requires the runner-up to be a non-answer.** The ratio
  test could be satisfied while the second result was still worth showing, and
  high confidence returns one result — so that answer was ranked and then cut.
  A name search made it obvious: an image matches on every weighted field at
  once and buried every document discussing the person. A runner-up at or above
  the medium threshold now rules out high confidence. Ranking is unchanged. The
  caps are settable: `result_limit_high`, `result_limit_medium`,
  `result_limit_low`, clamped 1-10.
- **Further mentions in the same document.** Collapse gives each document one
  result, so a term mentioned three times in one PDF was reachable only at its
  best-scoring passage. The others now ride along beneath it as secondary
  links, each with its own snippet, deep link and — for PDFs — page number.
  Ranking, collapse and the confidence caps are unchanged, so no document can
  crowd out another. Adjacent chunks are suppressed, since the 50-word overlap
  makes a boundary phrase match twice. Capped at three; `passage_extras_max` 0
  restores the previous behaviour.

### Fixed

- **A mention's page label could name the wrong page.** A passage records the
  page it starts on, and a chunk routinely runs onto the next, so a match in its
  later half sits a page further on. The deep link corrected for that; the label
  did not. Now both use the corrected page.
- **A `<body>` mention truncated the content.** The 0.9.13 body-only rule ran on
  every field, extracted PDF text included, and its unclosed-tag branch dropped
  everything before the mention. A post whose mention fell after its prose
  indexed as nothing; this plugin's own guide lost its first five pages and had
  the rest renumbered from 1, sending PDF deep links to the wrong page. The rule
  now requires a doctype or `<html>` tag near the start before it applies.
- **Decorative did not stick.** It wrote empty alt text, which is
  indistinguishable from alt text nobody wrote, so the image returned to the
  worklist on every scan. A separate marker now records the decision; the alt
  attribute is still written empty. Marked images can be listed again to undo.
- **WooCommerce galleries reported as unused.** `_product_image_gallery` holds a
  bare comma-separated ID list that no markup or JSON pattern matches, so live
  product images sank to the bottom of the Alt Text Audit. Plain ID-list meta is
  now read, filterable through `mbr_isa_id_list_meta_keys`.


## [0.9.17] - 21/08/2026

Page-builder references are now found. **Full reindex required.**

### Fixed

- **JSON-escaped paths defeated the reference scan.** Builders store layouts as
  JSON, which escapes forward slashes, so a stored path never matched. Affected
  every attachment on Elementor- and Bricks-built sites, PDFs included.
- **Elementor's thumbnail cache** serves images from a path containing only the
  filename stem and a hash, so the original path appears nowhere.
- A last-resort stem pass now matches and then **verifies in PHP**, requiring a
  path boundary and a recognised derivative suffix. That stops `logo-2.png`
  being read as a reference to `logo.png` — which would wrongly widen a
  visibility rule — while still recognising real size variants.


## [0.9.16] - 20/08/2026

Featured images count as published usage. **Full reindex required.**

### Fixed

- **A featured image was invisible to the visibility scan.** The scan looks for
  the file path in post content and meta; a featured image is stored as
  `_thumbnail_id` holding the attachment ID and rendered by the theme, so it
  appears in neither. Such images were indexed only if `post_parent` happened to
  be readable — and then linked to that parent, which records where the file
  was uploaded from rather than where it is displayed.
- Featured images are now checked first: an exact match on an indexed meta key,
  cheaper than the `LIKE` scans it precedes.


## [0.9.15] - 20/08/2026

Fixes image result URLs. No schema change; reindex not required.

### Fixed

- **Image results could link to a page the image does not appear on.** The URL
  preferred `post_parent`, which records the editor a file was uploaded from
  rather than where it is displayed. Results now prefer a post that actually
  references the file, falling back to the parent only when none does — the
  reverse of the PDF ordering, and deliberately so.
- **The choice is deterministic.** Candidates are sorted after fetching (most
  recent first, then descending ID), so an image on several posts no longer
  links somewhere different on each request.


## [0.9.14] - 20/08/2026

Fixes a 0.9.13 regression. **Full reindex required.**

### Fixed

- **Deep links no longer scrolled to the matched passage.** 0.9.13's bounded tag
  remover replaced inline tags with a space where `strip_tags()` had used
  nothing, so `<strong>WordPress</strong>'s` indexed as `WordPress 's`. Text
  Fragments require an exact match against the rendered page, so the browser
  could not find the passage. Phrase search across inline markup failed the
  same way.


## [0.9.13] - 20/08/2026

Fixes silent content loss during indexing. **Full reindex required.**

### Fixed

- **`strip_tags()` could consume an entire document.** It has no bound on how
  far it searches for a closing bracket, so one unterminated construct cost
  everything after it. A 36,965-character page reached the index as 47
  characters — the `<title>` and nothing else — while rendering perfectly.
  Tag removal is now bounded, so a malformation costs its own fragment.

### Added

- A full HTML document in `post_content` is indexed on its `<body>` only.
  Doctype, head, inline CSS and meta tags are never useful search results, and
  on one real page accounted for over half the character count.


## [0.9.12] - 20/08/2026

Fixes an indexing fault present since the tokeniser landed. **Full reindex required.**

### Fixed

- **HTML entities were never decoded during indexing.** A heading reading
  "Rules & Targeting" was stored as `Rules &amp; Targeting`, so quoted phrase
  search could never match it. Apostrophes stored as `&#8217;` failed the same
  way.
- **Junk terms in the dictionary.** `&amp;` was tokenised as "amp", `&#8217;`
  as "8217", `&nbsp;` as "nbsp" — real searchable entries attached to much of a
  typical site.
- **Raw entities in visitor-facing snippets.**

### Notes

- Entities are decoded after tags are stripped, never before: decoding first
  would resurrect an encoded `&lt;script&gt;` into a real tag.
- Decoding is applied at phrase-comparison time as well, so quoted search works
  before the reindex that the dictionary and snippets still need.


## [0.9.11] - 20/08/2026

Documentation-accuracy fix. No schema change, no reindex.

### Fixed

- `wp mbr-isa status` now reports image indexing. The 0.9.10 user guide documented
  an `Image indexing:` line the command never printed — the CLI was overlooked
  when image support landed in 0.9.9. The line also carries the count of images
  actually indexed, because "on" with a count of zero is a real state that means
  the setting is enabled but nothing qualifies.

### Notes

- Every filter, setting, option and cron hook documented in the 0.9.10 guide was
  re-checked against the source. This was the only discrepancy.


## [0.9.10] - 19/08/2026

Adds an Alt Text Audit. No schema change; no reindex required.

### Added

- **Alt Text Audit** panel on Diagnostics. Lists images with no alt text, ranked
  by how many published pages display each one, with inline editing, a
  "Decorative" action for images that should carry empty alt, and immediate
  reindexing of any image that becomes eligible once described.
- Usage scanning covers page builder layouts (`_elementor_data`,
  `_bricks_page_content_2`), which live in postmeta rather than `post_content`.
  Filterable via `mbr_isa_builder_meta_keys`.

### Notes

- The audit deliberately does not generate alt text. The plugin has no vision
  capability; text inferred from a filename is not a description, and incorrect
  alt text is worse for a screen reader user than none. The panel ranks and
  contextualises the work so a human can do it quickly.


## [0.9.9] - 18/08/2026

Adds image indexing, and resolves the findings of an external audit of 0.9.8
(nine medium, nineteen low; no critical or high). Schema version 6 — additive,
applied automatically. A full reindex is needed before images appear in results;
the audit fixes need none.

### Added

- Image indexing, off by default, configured under Content Sources. Images are
  indexed on alt text, caption, description and filename — nothing is read from
  the image itself. JPEG, PNG, GIF, WebP and AVIF; SVG is deliberately excluded.
- Alt text may be required before an image is indexed (on by default), which
  keeps the index to images somebody has actually described. Re-tested at search
  time, so removing alt text in the Media Library removes the image from results
  without waiting for a reindex.
- Separate visibility setting for images (`image_visibility`), using the same
  three modes as PDFs but stored independently — "every image in the Media
  Library" is a far larger claim than "every PDF".
- Clicking an image result's thumbnail opens the picture in a lightbox, rather
  than navigating to the page. The title link still goes to the page, so the two
  now lead somewhere different — which is the point of having both. Keyboard
  accessible, Escape to close, focus trapped and restored; the thumbnail remains
  a real link to the image file, so middle-click and open-in-new-tab still work
  and it degrades to the file without JavaScript.
- Thumbnails on image results in the chat widget, and an `IMAGE` badge before
  the title so a picture is distinguishable from a page at a glance. Supplied as
  a separate `kind_label` field in the REST response rather than prefixed onto
  the title, so consumers can render, translate or ignore it.
- `languages/mbr-isa.pot`, covering 283 strings. `load_plugin_textdomain()` had
  pointed at a directory that did not exist, leaving every `__()` call inert.
- `Update URI:` header, so a WordPress.org plugin claiming the same slug cannot
  serve an update to this one.
- `KEY is_contents` on the documents table, and an `ensure_index()` helper
  alongside the existing `ensure_column()`.
- Filters: `mbr_isa_image_result_url`, `mbr_isa_image_score_weight`,
  `mbr_isa_image_length_floor`, `mbr_isa_indexable_image_mimes`,
  `mbr_isa_filename_is_meaningless`.

### Fixed

- **Feedback ratings were rejected outright on every site west of Greenwich.**
  `created_at` is written in site-local time but compared against a UTC clock,
  so on a negative UTC offset every row appeared more than an hour old the
  instant it was written and every thumbs up/down returned 410. On a positive
  offset the one-hour window was silently extended instead.
- **Undefined `$phrase` in `pages_before_match()`**, on the public search path.
  Emitted a PHP warning for every PDF result whose passage spanned a page
  boundary; with `display_errors` on, that warning was written before the REST
  body, corrupting the JSON and disclosing the server path. Functionally it also
  made quoted searches on PDFs open at the wrong page.
- **Undefined array key `extra_attrs`** on every `[mbr_isa_chat]` render, with
  the same warning-into-the-page-body consequence.
- **Unbounded recursion on a cyclic PDF page tree.** Neither the depth-32 nor
  the 5,000-page guard bounded a cycle, so a crafted `/Kids` graph ran until the
  PHP time limit — reachable by any user who can upload a file, and it broke the
  administrator's reindex too. A visited-node set now terminates the walk.
- **PDF text was discarded entirely when the unicode whitespace regex failed.**
  The fallback ran against an empty string literal rather than the extracted
  text, so a document with invalid UTF-8 was reported as having no text layer
  instead of indexing partially.
- Contents-page demotion full-scanned the documents table on every visitor
  search — the last unbounded query left on that path.
- Orphan-term pruning ran a full anti-join across the terms and postings tables
  on every post save, trash and delete. Orphaned terms are invisible to search,
  so the prune now runs only where the index is already being walked.
- Diagnostics could take itself down on the sites that most needed it: the PDF
  eligibility counter ran up to 1,000 unindexable `LIKE` scans in a single page
  load. Both eligibility counters now test a bounded sample and say so.
- `wp_kses()` allowed `onclick` on a translatable string, so a malicious
  translation could have run script in an administrator's session.
- `uninstall.php` cleaned only the current site, leaving every other site in a
  multisite network fully populated after a network-activated delete.
- Feedback statistics compared site-local rows against the database server's
  clock; query-log retention used the discouraged `current_time('timestamp')`.
- Implicitly nullable constructor parameters, deprecated in PHP 8.4.
- A `\u2014` escape in a single-quoted PHP string printed literally in WP-CLI.

### Changed

- The `/ask` endpoint declares a length bound at the route, so an oversized body
  is rejected before `wp_strip_all_tags()` runs over all of it.
- `sessionStorage` persistence now follows the query-logging setting. PECR
  regulation 6 covers all storage on a visitor's device, and the session
  identifier serves the query log rather than the delivery of the service.
- The update check no longer transmits the site's PHP version, locale and
  installed plugin version to GitHub. The manifest is a static file that ignores
  them.

### Security

- No critical or high-severity findings were identified. No SQL injection, no
  authorisation gap, no secret disclosure and no unsafe file handling was found.

[Unreleased]: https://littlewebshack.com/mbr-intelligent-site-assistant/
[0.9.9]: https://littlewebshack.com/mbr-intelligent-site-assistant/
