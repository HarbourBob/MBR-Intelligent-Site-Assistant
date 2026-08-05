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

/**
 * Turns search results into a conversational response payload. See the file docblock above for details.
 */
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

    public function __construct( array $settings = null ) {
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
    private function build_deep_link( $passage, array $query_tokens, $post_type, $page_number ) {
        if ( 'attachment' === $post_type ) {
            if ( $page_number < 1 ) {
                return '';
            }

            // The stored page is the one the chunk *begins* on, but a chunk
            // of a few hundred words routinely spans a page boundary, so the
            // matched text is often on the next page. Count the page markers
            // that fall before the match to land on the right one.
            $page = $page_number + $this->pages_before_match( $passage, $query_tokens );

            return '#page=' . (int) $page;
        }

        return $this->build_text_fragment( $passage, $query_tokens, $post_type );
    }

    /**
     * How many page boundaries fall before the matching text in a passage.
     *
     * @param string   $passage      Passage text, including page markers.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return int Zero when the match is on the passage's first page.
     */
    private function pages_before_match( $passage, array $query_tokens ) {
        $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;
        if ( false === strpos( (string) $passage, $marker ) ) {
            return 0;
        }

        $pos = $this->locate_match( (string) $passage, $query_tokens );
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
     * Prefers a match sitting in prose over one inside a code sample; falls
     * back to the first match when every occurrence is in code. Shared by
     * the snippet builder and the deep-link builder so that the passage
     * shown and the place linked to are always the same one.
     *
     * @param string   $passage      Passage text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return int|null Character offset, or null if nothing matched.
     */
    private function locate_match( $passage, array $query_tokens ) {
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

        $first = null;
        foreach ( $all[0] as $match ) {
            $pos = mb_strlen( substr( $passage, 0, (int) $match[1] ) );
            if ( null === $first ) {
                $first = $pos;
            }
            $around = mb_substr( $passage, max( 0, $pos - 60 ), 160 );
            if ( ! $this->looks_like_code( $around ) ) {
                return $pos;
            }
        }

        return $first;
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
     * @return array
     */
    public function format_search_response( array $search_results, array $query_tokens ) {
        $results    = isset( $search_results['results'] ) ? $search_results['results'] : [];
        $confidence = $this->determine_confidence( $results );
        $message    = $this->message_for_confidence( $confidence );

        // Attach snippets (plus keep the raw excerpt) and, where possible, a
        // text fragment so the link lands on the matching passage rather than
        // the top of the page.
        $deep_links = ! isset( $this->settings['deep_link_results'] )
            || ! empty( $this->settings['deep_link_results'] );

        foreach ( $results as $i => $r ) {
            $results[ $i ]['snippet'] = $this->build_snippet( $r['excerpt'] ?? '', $query_tokens );

            if ( $deep_links ) {
                $fragment = $this->build_deep_link(
                    $r['excerpt'] ?? '',
                    $query_tokens,
                    $r['post_type'] ?? '',
                    (int) ( $r['page_number'] ?? 0 )
                );
                if ( '' !== $fragment ) {
                    $results[ $i ]['url']           = ( $r['url'] ?? '' ) . $fragment;
                    $results[ $i ]['has_deep_link'] = true;
                }
            }
        }

        // Cap results by confidence level.
        $result_limits = [
            self::CONFIDENCE_HIGH   => 1,
            self::CONFIDENCE_MEDIUM => 3,
            self::CONFIDENCE_LOW    => 3,
            self::CONFIDENCE_NONE   => 0,
        ];
        $results = array_slice( $results, 0, $result_limits[ $confidence ] );

        $suggestions = $this->suggestions_for_confidence( $confidence );

        return [
            'type'        => 'search_results',
            'confidence'  => $confidence,
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

        $is_dominant = ( 1 === count( $results ) )
            || ( $second_score <= 0.0 )
            || ( $top_score >= 1.5 * $second_score );

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
    private function build_snippet( $excerpt, array $query_tokens ) {
        // Block markers are structural metadata for deep linking; visitors
        // must never see them.
        $excerpt = str_replace(
            [ MBR_ISA_Tokeniser::BLOCK_MARKER, MBR_ISA_PDF_Extractor::PAGE_MARKER ],
            ' ',
            (string) $excerpt
        );
        $excerpt = trim( (string) preg_replace( '/\s+/u', ' ', $excerpt ) );
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
            $char_pos = $this->locate_match( $excerpt, $query_tokens );

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

        // Escape first so <mark> is the only HTML we introduce.
        $escaped = esc_html( $excerpt );

        if ( empty( $patterns ) ) {
            return $escaped;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';

        $highlighted = preg_replace_callback( $regex, function ( $m ) {
            return '<mark>' . $m[0] . '</mark>';
        }, $escaped );

        return is_string( $highlighted ) ? $highlighted : $escaped;
    }
}