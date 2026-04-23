<?php
/**
 * Intents — match known question patterns to pre-written answers.
 *
 * Runs before search. If a query matches an intent's trigger phrases,
 * the intent's response is returned directly — no BM25, no indexing.
 * This is the single biggest UX win for a chat assistant: the common
 * questions get perfect answers every time.
 *
 * Intent structure:
 *   [
 *     'id'         => 'contact',
 *     'label'      => 'Contact',
 *     'triggers'   => ['contact', 'email', 'get in touch', 'how do I reach'],
 *     'response'   => 'You can reach Bob at robert@madebyrobert.co.uk ...',
 *     'confidence' => 1.0,  // Higher wins ties.
 *   ]
 *
 * Triggers are matched as case-insensitive substrings against the raw query.
 * For more precision an admin can prefix a trigger with "re:" to treat it
 * as a regex (e.g. "re:\bhours?\b" to match whole words only).
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Intents {

    /**
     * Try to match a query against configured intents.
     *
     * @param string $raw_query Unmodified user query.
     * @return array|null Intent array if matched, null otherwise.
     */
    public function match( $raw_query ) {
        $raw_query = trim( (string) $raw_query );
        if ( '' === $raw_query ) {
            return null;
        }

        $normalised = $this->normalise( $raw_query );
        $intents    = $this->get_configured_intents();
        $best       = null;

        foreach ( $intents as $intent ) {
            if ( ! $this->intent_is_valid( $intent ) ) {
                continue;
            }

            if ( $this->triggers_match( $intent['triggers'], $raw_query, $normalised ) ) {
                $confidence = isset( $intent['confidence'] ) ? (float) $intent['confidence'] : 1.0;
                if ( null === $best || $confidence > $best['confidence'] ) {
                    $best = [
                        'id'         => $intent['id'],
                        'label'      => $intent['label'],
                        'response'   => $intent['response'],
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Get all configured intents, falling back to defaults if none stored.
     *
     * @return array<int,array>
     */
    public function get_configured_intents() {
        $intents = get_option( 'mbr_isa_intents', null );

        if ( ! is_array( $intents ) || empty( $intents ) ) {
            $intents = $this->get_default_intents();
        }

        return $intents;
    }

    /**
     * Default intents shipped with the plugin.
     *
     * The responses are intentionally generic placeholders — the admin
     * UI (later session) will let users edit these to match their site.
     *
     * @return array<int,array>
     */
    public function get_default_intents() {
        return [
            [
                'id'         => 'contact',
                'label'      => 'Contact',
                'triggers'   => [ 'contact', 'email address', 'get in touch', 'how do i reach', 'reach you', 'how can i contact' ],
                'response'   => 'You can get in touch via the contact form on this site. Look for the "Contact" link in the main menu.',
                'confidence' => 1.0,
            ],
            [
                'id'         => 'pricing',
                'label'      => 'Pricing',
                'triggers'   => [ 'how much', 'what do you charge', 'pricing', 'your rates', 'cost of', 'get a quote' ],
                'response'   => 'Pricing depends on the scope of work. Please get in touch with your requirements and I can give you a tailored quote.',
                'confidence' => 1.0,
            ],
            [
                'id'         => 'services',
                'label'      => 'Services',
                'triggers'   => [ 'what services', 'what do you do', 'what do you offer', 'do you do', 'can you build' ],
                'response'   => 'I build and maintain WordPress websites, custom plugins, and e-commerce solutions. Ask me about anything specific on this site, or get in touch to discuss your project.',
                'confidence' => 0.9,
            ],
            [
                'id'         => 'hours',
                'label'      => 'Hours',
                'triggers'   => [ 'opening hours', 'what hours', 'when are you open', 'are you open', 'business hours' ],
                'response'   => 'I work flexible hours including evenings, and respond to messages as quickly as possible. For urgent matters, please mention it in your message.',
                'confidence' => 1.0,
            ],
            [
                'id'         => 'help',
                'label'      => 'Help',
                'triggers'   => [ 'need help', 'can you help', 'i am stuck', 'not working' ],
                'response'   => 'Happy to help. Tell me a bit more about what you are trying to do, or get in touch and I will take a look.',
                'confidence' => 0.7,
            ],
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Check if any trigger matches the query.
     *
     * @param array  $triggers   List of trigger phrases.
     * @param string $raw_query  Raw user query.
     * @param string $normalised Lowercased, whitespace-normalised query.
     * @return bool
     */
    private function triggers_match( array $triggers, $raw_query, $normalised ) {
        foreach ( $triggers as $trigger ) {
            $trigger = trim( (string) $trigger );
            if ( '' === $trigger ) {
                continue;
            }

            // Regex trigger: prefixed with "re:"
            if ( 0 === stripos( $trigger, 're:' ) ) {
                $pattern = substr( $trigger, 3 );
                if ( '' === $pattern ) {
                    continue;
                }
                // Wrap in delimiters with case-insensitive + UTF-8 flags.
                $full_pattern = '/' . str_replace( '/', '\/', $pattern ) . '/iu';
                if ( @preg_match( $full_pattern, $raw_query ) === 1 ) {
                    return true;
                }
                continue;
            }

            // Plain substring match, case-insensitive.
            $trigger_lower = $this->normalise( $trigger );
            if ( '' === $trigger_lower ) {
                continue;
            }
            if ( false !== strpos( $normalised, $trigger_lower ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase + whitespace-normalise a string for matching.
     *
     * @param string $text
     * @return string
     */
    private function normalise( $text ) {
        $text = (string) $text;
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }

    /**
     * Is this intent array well-formed enough to try matching?
     *
     * @param mixed $intent
     * @return bool
     */
    private function intent_is_valid( $intent ) {
        if ( ! is_array( $intent ) ) {
            return false;
        }
        if ( empty( $intent['id'] ) || empty( $intent['triggers'] ) || ! is_array( $intent['triggers'] ) ) {
            return false;
        }
        if ( ! isset( $intent['response'] ) || '' === trim( (string) $intent['response'] ) ) {
            return false;
        }
        return true;
    }
}