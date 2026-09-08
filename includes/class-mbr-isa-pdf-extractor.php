<?php
/**
 * PDF text extractor — pulls a plain-text layer out of a PDF file in pure PHP.
 *
 * No Composer, no external binaries. Uses only zlib (gzuncompress / gzinflate),
 * which ships with PHP by default, to inflate FlateDecode content streams.
 *
 * Scope and honest limits:
 *   - Works well on "born-digital" PDFs with standard fonts (exports from Word,
 *     LibreOffice, most report generators).
 *   - Encrypted PDFs are skipped (we do not attempt to decrypt).
 *   - Image-only / scanned PDFs have no text layer, so nothing is extracted.
 *     Detecting those and skipping cleanly is the correct behaviour — OCR is
 *     out of scope for a self-hosted, dependency-free plugin.
 *   - Subsetted / CID (Type0) fonts with no usable encoding may yield partial
 *     or garbled text. The downstream tokeniser's junk filters (min/max token
 *     length, pure-number removal) absorb most of that noise.
 *
 * The extractor never throws on malformed input: it returns an empty string and
 * records a status via {@see last_status()} so the caller can log or fall back
 * to attachment metadata.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_PDF_Extractor {

    /**
     * Sentinel marking a page boundary in extracted text. Survives chunking
     * as an ordinary word, is stripped before tokenising so it is never
     * indexed, and is removed before any snippet is displayed.
     */
    const PAGE_MARKER = "\xc2\xa4";

    /**
     * Height in PDF points of each page, in document order.
     *
     * Populated by the page-aware walk and read by the indexer to estimate
     * where on a page a passage begins (see get_page_heights()). Empty when
     * the flat fallback walk ran, since that has no notion of pages at all.
     *
     * @var float[]
     */
    private $page_heights = [];

    /**
     * Page-tree nodes already visited during the current walk.
     *
     * The depth guard alone does not bound a cyclic /Kids graph. A node whose
     * kids point back at it branches two ways per level, so the depth-32
     * ceiling is reached only after roughly four billion calls — and because
     * no /Type /Page is ever reached on that path, $order stays empty and the
     * 5,000-page ceiling never fires either. A visited set is what actually
     * terminates the walk.
     *
     * @since 0.9.9
     * @var array<int,bool>
     */
    private $visited_nodes = [];

    /**
     * Default maximum file size to attempt, in bytes. Larger files are skipped
     * to protect memory on shared hosting. Overridable per-call.
     */
    const DEFAULT_MAX_FILESIZE = 20971520; // 20 MB.

    /**
     * Hard cap on extracted characters. Prevents a pathological PDF from
     * flooding the index. 500k characters is far more than any real page count
     * of prose needs for search.
     */
    const MAX_OUTPUT_CHARS = 500000;

    /**
     * Hard cap on the size a single decompressed stream may reach, in bytes.
     *
     * MAX_OUTPUT_CHARS bounds what reaches the index, but it is applied
     * *after* inflation — so without this a small FlateDecode stream could
     * expand to gigabytes and exhaust the PHP process before any output
     * check ran. zlib compresses repetitive data at ratios past 1000:1, so a
     * 5 MB stream inside an otherwise-legal 20 MB PDF is enough to do it.
     *
     * 4 MB is roughly eight times the output cap: comfortably above any
     * genuine content stream, far below anything that threatens a 128 MB
     * process.
     */
    const MAX_INFLATED_BYTES = 4194304; // 4 MB.

    /**
     * A TJ array horizontal adjustment more negative than this (thousandths of
     * text space) is treated as a word space. Word spaces are typically -200 to
     * -1000; inter-letter kerning is small, so -60 is a safe threshold.
     */
    const TJ_SPACE_THRESHOLD = -60;

    /**
     * Status of the most recent extract() call.
     *
     * One of: ok, unreadable, too_large, not_pdf, encrypted, no_text_layer.
     *
     * @var string
     */
    private $last_status = 'ok';

    /**
     * Running total of bytes produced by stream inflation during the current
     * extract() call.
     *
     * The per-stream cap alone is not sufficient: a PDF can carry hundreds of
     * separate streams, each individually under the limit but ruinous in
     * aggregate. This budget bounds the whole document. Reset at the top of
     * every extract().
     *
     * @var int
     */
    private $inflated_bytes = 0;

    /**
     * Extract plain text from a PDF file.
     *
     * @param string   $file_path    Absolute path to the PDF on disk.
     * @param int|null $max_filesize Optional byte cap; defaults to DEFAULT_MAX_FILESIZE.
     * @return string Extracted UTF-8 text, or '' on any failure/skip (see last_status()).
     */
    public function extract( $file_path, $max_filesize = null ) {
        $this->last_status    = 'ok';
        $this->inflated_bytes = 0;

        if ( ! is_string( $file_path ) || '' === $file_path || ! is_readable( $file_path ) ) {
            return $this->fail( 'unreadable' );
        }

        $size = @filesize( $file_path );
        $max  = $max_filesize ? (int) $max_filesize : self::DEFAULT_MAX_FILESIZE;

        if ( false === $size || $size <= 0 ) {
            return $this->fail( 'unreadable' );
        }
        if ( $size > $max ) {
            return $this->fail( 'too_large' );
        }

        $data = @file_get_contents( $file_path );
        if ( false === $data || '' === $data ) {
            return $this->fail( 'unreadable' );
        }

        // Header sniff — a real PDF starts with %PDF- within the first bytes.
        if ( false === strpos( substr( $data, 0, 1024 ), '%PDF-' ) ) {
            return $this->fail( 'not_pdf' );
        }

        // We cannot decrypt. Bail cleanly rather than emit rubbish.
        if ( preg_match( '/\/Encrypt(\s|\/|>|\[)/', $data ) ) {
            return $this->fail( 'encrypted' );
        }

        $pages = $this->extract_by_page( $data );

        if ( null !== $pages && ! empty( $pages ) ) {
            // Join with a page marker so downstream code can attribute each
            // passage to a page number and deep-link to it.
            $text = implode( ' ' . self::PAGE_MARKER . ' ', $pages );
        } else {
            // Structure could not be read (object streams, an unusual page
            // tree, a damaged file). Fall back to the flat whole-document
            // walk, which yields text but no page numbers.
            $text = $this->extract_from_streams( $data );
        }

        $text = $this->normalise( $text );

        if ( '' === $text ) {
            // No decodable text layer — almost certainly scanned/image-only,
            // or fonts we can't map. Caller may fall back to metadata.
            return $this->fail( 'no_text_layer' );
        }

        if ( mb_strlen( $text ) > self::MAX_OUTPUT_CHARS ) {
            $text = mb_substr( $text, 0, self::MAX_OUTPUT_CHARS );
        }

        $this->last_status = 'ok';
        return $text;
    }

    /**
     * Page heights in PDF points, in document order, or [] if unknown.
     *
     * Valid only for the extract() call that just completed.
     *
     * @return float[]
     */
    public function get_page_heights() {
        return $this->page_heights;
    }

    // =========================================================================
    // Page-aware extraction.
    // =========================================================================

    /**
     * Extract text page by page, in document order.
     *
     * PDF stores content in indirect objects whose file order need not match
     * reading order, so the page sequence is read from the catalogue's page
     * tree rather than assumed. Returns null when the structure cannot be
     * read — in which case the caller falls back to the flat walk, and the
     * only thing lost is page numbers.
     *
     * @param string $data Raw PDF bytes.
     * @return string[]|null One entry per page, or null if unavailable.
     */
    private function extract_by_page( $data ) {
        // Object streams (PDF 1.5+) hide object definitions inside compressed
        // streams. Decoding those is a different job; bail to the flat walk.
        if ( false !== strpos( $data, '/ObjStm' ) ) {
            return null;
        }

        $objects = $this->index_objects( $data );
        if ( count( $objects ) < 2 ) {
            return null;
        }

        $order = $this->page_order( $objects );
        if ( empty( $order ) ) {
            return null;
        }

        $pages              = [];
        $heights            = [];
        $total              = 0;
        $this->page_heights = [];

        foreach ( $order as $obj_num ) {
            $body = $objects[ $obj_num ] ?? '';
            $text = '';

            // Page geometry, for estimating where on the page a passage sits.
            $heights[] = $this->page_height( $body, $objects );

            foreach ( $this->content_stream_refs( $body ) as $ref ) {
                if ( ! isset( $objects[ $ref ] ) ) {
                    continue;
                }
                $text .= $this->text_from_object( $objects[ $ref ] );
            }

            $pages[] = trim( $text );
            $total  += strlen( $text );

            if ( $total > self::MAX_OUTPUT_CHARS * 4 ) {
                break;
            }
        }

        // If nothing came out, the page walk found no usable content and the
        // flat walk deserves a turn.
        if ( '' === trim( implode( '', $pages ) ) ) {
            return null;
        }

        /*
         * Heights are kept only when one was resolved for every page. A
         * partial list cannot be indexed alongside the page array without
         * risking an off-by-one that would send a link to the wrong offset,
         * and a wrong offset is worse than none: it lands the visitor
         * confidently in the wrong place instead of predictably at the top.
         */
        $this->page_heights = ( count( $heights ) === count( $pages ) && ! in_array( 0.0, $heights, true ) )
            ? $heights
            : [];

        return $this->strip_running_headers( $pages );
    }

    /**
     * Remove running headers and footers repeated across pages.
     *
     * A page header such as "Acme Handbook · Version 2 · Page 14" appears on
     * every page. Left in, it lands in the middle of search snippets and
     * inflates the term frequency of whatever words it contains, making a
     * document look more relevant to its own title than it should be.
     *
     * Detection is by agreement rather than pattern: words shared by most
     * pages at the same position, at the start or the end of the page, are
     * boilerplate. Only pages that actually carry the boilerplate lose it.
     *
     * @param string[] $pages Page texts in reading order.
     * @return string[]
     */
    private function strip_running_headers( array $pages ) {
        $count = count( $pages );
        if ( $count < 3 ) {
            return $pages;
        }

        $words = [];
        foreach ( $pages as $p ) {
            $words[] = preg_split( '/\s+/u', trim( $p ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        }

        $prefix_len = $this->common_run_length( $words, true );
        $suffix_len = $this->common_run_length( $words, false );

        if ( 0 === $prefix_len && 0 === $suffix_len ) {
            return $pages;
        }

        $out = [];
        foreach ( $words as $w ) {
            if ( $prefix_len > 0 && count( $w ) > $prefix_len ) {
                $w = array_slice( $w, $prefix_len );

                // The page number itself varies, so it is never part of the
                // shared run — but it sits immediately after it.
                if ( isset( $w[0] ) && preg_match( '/^\d{1,4}$/', $w[0] ) ) {
                    $w = array_slice( $w, 1 );
                }
            }
            if ( $suffix_len > 0 && count( $w ) > $suffix_len ) {
                $w = array_slice( $w, 0, count( $w ) - $suffix_len );

                if ( ! empty( $w ) && preg_match( '/^\d{1,4}$/', $w[ count( $w ) - 1 ] ) ) {
                    array_pop( $w );
                }
            }
            $out[] = implode( ' ', $w );
        }

        return $out;
    }

    /**
     * Length of the run of words most pages share at one end.
     *
     * @param array<int,string[]> $words   Word arrays, one per page.
     * @param bool                $at_start True for prefix, false for suffix.
     * @return int Number of words, 0 if there is no agreement.
     */
    private function common_run_length( array $words, $at_start ) {
        $pages = count( $words );
        $limit = 20;   // never treat more than this as furniture
        $run   = 0;

        for ( $i = 0; $i < $limit; $i++ ) {
            $tally = [];
            foreach ( $words as $w ) {
                $n = count( $w );
                if ( $n <= $i + 1 ) {
                    continue;
                }
                $word = $at_start ? $w[ $i ] : $w[ $n - 1 - $i ];
                $key  = mb_strtolower( $word );
                $tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
            }

            if ( empty( $tally ) ) {
                break;
            }

            arsort( $tally );
            $top = reset( $tally );

            // Agreement across most pages means furniture, not content. A
            // varying page number breaks the run, which is why the caller
            // removes a trailing bare number separately.
            if ( $top / $pages < 0.6 ) {
                break;
            }

            $run = $i + 1;
        }

        // A single shared word is coincidence, not a header.
        return $run >= 3 ? $run : 0;
    }

    /**
     * Map indirect object numbers to their raw bodies.
     *
     * Each object runs from its own "N G obj" header to the start of the
     * next one. Slicing on the next header rather than on "endobj" avoids
     * being fooled by those bytes occurring inside binary stream data.
     *
     * @param string $data Raw PDF bytes.
     * @return array<int,string> Object number => body.
     */
    private function index_objects( $data ) {
        if ( ! preg_match_all( '/(?:^|[\r\n\s])(\d{1,7})\s+(\d{1,5})\s+obj\b/', $data, $m, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }

        $objects = [];
        $count   = count( $m[0] );

        for ( $i = 0; $i < $count; $i++ ) {
            $num   = (int) $m[1][ $i ][0];
            $start = (int) $m[0][ $i ][1];
            $end   = ( $i + 1 < $count ) ? (int) $m[0][ $i + 1 ][1] : strlen( $data );

            // Later definitions win, matching how incremental updates work.
            $objects[ $num ] = substr( $data, $start, $end - $start );
        }

        return $objects;
    }

    /**
     * Determine page object numbers in reading order.
     *
     * Walks the catalogue's /Pages tree. Falls back to every object marked
     * /Type /Page in numeric order, which is correct for the great majority
     * of generators even though the specification does not require it.
     *
     * @param array<int,string> $objects Object map.
     * @return int[] Page object numbers, in order.
     */
    private function page_order( array $objects ) {
        $root = null;

        foreach ( $objects as $num => $body ) {
            if ( preg_match( '#/Type\s*/Catalog\b#', $body ) ) {
                if ( preg_match( '#/Pages\s+(\d+)\s+\d+\s+R#', $body, $m ) ) {
                    $root = (int) $m[1];
                }
                break;
            }
        }

        $order = [];

        $this->visited_nodes = [];

        if ( null !== $root ) {
            $this->walk_page_tree( $objects, $root, $order, 0 );
        }

        if ( empty( $order ) ) {
            foreach ( $objects as $num => $body ) {
                if ( preg_match( '#/Type\s*/Page\b(?!s)#', $body ) ) {
                    $order[] = (int) $num;
                }
            }
            sort( $order, SORT_NUMERIC );
        }

        return $order;
    }

    /**
     * Recursively collect page objects from a /Pages node.
     *
     * @param array<int,string> $objects Object map.
     * @param int               $node    Object number to visit.
     * @param int[]             $order   Accumulator, by reference.
     * @param int               $depth   Recursion guard.
     * @return void
     */
    private function walk_page_tree( array $objects, $node, array &$order, $depth ) {
        $node = (int) $node;

        if ( $depth > 32 || ! isset( $objects[ $node ] ) || count( $order ) > 5000 ) {
            return;
        }

        // A node reached twice is a cycle, not a second page. Without this a
        // malformed or deliberately crafted /Kids graph runs until the PHP
        // time limit kills the request that uploaded the file.
        if ( isset( $this->visited_nodes[ $node ] ) ) {
            return;
        }
        $this->visited_nodes[ $node ] = true;

        $body = $objects[ $node ];

        if ( preg_match( '#/Type\s*/Page\b(?!s)#', $body ) ) {
            $order[] = (int) $node;
            return;
        }

        if ( ! preg_match( '#/Kids\s*\[(.*?)\]#s', $body, $m ) ) {
            return;
        }

        if ( preg_match_all( '/(\d+)\s+\d+\s+R/', $m[1], $kids ) ) {
            foreach ( $kids[1] as $kid ) {
                $this->walk_page_tree( $objects, (int) $kid, $order, $depth + 1 );
            }
        }
    }

    /**
     * Object numbers referenced by a page's /Contents entry.
     *
     * /Contents is either a single reference or an array of them, whose
     * streams concatenate to form the page's content stream.
     *
     * @param string $body Page object body.
     * @return int[]
     */
    private function content_stream_refs( $body ) {
        if ( ! preg_match( '#/Contents\s*(\[[^\]]*\]|\d+\s+\d+\s+R)#s', $body, $m ) ) {
            return [];
        }

        if ( ! preg_match_all( '/(\d+)\s+\d+\s+R/', $m[1], $refs ) ) {
            return [];
        }

        return array_map( 'intval', $refs[1] );
    }

    /**
     * Decode and parse the text out of one content-stream object.
     *
     * @param string $body Object body, including its stream.
     * @return string
     */
    private function text_from_object( $body ) {
        if ( ! preg_match( '/stream\r?\n(.*?)\r?\n?endstream/s', $body, $m ) ) {
            return '';
        }

        $content = $this->decode_stream( $m[1] );
        if ( '' === $content ) {
            return '';
        }

        if ( ! preg_match( '/[\)\]>]\s*T[jJ][^a-zA-Z]/', $content ) ) {
            return '';
        }

        return $this->parse_content_stream( $content ) . "\n";
    }

    /**
     * Status code from the most recent extract() call.
     *
     * @return string
     */
    public function last_status() {
        return $this->last_status;
    }

    /**
     * Set status and return the empty-string sentinel in one step.
     *
     * @param string $status Status code.
     * @return string Always ''.
     */
    private function fail( $status ) {
        $this->last_status = $status;
        return '';
    }

    // =========================================================================
    // Stream extraction.
    // =========================================================================

    /**
     * Walk every stream…endstream object, inflate where needed, and parse text
     * operators out of anything that looks like a content stream.
     *
     * @param string $data Raw PDF bytes.
     * @return string Concatenated raw text (pre-normalisation).
     */
    private function extract_from_streams( $data ) {
        // Capture bytes between `stream` and `endstream`. The `s` flag makes `.`
        // match newlines; the lazy quantifier stops at the first `endstream`.
        // A leading EOL after `stream` is consumed; trailing EOL is trimmed later.
        if ( ! preg_match_all( '/stream\r?\n(.*?)\r?\n?endstream/s', $data, $matches ) ) {
            return '';
        }

        $out = '';

        foreach ( $matches[1] as $raw_stream ) {
            $content = $this->decode_stream( $raw_stream );

            if ( '' === $content ) {
                continue;
            }

            // Only parse things that actually show text. Require Tj/TJ in
            // operator position — immediately after a literal-string, hex-string
            // or array close — rather than as a raw substring, so binary streams
            // that merely *contain* the bytes "Tj" are skipped.
            if ( ! preg_match( '/[\)\]>]\s*T[jJ][^a-zA-Z]/', $content ) ) {
                continue;
            }

            $out .= $this->parse_content_stream( $content ) . "\n";

            if ( strlen( $out ) > self::MAX_OUTPUT_CHARS * 4 ) {
                // Enough raw material; stop before normalisation trims to cap.
                break;
            }
        }

        return $out;
    }

    /**
     * Decode a stream body to usable content.
     *
     * Handles the filter combinations seen in practice for content streams:
     *   - FlateDecode (raw binary deflate) — the most common case.
     *   - ASCII85Decode or ASCIIHexDecode wrapping FlateDecode — used by
     *     reportlab and various other generators to keep streams 7-bit clean.
     *   - Either ASCII filter with no compression.
     *   - A plain, uncompressed text content stream.
     *
     * The cascade is ordered cheapest-first and bails to '' for anything that
     * decodes to binary (image/font data), which the caller then skips.
     *
     * @param string $raw Raw stream bytes.
     * @return string Decoded content, or '' if unusable.
     */
    private function decode_stream( $raw ) {
        // 1. Direct deflate. Successful inflation is not enough on its own —
        // embedded font programs (FontFile/FontFile2) are also Flate-compressed
        // and inflate cleanly to *binary*, which must not reach the parser.
        $d = $this->try_inflate( $raw );
        if ( null !== $d ) {
            return $this->looks_like_text( $d ) ? $d : '';
        }

        // 2. ASCII85 → (optionally) deflate.
        $a = $this->ascii85_decode( $raw );
        if ( null !== $a && '' !== $a ) {
            $d = $this->try_inflate( $a );
            if ( null !== $d ) {
                return $this->looks_like_text( $d ) ? $d : '';
            }
            if ( $this->looks_like_text( $a ) ) {
                return $a;
            }
        }

        // 3. ASCIIHex → (optionally) deflate.
        $h = $this->asciihex_decode( $raw );
        if ( null !== $h && '' !== $h ) {
            $d = $this->try_inflate( $h );
            if ( null !== $d ) {
                return $this->looks_like_text( $d ) ? $d : '';
            }
            if ( $this->looks_like_text( $h ) ) {
                return $h;
            }
        }

        // 4. Plain, uncompressed text content stream.
        if ( $this->looks_like_text( $raw ) ) {
            return $raw;
        }

        return '';
    }

    /**
     * Attempt zlib inflation, trying the zlib-wrapped and raw-deflate forms.
     *
     * @param string $s Bytes to inflate.
     * @return string|null Inflated bytes, or null if neither form succeeded.
     */
    private function try_inflate( $s ) {
        if ( '' === $s ) {
            return null;
        }

        // Document-wide budget already spent — refuse without decompressing.
        if ( $this->inflated_bytes >= self::MAX_INFLATED_BYTES ) {
            return null;
        }

        $budget = self::MAX_INFLATED_BYTES - $this->inflated_bytes;

        // The $max_length argument is what makes this safe: zlib stops
        // producing output at the limit instead of allocating whatever the
        // stream asks for. A stream that exceeds it returns false and is
        // skipped, which is the correct outcome — a content stream needing
        // more than 4 MB of text is not something we want in the index
        // regardless of intent.
        $d = @gzuncompress( $s, $budget );
        if ( false === $d ) {
            $d = @gzinflate( $s, $budget );
        }

        if ( false === $d || null === $d ) {
            return null;
        }

        $this->inflated_bytes += strlen( $d );

        return $d;
    }

    /**
     * Decode an ASCII85 (base-85) stream, Adobe/PDF variant.
     *
     * Tolerates a leading `<~` and a trailing `~>`, ignores whitespace, and
     * supports the `z` shorthand for four zero bytes. Returns null if the input
     * contains characters outside the ASCII85 alphabet (i.e. it isn't ASCII85),
     * so binary Flate streams are not mangled.
     *
     * @param string $raw Raw stream bytes.
     * @return string|null Decoded bytes, or null if not valid ASCII85.
     */
    private function ascii85_decode( $raw ) {
        $s = ltrim( $raw );

        // Optional Adobe opener.
        if ( 0 === strpos( $s, '<~' ) ) {
            $s = substr( $s, 2 );
        }
        // Trim at the terminator if present.
        $end = strpos( $s, '~>' );
        if ( false !== $end ) {
            $s = substr( $s, 0, $end );
        }

        $out    = '';
        $tuple  = 0;
        $count  = 0;
        $len    = strlen( $s );

        for ( $i = 0; $i < $len; $i++ ) {
            $c = $s[ $i ];

            // Skip whitespace.
            if ( " " === $c || "\n" === $c || "\r" === $c || "\t" === $c || "\f" === $c || "\0" === $c ) {
                continue;
            }

            if ( 'z' === $c ) {
                // 'z' is only valid at a group boundary.
                if ( 0 !== $count ) {
                    return null;
                }
                $out .= "\0\0\0\0";
                continue;
            }

            $val = ord( $c ) - 33; // '!' == 0 … 'u' == 84.
            if ( $val < 0 || $val > 84 ) {
                return null; // Not ASCII85.
            }

            $tuple = $tuple * 85 + $val;
            $count++;

            if ( 5 === $count ) {
                $out  .= chr( ( $tuple >> 24 ) & 0xFF )
                       . chr( ( $tuple >> 16 ) & 0xFF )
                       . chr( ( $tuple >> 8 ) & 0xFF )
                       . chr( $tuple & 0xFF );
                $tuple = 0;
                $count = 0;
            }
        }

        // Flush a final partial group (1–4 chars → count-1 bytes).
        if ( $count > 0 ) {
            if ( 1 === $count ) {
                return null; // A single trailing char is invalid.
            }
            for ( $k = $count; $k < 5; $k++ ) {
                $tuple = $tuple * 85 + 84;
            }
            for ( $k = 0; $k < $count - 1; $k++ ) {
                $out .= chr( ( $tuple >> ( 24 - $k * 8 ) ) & 0xFF );
            }
        }

        return $out;
    }

    /**
     * Decode an ASCIIHexDecode stream. Returns null unless the input is
     * genuinely hex (hex digits, whitespace, optional trailing '>'), so it is
     * never applied to arbitrary binary.
     *
     * @param string $raw Raw stream bytes.
     * @return string|null Decoded bytes, or null if not valid ASCII hex.
     */
    private function asciihex_decode( $raw ) {
        $s = str_replace( [ " ", "\n", "\r", "\t", "\f", "\0" ], '', $raw );
        $s = rtrim( $s, '>' );

        if ( '' === $s || ! ctype_xdigit( $s ) ) {
            return null;
        }
        if ( 0 !== strlen( $s ) % 2 ) {
            $s .= '0';
        }

        $bin = @hex2bin( $s );
        return ( false === $bin ) ? null : $bin;
    }

    /**
     * Cheap printable-ratio test to tell a plain-text content stream from
     * binary image/font data.
     *
     * @param string $s Bytes to test.
     * @return bool
     */
    private function looks_like_text( $s ) {
        $sample = substr( $s, 0, 2048 );
        $len    = strlen( $sample );
        if ( 0 === $len ) {
            return false;
        }

        $printable = 0;
        for ( $i = 0; $i < $len; $i++ ) {
            $o = ord( $sample[ $i ] );
            // Printable ASCII plus common whitespace.
            if ( ( $o >= 32 && $o < 127 ) || 9 === $o || 10 === $o || 13 === $o ) {
                $printable++;
            }
        }

        return ( $printable / $len ) > 0.85;
    }

    // =========================================================================
    // Content-stream text operator parsing.
    // =========================================================================

    /**
     * Extract shown text from a decoded content stream.
     *
     * Handles the text-showing operators Tj, TJ, ' and " plus the literal `( )`
     * and hex `< >` string forms. Line-moving operators (Td, TD, T*, ', ")
     * insert whitespace; large negative TJ adjustments insert word spaces. The
     * bias is deliberately towards *over*-spacing: the tokeniser collapses
     * whitespace, so a spurious space is harmless whereas glued words are not.
     *
     * @param string $content Decoded content stream.
     * @return string
     */
    private function parse_content_stream( $content ) {
        $out          = '';
        $len          = strlen( $content );
        $i            = 0;
        $array_depth  = 0;

        while ( $i < $len ) {
            $c = $content[ $i ];

            // Skip PDF comments to end of line.
            if ( '%' === $c ) {
                $nl = strpos( $content, "\n", $i );
                $i  = ( false === $nl ) ? $len : $nl + 1;
                continue;
            }

            // Dictionary delimiters << >> — step over so we don't mistake << for
            // a hex string opener.
            if ( '<' === $c && $i + 1 < $len && '<' === $content[ $i + 1 ] ) {
                $i += 2;
                continue;
            }
            if ( '>' === $c && $i + 1 < $len && '>' === $content[ $i + 1 ] ) {
                $i += 2;
                continue;
            }

            // Literal string ( ... ).
            if ( '(' === $c ) {
                list( $str, $i ) = $this->read_literal_string( $content, $i, $len );
                $out            .= $str;
                continue;
            }

            // Hex string < ... >.
            if ( '<' === $c ) {
                list( $str, $i ) = $this->read_hex_string( $content, $i, $len );
                $out            .= $str;
                continue;
            }

            // Array delimiters (TJ operands).
            if ( '[' === $c ) {
                $array_depth++;
                $i++;
                continue;
            }
            if ( ']' === $c ) {
                if ( $array_depth > 0 ) {
                    $array_depth--;
                }
                $i++;
                continue;
            }

            // Numbers — only meaningful to us inside a TJ array, where a large
            // negative adjustment marks a word break.
            if ( '-' === $c || '+' === $c || '.' === $c || ( $c >= '0' && $c <= '9' ) ) {
                $start = $i;
                $i++;
                while ( $i < $len ) {
                    $d = $content[ $i ];
                    if ( '.' === $d || '-' === $d || '+' === $d || ( $d >= '0' && $d <= '9' ) || 'e' === $d || 'E' === $d ) {
                        $i++;
                    } else {
                        break;
                    }
                }
                if ( $array_depth > 0 ) {
                    $num = (float) substr( $content, $start, $i - $start );
                    if ( $num < self::TJ_SPACE_THRESHOLD ) {
                        $out .= ' ';
                    }
                }
                continue;
            }

            // Bareword operator/keyword token.
            if ( ( $c >= 'a' && $c <= 'z' ) || ( $c >= 'A' && $c <= 'Z' ) || "'" === $c || '"' === $c || '*' === $c ) {
                $start = $i;
                $i++;
                while ( $i < $len ) {
                    $d = $content[ $i ];
                    if ( ( $d >= 'a' && $d <= 'z' ) || ( $d >= 'A' && $d <= 'Z' ) || '*' === $d ) {
                        $i++;
                    } else {
                        break;
                    }
                }
                $token = substr( $content, $start, $i - $start );

                switch ( $token ) {
                    case 'Tj':
                    case 'TJ':
                        /*
                         * Deliberately nothing.
                         *
                         * This used to append a space after every show
                         * operator, to keep successive ones from running
                         * together. But two Tj operators with no positioning
                         * between them continue at the current point: the
                         * glyphs are adjacent on the page, so there is no
                         * space there and inserting one invents a word break
                         * that the document does not contain.
                         *
                         * Producers split strings constantly and for reasons
                         * that have nothing to do with spacing — an escaped
                         * entity, a font switch, an encoding change mid-word.
                         * ReportLab writes "What's" as (What) Tj (') Tj
                         * (s ...) Tj, which arrived in the index as
                         * "what ' s" and could then never be found by anyone
                         * quoting the phrase.
                         *
                         * Separation is now the business of the operators
                         * that actually move the cursor, below. Everything
                         * that genuinely starts a new run passes through one
                         * of them first.
                         */
                        break;
                    case 'Tm':
                        // Sets the text matrix outright, so the next run may
                        // begin anywhere — including immediately after a
                        // Courier span on the same line, where a space is
                        // wanted and no Td or T* will supply it. A space
                        // rather than a newline: Tm is also emitted at the
                        // head of every text object, where a break would be
                        // wrong.
                        $out .= ' ';
                        break;
                    case 'Td':
                    case 'TD':
                    case 'T':  // T* arrives as 'T' then '*' below; handle both.
                    case "'":
                    case '"':
                        $out .= "\n";
                        break;
                    case 'ET':
                        $out .= "\n";
                        break;
                }
                continue;
            }

            // T* (next-line) — the '*' after a 'T' that we may have split.
            if ( '*' === $c ) {
                $out .= "\n";
                $i++;
                continue;
            }

            $i++;
        }

        return $out;
    }

    /**
     * Read a PDF literal string starting at an opening '('. Handles escape
     * sequences, octal codes, line continuations and balanced nested parens.
     *
     * @param string $content Content stream.
     * @param int    $i       Index of the opening '('.
     * @param int    $len     Length of $content.
     * @return array{0:string,1:int} Decoded UTF-8 string and index just past the closing ')'.
     */
    private function read_literal_string( $content, $i, $len ) {
        $i++; // Skip '('.
        $depth = 1;
        $buf   = '';

        while ( $i < $len && $depth > 0 ) {
            $c = $content[ $i ];

            if ( '\\' === $c && $i + 1 < $len ) {
                $n = $content[ $i + 1 ];
                switch ( $n ) {
                    case 'n': $buf .= "\n"; $i += 2; break;
                    case 'r': $buf .= "\r"; $i += 2; break;
                    case 't': $buf .= "\t"; $i += 2; break;
                    case 'b': $buf .= "\x08"; $i += 2; break;
                    case 'f': $buf .= "\x0c"; $i += 2; break;
                    case '(': $buf .= '('; $i += 2; break;
                    case ')': $buf .= ')'; $i += 2; break;
                    case '\\': $buf .= '\\'; $i += 2; break;
                    case "\r":
                        // Line continuation: backslash-CR or backslash-CRLF.
                        $i += 2;
                        if ( $i < $len && "\n" === $content[ $i ] ) {
                            $i++;
                        }
                        break;
                    case "\n":
                        $i += 2; // Line continuation.
                        break;
                    default:
                        if ( $n >= '0' && $n <= '7' ) {
                            // Octal escape: 1-3 digits.
                            $oct = '';
                            $i++; // Move to first octal digit.
                            $k = 0;
                            while ( $k < 3 && $i < $len && $content[ $i ] >= '0' && $content[ $i ] <= '7' ) {
                                $oct .= $content[ $i ];
                                $i++;
                                $k++;
                            }
                            $buf .= chr( octdec( $oct ) & 0xFF );
                        } else {
                            // Unknown escape — keep the literal char.
                            $buf .= $n;
                            $i   += 2;
                        }
                        break;
                }
                continue;
            }

            if ( '(' === $c ) {
                $depth++;
                $buf .= $c;
                $i++;
                continue;
            }
            if ( ')' === $c ) {
                $depth--;
                if ( 0 === $depth ) {
                    $i++;
                    break;
                }
                $buf .= $c;
                $i++;
                continue;
            }

            $buf .= $c;
            $i++;
        }

        return [ $this->decode_string_bytes( $buf ), $i ];
    }

    /**
     * Read a PDF hex string starting at an opening '<' (caller has already ruled
     * out '<<'). Whitespace is ignored; an odd final nibble is padded with 0.
     *
     * @param string $content Content stream.
     * @param int    $i       Index of the opening '<'.
     * @param int    $len     Length of $content.
     * @return array{0:string,1:int} Decoded UTF-8 string and index just past the closing '>'.
     */
    private function read_hex_string( $content, $i, $len ) {
        $i++; // Skip '<'.
        $hex = '';

        while ( $i < $len ) {
            $c = $content[ $i ];
            if ( '>' === $c ) {
                $i++;
                break;
            }
            if ( ctype_xdigit( $c ) ) {
                $hex .= $c;
            }
            $i++;
        }

        if ( '' === $hex ) {
            return [ '', $i ];
        }

        if ( 0 !== strlen( $hex ) % 2 ) {
            $hex .= '0';
        }

        $bin = @hex2bin( $hex );
        if ( false === $bin || '' === $bin ) {
            return [ '', $i ];
        }

        return [ $this->decode_string_bytes( $bin ), $i ];
    }

    /**
     * Convert raw PDF string bytes to UTF-8.
     *
     * UTF-16BE (with BOM) is decoded as such; everything else is treated as
     * Windows-1252, which is the effective superset of the standard PDF text
     * encodings for Latin-script content and maps smart quotes, dashes etc.
     *
     * @param string $bytes Raw decoded bytes.
     * @return string UTF-8 text.
     */
    private function decode_string_bytes( $bytes ) {
        if ( '' === $bytes ) {
            return '';
        }

        // UTF-16BE byte-order mark.
        if ( strlen( $bytes ) >= 2 && "\xFE\xFF" === substr( $bytes, 0, 2 ) ) {
            $converted = @mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
            return ( false === $converted ) ? '' : $converted;
        }

        // Pure ASCII passes through untouched.
        if ( preg_match( '//u', $bytes ) && ! preg_match( '/[\x80-\xFF]/', $bytes ) ) {
            return $bytes;
        }

        $converted = @mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
        return ( false === $converted ) ? '' : $converted;
    }

    // =========================================================================
    // Post-processing.
    // =========================================================================

    /**
     * Normalise extracted text: strip control characters, remove
     * table-of-contents leader runs, collapse runs of whitespace to single
     * spaces, and trim.
     *
     * @param string $text Raw extracted text.
     * @return string
     */
    private function normalise( $text ) {
        if ( '' === $text ) {
            return '';
        }

        // Drop control chars except tab/newline/carriage-return. If the
        // unicode pattern cannot run — invalid UTF-8 reached this point,
        // which subsetted and CID fonts routinely produce — fall back to the
        // byte-wise form against the same text rather than against a variable
        // the failure has already emptied. Assigning the result of the first
        // pass straight back into $text threw the whole document away and
        // reported it as having no text layer, which is indistinguishable
        // from a genuine scan.
        $scrubbed = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );
        if ( null === $scrubbed ) {
            $scrubbed = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text );
        }

        $text = (string) $scrubbed;

        // Remove leader runs — the rows of dots that join a contents-page
        // heading to its page number — together with the page number that
        // immediately follows one. Handling both in a single pattern means
        // a bare number is only ever removed when a leader run introduced
        // it, so ordinary text like a page footer reading "Page 4" ahead of
        // a numbered heading is left intact.
        $stripped = preg_replace(
            '/(?:\s*[.\x{00B7}\x{2022}\x{2024}\x{2027}_\x{2013}\x{2014}-]\s*){4,}(\s*\d{1,4}\b)?/u',
            ' ',
            $text
        );
        if ( null !== $stripped ) {
            $text = $stripped;
        }

        // Whitespace tidy. If the unicode pattern cannot run — invalid
        // UTF-8 survived the decode — fall back to the byte-wise form against
        // the same text rather than against an empty string, which threw the
        // whole document away and reported it as having no text layer.
        $tidied = preg_replace( '/\s+/u', ' ', $text );
        if ( null === $tidied ) {
            $tidied = preg_replace( '/\s+/', ' ', $text );
        }
        $text = (string) $tidied;

        // Page markers are positional: each one is a page boundary, so runs
        // must NOT be collapsed and leading markers must not be trimmed. A
        // blank page that produced no text still occupies a page number, and
        // dropping its marker would shift every later page's number by one,
        // sending deep links to the wrong page.
        return trim( (string) $text, " \t\n\r\0\x0B" );
    }

    /**
     * Height of a page in PDF points.
     *
     * Read from MediaBox, which is [llx lly urx ury] — the height is the
     * difference of the y values, not simply ury, because the origin is not
     * required to be zero.
     *
     * MediaBox is an inheritable attribute: a page that does not declare one
     * takes its parent's, and most documents declare it once on the root
     * Pages node. The parent chain is followed up to a small depth, which is
     * plenty for any real page tree and cannot loop forever on a malformed
     * one.
     *
     * A page rotated by 90 or 270 degrees swaps its effective width and
     * height, so /Rotate is honoured — otherwise a landscape page would be
     * measured against portrait dimensions and every offset on it would be
     * wrong by the aspect ratio.
     *
     * @param string $body    Page object body.
     * @param array  $objects Object number => body.
     * @param int    $depth   Recursion guard.
     * @return float Height in points, or 0.0 when it cannot be determined.
     */
    private function page_height( $body, array $objects, $depth = 0 ) {
        if ( $depth > 8 || '' === (string) $body ) {
            return 0.0;
        }

        $height = 0.0;

        if ( preg_match( '/\/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]/', $body, $m ) ) {
            $height = abs( (float) $m[4] - (float) $m[2] );
            $width  = abs( (float) $m[3] - (float) $m[1] );

            if ( preg_match( '/\/Rotate\s+(-?\d+)/', $body, $r ) ) {
                $rotate = ( (int) $r[1] % 360 + 360 ) % 360;
                if ( 90 === $rotate || 270 === $rotate ) {
                    $height = $width;
                }
            }

            return $height > 0 ? $height : 0.0;
        }

        // Not declared here — inherit from the parent Pages node.
        if ( preg_match( '/\/Parent\s+(\d+)\s+\d+\s+R/', $body, $p ) ) {
            $parent = (int) $p[1];
            if ( isset( $objects[ $parent ] ) ) {
                return $this->page_height( $objects[ $parent ], $objects, $depth + 1 );
            }
        }

        return 0.0;
    }

}
