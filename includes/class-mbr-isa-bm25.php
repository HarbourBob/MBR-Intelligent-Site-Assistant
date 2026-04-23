<?php
/**
 * BM25 scoring engine.
 *
 * Pure, testable, WordPress-free. Given pre-fetched term statistics
 * and document lengths, produces a ranked list of documents for a
 * query. The Indexer + DB layer above is responsible for populating
 * the stats; this class just does the maths.
 *
 * Formula:
 *   score(D,Q) = Σ  IDF(qi) * ( tf * (k1+1) ) / ( tf + k1 * (1 - b + b * |D|/avgdl) )
 *
 * Where:
 *   qi       = query term i
 *   tf       = frequency of qi in document D
 *   |D|      = length of document D in tokens
 *   avgdl    = average document length across corpus
 *   k1, b    = tuning parameters (defaults 1.2 and 0.75 — industry standard)
 *   IDF(qi)  = log( (N - n + 0.5) / (n + 0.5) + 1 )
 *     N = total number of documents
 *     n = number of documents containing qi
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_BM25 {

    /**
     * k1 — term frequency saturation parameter.
     *
     * @var float
     */
    private $k1;

    /**
     * b — length normalisation parameter.
     *
     * @var float
     */
    private $b;

    /**
     * @param float $k1 Typically 1.2–2.0. Default 1.2.
     * @param float $b  Between 0 and 1. Default 0.75.
     */
    public function __construct( $k1 = 1.2, $b = 0.75 ) {
        $this->k1 = (float) $k1;
        $this->b  = (float) $b;
    }

    /**
     * Calculate IDF for a single term.
     *
     * Uses the BM25+ variant with the +1 smoothing term, which guarantees
     * a positive IDF even when a term appears in more than half the
     * corpus. Prevents negative scores that can happen with plain BM25.
     *
     * @param int $total_docs    Total documents in corpus.
     * @param int $doc_frequency Number of documents containing this term.
     * @return float
     */
    public function calculate_idf( $total_docs, $doc_frequency ) {
        if ( $total_docs <= 0 ) {
            return 0.0;
        }
        $numerator   = $total_docs - $doc_frequency + 0.5;
        $denominator = $doc_frequency + 0.5;
        return log( ( $numerator / $denominator ) + 1.0 );
    }

    /**
     * Score a single document against a single query term.
     *
     * @param float $idf            Pre-computed IDF of the term.
     * @param int   $term_frequency Count of this term in this document.
     * @param int   $doc_length     Document length in tokens.
     * @param float $avg_doc_length Average document length across corpus.
     * @return float
     */
    public function score_term_in_document( $idf, $term_frequency, $doc_length, $avg_doc_length ) {
        if ( $term_frequency <= 0 || $avg_doc_length <= 0 ) {
            return 0.0;
        }

        $k1       = $this->k1;
        $b        = $this->b;
        $norm     = 1.0 - $b + $b * ( $doc_length / $avg_doc_length );
        $num      = $term_frequency * ( $k1 + 1.0 );
        $denom    = $term_frequency + $k1 * $norm;

        return $idf * ( $num / $denom );
    }

    /**
     * Score every candidate document against a full query.
     *
     * @param array $query_terms    Unique stemmed query tokens.
     * @param array $term_stats     Map: term => [
     *                                 'idf'      => float,
     *                                 'postings' => [ doc_id => term_frequency ]
     *                              ].
     * @param array $doc_lengths    Map: doc_id => token_count.
     * @param float $avg_doc_length Average document length across corpus.
     * @return array Map: doc_id => score, sorted descending.
     */
    public function score_documents( array $query_terms, array $term_stats, array $doc_lengths, $avg_doc_length ) {
        $scores = [];

        foreach ( $query_terms as $term ) {
            if ( ! isset( $term_stats[ $term ] ) ) {
                continue;
            }

            $idf      = $term_stats[ $term ]['idf'];
            $postings = $term_stats[ $term ]['postings'];

            foreach ( $postings as $doc_id => $tf ) {
                $doc_length = isset( $doc_lengths[ $doc_id ] ) ? $doc_lengths[ $doc_id ] : 0;
                if ( $doc_length <= 0 ) {
                    continue;
                }

                $contribution = $this->score_term_in_document( $idf, $tf, $doc_length, $avg_doc_length );

                if ( ! isset( $scores[ $doc_id ] ) ) {
                    $scores[ $doc_id ] = 0.0;
                }
                $scores[ $doc_id ] += $contribution;
            }
        }

        arsort( $scores, SORT_NUMERIC );

        return $scores;
    }

    // --- Accessors (useful for logging/diagnostics) --------------------------

    public function get_k1() {
        return $this->k1;
    }

    public function get_b() {
        return $this->b;
    }
}