<?php
/**
 * Synonyms — expands query tokens with equivalent terms before searching.
 *
 * A synonym group is an array of terms considered equivalent. When a query
 * contains any term in a group, all other terms in that group are added
 * to the search. Terms are matched on their stemmed form, so "WordPress"
 * and "WP" both get reduced to a stem first.
 *
 * Example group: ['wp', 'wordpress']
 *   Query "WP plugin" -> tokens [wp, plugin] -> expanded [wp, wordpress, plugin]
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Synonyms {

    /**
     * @var MBR_ISA_Tokeniser
     */
    private $tokeniser;

    /**
     * Cached expansion lookup: stem => array of extra stems to add.
     *
     * @var array|null
     */
    private $expansion_map = null;

    public function __construct( MBR_ISA_Tokeniser $tokeniser ) {
        $this->tokeniser = $tokeniser;
    }

    /**
     * Expand a list of query tokens with synonyms.
     *
     * Input tokens should already be stemmed. Output contains the originals
     * plus any synonym stems, deduplicated, preserving input order first.
     *
     * @param string[] $tokens Stemmed query tokens.
     * @return string[]
     */
    public function expand( array $tokens ) {
        if ( empty( $tokens ) ) {
            return [];
        }

        $map      = $this->get_expansion_map();
        $expanded = $tokens;

        foreach ( $tokens as $token ) {
            if ( isset( $map[ $token ] ) ) {
                foreach ( $map[ $token ] as $extra ) {
                    $expanded[] = $extra;
                }
            }
        }

        return array_values( array_unique( $expanded ) );
    }

    /**
     * Retrieve all configured synonym groups (raw, not yet stemmed).
     *
     * @return array<int,string[]>
     */
    public function get_all_groups() {
        $groups = get_option( 'mbr_isa_synonyms', null );

        if ( ! is_array( $groups ) || empty( $groups ) ) {
            $groups = $this->get_default_groups();
        }

        return $groups;
    }

    /**
     * Default synonym groups shipped with the plugin.
     *
     * @return array<int,string[]>
     */
    public function get_default_groups() {
        return [
            [ 'wp', 'wordpress' ],
            [ 'site', 'website', 'webpage' ],
            [ 'ecommerce', 'e-commerce', 'shop', 'store', 'online shop' ],
            [ 'price', 'pricing', 'cost', 'fee', 'rate', 'quote' ],
            [ 'contact', 'email', 'reach', 'get in touch' ],
            [ 'help', 'support', 'assistance' ],
            [ 'plugin', 'extension', 'add-on', 'addon' ],
            [ 'theme', 'template', 'skin' ],
            [ 'build', 'develop', 'create', 'make' ],
            [ 'seo', 'search engine optimisation', 'search engine optimization' ],
        ];
    }

    /**
     * Build the lookup map: each stemmed term points at the other stemmed
     * terms in its group. Cached for the request.
     *
     * @return array<string,string[]>
     */
    private function get_expansion_map() {
        if ( null !== $this->expansion_map ) {
            return $this->expansion_map;
        }

        $map    = [];
        $groups = $this->get_all_groups();

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) || count( $group ) < 2 ) {
                continue;
            }

            // Stem every phrase in the group. Multi-word phrases yield multiple stems.
            $stemmed = [];
            foreach ( $group as $phrase ) {
                $phrase_stems = $this->tokeniser->tokenise( $phrase );
                foreach ( $phrase_stems as $stem ) {
                    $stemmed[] = $stem;
                }
            }

            $stemmed = array_values( array_unique( $stemmed ) );
            if ( count( $stemmed ) < 2 ) {
                continue;
            }

            foreach ( $stemmed as $stem ) {
                if ( ! isset( $map[ $stem ] ) ) {
                    $map[ $stem ] = [];
                }
                foreach ( $stemmed as $other ) {
                    if ( $other !== $stem ) {
                        $map[ $stem ][] = $other;
                    }
                }
                $map[ $stem ] = array_values( array_unique( $map[ $stem ] ) );
            }
        }

        $this->expansion_map = $map;
        return $this->expansion_map;
    }
}