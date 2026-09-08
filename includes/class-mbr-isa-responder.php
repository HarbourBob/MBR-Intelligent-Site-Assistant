<?php
/**
 * Responder — turns search results into a conversational response payload.
 *
 * Responsible for:
 *   - Choosing the right framing based on confidence ("Here's your answer"
 *     vs "I'm not sure, but these might help" vs "Couldn't find anything").
 *   - Generating short highlighted snippets around matched query terms.
 *   - Structuring the final JSON payload the widget will consume.
 *
 * Confidence heuristic:
 *   HIGH   = top score >= 1.5 AND top is >= 1.5x the #2 score (or only 1 result)
 *   MEDIUM = top score >= 1.0
 *   LOW    = any results but below medium threshold
 *   NONE   = zero results
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Responder {

    const CONFIDENCE_HIGH   = 'high';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_LOW    = 'low';
    const CONFIDENCE_NONE   = 'none';

    /**
     * Plugin settings array (cached).
     *
     * @var array
     */
    private $settings;

    public function __construct( ?array $settings = null ) {
        $this->settings = null === $settings ? get_option( 'mbr_isa_settings', [] ) : $settings;
    }

    /**
     * Build the deep-link fragment for a result URL.
     *
     * Two different mechanisms, because the two viewers are different
     * software: HTML pages use the Text Fragments standard, while PDFs use
     * the long-standing PDF open parameters, which every mainstream viewer
     * honours. Neither can break a link — an unsupported directive is
     * ignored and the document simply opens at the start.
     *
     * @param string   $passage      Matching chunk text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $post_type    Result post type.
     * @param int      $page_number  Page the chunk begins on (PDFs only).
     * @return string Fragment including its leading '#', or ''.
     */
    private function build_deep_link( $passage, array $query_tokens, $post_type, $page_number, $page_top = 0, $phrase = '' ) {
        if ( 'attachment' === $post_type ) {
            if ( $page_number < 1 ) {
                return '';
            }

            // The stored page is the one the chunk *begins* on, but a chunk
            // of a few hundred words routinely spans a page boundary, so the
            // matched text is often on the next page. Count the page markers
            // that fall before the match to land on the right one.
            $pages_over = $this->pages_before_match( $passage, $query_tokens, $phrase );
            $page       = $page_number + $pages_over;

            /*
             * A vertical offset only applies to the page the chunk starts on.
             * Where the match is on a later page, the chunk crossed a page
             * boundary and its text there begins at the top of that page, so
             * the top is already the right place to land.
             */
            if ( $pages_over > 0 || $page_top < 1 || ! $this->pdf_position_enabled() ) {
                return '#page=' . (int) $page;
            }

            /*
             * `zoom=scale,left,top` rather than `view=FitH,top`.
             *
             * Both can scroll, but FitH also fits the page to the window
             * width, which on a wide window zooms well past 100% — 163% was
             * reported on a normal desktop. That is a jarring change for
             * something the visitor only asked to scroll.
             *
             * zoom states the scale explicitly, so the page opens at a
             * predictable size. It defaults to 100 and is configurable, since
             * a site with dense or small-print documents may prefer more.
             *
             * The two parameters also use opposite origins: FitH takes PDF
             * user space, measured up from the bottom-left, while zoom
             * measures down from the top-left. page_top is stored in the
             * latter, so it is emitted as-is.
             */
            return '#page=' . (int) $page
                . '&zoom=' . $this->pdf_link_zoom() . ',0,' . (int) $page_top;
        }

        return $this->build_text_fragment( $passage, $query_tokens, $post_type );
    }

    /**
     * How many page boundaries fall before the matching text in a passage.
     *
     * @param string   $passage      Passage text, including page markers.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $phrase       Literal phrase for a quoted search, or ''.
     * @return int Zero when the match is on the passage's first page.
     */
    private function pages_before_match( $passage, array $query_tokens, $phrase = '' ) {
        $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;
        if ( false === strpos( (string) $passage, $marker ) ) {
            return 0;
        }

        $pos = $this->locate_match( (string) $passage, $query_tokens, $phrase );
        if ( null === $pos ) {
            return 0;
        }

        return substr_count( mb_substr( (string) $passage, 0, $pos ), $marker );
    }

    /**
     * Build a scroll-to-text fragment for a result URL.
     *
     * Produces the `#:~:text=` directive defined by the Text Fragments spec,
     * so a click lands on the passage that matched rather than the top of
     * the page. Every current browser engine supports it; anything that
     * does not simply ignores the directive and loads the page normally,
     * so this can never break a link.
     *
     * Two constraints shape the implementation:
     *
     *  - The phrase must match the *rendered* text of the page. We match on
     *    the stored passage, which came from post_content. Where a page
     *    builder renders from its own stored data rather than post_content,
     *    the phrase may not be found — in which case the browser falls back
     *    to the top of the page, which is the behaviour we had anyway.
     *
     *  - Hyphens and commas are directive delimiters and must be encoded
     *    even though they are URL-safe characters.
     *
     * @param string   $passage      The matching chunk's text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $post_type    Result post type; attachments are skipped.
     * @return string Fragment beginning with '#:~:text=', or '' if unavailable.
     */
    private function build_text_fragment( $passage, array $query_tokens, $post_type = '' ) {
        // PDF viewers do not implement text fragments — they use their own
        // #page= / #search= parameters — so a directive would be dead weight
        // in the URL. Skipped rather than guessed at.
        if ( 'attachment' === $post_type ) {
            return '';
        }

        $passage = trim( (string) preg_replace( '/\s+/u', ' ', (string) $passage ) );
        if ( '' === $passage ) {
            return '';
        }

        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 3 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }
        if ( empty( $patterns ) ) {
            return '';
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';
        if ( ! preg_match_all( $regex, $passage, $all, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }

        // Try each match in turn. The first occurrence of a query term is
        // often inside a heading, which is its own block and usually too
        // short to anchor on — in that case the next occurrence, in the
        // prose beneath, is the one worth linking to.
        foreach ( $all[0] as $match ) {
            // Work in characters, not bytes, so multibyte passages behave.
            $match_pos = mb_strlen( substr( $passage, 0, (int) $match[1] ) );

            // Anchor at the matched word and extend forwards, stopping at
            // the end of the sentence or the end of the block, whichever
            // comes first. Browsers will not match a range that crosses a
            // block boundary, and a mid-sentence range is perfectly valid.
            $tail  = mb_substr( $passage, $match_pos );
            $tail  = $this->truncate_at_boundary( $tail );
            $words = preg_split( '/ /u', $tail, -1, PREG_SPLIT_NO_EMPTY );

            // Too short to identify a location uniquely — try the next one.
            if ( count( $words ) < 4 ) {
                continue;
            }

            if ( count( $words ) <= 10 ) {
                $start = implode( ' ', $words );
                $end   = '';
            } else {
                // Longer run: use the textStart,textEnd range form so the
                // browser matches the span without needing every word
                // between to line up.
                $start = implode( ' ', array_slice( $words, 0, 6 ) );
                $end   = implode( ' ', array_slice( $words, 10, 4 ) );
                if ( '' === trim( $end ) ) {
                    $end = '';
                }
            }

            $fragment = '#:~:text=' . $this->encode_fragment_text( $start );
            if ( '' !== $end ) {
                $fragment .= ',' . $this->encode_fragment_text( $end );
            }

            return $fragment;
        }

        return '';
    }

    /**
     * Cut text at the end of its sentence or its block, whichever is first.
     *
     * Keeps a fragment inside a single block element, which is a hard
     * requirement: a browser will not match a range that spans one.
     *
     * @param string $text Text starting at the matched word.
     * @return string
     */
    private function truncate_at_boundary( $text ) {
        $marker = MBR_ISA_Tokeniser::BLOCK_MARKER;
        $len    = mb_strlen( $text );

        for ( $i = 0; $i < $len; $i++ ) {
            $ch = mb_substr( $text, $i, 1 );
            if ( $marker === $ch || in_array( $ch, [ '.', '!', '?', ':', ';' ], true ) ) {
                return trim( mb_substr( $text, 0, $i ) );
            }
        }

        return trim( $text );
    }

    /**
     * Find the character offset of the occurrence a snippet should open on.
     *
     * Where the query has several terms, the right place to open is where
     * most of them appear together — not wherever the first of them happens
     * to turn up. Searching "privacy, data, and rate limiting" against a
     * passage that says "rate" in its opening line and carries the whole
     * phrase two paragraphs later should land on the phrase; before 0.9.5 it
     * opened on the stray "rate" and the real match sat below the fold.
     *
     * So each occurrence is scored by how many *distinct* query terms fall
     * within a window around it, and the densest wins. Ties go to prose over
     * code, then to the earliest position, which keeps single-term queries
     * behaving exactly as they did.
     *
     * Shared by the snippet builder and the deep-link builder, so the passage
     * shown and the place linked to are always the same one — an improvement
     * here also sharpens which page a PDF result opens at.
     *
     * @param string   $passage      Passage text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return int|null Character offset, or null if nothing matched.
     */
    /**
     * Character offset of a literal phrase within a passage, or null.
     *
     * Matched the way the index matches it (see the phrase filter in the
     * indexer): case-insensitively, with any run of whitespace matching any
     * other, so a phrase broken across a line in a PDF is still found. The
     * two must agree — a passage the filter accepted but this could not
     * locate would be shown opening in the wrong place, which is the fault
     * this exists to fix.
     *
     * @since 0.9.7
     *
     * @param string $passage Passage text.
     * @param string $phrase  Literal phrase, unquoted.
     * @return int|null Character offset of the match.
     */
    private function locate_phrase( $passage, $phrase ) {
        $fold = function ( $text ) {
            $text = str_replace(
                [ "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xc2\xa0" ],
                [ "'", "'", '"', '"', ' ' ],
                (string) $text
            );
            return $text;
        };

        $needle = trim( preg_replace( '/\s+/u', ' ', $fold( $phrase ) ) );
        if ( '' === $needle ) {
            return null;
        }

        // Whitespace in the phrase matches any run of whitespace in the
        // passage, which is what lets a phrase span a wrapped PDF line.
        $pattern = '/' . implode( '\s+', array_map(
            function ( $w ) { return preg_quote( $w, '/' ); },
            preg_split( '/ /', $needle, -1, PREG_SPLIT_NO_EMPTY )
        ) ) . '/iu';

        if ( ! preg_match( $pattern, $fold( $passage ), $m, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        return mb_strlen( substr( $fold( $passage ), 0, (int) $m[0][1] ) );
    }

    private function locate_match( $passage, array $query_tokens, $phrase = '' ) {
        /*
         * In phrase mode, the phrase is the answer to this question.
         *
         * A quoted query is tokenised like any other, which means its
         * stopwords are dropped before ranking — "How it works" reaches this
         * function as the single stem "work". Scoring occurrences of "work"
         * then picks whichever one happens to sit in the densest company,
         * and the snippet opens on "the two work together" several pages
         * from the phrase the visitor actually asked for. The deep link
         * shares this helper, so the PDF opened at the wrong page too.
         *
         * The literal phrase is unambiguous where it appears, so look for it
         * first. Falling through to the token scoring below is still right
         * when it is absent from this passage: a phrase result can be
         * matched on its title rather than its body.
         */
        $phrase = trim( (string) $phrase );
        if ( '' !== $phrase ) {
            $pos = $this->locate_phrase( $passage, $phrase );
            if ( null !== $pos ) {
                return $pos;
            }
        }

        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 2 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }
        if ( empty( $patterns ) ) {
            return null;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';
        if ( ! preg_match_all( $regex, $passage, $all, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        // Character offset and which term matched, for every occurrence.
        $hits = [];
        foreach ( $all[0] as $i => $match ) {
            $hits[] = [
                'pos'  => mb_strlen( substr( $passage, 0, (int) $match[1] ) ),
                'term' => mb_strtolower( isset( $all[1][ $i ][0] ) ? $all[1][ $i ][0] : $match[0] ),
            ];
        }
        if ( empty( $hits ) ) {
            return null;
        }

        // How far either side of a candidate another term still counts as
        // being "together with" it. Roughly the width of the snippet window,
        // so the terms scored are the ones a visitor would actually see.
        $span = 120;

        $best       = null;
        $best_score = -1;
        $best_code  = true;

        foreach ( $hits as $hit ) {
            $terms = [];
            foreach ( $hits as $other ) {
                if ( abs( $other['pos'] - $hit['pos'] ) <= $span ) {
                    $terms[ $other['term'] ] = true;
                }
            }
            $score = count( $terms );

            $around  = mb_substr( $passage, max( 0, $hit['pos'] - 60 ), 160 );
            $is_code = $this->looks_like_code( $around );

            // Higher density wins. At equal density prose beats code, and
            // at equal footing the earliest position wins — which is simply
            // the first hit found, since $hits is in document order.
            if ( $score > $best_score
                || ( $score === $best_score && $best_code && ! $is_code ) ) {
                $best       = $hit['pos'];
                $best_score = $score;
                $best_code  = $is_code;
            }
        }

        return $best;
    }

    /**
     * Does this run of text look like source code rather than prose?
     *
     * Used only to choose where a snippet opens — never to exclude content,
     * which remains fully searchable and rankable. The test is the density
     * of characters common in code and rare in prose, plus a few
     * unmistakable digraphs.
     *
     * @param string $text Candidate window.
     * @return bool
     */
    private function looks_like_code( $text ) {
        $text = trim( (string) $text );
        $len  = mb_strlen( $text );
        if ( $len < 20 ) {
            return false;
        }

        $code_chars = preg_match_all( '/[$;{}\[\]=<>|\\\\]/u', $text );
        $digraphs   = preg_match_all( '#//|=>|->|::#', $text );

        return ( ( $code_chars + ( $digraphs * 3 ) ) / $len ) > 0.045;
    }

    /**
     * Percent-encode text for a text fragment directive.
     *
     * rawurlencode leaves '-' alone and encodes ',' — but both are directive
     * delimiters, so '-' is encoded explicitly here.
     *
     * @param string $text Plain text.
     * @return string
     */
    private function encode_fragment_text( $text ) {
        return str_replace( '-', '%2D', rawurlencode( $text ) );
    }

    /**
     * Build the final response payload.
     *
     * @param array    $search_results Output of MBR_ISA_Indexer::search().
     * @param string[] $query_tokens   Stemmed query tokens (for snippet highlighting).
     * @param string   $phrase         Literal phrase for a quoted search, or ''.
     * @param int      $limit_override Caller's own maximum, replacing the confidence
     *                                 cap. 0 (the default) uses the confidence cap.
     * @return array
     */
    public function format_search_response( array $search_results, array $query_tokens, $phrase = '', $limit_override = 0 ) {
        $results    = isset( $search_results['results'] ) ? $search_results['results'] : [];
        $confidence = $this->determine_confidence( $results );
        $message    = '' !== (string) $phrase
            ? $this->message_for_phrase( $confidence, (string) $phrase )
            : $this->message_for_confidence( $confidence );

        // Attach snippets (plus keep the raw excerpt) and, where possible, a
        // text fragment so the link lands on the matching passage rather than
        // the top of the page.
        $deep_links = ! isset( $this->settings['deep_link_results'] )
            || ! empty( $this->settings['deep_link_results'] );

        foreach ( $results as $i => $r ) {
            $results[ $i ]['snippet'] = $this->build_snippet(
                $r['excerpt'] ?? '',
                $query_tokens,
                (string) ( $r['post_type'] ?? '' ),
                (string) $phrase
            );

            /*
             * Further mentions in the same document.
             *
             * Each gets its own snippet and its own deep link, built by the
             * same helpers as the primary result, so a mention on page 34
             * opens at page 34 rather than at the top of the file. They are
             * secondary links on this result, not results in their own right:
             * the confidence caps below still count documents.
             *
             * A PDF's mention carries its page number as a label, which is
             * the one piece of context that makes a bare second link worth
             * clicking. Nothing else has an equivalent, so nothing else gets
             * a label rather than a made-up one.
             *
             * @since 0.9.18
             */
            $results[ $i ]['more'] = [];

            foreach ( (array) ( $r['extras'] ?? [] ) as $extra ) {
                $more_url = (string) ( $extra['url'] ?? '' );

                if ( '' === $more_url ) {
                    continue;
                }

                if ( $deep_links ) {
                    $fragment = $this->build_deep_link(
                        (string) ( $extra['excerpt'] ?? '' ),
                        $query_tokens,
                        (string) ( $r['post_type'] ?? '' ),
                        (int) ( $extra['page_number'] ?? 0 ),
                        (int) ( $extra['page_top'] ?? 0 ),
                        (string) $phrase
                    );

                    if ( '' !== $fragment ) {
                        $more_url .= $fragment;
                    }
                }

                $entry = [
                    'url'     => $more_url,
                    'snippet' => $this->build_snippet(
                        (string) ( $extra['excerpt'] ?? '' ),
                        $query_tokens,
                        (string) ( $r['post_type'] ?? '' ),
                        (string) $phrase
                    ),
                ];

                if ( 'pdf' === (string) ( $r['doc_kind'] ?? 'text' )
                    && (int) ( $extra['page_number'] ?? 0 ) > 0 ) {
                    /*
                     * The label has to be the page the link opens at, not the
                     * page the chunk started on.
                     *
                     * page_number records where the passage begins. A 250-word
                     * chunk routinely runs onto the next page, so a match in
                     * its later half is a page further on — which is exactly
                     * what pages_before_match() exists to work out, and what
                     * build_deep_link() above has already applied to the URL.
                     * Reading the raw column here made the label disagree with
                     * its own link whenever a chunk straddled a boundary, and
                     * a label that names the wrong page is worse than no label
                     * at all: it tells somebody the mention is somewhere it
                     * is not, and they check that page and conclude the search
                     * is broken.
                     *
                     * @since 0.9.18
                     */
                    $label_page = (int) $extra['page_number']
                        + $this->pages_before_match(
                            (string) ( $extra['excerpt'] ?? '' ),
                            $query_tokens,
                            (string) $phrase
                        );

                    $entry['label'] = sprintf(
                        /* translators: %d: page number within a PDF */
                        __( 'Page %d', 'mbr-isa' ),
                        $label_page
                    );
                }

                $results[ $i ]['more'][] = $entry;
            }

            // Internal only: the responder has taken what it needs. The
            // snippet is what the widget renders; the passage it was cut from
            // is up to 2,000 characters the client has no use for, and the
            // windowing deliberately chose not to show most of it.
            unset( $results[ $i ]['extras'], $results[ $i ]['excerpt'] );

            /*
             * Image results carry a thumbnail.
             *
             * An image's snippet is its alt text — a single line, often only
             * a few words. Rendered as a text result it looks like a page
             * with almost nothing on it, which is a poor showing for what may
             * be a perfectly good answer. The picture is the answer, so the
             * widget is given what it needs to show one.
             *
             * The 'medium' size rather than 'thumbnail': WordPress crops
             * thumbnails to a square by default, which decapitates people and
             * ruins anything wide. Medium preserves the aspect ratio, and the
             * widget scales it down in CSS.
             *
             * Falls back silently. A registered size can be missing on an
             * image uploaded before the theme declared it, and a result
             * without a thumbnail should still be a result.
             */
            if ( 'image' === (string) ( $r['doc_kind'] ?? '' ) ) {
                /*
                 * Label the result as an image.
                 *
                 * Added to the payload rather than prefixed onto the stored
                 * title, for two reasons. The title column is what the widget
                 * shows and what any other consumer of the REST response
                 * reads, so mutating it would put the word into data that is
                 * meant to be the page's own name. And the title field is
                 * indexed — writing "IMAGE" into it at index time would make
                 * 'image' a term attached to every picture on the site, which
                 * would then match any query mentioning the word.
                 *
                 * As a separate field the widget can render it as a badge,
                 * and an API consumer can use it, ignore it, or translate it,
                 * without any of them having to strip a prefix back off.
                 */
                $results[ $i ]['kind_label'] = __( 'IMAGE', 'mbr-isa' );

                $thumb = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'medium' );

                if ( is_array( $thumb ) && ! empty( $thumb[0] ) ) {
                    $results[ $i ]['thumbnail'] = (string) $thumb[0];
                    $results[ $i ]['thumb_w']   = (int) ( $thumb[1] ?? 0 );
                    $results[ $i ]['thumb_h']   = (int) ( $thumb[2] ?? 0 );
                }

                /*
                 * A larger rendition for the lightbox.
                 *
                 * 'large' rather than 'full': the original is whatever came
                 * off somebody's camera, which can be several megabytes and
                 * many times the size of any screen it will be shown on.
                 * Falls back to the original only when the large size was
                 * never generated — an image uploaded before the size was
                 * registered, or one smaller than the threshold.
                 */
                $large = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'large' );
                if ( ! is_array( $large ) || empty( $large[0] ) ) {
                    $large = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'full' );
                }

                if ( is_array( $large ) && ! empty( $large[0] ) ) {
                    $results[ $i ]['image_full']   = (string) $large[0];
                    $results[ $i ]['image_full_w'] = (int) ( $large[1] ?? 0 );
                    $results[ $i ]['image_full_h'] = (int) ( $large[2] ?? 0 );
                }

                // The alt text again, unhighlighted, so the widget can use it
                // as the img element's own alt attribute. A result that
                // describes a picture must not itself be undescribed.
                $results[ $i ]['image_alt'] = (string) get_post_meta(
                    (int) ( $r['post_id'] ?? 0 ),
                    '_wp_attachment_image_alt',
                    true
                );
            }

            if ( $deep_links ) {
                $fragment = $this->build_deep_link(
                    $r['excerpt'] ?? '',
                    $query_tokens,
                    $r['post_type'] ?? '',
                    (int) ( $r['page_number'] ?? 0 ),
                    (int) ( $r['page_top'] ?? 0 ),
                    (string) $phrase
                );
                if ( '' !== $fragment ) {
                    $results[ $i ]['url']           = ( $r['url'] ?? '' ) . $fragment;
                    $results[ $i ]['has_deep_link'] = true;
                }
            }
        }

        /*
         * Cap results by confidence level.
         *
         * Settable since 0.9.18. The defaults suit a small site; a
         * documentation library where one term legitimately appears across a
         * dozen guides wants a longer list, and that is a judgement about the
         * corpus rather than something the plugin can infer. Clamped to 1-10:
         * zero would mean a confidence level that finds results and shows
         * none, which is never what anybody wants.
         */
        $limit_setting = function ( $key, $default ) {
            $value = isset( $this->settings[ $key ] ) ? (int) $this->settings[ $key ] : $default;
            return max( 1, min( 10, $value ) );
        };

        $result_limits = [
            self::CONFIDENCE_HIGH   => $limit_setting( 'result_limit_high', 1 ),
            self::CONFIDENCE_MEDIUM => $limit_setting( 'result_limit_medium', 3 ),
            self::CONFIDENCE_LOW    => $limit_setting( 'result_limit_low', 3 ),
            self::CONFIDENCE_NONE   => 0,
        ];

        /*
         * A caller may state its own maximum, and where it does, that is the
         * one that applies.
         *
         * The confidence caps count documents in a *search* answer: at high
         * confidence result_limit_high is 1, because one confident answer is
         * the whole point of the level. Supplementary results beneath an
         * intent are a footnote to a different answer and are counted
         * separately, so inheriting the cap meant intent_supplementary_max
         * could never exceed one — and the better the supplementary hits
         * scored, the fewer of them were shown. CONFIDENCE_NONE still yields
         * nothing, since there is nothing to show.
         *
         * @since 0.9.19
         */
        $limit_override = max( 0, (int) $limit_override );
        $limit          = ( $limit_override > 0 && self::CONFIDENCE_NONE !== $confidence )
            ? $limit_override
            : $result_limits[ $confidence ];

        $results = array_slice( $results, 0, $limit );

        $suggestions = $this->suggestions_for_confidence( $confidence );

        return [
            'type'        => 'search_results',
            'confidence'  => $confidence,
            'phrase'      => (string) $phrase,
            'message'     => $message,
            'results'     => $results,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Build a response payload for an intent hit.
     *
     * @param array $intent
     * @return array
     */
    public function format_intent_response( array $intent ) {
        $response_html = (string) $intent['response'];

        return [
            'type'           => 'intent',
            'intent_id'      => $intent['id'],
            'intent_label'   => $intent['label'],
            // Plain-text fallback (used by logging and any consumer that
            // can't render HTML safely).
            'message'        => wp_strip_all_tags( $response_html ),
            // HTML-safe response. Already sanitised on save with wp_kses_post,
            // and stripped of any tags wp_kses_post wouldn't have allowed in
            // case the option was edited directly. The widget renders this
            // via innerHTML when present.
            'message_html'   => wp_kses_post( $response_html ),
            'message_format' => 'html',
            'results'        => [],
            'suggestions'    => [ __( 'Ask me something else about this site', 'mbr-isa' ) ],
        ];
    }

    /**
     * Build a response payload for empty/invalid queries.
     *
     * @return array
     */
    public function format_empty_query_response() {
        return [
            'type'        => 'empty_query',
            'confidence'  => self::CONFIDENCE_NONE,
            'message'     => __( 'What would you like to know? Try asking about a specific topic or service.', 'mbr-isa' ),
            'results'     => [],
            'suggestions' => [],
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Pick a confidence level based on score distribution.
     *
     * @param array $results Ranked results with 'score' keys.
     * @return string One of the CONFIDENCE_* constants.
     */
    private function determine_confidence( array $results ) {
        if ( empty( $results ) ) {
            return self::CONFIDENCE_NONE;
        }

        $top_score    = isset( $results[0]['score'] ) ? (float) $results[0]['score'] : 0.0;
        $second_score = isset( $results[1]['score'] ) ? (float) $results[1]['score'] : 0.0;

        /*
         * Dominant means the others are not answers, not merely that this one
         * is ahead.
         *
         * The ratio test alone was too easy to satisfy. A top score of 1.5
         * against a runner-up of 0.9 passed it, so the widget said "here is
         * what I found", showed one result, and discarded a 0.9 that would
         * have been shown as a perfectly good medium-confidence answer had the
         * leader scored slightly less. Searching a person's name showed that
         * plainly: an image matches a name on every weighted field at once —
         * filename into the title, alt text as both body and excerpt — so it
         * runs away with the score, and every document that actually
         * discusses the person was ranked, then sliced off behind it.
         *
         * A runner-up at or above the medium threshold is by definition worth
         * showing, so its presence now rules out high confidence. The leader
         * keeps its place at the top either way; what changes is that the
         * rest of the list survives.
         *
         * @since 0.9.18
         */
        $is_dominant = ( 1 === count( $results ) )
            || ( $second_score <= 0.0 )
            || ( $second_score < 1.0 && $top_score >= 1.5 * $second_score );

        if ( $top_score >= 1.5 && $is_dominant ) {
            return self::CONFIDENCE_HIGH;
        }
        if ( $top_score >= 1.0 ) {
            return self::CONFIDENCE_MEDIUM;
        }
        if ( $top_score > 0.0 ) {
            return self::CONFIDENCE_LOW;
        }
        return self::CONFIDENCE_NONE;
    }

    /**
     * Pick a message to accompany results at this confidence.
     *
     * @param string $confidence
     * @return string
     */
    private function message_for_confidence( $confidence ) {
        switch ( $confidence ) {
            case self::CONFIDENCE_HIGH:
                return __( 'Here is what I found on this site about that:', 'mbr-isa' );
            case self::CONFIDENCE_MEDIUM:
                return __( 'A few things on this site look relevant:', 'mbr-isa' );
            case self::CONFIDENCE_LOW:
                return __( 'I am not sure I found exactly what you are looking for, but these might help:', 'mbr-isa' );
            case self::CONFIDENCE_NONE:
            default:
                return __( 'I could not find anything specific about that on this site. Would you like to get in touch directly?', 'mbr-isa' );
        }
    }

    /**
     * Follow-up suggestions to offer the user.
     *
     * @param string $confidence
     * @return string[]
     */
    private function suggestions_for_confidence( $confidence ) {
        if ( self::CONFIDENCE_NONE === $confidence || self::CONFIDENCE_LOW === $confidence ) {
            return [
                __( 'Get in touch directly', 'mbr-isa' ),
                __( 'Try rephrasing your question', 'mbr-isa' ),
            ];
        }
        return [];
    }

    /**
     * Build a short snippet of text highlighting query terms.
     *
     * Looks for the first query-term hit in the excerpt and returns a
     * window around it with the matched terms wrapped in <mark>.
     *
     * @param string   $excerpt      Plain-text excerpt from the indexer.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return string HTML-safe string with <mark> wrapping matches.
     */
    private function build_snippet( $excerpt, array $query_tokens, $post_type = '', $phrase = '' ) {
        /*
         * Block markers record where one block element ended and the next
         * began. They are structural metadata and a visitor must never see
         * the pilcrow itself — but the boundary it marks is real paragraph
         * structure, and a snippet that runs two paragraphs together as one
         * sentence is harder to read than it needs to be.
         *
         * So the marker becomes a newline here, survives windowing as an
         * ordinary character, and is turned into a <br> after escaping.
         * Page markers are dropped to a space instead: a page boundary in a
         * PDF is a layout event, not a change of thought, and breaking there
         * would imply a paragraph that is not present.
         *
         * PDF text has no block markers at all. Its own line breaks are hard
         * wraps from the original layout — a break every dozen words, mid
         * sentence — and were collapsed before the passage was stored.
         * Reinstating them would make snippets more ragged, not more
         * readable, so PDF snippets stay as continuous prose.
         */
        $excerpt = str_replace( MBR_ISA_PDF_Extractor::PAGE_MARKER, ' ', (string) $excerpt );
        $excerpt = str_replace( MBR_ISA_Tokeniser::BLOCK_MARKER, "\n", $excerpt );

        // Collapse runs of whitespace, but keep single newlines: a run of
        // markers has already been collapsed upstream, and any blank line
        // left here would open a gap in a two-line snippet.
        $excerpt = (string) preg_replace( '/[ \t]*\n[ \t\n]*/u', "\n", $excerpt );
        $excerpt = (string) preg_replace( '/[^\S\n]+/u', ' ', $excerpt );
        $excerpt = trim( $excerpt );
        if ( '' === $excerpt ) {
            return '';
        }

        // Highlight by matching token prefixes on word boundaries.
        // We use stems so a token like "plugin" will match "plugins", "plugin"
        // etc. in the raw text. Matching rule: word starts with the stem.
        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 2 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }

        // Since 0.8.0 the stored excerpt is the full matching passage chunk
        // (up to 2,000 chars), so the first hit can sit anywhere in it. Cut
        // a window centred on the first hit, snapped to word boundaries,
        // before escaping and highlighting.
        $window = 240;

        if ( ! empty( $patterns ) ) {
            $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';

            // Prefer a match sitting in prose over one inside a code
            // sample; see locate_match().
            $char_pos = $this->locate_match( $excerpt, $query_tokens, $phrase );

            if ( null !== $char_pos ) {
                // Normally the window is centred on the match. But if what
                // precedes it is code — which happens when the only
                // occurrence of a term is inside a sample — leading with
                // that code wastes the snippet. Start at the match instead
                // and run forward into the prose that follows.
                $preceding = mb_substr( $excerpt, max( 0, $char_pos - 70 ), min( 70, $char_pos ) );
                $lead      = $this->looks_like_code( $preceding ) ? 0 : (int) floor( $window / 2 );

                $start = max( 0, $char_pos - $lead );

                // Snap the left edge forward to the next space so the
                // snippet never opens mid-word.
                if ( $start > 0 ) {
                    $space = mb_strpos( $excerpt, ' ', $start );
                    if ( false !== $space && $space < $char_pos ) {
                        $start = $space + 1;
                    }
                }

                $slice = mb_substr( $excerpt, $start, $window );

                // Trim a trailing part-word, then add ellipses where cut.
                if ( $start + $window < mb_strlen( $excerpt ) ) {
                    $last_space = mb_strrpos( $slice, ' ' );
                    if ( false !== $last_space && $last_space > (int) floor( $window / 2 ) ) {
                        $slice = mb_substr( $slice, 0, $last_space );
                    }
                    $slice .= '…';
                }
                if ( $start > 0 ) {
                    $slice = '…' . $slice;
                }

                $excerpt = $slice;
            } else {
                $excerpt = mb_substr( $excerpt, 0, $window );
            }
        } else {
            $excerpt = mb_substr( $excerpt, 0, $window );
        }

        /*
         * PDFs carry no block structure, so a snippet from one arrives as a
         * single unbroken run. Sentence boundaries are the only division the
         * text offers, so they stand in for paragraphs — artificial, but a
         * three-sentence snippet is markedly easier to scan broken up than
         * as one long line.
         */
        if ( 'attachment' === $post_type ) {
            $excerpt = $this->add_sentence_breaks( $excerpt );
        }

        // Escape first so <mark> and <br> are the only HTML we introduce.
        $escaped = esc_html( $excerpt );

        // Paragraph boundaries, marked as newlines above. Done after escaping
        // so the tag cannot come from the source text.
        $escaped = str_replace( [ "\r\n", "\n" ], '<br>', $escaped );

        if ( empty( $patterns ) ) {
            return $escaped;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';

        $highlighted = preg_replace_callback( $regex, function ( $m ) {
            return '<mark>' . $m[0] . '</mark>';
        }, $escaped );

        return is_string( $highlighted ) ? $highlighted : $escaped;
    }

    /**
     * Framing for a quoted phrase search.
     *
     * Kept separate from message_for_confidence() because the two say
     * different things. An ordinary search hedges — these *look* relevant —
     * whereas a phrase result is a statement of fact: the phrase is on the
     * page or it is not. The confidence badge still varies with score, since
     * that reflects how prominently the phrase features, but the wording no
     * longer implies doubt about whether it is there at all.
     *
     * The "nothing found" case is the one that matters. A visitor who quoted
     * something and got the standard "I could not find anything about that"
     * would reasonably conclude the words are absent from the site, when the
     * truth may be that they are present but split across a chunk boundary,
     * or spelled differently. Saying which phrase was searched for, and
     * suggesting dropping the quotes, is more honest and more useful.
     *
     * @param string $confidence
     * @param string $phrase
     * @return string
     */
    private function message_for_phrase( $confidence, $phrase ) {
        if ( self::CONFIDENCE_NONE === $confidence ) {
            return sprintf(
                /* translators: %s: the exact phrase the visitor searched for */
                __( 'I could not find the exact phrase "%s" on this site. Searching without the quotes will look for the words separately.', 'mbr-isa' ),
                $phrase
            );
        }

        return sprintf(
            /* translators: %s: the exact phrase the visitor searched for */
            __( 'Here is what contains the exact phrase "%s":', 'mbr-isa' ),
            $phrase
        );
    }

    /**
     * Build a short result list to sit beneath an intent's canned answer.
     *
     * Shares the snippet and deep-link treatment of an ordinary search, but
     * applies a higher bar: only results at medium confidence or better are
     * returned. An intent has already answered the question well, so weak
     * extras beneath it read as the assistant hedging on an answer it was
     * sure of a line earlier.
     *
     * @param array    $search_results Output of MBR_ISA_Indexer::search().
     * @param string[] $query_tokens   Stemmed tokens, for snippet highlighting.
     * @param int      $max            Hard cap on how many to return.
     * @return array Result rows, possibly empty.
     */
    public function format_supplementary_results( array $search_results, array $query_tokens, $max = 3 ) {
        $results = isset( $search_results['results'] ) ? $search_results['results'] : [];
        if ( empty( $results ) ) {
            return [];
        }

        if ( self::CONFIDENCE_HIGH !== $this->determine_confidence( $results )
            && self::CONFIDENCE_MEDIUM !== $this->determine_confidence( $results ) ) {
            return [];
        }

        // Reuse the full formatting path. This keeps snippets, deep links and
        // escaping identical to a normal search — there is no second code
        // path to drift out of step. The maximum is passed *in* rather than
        // applied to what comes back, because the confidence caps travel with
        // the formatting: slicing afterwards left the high-confidence cap of
        // one already applied, so this could never return more than a single
        // row however well the extras scored.
        $max       = max( 1, (int) $max );
        $formatted = $this->format_search_response( $search_results, $query_tokens, '', $max );
        $rows      = isset( $formatted['results'] ) ? $formatted['results'] : [];

        return array_slice( $rows, 0, $max );
    }


    /**
     * Is vertical positioning of PDF links switched on?
     *
     * Separate from deep_link_results because the trade-off is different: a
     * text-fragment link on a post costs nothing when it misses, whereas a
     * PDF offset is an estimate that also overrides the reader's zoom. Some
     * sites will prefer the plain page link.
     *
     * @return bool
     */
    private function pdf_position_enabled() {
        return ! isset( $this->settings['pdf_link_position'] )
            || ! empty( $this->settings['pdf_link_position'] );
    }


    /**
     * Zoom percentage for a positioned PDF link.
     *
     * Any scrolling open parameter also sets a view, so a scale has to be
     * named. 100 keeps the document at its natural size, which is what most
     * readers default to.
     *
     * @return int
     */
    private function pdf_link_zoom() {
        $zoom = isset( $this->settings['pdf_link_zoom'] )
            ? (int) $this->settings['pdf_link_zoom']
            : 100;

        // Well outside this and a viewer is as likely to ignore the whole
        // fragment as to honour it, losing the page jump along with the zoom.
        return max( 25, min( 400, $zoom ) );
    }


    /**
     * Insert line breaks at sentence boundaries.
     *
     * Used for PDF snippets, which have no paragraph structure to fall back
     * on. A break goes after . ! or ? when what follows looks like the start
     * of a new sentence.
     *
     * The guards matter more than the rule. Technical prose is full of full
     * stops that end nothing: section references (§ 11.5.), version numbers,
     * initials, and abbreviations such as e.g. and Fig. Breaking on those
     * produces a snippet more broken up than the original, which is the
     * opposite of the point. Decimals need no guard — a digit is not
     * followed by a space — but the rest do.
     *
     * A very short trailing fragment is left attached rather than stranded
     * on a line of its own.
     *
     * @param string $text Windowed snippet text, whitespace already collapsed.
     * @return string Same text with newlines inserted at sentence ends.
     */
    private function add_sentence_breaks( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return $text;
        }

        /*
         * Done by hand rather than with one regex. The obvious approach — a
         * negative lookbehind listing the abbreviations — will not compile,
         * because PCRE requires lookbehind assertions to be a fixed length
         * and "e.g" and "approx" are not. So candidates are found first and
         * each is then judged on the text preceding it.
         */
        if ( ! preg_match_all( '/[.!?] +(?=[A-Z\x{201C}"\x{2018}\'(\[])/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
            return $text;
        }

        $abbr = [
            'e.g', 'i.e', 'etc', 'vs', 'cf', 'approx', 'no', 'fig', 'figs',
            'ch', 'sec', 'pp', 'mr', 'mrs', 'ms', 'dr', 'prof', 'st', 'jr',
            'sr', 'inc', 'ltd', 'co', 'al', 'ed', 'eds', 'vol',
        ];

        $cuts = [];
        foreach ( $m[0] as $match ) {
            $offset = (int) $match[1];

            // The word ending at this full stop.
            $before = substr( $text, max( 0, $offset - 14 ), min( 14, $offset ) );
            if ( ! preg_match( '/([\p{L}\p{N}.]+)$/u', $before, $w ) ) {
                continue;
            }
            $word = rtrim( $w[1], '.' );

            // A single capital is an initial — "R. Palmer" is one name.
            if ( 1 === mb_strlen( $word ) && preg_match( '/^\p{Lu}$/u', $word ) ) {
                continue;
            }

            if ( in_array( mb_strtolower( $word ), $abbr, true ) ) {
                continue;
            }

            // Cut after the punctuation, before the space.
            $cuts[] = $offset + 1;
        }

        if ( empty( $cuts ) ) {
            return $text;
        }

        $lines = [];
        $prev  = 0;
        foreach ( $cuts as $cut ) {
            $lines[] = substr( $text, $prev, $cut - $prev );
            $prev    = $cut;
        }
        $lines[] = substr( $text, $prev );

        // Re-join anything too short to earn a line of its own. The bar is
        // deliberately low: "Others are skipped." is a real sentence and
        // belongs on its own line, whereas a stray "OK." does not.
        $out = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( ! empty( $out ) && mb_strlen( $line ) < 15 ) {
                $out[ count( $out ) - 1 ] .= ' ' . $line;
                continue;
            }
            $out[] = $line;
        }

        return implode( "\n", $out );
    }


}