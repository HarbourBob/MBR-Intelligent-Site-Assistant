<?php
/**
 * Passage chunker.
 *
 * Pure, testable, WordPress-free. Splits long plain text into overlapping
 * word-window chunks so that long documents (large PDFs especially) can be
 * indexed and ranked at passage level rather than as one monolithic
 * document. Each chunk becomes its own row in the documents table and its
 * own BM25 scoring unit; search collapses chunks back to one result per
 * post, keeping the best-matching passage for the snippet.
 *
 * Why words, not tokens: the chunk boundary only needs to be *roughly*
 * even. Counting words is cheap, deterministic, and independent of the
 * stopword list and stemmer, so a chunk's stored excerpt always matches
 * what was indexed from it.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Chunker {

    /**
     * Target chunk size in words.
     *
     * @var int
     */
    private $size_words;

    /**
     * Overlap between consecutive chunks in words. Overlap means a
     * sentence that straddles a boundary is fully present in at least
     * one chunk, so a query matching it cannot fall down the crack.
     *
     * @var int
     */
    private $overlap_words;

    /**
     * @param int $size_words    Words per chunk. Default 250 (~1,500 chars).
     * @param int $overlap_words Words shared between consecutive chunks. Default 50.
     */
    public function __construct( $size_words = 250, $overlap_words = 50 ) {
        $this->size_words    = max( 40, (int) $size_words );
        $this->overlap_words = max( 0, (int) $overlap_words );

        if ( $this->overlap_words >= $this->size_words ) {
            $this->overlap_words = (int) floor( $this->size_words / 4 );
        }
    }

    /**
     * Split text into overlapping word-window chunks.
     *
     * Text at or below 1.5x the chunk size is returned as a single chunk —
     * splitting a 260-word post into two heavily-overlapping chunks would
     * only duplicate postings for no ranking benefit.
     *
     * @param string $text Plain text (tags already stripped by the caller).
     * @return string[] One or more non-empty chunk strings, in document order.
     */
    public function chunk( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return [];
        }

        $words = preg_split( '/ /', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $words ) ) {
            return [];
        }

        $total = count( $words );
        if ( $total <= (int) floor( $this->size_words * 1.5 ) ) {
            return [ implode( ' ', $words ) ];
        }

        $chunks = [];
        $step   = max( 1, $this->size_words - $this->overlap_words );

        for ( $start = 0; $start < $total; $start += $step ) {
            $slice = array_slice( $words, $start, $this->size_words );
            if ( empty( $slice ) ) {
                break;
            }

            // If the final window would leave a runt shorter than the
            // overlap, extend the previous chunk to the end instead of
            // emitting a tiny fragment that BM25's length normalisation
            // would over-reward.
            $remaining_after = $total - ( $start + $this->size_words );
            if ( $remaining_after > 0 && $remaining_after <= $this->overlap_words ) {
                $slice = array_slice( $words, $start );
                $chunks[] = implode( ' ', $slice );
                break;
            }

            $chunks[] = implode( ' ', $slice );

            if ( $start + $this->size_words >= $total ) {
                break;
            }
        }

        return $chunks;
    }

    // --- Accessors (useful for logging/diagnostics) --------------------------

    public function get_size_words() {
        return $this->size_words;
    }

    public function get_overlap_words() {
        return $this->overlap_words;
    }

    /**
     * Does this passage look like a table of contents or an index?
     *
     * Contents pages are the densest concentration of topic vocabulary in a
     * document — they list every heading in it — so they out-score the
     * passage that actually answers the question. They are also useless as
     * an answer: the visitor gets a list of section titles and a page
     * number rather than prose.
     *
     * Detection is structural rather than lexical (no reliance on the word
     * "contents", which does not survive translation or every house style):
     * a contents page is a run of short numbered entries with little
     * connective prose. Deliberately conservative — a false positive only
     * applies a scoring penalty, never an exclusion, but a chapter that
     * happens to be list-heavy should not be demoted.
     *
     * @param string $text Passage text, already leader-stripped.
     * @return bool
     */
    public function looks_like_contents( $text ) {
        $text = trim( (string) $text );
        if ( '' === $text ) {
            return false;
        }

        $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $total = count( $words );
        if ( $total < 25 ) {
            return false;
        }

        // Entry markers: an integer followed by a full stop and then the
        // start of a heading — "3. How it works". Requiring a letter after
        // the stop excludes decimals in data tables ("1.5", "0.75"), which
        // an earlier density-based approach wrongly flagged; demoting a
        // settings reference table would be far worse than missing a
        // contents page.
        $found = preg_match_all(
            '/(?:^|\s)(\d{1,2})\.\s+\p{L}/u',
            $text,
            $m
        );
        if ( $found < 5 ) {
            return false;
        }

        $numbers = array_map( 'intval', $m[1] );

        // A contents page numbers its entries in order. A chapter that
        // happens to contain a numbered list usually has one short run;
        // a contents page is almost entirely run.
        $ascending = 0;
        for ( $i = 1, $n = count( $numbers ); $i < $n; $i++ ) {
            if ( $numbers[ $i ] > $numbers[ $i - 1 ] ) {
                $ascending++;
            }
        }
        if ( $ascending < ( count( $numbers ) - 1 ) * 0.8 ) {
            return false;
        }

        // Stopwords are the fingerprint of connective prose. Contents
        // entries are noun phrases and carry very few of them; a numbered
        // list of instructions inside a chapter carries plenty, which is
        // what keeps step-by-step content out of this branch.
        $function_words = 0;
        $common = [ 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'of', 'in', 'on',
                    'that', 'this', 'it', 'as', 'be', 'by', 'from', 'you', 'your', 'not',
                    'have', 'has', 'but', 'they', 'which', 'when', 'if', 'so' ];
        foreach ( $words as $w ) {
            if ( in_array( strtolower( trim( $w, '.,;:()[]' ) ), $common, true ) ) {
                $function_words++;
            }
        }

        return ( $function_words / $total ) < 0.12;
    }
}
