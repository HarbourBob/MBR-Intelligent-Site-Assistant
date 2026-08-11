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
     * Clean raw text: strip HTML, shortcodes, URLs, normalise whitespace.
     *
     * @param string $text Input.
     * @return string Cleaned text.
     */
    public function clean( $text ) {
        // Remove shortcodes first — before strip_tags so their contents don't leak.
        if ( function_exists( 'strip_shortcodes' ) ) {
            $text = strip_shortcodes( $text );
        }

        // Remove script/style tags and their contents entirely.
        $text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $text );

        // Strip remaining HTML.
        $text = wp_strip_all_tags( $text );

        // Decode HTML entities so &amp; becomes &, etc.
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

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

        $lower = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $cleaned, 'UTF-8' )
            : strtolower( $cleaned );

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
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $token, 'UTF-8' ) : strlen( $token );

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