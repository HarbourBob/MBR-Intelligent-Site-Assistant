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
 * Contains code contributed by James Wilson (Director of Technology,
 * Cogora), whose review and patches to the passage-chunking logic are
 * gratefully acknowledged.
 *
 * @package MBR_ISA
 * @author  Robert Palmer
 * @author  James Wilson <Cogora>
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Chunker {

    /**
     * Entry marker for contents detection: a number beginning a heading,
     * with or without a full stop after it. See looks_like_contents().
     */
    const CONTENTS_MARKER = '/(?:^|\s)(\d{1,2})(?:\.\s+\p{L}|\s+\p{Lu})/u';

    /** Passages shorter than this are never treated as a contents page. */
    const CONTENTS_MIN_WORDS = 25;

    /** Entries needed in one ascending run before a passage can qualify. */
    const CONTENTS_MIN_ENTRIES = 5;

    /**
     * How much of the passage the entry run must account for.
     *
     * Low enough to catch a contents list sharing a chunk with the prose
     * either side of it, high enough that a chapter containing one short
     * numbered list is left alone.
     */
    const CONTENTS_MIN_SPAN = 0.20;

    /** Function-word ratio above which the run reads as prose, not entries. */
    const CONTENTS_MAX_PROSE = 0.12;

    /** A run must cover at least this many words to earn its own chunk. */
    const CONTENTS_MIN_RUN_WORDS = 20;

    /** Entries further apart than this are section numbering, not a list. */
    const CONTENTS_MAX_ENTRY_WORDS = 25;

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
     * Word positions at which a chunk must start or end.
     *
     * A contents run is the one structure that must not be shared between
     * chunks. Everything else benefits from the overlap: a sentence
     * straddling a boundary is present whole in at least one chunk, so a
     * query matching it cannot fall down the crack. A contents run inverts
     * that. Copying its opening entries into the neighbouring chunk hands
     * those chapter titles to a passage that is mostly ordinary prose, and
     * that passage is not a contents page by any reasonable test — so it is
     * not detected, not demoted, and free to represent the document. The
     * result is the exact failure the demotion exists to prevent, produced
     * by the mechanism meant to make matching more forgiving.
     *
     * Splitting at the run's edges gives the entries a chunk of their own,
     * where they are detected and demoted, and leaves the prose either side
     * carrying only prose. No overlap is taken across these boundaries,
     * which is the whole point of them.
     *
     * Deliberately narrower than looks_like_contents(): this only has to
     * find where a run of entries begins and ends, and a false boundary
     * costs a chunk edge in a slightly different place, not a demotion. The
     * two guards below keep ordinary numbered lists out — a run has to be
     * long enough to matter and tight enough to be a list of titles rather
     * than a document's section numbering.
     *
     * @since 0.9.7
     *
     * @param string[] $words Word array for the whole document.
     * @return int[] Sorted, unique word indices.
     */
    public function contents_boundaries( array $words ) {
        $total = count( $words );
        if ( $total < self::CONTENTS_MIN_WORDS ) {
            return [];
        }

        // Entry markers, as word positions: a one or two digit number whose
        // following word begins a heading. Same two forms looks_like_contents()
        // accepts, and the same reason for requiring a capital after the
        // undotted one.
        $marks = [];
        for ( $i = 0; $i < $total - 1; $i++ ) {
            if ( ! preg_match( '/^(\d{1,2})(\.?)$/', $words[ $i ], $m ) ) {
                continue;
            }
            $pattern = ( '.' === $m[2] ) ? '/^\p{L}/u' : '/^\p{Lu}/u';
            if ( ! preg_match( $pattern, $words[ $i + 1 ] ) ) {
                continue;
            }
            $marks[] = [ $i, (int) $m[1] ];
        }

        if ( count( $marks ) < self::CONTENTS_MIN_ENTRIES ) {
            return [];
        }

        $bounds    = [];
        $run_start = 0;
        $count     = count( $marks );

        for ( $k = 1; $k <= $count; $k++ ) {
            $ends_run = ( $k === $count ) || ( $marks[ $k ][1] <= $marks[ $k - 1 ][1] );
            if ( ! $ends_run ) {
                continue;
            }

            $length = $k - $run_start;
            $first  = $marks[ $run_start ][0];
            $last   = $marks[ $k - 1 ][0];
            $span   = $last - $first;
            $mean   = $length > 1 ? (int) round( $span / ( $length - 1 ) ) : 0;

            $run_start = $k;

            if ( $length < self::CONTENTS_MIN_ENTRIES ) {
                continue;
            }
            // Too short to be worth its own chunk.
            if ( $span < self::CONTENTS_MIN_RUN_WORDS ) {
                continue;
            }
            // Entries too far apart to be a list of titles — this is a
            // document numbering its own sections, with prose in between.
            if ( $mean > self::CONTENTS_MAX_ENTRY_WORDS ) {
                continue;
            }

            $bounds[] = $first;
            $bounds[] = min( $total, $last + max( 2, $mean ) );
        }

        sort( $bounds );

        return array_values( array_unique( $bounds ) );
    }

    /**
     * Plan the chunk windows for a document.
     *
     * Shared by chunk() and chunk_with_pages() so the two cannot drift —
     * they previously carried the same window arithmetic twice over.
     *
     * @param string[] $words Word array.
     * @return array<int,array{0:int,1:int}> [start, length] pairs.
     */
    private function windows( array $words ) {
        $total  = count( $words );
        $bounds = $this->contents_boundaries( $words );

        // Short content stays a single chunk — splitting a 260-word post
        // into two heavily-overlapping chunks only duplicates postings for
        // no ranking benefit. A contents run is the exception: a short
        // document that opens with one still needs it separated out.
        if ( $total <= (int) floor( $this->size_words * 1.5 ) && empty( $bounds ) ) {
            return [ [ 0, $total ] ];
        }

        $out   = [];
        $start = 0;

        while ( $start < $total ) {
            $end  = min( $total, $start + $this->size_words );
            $hard = false;

            foreach ( $bounds as $b ) {
                if ( $b > $start && $b < $end ) {
                    $end  = $b;
                    $hard = true;
                    break;
                }
            }

            if ( ! $hard ) {
                // If the final window would leave a runt shorter than the
                // overlap, extend to the end instead of emitting a tiny
                // fragment that BM25's length normalisation over-rewards.
                // Not when a boundary lies ahead: the runt is somebody
                // else's chunk in that case.
                $next_hard = null;
                foreach ( $bounds as $b ) {
                    if ( $b >= $end ) {
                        $next_hard = $b;
                        break;
                    }
                }
                $remaining = $total - $end;
                if ( $remaining > 0 && $remaining <= $this->overlap_words && null === $next_hard ) {
                    $end = $total;
                }
            }

            $out[] = [ $start, $end - $start ];

            if ( $end >= $total ) {
                break;
            }

            // Overlap between ordinary windows, none across a boundary.
            $start = $hard ? $end : max( $start + 1, $end - $this->overlap_words );
        }

        return $out;
    }

    /**
     * Split text into overlapping word-window chunks.
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

        $chunks = [];
        foreach ( $this->windows( $words ) as $w ) {
            $slice = array_slice( $words, $w[0], $w[1] );
            if ( ! empty( $slice ) ) {
                $chunks[] = implode( ' ', $slice );
            }
        }

        return $chunks;
    }

    /**
     * Split text into chunks, recording which page each chunk begins on.
     *
     * Used for PDFs, whose extracted text carries a page marker between
     * pages. A chunk that straddles a boundary is attributed to the page it
     * starts on, which is where a reader following the link should land.
     *
     * @param string $text   Plain text containing page markers.
     * @param string $marker Page marker sentinel.
     * @return array<int,array{text:string,page:int}>
     */
    public function chunk_with_pages( $text, $marker ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return [];
        }

        $words = preg_split( '/ /', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $words ) ) {
            return [];
        }

        // Page number for each word position: starts at page 1 and advances
        // every time a marker is passed. Alongside it, how many words into
        // that page each position sits, and how many words each page holds —
        // together these give the fraction of the way down a page a chunk
        // begins, which is what the deep link uses to aim below the top of
        // the page. See MBR_ISA_Indexer::estimate_page_top().
        $page_at     = [];
        $word_in_page = [];
        $page_words  = [];
        $page        = 1;
        $in_page     = 0;
        foreach ( $words as $i => $w ) {
            if ( $marker === $w ) {
                $page_words[ $page ] = $in_page;
                $page++;
                $in_page             = 0;
                $page_at[ $i ]       = $page;
                $word_in_page[ $i ]  = 0;
                continue;
            }
            $page_at[ $i ]      = $page;
            $word_in_page[ $i ] = $in_page;
            $in_page++;
        }
        $page_words[ $page ] = $in_page;

        // Same window plan as chunk(), so a PDF and a post are divided by
        // identical rules and a contents run gets its own chunk in both.
        $out = [];
        foreach ( $this->windows( $words ) as $w ) {
            $slice = array_slice( $words, $w[0], $w[1] );
            if ( empty( $slice ) ) {
                continue;
            }
            $out[] = [
                'text'     => implode( ' ', $slice ),
                'page'     => $page_at[ $w[0] ] ?? 1,
                'fraction' => $this->page_fraction( $w[0], $page_at, $word_in_page, $page_words ),
            ];
        }

        return $out;
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
        $text = trim( str_replace( [ "\xc2\xb6", "\xc2\xa4" ], ' ', (string) $text ) );
        if ( '' === $text ) {
            return false;
        }

        $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $total = count( $words );
        if ( $total < self::CONTENTS_MIN_WORDS ) {
            return false;
        }

        /*
         * Entry markers: an integer beginning a heading.
         *
         * Two forms, because documents write them both ways and the earlier
         * pattern only accepted the first:
         *
         *   "3. How it works"  — number, full stop, heading
         *   "3 How it works"   — number, heading, no punctuation at all
         *
         * The second is what this plugin's own user guide uses, and what a
         * great many templates produce, so requiring the stop meant a
         * contents page written that way was never recognised: no demotion,
         * and no substitution of the chapter for the contents line that
         * names it. The dotted form accepts any letter after the stop, as
         * before; the dotless form requires a capital, since without the
         * punctuation there is nothing else separating "7 The widget" from
         * "30 requests per minute" in a data table.
         *
         * Decimals are still excluded either way. In "1.5" the digit after
         * the stop is not a letter, and the "5" is not preceded by
         * whitespace — so a settings reference table does not register, which
         * matters more than catching every contents page.
         */
        if ( ! preg_match_all( self::CONTENTS_MARKER, $text, $m, PREG_OFFSET_CAPTURE ) ) {
            return false;
        }

        $marks = $m[1];
        if ( count( $marks ) < self::CONTENTS_MIN_ENTRIES ) {
            return false;
        }

        // A contents page numbers its entries in order. Find the longest
        // ascending run rather than testing the markers as a whole: a
        // passage can hold a contents list and something else besides.
        $run_start = 0;
        $best_from = 0;
        $best_to   = 0;
        for ( $i = 1, $n = count( $marks ); $i < $n; $i++ ) {
            if ( (int) $marks[ $i ][0] <= (int) $marks[ $i - 1 ][0] ) {
                $run_start = $i;
                continue;
            }
            if ( ( $i - $run_start ) > ( $best_to - $best_from ) ) {
                $best_from = $run_start;
                $best_to   = $i;
            }
        }

        $run = array_slice( $marks, $best_from, $best_to - $best_from + 1 );
        if ( count( $run ) < self::CONTENTS_MIN_ENTRIES ) {
            return false;
        }

        /*
         * Measure the run, not the passage.
         *
         * The original test asked whether the whole chunk read as connective
         * prose. That works when a contents page fills its passage and fails
         * when it does not: a short contents list sitting in a chunk with a
         * cover blurb on one side and the opening of chapter one on the other
         * is a dense list of every topic in the document — exactly the thing
         * that out-scores the chapter a visitor asked for — but the prose
         * around it lifts the average and the list is waved through.
         *
         * So the span covered by the run is measured on its own, and the run
         * additionally has to account for a fair share of the passage. A
         * chapter carrying one small numbered list still fails on the second
         * test, which is what keeps step-by-step instructions out of here.
         */
        $from = (int) $run[0][1];
        $to   = (int) $run[ count( $run ) - 1 ][1];

        $span_words = preg_split( '/\s+/u', substr( $text, $from, max( 0, $to - $from ) ),
                                  -1, PREG_SPLIT_NO_EMPTY );

        // The last entry's own words fall after its marker, so extend the
        // span by the mean length of the entries before it.
        $entries = max( 1, count( $run ) - 1 );
        $tail    = preg_split( '/\s+/u', substr( $text, $to ), -1, PREG_SPLIT_NO_EMPTY );
        $span_words = array_merge(
            $span_words,
            array_slice( $tail, 0, (int) floor( count( $span_words ) / $entries ) )
        );

        $span_total = count( $span_words );
        if ( 0 === $span_total ) {
            return false;
        }

        if ( ( $span_total / $total ) < self::CONTENTS_MIN_SPAN ) {
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
        foreach ( $span_words as $w ) {
            if ( in_array( strtolower( trim( $w, '.,;:()[]' ) ), $common, true ) ) {
                $function_words++;
            }
        }

        return ( $function_words / $span_total ) < self::CONTENTS_MAX_PROSE;
    }

    /**
     * How far down its page a chunk begins, as a fraction from 0 to 1.
     *
     * Measured in words, which assumes text is spread evenly down the page.
     * That is roughly true of prose and wrong wherever a figure, a table or
     * a half-empty final page breaks the assumption — which is why the
     * caller aims deliberately high rather than treating this as exact.
     *
     * @param int   $start        Word index the chunk starts at.
     * @param array $page_at      Word index => page number.
     * @param array $word_in_page Word index => words preceding it on its page.
     * @param array $page_words   Page number => total words on that page.
     * @return float
     */
    private function page_fraction( $start, array $page_at, array $word_in_page, array $page_words ) {
        $page  = $page_at[ $start ] ?? 1;
        $total = $page_words[ $page ] ?? 0;

        if ( $total < 1 ) {
            return 0.0;
        }

        $fraction = ( $word_in_page[ $start ] ?? 0 ) / $total;

        return max( 0.0, min( 1.0, $fraction ) );
    }

}
