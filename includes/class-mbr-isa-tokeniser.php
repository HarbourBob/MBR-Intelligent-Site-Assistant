<?php
/**
 * Tokeniser — converts text into a list of stemmed tokens ready for indexing or searching.
 *
 * Pipeline:
 *   1. clean()          Strip HTML, shortcodes, URLs, punctuation; normalise whitespace.
 *   2. split()          Lowercase and split on word boundaries.
 *   3. filter()         Remove stopwords, overly-short tokens, pure numbers.
 *   4. stem()           Porter stemmer reduces words to their root form.
 *
 * Pure PHP, no WordPress dependencies beyond the stopword loader.
 * Testable in isolation.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Tokeniser {

    /**
     * Sentinel marking a block-element boundary in extracted text.
     */
    const BLOCK_MARKER = "\xc2\xb6";

    /**
     * Minimum token length after cleaning. Single letters rarely useful.
     */
    const MIN_TOKEN_LENGTH = 2;

    /**
     * Maximum token length — defensive cap against binary/junk data.
     */
    const MAX_TOKEN_LENGTH = 40;

    /**
     * Cached stopword lookup (keys for O(1) membership test).
     *
     * @var array<string,bool>|null
     */
    private $stopwords = null;

    /**
     * Run the full tokenisation pipeline.
     *
     * @param string $text Input text.
     * @return string[] Array of stemmed tokens in order of appearance.
     */
    public function tokenise( $text ) {
        $cleaned         = $this->clean( (string) $text );
        $split           = $this->split( $cleaned );
        $after_stopwords = $this->filter( $split );
        $stemmed         = array_map( [ $this, 'stem' ], $after_stopwords );

        return $stemmed;
    }

    /**
     * Run the pipeline but return every intermediate stage.
     *
     * Used by the diagnostic page so you can see exactly what the
     * tokeniser is doing at each step.
     *
     * @param string $text Input text.
     * @return array{original:string,cleaned:string,split:string[],after_stopwords:string[],after_stemming:string[]}
     */
    public function tokenise_with_trace( $text ) {
        $original        = (string) $text;
        $cleaned         = $this->clean( $original );
        $split           = $this->split( $cleaned );
        $after_stopwords = $this->filter( $split );
        $after_stemming  = array_map( [ $this, 'stem' ], $after_stopwords );

        return [
            'original'        => $original,
            'cleaned'         => $cleaned,
            'split'           => $split,
            'after_stopwords' => $after_stopwords,
            'after_stemming'  => $after_stemming,
        ];
    }

    /**
     * Remove markup but keep human-readable punctuation.
     *
     * Shared by clean() and by the indexer, which must convert content to
     * plain text *before* it is split into passage chunks. Chunking raw
     * HTML is unsafe: a chunk boundary can fall inside a tag, leaving the
     * next chunk starting mid-attribute with no opening angle bracket for
     * strip_tags() to recognise — at which point SVG icon attributes and
     * similar markup leak into both the snippet and the index.
     *
     * @param string $text Raw content, possibly containing HTML.
     * @return string Plain text with punctuation intact.
     */
    public function strip_markup( $text ) {
        $text = (string) $text;

        /*
         * A full HTML document in post_content is indexed on its body only.
         *
         * WordPress treats post_content as a fragment, and so did this method
         * until 0.9.13. But a hand-built landing page pasted in whole carries
         * a complete document: doctype, head, an inline stylesheet, meta tags.
         * On one real example that was 21,641 of 36,913 characters — more than
         * half the "content" being CSS and metadata that can never be a useful
         * search result, fed to the chunker as though it were prose.
         *
         * Taking the body also puts the head's markup out of reach of
         * everything below, which matters because the head is where malformed
         * constructs tend to live.
         */
        /*
         * Corroboration required, added in 0.9.17.
         *
         * A <body> tag on its own does not make the content an HTML document.
         * Plain text that merely mentions one — a tutorial, a code example, a
         * PDF about HTML — contains the same three characters, and the
         * unclosed-tag branch below then discards everything before the
         * mention. This method runs on extracted PDF text as well as on
         * post_content, where such a mention is ordinary prose rather than
         * markup.
         *
         * That was not hypothetical: this plugin's own user guide describes
         * this rule in § 2.2, so indexing it threw away every page before that
         * sentence and renumbered what survived from 1, which sent every PDF
         * deep link to the wrong page.
         *
         * A real document announces itself with a doctype or an <html> tag
         * before its body. Requiring one costs nothing on genuine documents
         * and makes a passing mention inert.
         */
        $head = substr( $text, 0, 4096 );
        $is_html_document = (bool) preg_match( '#<!doctype\s+html\b#i', $head )
            || (bool) preg_match( '#<html[\s>]#i', $head );

        if ( $is_html_document ) {
            if ( preg_match( '#<body\b[^>]*>(.*)</body\s*>#is', $text, $m ) ) {
                $text = $m[1];
            } elseif ( preg_match( '#<body\b[^>]*>(.*)$#is', $text, $m ) ) {
                // Opening body tag with no closing one: take everything after it.
                $text = $m[1];
            }
        }

        // Remove shortcodes first — before strip_tags so their contents don't leak.
        if ( function_exists( 'strip_shortcodes' ) ) {
            $text = strip_shortcodes( $text );
        }

        // Remove script/style tags and their contents entirely.
        $text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $text );

        // Drop HTML comments, which can carry block-editor JSON.
        $text = preg_replace( '/<!--.*?-->/s', ' ', (string) $text );

        // Mark block-level boundaries before stripping tags. Two reasons:
        // strip_tags() alone turns "<p>one</p><p>two</p>" into "onetwo",
        // fabricating a word that appears nowhere on the page; and deep
        // links need to know where blocks end, because a browser will not
        // match a text fragment that spans a block boundary.
        //
        // The marker is a pilcrow, which survives chunking as an ordinary
        // word, is stripped by clean() before tokenising (so it is never
        // indexed), and is removed again before any snippet is displayed.
        $text = preg_replace(
            '#</?(?:p|div|section|article|header|footer|aside|main|nav|h[1-6]|li|ul|ol|dl|dt|dd|tr|td|th|table|thead|tbody|blockquote|pre|figure|figcaption|br|hr)\b[^>]*>#i',
            ' ' . self::BLOCK_MARKER . ' ',
            (string) $text
        );

        /*
         * Strip remaining HTML — with a bounded matcher rather than
         * strip_tags().
         *
         * strip_tags() has no upper bound on how far it will search for a
         * closing bracket. One unterminated construct anywhere in a document
         * therefore consumes everything after it, and the failure is silent:
         * the page renders perfectly while contributing almost nothing to the
         * index. A 36,965-character page reduced to 47 characters is what
         * prompted this change.
         *
         * The replacement only treats a run as a tag when it starts with a
         * name character and closes within a plausible distance. Anything
         * longer is not a tag by any reasonable reading, so it is left as
         * text, and a malformation costs the fragment it appears in rather
         * than the remainder of the document.
         *
         * Script and style blocks have already been removed above, so nothing
         * executable survives to be exposed by the looser matching. Any stray
         * brackets left behind become whitespace and are dropped by clean()
         * before tokenising; snippets are escaped again at render time.
         */
        /*
         * Removed with an empty string, not a space.
         *
         * Block-level tags have already become pilcrow markers in the step
         * above, so everything reaching here is inline: <strong>, <em>, <a>,
         * <code>, <span>. Those sit inside a sentence, and replacing them
         * with a space breaks the word they interrupt — "Uses
         * <strong>WordPress</strong>'s own" becomes "Uses WordPress 's own".
         *
         * That is not merely untidy. Deep links use the Text Fragments
         * standard, which requires the quoted run to match the rendered page
         * exactly; a fabricated space means the browser cannot find the
         * passage and silently declines to scroll or highlight. Phrase search
         * across inline markup fails for the same reason. strip_tags(), which
         * this replaced in 0.9.13, joined without a space, and that behaviour
         * has to be preserved.
         */
        $stripped = preg_replace( '#<[a-zA-Z!/][^>]{0,2000}>#s', '', (string) $text );
        $text     = ( null === $stripped ) ? (string) $text : $stripped;

        // Whatever angle brackets remain are inert text, not markup.
        $text = str_replace( [ '<', '>' ], ' ', $text );

        /*
         * Decode HTML entities — after stripping tags, never before.
         *
         * WordPress stores an ampersand in post content as &amp; and a curly
         * apostrophe as &#8217;. Left encoded, clean() strips the punctuation
         * around them and the remnants survive as words: &amp; becomes the
         * token "amp", &#8217; becomes "8217", &nbsp; becomes "nbsp". Those
         * are then real entries in the term dictionary, attached to a large
         * fraction of the site's pages, matching queries that mention them
         * and contributing nothing when they do.
         *
         * Order matters for safety. Decoding before wp_strip_all_tags() would
         * turn an encoded &lt;script&gt; back into a real tag; decoding after
         * leaves it as inert literal text, which is escaped again at render
         * time.
         */
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // An unterminated tag would otherwise survive as attribute text.
        // Nothing legitimate in prose looks like an unclosed tag.
        $text = preg_replace( '/<[^>]*$/s', ' ', (string) $text );

        // Collapse whitespace.
        $text = preg_replace( '/\s+/u', ' ', (string) $text );

        // Collapse runs of block markers (</p><p> produces two) and trim
        // them from the ends, where they carry no information.
        $text = preg_replace(
            '/(?:' . self::BLOCK_MARKER . '\s*)+/u',
            self::BLOCK_MARKER . ' ',
            (string) $text
        );

        return trim( (string) $text, " \t\n\r\0\x0B" . self::BLOCK_MARKER );
    }

    /**
     * Clean raw text: strip HTML, shortcodes, URLs, normalise whitespace.
     *
     * @param string $text Input.
     * @return string Cleaned text.
     */
    public function clean( $text ) {
        $text = $this->strip_markup( $text );

        // Remove URLs.
        $text = preg_replace( '#https?://\S+#i', ' ', $text );

        // Replace any non-alphanumeric with whitespace (keeps unicode letters).
        // \p{L} = any letter, \p{N} = any number. The u flag enables unicode mode.
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

        // Collapse multiple spaces.
        $text = preg_replace( '/\s+/', ' ', $text );

        return trim( (string) $text );
    }

    /**
     * Split cleaned text into lowercase word-tokens.
     *
     * @param string $cleaned Cleaned text.
     * @return string[]
     */
    public function split( $cleaned ) {
        if ( '' === $cleaned ) {
            return [];
        }

        $lower = mb_strtolower( $cleaned, 'UTF-8' );

        $tokens = preg_split( '/\s+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );

        return $tokens ? array_values( $tokens ) : [];
    }

    /**
     * Remove stopwords, too-short tokens, and pure-number tokens.
     *
     * @param string[] $tokens Raw lowercase tokens.
     * @return string[]
     */
    public function filter( array $tokens ) {
        $stopwords = $this->get_stopwords();
        $out       = [];

        foreach ( $tokens as $token ) {
            $len = mb_strlen( $token, 'UTF-8' );

            if ( $len < self::MIN_TOKEN_LENGTH || $len > self::MAX_TOKEN_LENGTH ) {
                continue;
            }
            if ( ctype_digit( $token ) ) {
                continue;
            }
            if ( isset( $stopwords[ $token ] ) ) {
                continue;
            }

            $out[] = $token;
        }

        return $out;
    }

    /**
     * Get the stopword lookup table, loading it on first call.
     *
     * @return array<string,bool>
     */
    public function get_stopwords() {
        if ( null !== $this->stopwords ) {
            return $this->stopwords;
        }

        $words = [];
        $file  = MBR_ISA_DIR . 'data/stopwords-en.php';

        if ( file_exists( $file ) ) {
            $loaded = include $file;
            if ( is_array( $loaded ) ) {
                $words = $loaded;
            }
        }

        /**
         * Filter the stopword list.
         *
         * @param string[] $words Flat list of stopwords.
         */
        $words = apply_filters( 'mbr_isa_stopwords', $words );

        $this->stopwords = array_fill_keys( array_map( 'strval', $words ), true );

        return $this->stopwords;
    }

    // =========================================================================
    // Porter stemmer — faithful implementation of Porter (1980).
    // =========================================================================

    /**
     * Apply the Porter stemming algorithm to a single token.
     *
     * @param string $word Lowercase token.
     * @return string Stemmed token.
     */
    public function stem( $word ) {
        if ( strlen( $word ) < 3 ) {
            return $word;
        }

        $word = self::step1ab( $word );
        $word = self::step1c( $word );
        $word = self::step2( $word );
        $word = self::step3( $word );
        $word = self::step4( $word );
        $word = self::step5( $word );

        return $word;
    }

    private static function step1ab( $word ) {
        // Step 1a.
        if ( substr( $word, -1 ) === 's' ) {
            if ( self::ends( $word, 'sses' ) || self::ends( $word, 'ies' ) ) {
                $word = substr( $word, 0, -2 );
            } elseif ( substr( $word, -2, 1 ) !== 's' ) {
                $word = substr( $word, 0, -1 );
            }
        }

        // Step 1b.
        if ( self::ends( $word, 'eed' ) ) {
            if ( self::measure( substr( $word, 0, -3 ) ) > 0 ) {
                $word = substr( $word, 0, -1 );
            }
        } else {
            $did_remove = false;
            if ( self::ends( $word, 'ed' ) && self::contains_vowel( substr( $word, 0, -2 ) ) ) {
                $word       = substr( $word, 0, -2 );
                $did_remove = true;
            } elseif ( self::ends( $word, 'ing' ) && self::contains_vowel( substr( $word, 0, -3 ) ) ) {
                $word       = substr( $word, 0, -3 );
                $did_remove = true;
            }

            if ( $did_remove ) {
                if ( self::ends( $word, 'at' ) || self::ends( $word, 'bl' ) || self::ends( $word, 'iz' ) ) {
                    $word .= 'e';
                } elseif ( self::has_double_consonant_suffix( $word )
                    && substr( $word, -1 ) !== 'l'
                    && substr( $word, -1 ) !== 's'
                    && substr( $word, -1 ) !== 'z' ) {
                    $word = substr( $word, 0, -1 );
                } elseif ( self::measure( $word ) === 1 && self::cvc( $word ) ) {
                    $word .= 'e';
                }
            }
        }

        return $word;
    }

    private static function step1c( $word ) {
        if ( self::ends( $word, 'y' ) && self::contains_vowel( substr( $word, 0, -1 ) ) ) {
            $word = substr( $word, 0, -1 ) . 'i';
        }
        return $word;
    }

    private static function step2( $word ) {
        static $map = [
            'ational' => 'ate',   'tional'  => 'tion', 'enci'    => 'ence',
            'anci'    => 'ance',  'izer'    => 'ize',  'abli'    => 'able',
            'alli'    => 'al',    'entli'   => 'ent',  'eli'     => 'e',
            'ousli'   => 'ous',   'ization' => 'ize',  'ation'   => 'ate',
            'ator'    => 'ate',   'alism'   => 'al',   'iveness' => 'ive',
            'fulness' => 'ful',   'ousness' => 'ous',  'aliti'   => 'al',
            'iviti'   => 'ive',   'biliti'  => 'ble',
        ];
        return self::replace_suffix( $word, $map );
    }

    private static function step3( $word ) {
        static $map = [
            'icate' => 'ic', 'ative' => '',   'alize' => 'al',
            'iciti' => 'ic', 'ical'  => 'ic', 'ful'   => '',
            'ness'  => '',
        ];
        return self::replace_suffix( $word, $map );
    }

    private static function step4( $word ) {
        static $suffixes = [
            'al','ance','ence','er','ic','able','ible','ant','ement','ment',
            'ent','ou','ism','ate','iti','ous','ive','ize',
        ];

        foreach ( $suffixes as $suffix ) {
            if ( self::ends( $word, $suffix ) ) {
                $stem = substr( $word, 0, -strlen( $suffix ) );
                if ( self::measure( $stem ) > 1 ) {
                    // Special case: ion only strips after s or t.
                    return $stem;
                }
                return $word;
            }
        }

        // Special case: 'ion' suffix.
        if ( self::ends( $word, 'ion' ) ) {
            $stem = substr( $word, 0, -3 );
            $last = substr( $stem, -1 );
            if ( self::measure( $stem ) > 1 && ( 's' === $last || 't' === $last ) ) {
                return $stem;
            }
        }

        return $word;
    }

    private static function step5( $word ) {
        // Step 5a.
        if ( self::ends( $word, 'e' ) ) {
            $stem = substr( $word, 0, -1 );
            $m    = self::measure( $stem );
            if ( $m > 1 || ( 1 === $m && ! self::cvc( $stem ) ) ) {
                $word = $stem;
            }
        }

        // Step 5b.
        if ( self::measure( $word ) > 1 && self::has_double_consonant_suffix( $word ) && self::ends( $word, 'l' ) ) {
            $word = substr( $word, 0, -1 );
        }

        return $word;
    }

    // --- Porter helpers ------------------------------------------------------

    /**
     * Does the word end with the given suffix?
     */
    private static function ends( $word, $suffix ) {
        $sl = strlen( $suffix );
        $wl = strlen( $word );
        if ( $sl > $wl ) {
            return false;
        }
        return substr_compare( $word, $suffix, -$sl, $sl ) === 0;
    }

    /**
     * Is the character at position $i in $word a consonant?
     */
    private static function is_consonant( $word, $i ) {
        $c = $word[ $i ];
        if ( in_array( $c, [ 'a', 'e', 'i', 'o', 'u' ], true ) ) {
            return false;
        }
        if ( 'y' === $c ) {
            return 0 === $i ? true : ! self::is_consonant( $word, $i - 1 );
        }
        return true;
    }

    /**
     * Does the word contain a vowel?
     */
    private static function contains_vowel( $word ) {
        $len = strlen( $word );
        for ( $i = 0; $i < $len; $i++ ) {
            if ( ! self::is_consonant( $word, $i ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the word end in a double consonant (e.g. "-tt", "-ss")?
     */
    private static function has_double_consonant_suffix( $word ) {
        $len = strlen( $word );
        if ( $len < 2 ) {
            return false;
        }
        if ( $word[ $len - 1 ] !== $word[ $len - 2 ] ) {
            return false;
        }
        return self::is_consonant( $word, $len - 1 );
    }

    /**
     * Does the word end in CVC (consonant-vowel-consonant), where the second C is not w, x, or y?
     */
    private static function cvc( $word ) {
        $len = strlen( $word );
        if ( $len < 3 ) {
            return false;
        }
        $c = $word[ $len - 1 ];
        if ( in_array( $c, [ 'w', 'x', 'y' ], true ) ) {
            return false;
        }
        return self::is_consonant( $word, $len - 3 )
            && ! self::is_consonant( $word, $len - 2 )
            && self::is_consonant( $word, $len - 1 );
    }

    /**
     * Porter's "measure" — counts the number of consonant-vowel sequences.
     */
    private static function measure( $word ) {
        $len = strlen( $word );
        $m   = 0;
        $i   = 0;

        // Skip leading consonants.
        while ( $i < $len && self::is_consonant( $word, $i ) ) {
            $i++;
        }

        while ( $i < $len ) {
            // Skip vowels.
            while ( $i < $len && ! self::is_consonant( $word, $i ) ) {
                $i++;
            }
            if ( $i >= $len ) {
                break;
            }
            $m++;
            // Skip consonants.
            while ( $i < $len && self::is_consonant( $word, $i ) ) {
                $i++;
            }
        }

        return $m;
    }

    /**
     * Replace a suffix according to a map, guarded by Porter's measure test.
     *
     * @param string   $word Word to transform.
     * @param string[] $map  Suffix => replacement.
     * @return string
     */
    private static function replace_suffix( $word, array $map ) {
        foreach ( $map as $suffix => $replacement ) {
            if ( self::ends( $word, $suffix ) ) {
                $stem = substr( $word, 0, -strlen( $suffix ) );
                if ( self::measure( $stem ) > 0 ) {
                    return $stem . $replacement;
                }
                return $word;
            }
        }
        return $word;
    }
}