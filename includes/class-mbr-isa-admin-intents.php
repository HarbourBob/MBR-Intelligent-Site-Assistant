<?php
/**
 * Admin UI for managing intents.
 *
 * Adds a Tools → MBR ISA Intents page where admins can add, edit,
 * delete and re-enable/disable intents without touching code.
 *
 * The page is structured as one form per intent (so an in-progress edit
 * to one intent can never lose another's edits) plus a separate "Add new"
 * form and a "Reset to defaults" button.
 *
 * Storage key: mbr_isa_intents (option). Falls back to MBR_ISA_Intents
 * defaults whenever the stored array is empty, so wiping the option always
 * leaves a working baseline.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Admin_Intents {

    const PAGE_SLUG       = 'mbr-isa-intents';

    /**
     * Fragment identifier of the "Add a new intent" card.
     *
     * @since 0.9.20
     */
    const ANCHOR_ADD      = 'mbr-isa-add-intent';
    const OPTION_KEY      = 'mbr_isa_intents';
    const ACTION_SAVE     = 'mbr_isa_save_intent';
    const ACTION_DELETE   = 'mbr_isa_delete_intent';
    const ACTION_RESET    = 'mbr_isa_reset_intents';
    const NOTICE_KEY      = 'mbr_isa_intent_notice';

    /** @var MBR_ISA_Intents */
    private $intents_service;

    public function __construct( MBR_ISA_Intents $intents_service ) {
        $this->intents_service = $intents_service;
    }

    public function register_hooks() {
        add_action( 'admin_menu',                              [ $this, 'register_page' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE,         [ $this, 'handle_save' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE,       [ $this, 'handle_delete' ] );
        add_action( 'admin_post_' . self::ACTION_RESET,        [ $this, 'handle_reset' ] );
    }

    public function register_page() {
        add_submenu_page(
            MBR_ISA::PARENT_SLUG,
            __( 'MBR ISA Intents', 'mbr-isa' ),
            __( 'MBR ISA Intents', 'mbr-isa' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $intents = $this->intents_service->get_configured_intents();
        $notice  = $this->consume_notice();
        $test_q  = '';
        $test_hit = null;

        // Quick "test a query" panel.
        if ( isset( $_POST['mbr_isa_intent_test'] ) && check_admin_referer( 'mbr_isa_intent_test' ) ) {
            $test_q   = sanitize_text_field( wp_unslash( $_POST['mbr_isa_intent_test'] ) );
            $test_hit = $this->intents_service->match( $test_q );
        }

        ?>
        <div class="wrap mbr-isa-intents-page">
            <h1><?php esc_html_e( 'MBR Intelligent Site Assistant — Intents', 'mbr-isa' ); ?></h1>

            <p class="description" style="max-width:780px;">
                <?php esc_html_e( 'Intents match known question patterns to pre-written answers. They run before the search index, so the common questions get perfect answers every time. Triggers are matched as case-insensitive substrings; prefix a trigger with "re:" to use it as a regular expression (e.g. "re:\bhours?\b").', 'mbr-isa' ); ?>
            </p>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible" style="margin-top:1em;">
                    <p><?php echo wp_kses_post( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_test_panel( $test_q, $test_hit ); ?>

            <h2 style="margin-top:2em;">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: number of intents */
                    _n( 'Configured intent (%d)', 'Configured intents (%d)', count( $intents ), 'mbr-isa' ),
                    count( $intents )
                ) );
                ?>
            </h2>

            <?php if ( empty( $intents ) ) : ?>
                <p><em><?php esc_html_e( 'No intents configured. Add one below, or reset to the shipped defaults.', 'mbr-isa' ); ?></em></p>
            <?php else : ?>
                <?php foreach ( $intents as $intent ) : ?>
                    <?php $this->render_intent_form( $intent, false ); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2 style="margin-top:2.5em;"><?php esc_html_e( 'Add a new intent', 'mbr-isa' ); ?></h2>
            <?php $this->render_intent_form( $this->blank_intent(), true ); ?>

            <h2 style="margin-top:2.5em;"><?php esc_html_e( 'Reset to defaults', 'mbr-isa' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Replaces all intents with the defaults shipped with the plugin. Your edits will be lost.', 'mbr-isa' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Replace all intents with the shipped defaults? This cannot be undone.', 'mbr-isa' ) ); ?>');">
                <?php wp_nonce_field( self::ACTION_RESET ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESET ); ?>">
                <button type="submit" class="button"><?php esc_html_e( 'Reset to defaults', 'mbr-isa' ); ?></button>
            </form>
        </div>

        <?php
    }

    private function render_test_panel( $test_q, $test_hit ) {
        ?>
        <div class="mbr-isa-test-panel">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Test a query', 'mbr-isa' ); ?></h2>
            <p class="description" style="margin-top:0;">
                <?php esc_html_e( 'Type a question and see which intent (if any) would match. Useful for sanity-checking trigger phrases.', 'mbr-isa' ); ?>
            </p>
            <form method="post" style="margin-top:.5em;">
                <?php wp_nonce_field( 'mbr_isa_intent_test' ); ?>
                <input type="text" name="mbr_isa_intent_test" value="<?php echo esc_attr( $test_q ); ?>" placeholder="<?php esc_attr_e( 'e.g. how can I contact you?', 'mbr-isa' ); ?>" style="width:60%;max-width:500px;" autocomplete="off">
                <button type="submit" class="button"><?php esc_html_e( 'Test', 'mbr-isa' ); ?></button>
            </form>

            <?php if ( '' !== $test_q ) : ?>
                <?php if ( $test_hit ) : ?>
                    <div class="mbr-isa-test-result">
                        <strong><?php esc_html_e( 'Matched intent:', 'mbr-isa' ); ?></strong>
                        <code><?php echo esc_html( $test_hit['id'] ); ?></code>
                        <span class="mbr-isa-pill"><?php echo esc_html( $test_hit['label'] ); ?></span>
                        <span style="color:#666;font-size:12px;">
                            <?php
                            /* translators: %s: confidence score, formatted to two decimal places */
                            echo esc_html( sprintf( __( 'confidence: %s', 'mbr-isa' ), number_format_i18n( $test_hit['confidence'], 2 ) ) );
                            ?>
                        </span>
                        <div style="margin-top:.5em;font-size:13px;color:#444;">
                            <?php echo wp_kses_post( $test_hit['response'] ); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="mbr-isa-test-result is-no-match">
                        <strong><?php esc_html_e( 'No intent matched.', 'mbr-isa' ); ?></strong>
                        <span style="color:#666;font-size:13px;">
                            <?php esc_html_e( 'The query would fall through to the search index.', 'mbr-isa' ); ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_intent_form( array $intent, $is_new ) {
        $id          = (string) ( $intent['id'] ?? '' );
        $label       = (string) ( $intent['label'] ?? '' );
        $triggers    = is_array( $intent['triggers'] ?? null ) ? $intent['triggers'] : [];
        $response    = (string) ( $intent['response'] ?? '' );
        $confidence  = isset( $intent['confidence'] ) ? (float) $intent['confidence'] : 1.0;
        $disabled    = ! empty( $intent['disabled'] );

        // Field IDs need a unique suffix so multiple cards on the page don't collide.
        $suffix      = $is_new ? 'new' : sanitize_html_class( $id );
        $card_class  = 'mbr-isa-intent-card';
        if ( ! $is_new && $disabled ) {
            $card_class .= ' is-disabled';
        }

        ?>
        <div class="<?php echo esc_attr( $card_class ); ?>"
             id="<?php echo esc_attr( $is_new ? self::ANCHOR_ADD : 'mbr-isa-intent-' . $id ); ?>">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::ACTION_SAVE ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
                <input type="hidden" name="original_id" value="<?php echo esc_attr( $is_new ? '' : $id ); ?>">

                <h3>
                    <?php if ( $is_new ) : ?>
                        <?php esc_html_e( 'New intent', 'mbr-isa' ); ?>
                    <?php else : ?>
                        <code><?php echo esc_html( $id ); ?></code>
                        <?php if ( '' !== $label ) : ?>
                            <span class="mbr-isa-pill"><?php echo esc_html( $label ); ?></span>
                        <?php endif; ?>
                        <?php if ( $disabled ) : ?>
                            <span class="mbr-isa-pill is-disabled-pill"><?php esc_html_e( 'disabled', 'mbr-isa' ); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </h3>

                <div class="mbr-isa-row">
                    <div>
                        <label class="mbr-isa-field-label" for="mbr-isa-id-<?php echo esc_attr( $suffix ); ?>">
                            <?php esc_html_e( 'ID', 'mbr-isa' ); ?>
                        </label>
                        <input type="text"
                               id="mbr-isa-id-<?php echo esc_attr( $suffix ); ?>"
                               name="intent_id"
                               value="<?php echo esc_attr( $id ); ?>"
                               required
                               pattern="[a-z0-9_-]+"
                               style="width:100%;"
                               autocomplete="off"
                               placeholder="e.g. shipping">
                        <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, dashes, underscores.', 'mbr-isa' ); ?></p>
                    </div>
                    <div>
                        <label class="mbr-isa-field-label" for="mbr-isa-label-<?php echo esc_attr( $suffix ); ?>">
                            <?php esc_html_e( 'Label', 'mbr-isa' ); ?>
                        </label>
                        <input type="text"
                               id="mbr-isa-label-<?php echo esc_attr( $suffix ); ?>"
                               name="intent_label"
                               value="<?php echo esc_attr( $label ); ?>"
                               style="width:100%;"
                               autocomplete="off"
                               placeholder="e.g. Shipping">
                        <p class="description"><?php esc_html_e( 'Human-friendly name shown in diagnostics.', 'mbr-isa' ); ?></p>
                    </div>
                    <div style="flex:0 1 130px;">
                        <label class="mbr-isa-field-label" for="mbr-isa-confidence-<?php echo esc_attr( $suffix ); ?>">
                            <?php esc_html_e( 'Confidence', 'mbr-isa' ); ?>
                        </label>
                        <input type="number"
                               id="mbr-isa-confidence-<?php echo esc_attr( $suffix ); ?>"
                               name="intent_confidence"
                               value="<?php echo esc_attr( number_format( $confidence, 2, '.', '' ) ); ?>"
                               min="0" max="1" step="0.01"
                               style="width:100%;">
                        <p class="description"><?php esc_html_e( '0.0 – 1.0. Higher wins ties.', 'mbr-isa' ); ?></p>
                    </div>
                </div>

                <div style="margin-bottom:.75em;">
                    <label class="mbr-isa-field-label" for="mbr-isa-triggers-<?php echo esc_attr( $suffix ); ?>">
                        <?php esc_html_e( 'Triggers', 'mbr-isa' ); ?>
                    </label>
                    <textarea
                        id="mbr-isa-triggers-<?php echo esc_attr( $suffix ); ?>"
                        name="intent_triggers"
                        rows="4"
                        placeholder="contact&#10;email address&#10;get in touch&#10;re:\bhours?\b"
                    ><?php echo esc_textarea( implode( "\n", $triggers ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One trigger per line. Substring match by default; prefix with "re:" for a regular expression. Up to 10 regex triggers per intent, 250 characters each; nested repetitions such as (a+)+ are rejected because they can be extremely slow to match.', 'mbr-isa' ); ?></p>
                </div>

                <div style="margin-bottom:.75em;">
                    <label class="mbr-isa-field-label" for="mbr-isa-response-<?php echo esc_attr( $suffix ); ?>">
                        <?php esc_html_e( 'Response', 'mbr-isa' ); ?>
                    </label>
                    <textarea
                        id="mbr-isa-response-<?php echo esc_attr( $suffix ); ?>"
                        name="intent_response"
                        rows="4"
                    ><?php echo esc_textarea( $response ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Basic HTML allowed (links, <strong>, <em>, <br>, lists, etc.). Sanitised on save.', 'mbr-isa' ); ?>
                    </p>
                </div>

                <div>
                    <label>
                        <input type="checkbox" name="intent_disabled" value="1" <?php checked( $disabled ); ?>>
                        <?php esc_html_e( 'Disabled — keep this intent in the list but don\'t fire it.', 'mbr-isa' ); ?>
                    </label>
                </div>

                <div class="mbr-isa-intent-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_new ? esc_html__( 'Add intent', 'mbr-isa' ) : esc_html__( 'Save changes', 'mbr-isa' ); ?>
                    </button>
                </div>
            </form>

            <?php if ( ! $is_new ) : ?>
                <div style="margin-top:.5em;">
                    <form method="post"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Delete the "%s" intent? This cannot be undone.', 'mbr-isa' ), $id ) ); ?>');">
                        <?php wp_nonce_field( self::ACTION_DELETE . '_' . $id ); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
                        <input type="hidden" name="intent_id" value="<?php echo esc_attr( $id ); ?>">
                        <button type="submit" class="button button-link-delete" style="color:#b32d2e;">
                            <?php esc_html_e( 'Delete intent', 'mbr-isa' ); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function blank_intent() {
        return [
            'id'         => '',
            'label'      => '',
            'triggers'   => [],
            'response'   => '',
            'confidence' => 1.0,
            'disabled'   => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Save handler
    // -------------------------------------------------------------------------

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( self::ACTION_SAVE );

        $original_id = isset( $_POST['original_id'] ) ? sanitize_key( wp_unslash( $_POST['original_id'] ) ) : '';
        $is_new      = ( '' === $original_id );

        $new_id      = isset( $_POST['intent_id'] )         ? sanitize_key( wp_unslash( $_POST['intent_id'] ) ) : '';
        $label_raw   = isset( $_POST['intent_label'] )      ? wp_unslash( $_POST['intent_label'] )              : '';
        $label       = sanitize_text_field( $label_raw );
        $triggers_raw= isset( $_POST['intent_triggers'] )   ? wp_unslash( $_POST['intent_triggers'] )           : '';
        $response_raw= isset( $_POST['intent_response'] )   ? wp_unslash( $_POST['intent_response'] )           : '';
        $confidence  = isset( $_POST['intent_confidence'] ) ? (float) $_POST['intent_confidence']               : 1.0;
        $disabled    = ! empty( $_POST['intent_disabled'] );

        // Validate ID.
        if ( '' === $new_id ) {
            $this->set_notice( 'error', __( 'ID is required and must contain only lowercase letters, numbers, dashes or underscores.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        // Sanitise + validate triggers.
        $triggers = $this->parse_triggers( $triggers_raw );
        if ( empty( $triggers ) ) {
            $this->set_notice( 'error', __( 'At least one trigger is required.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        $regex_error = $this->validate_regex_triggers( $triggers );
        if ( null !== $regex_error ) {
            $this->set_notice( 'error', $regex_error );
            $this->redirect_back();
        }

        // Sanitise response (allow basic HTML).
        $response = wp_kses_post( $response_raw );
        if ( '' === trim( wp_strip_all_tags( $response ) ) ) {
            $this->set_notice( 'error', __( 'Response cannot be empty.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        // Clamp confidence.
        if ( $confidence < 0 ) { $confidence = 0.0; }
        if ( $confidence > 1 ) { $confidence = 1.0; }

        // Load current intents from the option (NOT from the service —
        // the service falls back to defaults when empty, which would
        // re-introduce the defaults on every save).
        $intents = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $intents ) ) {
            $intents = [];
        }

        // If empty (first edit), seed with the defaults so we don't lose them.
        if ( empty( $intents ) ) {
            $intents = $this->intents_service->get_default_intents();
        }

        // Uniqueness check for the (possibly new) ID.
        foreach ( $intents as $i => $existing ) {
            $existing_id = isset( $existing['id'] ) ? (string) $existing['id'] : '';
            if ( $existing_id === $new_id ) {
                // Allow if it's the same row we're editing.
                if ( $is_new || $existing_id !== $original_id ) {
                    $this->set_notice(
                        'error',
                        sprintf(
                            /* translators: %s: the duplicate ID */
                            __( 'An intent with the ID %s already exists. Pick a different ID.', 'mbr-isa' ),
                            '<code>' . esc_html( $new_id ) . '</code>'
                        )
                    );
                    $this->redirect_back();
                }
            }
        }

        $payload = [
            'id'         => $new_id,
            'label'      => '' !== $label ? $label : ucfirst( str_replace( [ '-', '_' ], ' ', $new_id ) ),
            'triggers'   => $triggers,
            'response'   => $response,
            'confidence' => $confidence,
            'disabled'   => $disabled,
        ];

        if ( $is_new ) {
            $intents[] = $payload;
            $this->set_notice(
                'success',
                sprintf( /* translators: %s: intent ID */ __( 'Intent %s added.', 'mbr-isa' ), '<code>' . esc_html( $new_id ) . '</code>' )
            );
        } else {
            $found = false;
            foreach ( $intents as $i => $existing ) {
                if ( isset( $existing['id'] ) && $existing['id'] === $original_id ) {
                    $intents[ $i ] = $payload;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                // The original_id no longer exists — treat as add.
                $intents[] = $payload;
            }
            $this->set_notice(
                'success',
                sprintf( /* translators: %s: intent ID */ __( 'Intent %s saved.', 'mbr-isa' ), '<code>' . esc_html( $new_id ) . '</code>' )
            );
        }

        update_option( self::OPTION_KEY, array_values( $intents ) );

        // Adding leaves you at the blank form ready for the next one; editing
        // returns you to the intent you were editing.
        $this->redirect_back( $is_new ? self::ANCHOR_ADD : 'mbr-isa-intent-' . $new_id );
    }

    // -------------------------------------------------------------------------
    // Delete handler
    // -------------------------------------------------------------------------

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }

        $id = isset( $_POST['intent_id'] ) ? sanitize_key( wp_unslash( $_POST['intent_id'] ) ) : '';
        check_admin_referer( self::ACTION_DELETE . '_' . $id );

        if ( '' === $id ) {
            $this->set_notice( 'error', __( 'No intent ID supplied.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        $intents = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $intents ) ) {
            $intents = [];
        }

        // If the option is empty, the user is "deleting" a default —
        // seed with defaults first so the deletion takes effect.
        if ( empty( $intents ) ) {
            $intents = $this->intents_service->get_default_intents();
        }

        $before = count( $intents );
        $intents = array_values( array_filter( $intents, function ( $intent ) use ( $id ) {
            return ! ( is_array( $intent ) && isset( $intent['id'] ) && $intent['id'] === $id );
        } ) );

        update_option( self::OPTION_KEY, $intents );

        if ( count( $intents ) < $before ) {
            $this->set_notice(
                'success',
                sprintf( /* translators: %s: intent ID */ __( 'Intent %s deleted.', 'mbr-isa' ), '<code>' . esc_html( $id ) . '</code>' )
            );
        } else {
            $this->set_notice( 'warning', __( 'No matching intent was found to delete.', 'mbr-isa' ) );
        }

        $this->redirect_back();
    }

    // -------------------------------------------------------------------------
    // Reset handler
    // -------------------------------------------------------------------------

    public function handle_reset() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( self::ACTION_RESET );

        // Persist the defaults explicitly so subsequent edits start from there.
        update_option( self::OPTION_KEY, $this->intents_service->get_default_intents() );

        $this->set_notice( 'success', __( 'Intents reset to the shipped defaults.', 'mbr-isa' ) );
        $this->redirect_back();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Split textarea content into clean trigger lines.
     *
     * @param string $raw
     * @return array<int,string>
     */
    private function parse_triggers( $raw ) {
        $raw   = (string) $raw;
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $out   = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            // Triggers may contain regex characters, so keep them as-is —
            // but strip control characters to be safe.
            $line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line );
            if ( null !== $line && '' !== $line ) {
                $out[] = $line;
            }
        }
        // Dedupe while preserving order.
        return array_values( array_unique( $out ) );
    }

    /**
     * Longest permitted "re:" pattern, in characters.
     *
     * Genuine triggers are short — a word-boundary match is a dozen characters.
     * The limit bounds how much work one pattern can ask the engine to do; it is
     * not meant to constrain legitimate use.
     */
    const MAX_REGEX_LENGTH = 250;

    /** Most "re:" triggers allowed on a single intent. */
    const MAX_REGEX_TRIGGERS = 10;

    /**
     * Validate every "re:" trigger, returning an error message or null.
     *
     * Three checks, in increasing order of fussiness.
     *
     * Syntactic validity is the only strictly necessary one — an invalid pattern
     * makes the intent silently stop matching. The other two bound catastrophic
     * backtracking: a pattern such as (a+)+$ is perfectly valid and will hang PHP
     * on a long non-matching subject. Intent matching runs on the public search
     * endpoint, so a hang there is visitor-facing.
     *
     * This is deliberately a coarse net rather than an analysis. Saving an intent
     * requires manage_options, so the realistic case is an administrator pasting
     * a pattern from the internet without reading it, not an attacker — and the
     * cheap checks catch that. A determined administrator can still write a slow
     * pattern, just as they can still write slow PHP.
     *
     * @param array $triggers Parsed trigger lines.
     * @return string|null Error message with escaped markup, or null if all pass.
     */
    private function validate_regex_triggers( array $triggers ) {
        $regex_triggers = [];
        foreach ( $triggers as $trigger ) {
            if ( 0 === stripos( $trigger, 're:' ) ) {
                $regex_triggers[] = $trigger;
            }
        }

        if ( count( $regex_triggers ) > self::MAX_REGEX_TRIGGERS ) {
            return sprintf(
                /* translators: %d: maximum number of regex triggers */
                __( 'An intent may have at most %d regex triggers. Plain substring triggers are unlimited, and are usually the better choice.', 'mbr-isa' ),
                self::MAX_REGEX_TRIGGERS
            );
        }

        foreach ( $regex_triggers as $trigger ) {
            $pattern = substr( $trigger, 3 );
            $shown   = '<code>' . esc_html( $trigger ) . '</code>';

            if ( strlen( $pattern ) > self::MAX_REGEX_LENGTH ) {
                return sprintf(
                    /* translators: 1: offending trigger, 2: maximum length */
                    __( 'The regex trigger %1$s is longer than %2$d characters. A long pattern is usually better expressed as several short ones.', 'mbr-isa' ),
                    $shown,
                    self::MAX_REGEX_LENGTH
                );
            }

            $full_pattern = '/' . str_replace( '/', '\/', $pattern ) . '/iu';

            // Suppress warnings; preg_match returns false on an invalid pattern.
            if ( @preg_match( $full_pattern, '' ) === false ) {
                return sprintf(
                    /* translators: %s: offending trigger */
                    __( 'The regex trigger %s is not a valid regular expression.', 'mbr-isa' ),
                    $shown
                );
            }

            if ( $this->regex_looks_pathological( $pattern ) ) {
                return sprintf(
                    /* translators: %s: offending trigger */
                    __( 'The regex trigger %s nests one repetition inside another, which can make matching extremely slow on some inputs. Please rewrite it without the nested quantifier.', 'mbr-isa' ),
                    $shown
                );
            }
        }

        return null;
    }

    /**
     * Detect the two classic catastrophic-backtracking shapes.
     *
     * First: a group containing a repetition, itself repeated — (a+)+, (\s*)*,
     * (x{2,}){3,}. Second: a repeated group of alternatives that can match the
     * same text more than one way, as in (a|a)+. Both give the engine an
     * exponential number of ways to fail, so a non-matching subject of a few
     * dozen characters can outlast the request.
     *
     * Not exhaustive, and not meant to be. It catches the forms people copy
     * without understanding them. See validate_regex_triggers().
     *
     * @param string $pattern The pattern, without the "re:" prefix.
     * @return bool
     */
    private function regex_looks_pathological( $pattern ) {
        $quantifier = '(?:[*+]|\{\d+,\d*\})';
        $atom       = '(?:[^()\\\\]|\\\\.)';

        // A group containing a quantifier, itself quantified.
        if ( @preg_match( '/\(' . $atom . '*' . $quantifier . $atom . '*\)\s*' . $quantifier . '/', $pattern ) ) {
            return true;
        }

        // A quantified group of alternatives.
        if ( @preg_match( '/\(' . $atom . '*\|' . $atom . '*\)\s*' . $quantifier . '/', $pattern ) ) {
            return true;
        }

        return false;
    }

    /**
     * Return to the intents screen, optionally at a particular card.
     *
     * Adding an intent used to drop you back at the top of the page, so
     * adding several in a row meant scrolling to the bottom again each time.
     * A successful save now returns to the card it concerns — the blank
     * "add" form when something was just added, so the next one can be typed
     * straight away, or the edited intent's own card otherwise.
     *
     * Errors deliberately get no anchor. The message they need to read is a
     * notice at the top of the screen, and scrolling past it to a card would
     * leave the save looking as though it had simply done nothing.
     *
     * @since 0.9.20
     * @param string $anchor Fragment to scroll to, without the "#".
     */
    private function redirect_back( $anchor = '' ) {
        $url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        if ( '' !== $anchor ) {
            $url .= '#' . rawurlencode( $anchor );
        }
        wp_safe_redirect( $url );
        exit;
    }

    private function set_notice( $type, $message ) {
        set_transient(
            self::NOTICE_KEY . '_' . get_current_user_id(),
            [ 'type' => $type, 'message' => $message ],
            30
        );
    }

    private function consume_notice() {
        $key    = self::NOTICE_KEY . '_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( $notice ) {
            delete_transient( $key );
            // Whitelist the notice type for the CSS class.
            $type = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
            if ( ! in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ) {
                $type = 'info';
            }
            return [
                'type'    => $type,
                'message' => isset( $notice['message'] ) ? (string) $notice['message'] : '',
            ];
        }
        return null;
    }
}
