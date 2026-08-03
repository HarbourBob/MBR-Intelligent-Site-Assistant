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
     * Extract plain text from a PDF file.
     *
     * @param string   $file_path    Absolute path to the PDF on disk.
     * @param int|null $max_filesize Optional byte cap; defaults to DEFAULT_MAX_FILESIZE.
     * @return string Extracted UTF-8 text, or '' on any failure/skip (see last_status()).
     */
    public function extract( $file_path, $max_filesize = null ) {
        $this->last_status = 'ok';

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

        $text = $this->extract_from_streams( $data );
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
        $d = @gzuncompress( $s );
        if ( false !== $d ) {
            return $d;
        }
        $d = @gzinflate( $s );
        if ( false !== $d ) {
            return $d;
        }
        return null;
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
                        // Ensure separation between successive show operators.
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

        // Drop control chars except tab/newline/carriage-return.
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );
        if ( null === $text ) {
            // preg failed on invalid UTF-8 — scrub and retry.
            $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', (string) $text );
        }

        $text = (string) $text;

        // Remove leader runs — the rows of dots that join a contents-page
        // heading to its page number. They are visual furniture: they carry
        // no meaning, they swallow most of a search snippet's window, and
        // they inflate the chunk's length for BM25. Handles full stops,
        // middots, bullets, one-dot leaders, underscores and dashes.
        // Four or more in a row is well clear of an ellipsis.
        $stripped = preg_replace(
            '/(?:\s*[.\x{00B7}\x{2022}\x{2024}\x{2027}_\x{2013}\x{2014}-]\s*){4,}/u',
            ' ',
            $text
        );
        if ( null !== $stripped ) {
            $text = $stripped;
        }

        // Collapse whitespace now, so the contents-page rule below sees
        // single spaces regardless of what the leader strip left behind.
        $collapsed = preg_replace( '/\s+/u', ' ', $text );
        if ( null !== $collapsed ) {
            $text = $collapsed;
        }

        // A leader run usually leaves its page number stranded between the
        // heading and the next entry ("Installation 7 3. How it works").
        // Drop a bare number only where it sits between a letter and a
        // following digit-and-full-stop, which is the contents-page shape;
        // ordinary prose numbers are left alone. Repeated because matches
        // overlap on consecutive entries.
        for ( $pass = 0; $pass < 2; $pass++ ) {
            $stripped = preg_replace( '/(?<=[\p{L}?!)\]]) \d{1,4} (?=\d{1,3}\.\s)/u', ' ', $text );
            if ( null === $stripped || $stripped === $text ) {
                break;
            }
            $text = $stripped;
        }

        // Final whitespace tidy.
        $text = preg_replace( '/\s+/u', ' ', $text );
        if ( null === $text ) {
            $text = preg_replace( '/\s+/', ' ', '' );
        }

        return trim( (string) $text );
    }
}
