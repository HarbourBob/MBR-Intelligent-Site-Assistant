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

        // Attach snippets (plus keep the raw excerpt).
        foreach ( $results as $i => $r ) {
            $results[ $i ]['snippet'] = $this->build_snippet( $r['excerpt'] ?? '', $query_tokens );
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
        $excerpt = trim( (string) preg_replace( '/\s+/u', ' ', (string) $excerpt ) );
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

            if ( preg_match( $regex, $excerpt, $m, PREG_OFFSET_CAPTURE ) ) {
                $char_pos = mb_strlen( substr( $excerpt, 0, (int) $m[0][1] ) );
                $start    = max( 0, $char_pos - (int) floor( $window / 2 ) );

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