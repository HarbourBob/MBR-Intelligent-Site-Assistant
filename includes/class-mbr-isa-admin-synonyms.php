<?php
/**
 * Admin UI for managing synonym groups.
 *
 * Adds a Tools → MBR ISA Synonyms page where admins can add, edit,
 * delete and reset synonym groups without touching code.
 *
 * Mirrors the structure of MBR_ISA_Admin_Intents:
 *   - One form per group so an in-progress edit can never lose another.
 *   - Add-new form at the bottom.
 *   - Reset to defaults button.
 *   - Test panel showing how a query expands.
 *
 * Storage key: mbr_isa_synonyms (option). Falls back to
 * MBR_ISA_Synonyms defaults whenever the stored array is empty.
 *
 * Group identification is by array index — synonyms have no ID. The
 * form passes the original_index in a hidden field so the save handler
 * knows which slot to update; an empty original_index means "create".
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Admin_Synonyms {

    const PAGE_SLUG     = 'mbr-isa-synonyms';
    const OPTION_KEY    = 'mbr_isa_synonyms';
    const ACTION_SAVE   = 'mbr_isa_save_synonym';
    const ACTION_DELETE = 'mbr_isa_delete_synonym';
    const ACTION_RESET  = 'mbr_isa_reset_synonyms';
    const NOTICE_KEY    = 'mbr_isa_synonym_notice';

    /** @var MBR_ISA_Synonyms */
    private $synonyms_service;

    /** @var MBR_ISA_Tokeniser */
    private $tokeniser;

    public function __construct( MBR_ISA_Synonyms $synonyms_service, MBR_ISA_Tokeniser $tokeniser ) {
        $this->synonyms_service = $synonyms_service;
        $this->tokeniser        = $tokeniser;
    }

    public function register_hooks() {
        add_action( 'admin_menu',                          [ $this, 'register_page' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE,     [ $this, 'handle_save' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE,   [ $this, 'handle_delete' ] );
        add_action( 'admin_post_' . self::ACTION_RESET,    [ $this, 'handle_reset' ] );
    }

    public function register_page() {
        add_management_page(
            __( 'MBR ISA Synonyms', 'mbr-isa' ),
            __( 'MBR ISA Synonyms', 'mbr-isa' ),
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

        $groups  = $this->synonyms_service->get_all_groups();
        $notice  = $this->consume_notice();

        // Test-panel state.
        $test_q       = '';
        $test_input   = [];
        $test_output  = [];
        $stem_to_word = [];
        if ( isset( $_POST['mbr_isa_synonym_test'] ) && check_admin_referer( 'mbr_isa_synonym_test' ) ) {
            $test_q      = sanitize_text_field( wp_unslash( $_POST['mbr_isa_synonym_test'] ) );
            $tokens_raw  = $this->tokeniser->tokenise( $test_q );
            $tokens_uniq = array_values( array_unique( $tokens_raw ) );
            $test_input  = $tokens_uniq;
            $test_output = $this->synonyms_service->expand( $tokens_uniq );

            // Build a stem→original map so the UI can show e.g. "websit (website)".
            // Input words get priority over synonym phrases — the user typed those,
            // so showing their actual word is the friendliest mapping.
            $stem_to_word = array_merge(
                $this->build_synonym_phrase_map(),
                $this->build_input_word_map( $test_q )
            );
        }

        ?>
        <div class="wrap mbr-isa-synonyms-page">
            <h1><?php esc_html_e( 'MBR Intelligent Site Assistant — Synonyms', 'mbr-isa' ); ?></h1>

            <p class="description" style="max-width:780px;">
                <?php esc_html_e( 'Synonym groups make the search index smarter. When a query contains any term in a group, all other terms in that group are added to the search — so "WP" finds "WordPress", and "shop" finds "store". Each group should contain at least two equivalent terms.', 'mbr-isa' ); ?>
            </p>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible" style="margin-top:1em;">
                    <p><?php echo wp_kses_post( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_test_panel( $test_q, $test_input, $test_output, $stem_to_word ); ?>

            <h2 style="margin-top:2em;">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: number of synonym groups */
                    _n( 'Configured group (%d)', 'Configured groups (%d)', count( $groups ), 'mbr-isa' ),
                    count( $groups )
                ) );
                ?>
            </h2>

            <?php if ( empty( $groups ) ) : ?>
                <p><em><?php esc_html_e( 'No synonym groups configured. Add one below, or reset to the shipped defaults.', 'mbr-isa' ); ?></em></p>
            <?php else : ?>
                <?php foreach ( $groups as $i => $group ) : ?>
                    <?php $this->render_group_form( $group, $i, false ); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2 style="margin-top:2.5em;"><?php esc_html_e( 'Add a new synonym group', 'mbr-isa' ); ?></h2>
            <?php $this->render_group_form( [], null, true ); ?>

            <h2 style="margin-top:2.5em;"><?php esc_html_e( 'Reset to defaults', 'mbr-isa' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Replaces all synonym groups with the defaults shipped with the plugin. Your edits will be lost.', 'mbr-isa' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Replace all synonym groups with the shipped defaults? This cannot be undone.', 'mbr-isa' ) ); ?>');">
                <?php wp_nonce_field( self::ACTION_RESET ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESET ); ?>">
                <button type="submit" class="button"><?php esc_html_e( 'Reset to defaults', 'mbr-isa' ); ?></button>
            </form>
        </div>

        <style>
            .mbr-isa-synonyms-page .mbr-isa-synonym-card {
                background:#fff;
                border:1px solid #c3c4c7;
                border-radius:4px;
                padding:1em 1.5em;
                margin:1em 0;
                max-width:900px;
                box-shadow:0 1px 1px rgba(0,0,0,.04);
            }
            .mbr-isa-synonyms-page .mbr-isa-synonym-card h3 {
                margin:0 0 .5em;
                display:flex;
                align-items:center;
                gap:.5em;
                flex-wrap:wrap;
            }
            .mbr-isa-synonyms-page .mbr-isa-synonym-card h3 code {
                font-size:13px;
                font-weight:normal;
                background:#f0f0f1;
                padding:2px 6px;
                border-radius:3px;
            }
            .mbr-isa-synonyms-page .mbr-isa-synonym-card textarea {
                width:100%;
                font-family:Menlo,Consolas,monospace;
                font-size:13px;
            }
            .mbr-isa-synonyms-page .mbr-isa-synonym-actions {
                display:flex;
                gap:.5em;
                align-items:center;
                margin-top:1em;
            }
            .mbr-isa-synonyms-page .mbr-isa-synonym-actions form { display:inline; margin:0; }
            .mbr-isa-synonyms-page .mbr-isa-test-panel {
                background:#f0f6fc;
                border:1px solid #c3d4e6;
                border-radius:4px;
                padding:1em 1.5em;
                max-width:900px;
                margin-top:1em;
            }
            .mbr-isa-synonyms-page .mbr-isa-test-result {
                background:#fff;
                border:1px solid #dcdcde;
                border-radius:4px;
                padding:.75em 1em;
                margin-top:.75em;
            }
            .mbr-isa-synonyms-page .mbr-isa-test-result.is-no-expansion {
                background:#fef8e7;
                border-color:#dba617;
            }
            .mbr-isa-synonyms-page .mbr-isa-token {
                display:inline-block;
                background:#dcdcde;
                color:#1d2327;
                padding:2px 8px;
                margin:2px 4px 2px 0;
                border-radius:10px;
                font-size:12px;
                font-family:Menlo,Consolas,monospace;
            }
            .mbr-isa-synonyms-page .mbr-isa-token.is-added {
                background:#cde7c4;
                color:#0a4006;
            }
            .mbr-isa-synonyms-page .mbr-isa-token-original {
                color:#646970;
                font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
                font-size:11px;
                font-weight:normal;
                margin-left:2px;
            }
            .mbr-isa-synonyms-page .mbr-isa-token.is-added .mbr-isa-token-original {
                color:#3a6c2f;
            }
        </style>
        <?php
    }

    private function render_test_panel( $test_q, array $tokens_in, array $tokens_out, array $stem_to_word = [] ) {
        $added = array_values( array_diff( $tokens_out, $tokens_in ) );
        ?>
        <div class="mbr-isa-test-panel">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Test expansion', 'mbr-isa' ); ?></h2>
            <p class="description" style="margin-top:0;">
                <?php esc_html_e( 'Enter a query and see which extra tokens get added by the synonym groups. Each chip shows the stemmed form with the original word in brackets when it differs — the search index uses the stemmed form, but visitors never see it.', 'mbr-isa' ); ?>
            </p>
            <form method="post" style="margin-top:.5em;">
                <?php wp_nonce_field( 'mbr_isa_synonym_test' ); ?>
                <input type="text" name="mbr_isa_synonym_test" value="<?php echo esc_attr( $test_q ); ?>" placeholder="<?php esc_attr_e( 'e.g. wp ecommerce plugin', 'mbr-isa' ); ?>" style="width:60%;max-width:500px;" autocomplete="off">
                <button type="submit" class="button"><?php esc_html_e( 'Test', 'mbr-isa' ); ?></button>
            </form>

            <?php if ( '' !== $test_q ) : ?>
                <?php if ( empty( $tokens_in ) ) : ?>
                    <div class="mbr-isa-test-result is-no-expansion">
                        <strong><?php esc_html_e( 'No tokens.', 'mbr-isa' ); ?></strong>
                        <span style="color:#666;font-size:13px;">
                            <?php esc_html_e( 'The query was emptied by tokenisation (stopwords or short tokens only).', 'mbr-isa' ); ?>
                        </span>
                    </div>
                <?php else : ?>
                    <div class="mbr-isa-test-result <?php echo empty( $added ) ? 'is-no-expansion' : ''; ?>">
                        <div style="margin-bottom:.5em;">
                            <strong><?php esc_html_e( 'Original tokens:', 'mbr-isa' ); ?></strong>
                            <?php foreach ( $tokens_in as $t ) : ?>
                                <?php $this->render_token_chip( $t, $stem_to_word, false ); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( ! empty( $added ) ) : ?>
                            <div>
                                <strong><?php esc_html_e( 'Added by synonyms:', 'mbr-isa' ); ?></strong>
                                <?php foreach ( $added as $t ) : ?>
                                    <?php $this->render_token_chip( $t, $stem_to_word, true ); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div style="color:#666;font-size:13px;">
                                <?php esc_html_e( 'No synonyms matched these tokens.', 'mbr-isa' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render one token chip, showing the stem and (if different) its original word.
     *
     * @param string $stem
     * @param array  $stem_to_word Mapping of stem => human-readable original word/phrase.
     * @param bool   $is_added     True if this token came from synonym expansion.
     */
    private function render_token_chip( $stem, array $stem_to_word, $is_added ) {
        $stem     = (string) $stem;
        $original = isset( $stem_to_word[ $stem ] ) ? (string) $stem_to_word[ $stem ] : '';
        $class    = 'mbr-isa-token' . ( $is_added ? ' is-added' : '' );

        // Only show the original when it actually differs from the stem,
        // case-insensitively — "post" → "post" doesn't need disambiguation.
        $show_original = ( '' !== $original ) && ( strtolower( $original ) !== strtolower( $stem ) );

        echo '<span class="' . esc_attr( $class ) . '">';
        echo esc_html( $stem );
        if ( $show_original ) {
            echo ' <span class="mbr-isa-token-original">(' . esc_html( $original ) . ')</span>';
        }
        echo '</span>';
    }

    /**
     * Build a stem → original-word map from the user's raw test query.
     *
     * Splits on whitespace and stems each word individually so the original
     * spelling can be displayed alongside the stemmed token.
     *
     * @param string $raw_query
     * @return array<string,string>
     */
    private function build_input_word_map( $raw_query ) {
        $raw_query = trim( (string) $raw_query );
        if ( '' === $raw_query ) {
            return [];
        }

        $words = preg_split( '/\s+/u', $raw_query );
        $map   = [];
        foreach ( $words as $word ) {
            $word = trim( (string) $word );
            if ( '' === $word ) {
                continue;
            }
            $stems = $this->tokeniser->tokenise( $word );
            foreach ( $stems as $stem ) {
                if ( ! isset( $map[ $stem ] ) ) {
                    $map[ $stem ] = $word;
                }
            }
        }
        return $map;
    }

    /**
     * Build a stem → phrase map from all configured synonym groups.
     *
     * Used to label tokens added by synonym expansion with the original
     * (unstemmed) phrase from the synonym group, so admins see e.g.
     * "websit (website)" instead of just "websit".
     *
     * @return array<string,string>
     */
    private function build_synonym_phrase_map() {
        $groups = $this->synonyms_service->get_all_groups();
        $map    = [];
        if ( ! is_array( $groups ) ) {
            return $map;
        }
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }
            foreach ( $group as $phrase ) {
                $phrase = trim( (string) $phrase );
                if ( '' === $phrase ) {
                    continue;
                }
                $stems = $this->tokeniser->tokenise( $phrase );
                foreach ( $stems as $stem ) {
                    // First-write-wins: keeps the most natural label
                    // when multiple phrases share a stem.
                    if ( ! isset( $map[ $stem ] ) ) {
                        $map[ $stem ] = $phrase;
                    }
                }
            }
        }
        return $map;
    }

    private function render_group_form( $group, $index, $is_new ) {
        $terms = is_array( $group ) ? array_values( array_filter( array_map( 'strval', $group ), 'strlen' ) ) : [];

        // Field IDs need a unique suffix so multiple cards don't collide.
        $suffix      = $is_new ? 'new' : ( 'g' . (int) $index );
        $preview_str = ! empty( $terms ) ? implode( ' • ', array_slice( $terms, 0, 5 ) ) : '';

        ?>
        <div class="mbr-isa-synonym-card">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::ACTION_SAVE ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
                <input type="hidden" name="original_index" value="<?php echo esc_attr( $is_new ? '' : (string) (int) $index ); ?>">

                <h3>
                    <?php if ( $is_new ) : ?>
                        <?php esc_html_e( 'New synonym group', 'mbr-isa' ); ?>
                    <?php else : ?>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d: 1-based index of the group */
                            __( 'Group #%d', 'mbr-isa' ),
                            ( (int) $index ) + 1
                        ) );
                        ?>
                        <?php if ( '' !== $preview_str ) : ?>
                            <code><?php echo esc_html( $preview_str ); ?><?php echo count( $terms ) > 5 ? '…' : ''; ?></code>
                        <?php endif; ?>
                    <?php endif; ?>
                </h3>

                <div style="margin-bottom:.5em;">
                    <label class="mbr-isa-field-label" for="mbr-isa-terms-<?php echo esc_attr( $suffix ); ?>" style="display:block;font-weight:600;margin-bottom:4px;">
                        <?php esc_html_e( 'Terms', 'mbr-isa' ); ?>
                    </label>
                    <textarea
                        id="mbr-isa-terms-<?php echo esc_attr( $suffix ); ?>"
                        name="terms"
                        rows="<?php echo esc_attr( max( 4, count( $terms ) + 1 ) ); ?>"
                        placeholder="wp&#10;wordpress"
                    ><?php echo esc_textarea( implode( "\n", $terms ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One term per line. Multi-word phrases are fine ("search engine optimisation"). At least two unique terms are required for the group to do anything.', 'mbr-isa' ); ?>
                    </p>
                </div>

                <div class="mbr-isa-synonym-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_new ? esc_html__( 'Add group', 'mbr-isa' ) : esc_html__( 'Save changes', 'mbr-isa' ); ?>
                    </button>
                </div>
            </form>

            <?php if ( ! $is_new ) : ?>
                <div style="margin-top:.5em;">
                    <form method="post"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('<?php echo esc_js( __( 'Delete this synonym group? This cannot be undone.', 'mbr-isa' ) ); ?>');">
                        <?php wp_nonce_field( self::ACTION_DELETE . '_' . (int) $index ); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
                        <input type="hidden" name="index" value="<?php echo esc_attr( (int) $index ); ?>">
                        <button type="submit" class="button button-link-delete" style="color:#b32d2e;">
                            <?php esc_html_e( 'Delete group', 'mbr-isa' ); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save handler
    // -------------------------------------------------------------------------

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( self::ACTION_SAVE );

        $original_index_raw = isset( $_POST['original_index'] ) ? (string) wp_unslash( $_POST['original_index'] ) : '';
        $is_new             = ( '' === $original_index_raw );
        $original_index     = $is_new ? null : (int) $original_index_raw;

        $terms_raw = isset( $_POST['terms'] ) ? (string) wp_unslash( $_POST['terms'] ) : '';
        $terms     = $this->parse_terms( $terms_raw );

        if ( count( $terms ) < 2 ) {
            $this->set_notice(
                'error',
                __( 'A synonym group needs at least two unique terms to be useful.', 'mbr-isa' )
            );
            $this->redirect_back();
        }

        // Load current groups from the option (NOT from the service —
        // the service falls back to defaults when empty, which would
        // re-introduce the defaults on every save).
        $groups = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $groups ) ) {
            $groups = [];
        }

        // If empty (first edit), seed with the defaults so we don't lose them.
        if ( empty( $groups ) ) {
            $groups = $this->synonyms_service->get_default_groups();
        }

        if ( $is_new ) {
            $groups[] = $terms;
            $this->set_notice( 'success', __( 'Synonym group added.', 'mbr-isa' ) );
        } else {
            if ( $original_index < 0 || ! isset( $groups[ $original_index ] ) ) {
                // The row no longer exists — append as new.
                $groups[] = $terms;
                $this->set_notice( 'warning', __( 'The original group was no longer available; added as a new group.', 'mbr-isa' ) );
            } else {
                $groups[ $original_index ] = $terms;
                $this->set_notice( 'success', __( 'Synonym group saved.', 'mbr-isa' ) );
            }
        }

        update_option( self::OPTION_KEY, array_values( $groups ) );
        $this->redirect_back();
    }

    // -------------------------------------------------------------------------
    // Delete handler
    // -------------------------------------------------------------------------

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }

        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        check_admin_referer( self::ACTION_DELETE . '_' . $index );

        if ( $index < 0 ) {
            $this->set_notice( 'error', __( 'No group index supplied.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        $groups = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $groups ) ) {
            $groups = [];
        }

        // If the option is empty, the user is "deleting" a default —
        // seed with defaults first so the deletion takes effect.
        if ( empty( $groups ) ) {
            $groups = $this->synonyms_service->get_default_groups();
        }

        if ( ! isset( $groups[ $index ] ) ) {
            $this->set_notice( 'warning', __( 'No matching group was found to delete.', 'mbr-isa' ) );
            $this->redirect_back();
        }

        unset( $groups[ $index ] );
        $groups = array_values( $groups );

        update_option( self::OPTION_KEY, $groups );
        $this->set_notice( 'success', __( 'Synonym group deleted.', 'mbr-isa' ) );
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

        update_option( self::OPTION_KEY, $this->synonyms_service->get_default_groups() );

        $this->set_notice( 'success', __( 'Synonym groups reset to the shipped defaults.', 'mbr-isa' ) );
        $this->redirect_back();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Split textarea content into clean term lines.
     *
     * Accepts both newline-separated and comma-separated input (so paste
     * from a thesaurus works), lowercases, trims, dedupes, and drops empties.
     *
     * @param string $raw
     * @return string[]
     */
    private function parse_terms( $raw ) {
        $raw = (string) $raw;
        // Allow either newlines OR commas as separators.
        $parts = preg_split( '/[\r\n,]+/u', $raw );
        $out   = [];
        foreach ( $parts as $part ) {
            $part = (string) $part;
            // Strip control chars, then sanitize.
            $part = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $part );
            $part = sanitize_text_field( $part );
            $part = trim( (string) $part );
            if ( '' === $part ) {
                continue;
            }
            // Lowercase for stable comparison (the tokeniser will lowercase
            // again anyway, but keeping it consistent in storage helps the
            // admin UI render a clean diff).
            $part = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
            $out[] = $part;
        }
        return array_values( array_unique( $out ) );
    }

    private function redirect_back() {
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
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
