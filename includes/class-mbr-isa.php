<?php
/**
 * Main plugin class — singleton orchestrator.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once MBR_ISA_DIR . 'includes/class-mbr-isa-tokeniser.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-bm25.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-indexer.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-synonyms.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-intents.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-responder.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-query-handler.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-rate-limiter.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-rest.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-frontend.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-admin-intents.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-admin-synonyms.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-admin-theme.php';

class MBR_ISA {

    private static $instance = null;

    private $tokeniser     = null;
    private $bm25          = null;
    private $indexer       = null;
    private $synonyms      = null;
    private $intents       = null;
    private $responder     = null;
    private $query_handler = null;
    private $rate_limiter  = null;
    private $rest          = null;
    private $frontend      = null;
    private $admin_intents = null;
    private $admin_synonyms = null;
    private $admin_theme = null;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException( 'Cannot unserialize singleton.' ); }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        $this->load_text_domain();
        $this->check_db_upgrades();
        $this->register_hooks();
    }

    private function load_text_domain() {
        load_plugin_textdomain( 'mbr-isa', false, dirname( MBR_ISA_BASENAME ) . '/languages' );
    }

    private function check_db_upgrades() {
        $installed_db_version = get_option( 'mbr_isa_db_version', '0' );
        if ( version_compare( $installed_db_version, MBR_ISA_DB_VERSION, '<' ) ) {
            MBR_ISA_Activator::run_schema_upgrade( $installed_db_version );
            update_option( 'mbr_isa_db_version', MBR_ISA_DB_VERSION );
        }
    }

    private function register_hooks() {
        $this->indexer()->register_hooks();
        $this->frontend()->register_hooks();
        $this->admin_intents()->register_hooks();
        $this->admin_synonyms()->register_hooks();
        $this->admin_theme()->register_hooks();

        add_action( 'rest_api_init', [ $this->rest(), 'register_routes' ] );
        add_action( 'admin_menu',    [ $this, 'register_diagnostic_page' ] );
        add_action( 'admin_post_mbr_isa_full_reindex',         [ $this, 'handle_full_reindex' ] );
        add_action( 'admin_post_mbr_isa_save_widget_settings', [ $this, 'handle_save_widget_settings' ] );
    }

    // --- Service accessors ---------------------------------------------------

    public function tokeniser() {
        if ( null === $this->tokeniser ) {
            $this->tokeniser = new MBR_ISA_Tokeniser();
        }
        return $this->tokeniser;
    }

    public function bm25() {
        if ( null === $this->bm25 ) {
            $settings = get_option( 'mbr_isa_settings', [] );
            $k1 = (float) ( $settings['bm25_k1'] ?? 1.2 );
            $b  = (float) ( $settings['bm25_b']  ?? 0.75 );
            $this->bm25 = new MBR_ISA_BM25( $k1, $b );
        }
        return $this->bm25;
    }

    public function indexer() {
        if ( null === $this->indexer ) {
            $this->indexer = new MBR_ISA_Indexer( $this->tokeniser(), $this->bm25() );
        }
        return $this->indexer;
    }

    public function synonyms() {
        if ( null === $this->synonyms ) {
            $this->synonyms = new MBR_ISA_Synonyms( $this->tokeniser() );
        }
        return $this->synonyms;
    }

    public function intents() {
        if ( null === $this->intents ) {
            $this->intents = new MBR_ISA_Intents();
        }
        return $this->intents;
    }

    public function responder() {
        if ( null === $this->responder ) {
            $this->responder = new MBR_ISA_Responder();
        }
        return $this->responder;
    }

    public function query_handler() {
        if ( null === $this->query_handler ) {
            $this->query_handler = new MBR_ISA_Query_Handler(
                $this->tokeniser(),
                $this->indexer(),
                $this->synonyms(),
                $this->intents(),
                $this->responder()
            );
        }
        return $this->query_handler;
    }

    public function rate_limiter() {
        if ( null === $this->rate_limiter ) {
            $this->rate_limiter = new MBR_ISA_Rate_Limiter();
        }
        return $this->rate_limiter;
    }

    public function rest() {
        if ( null === $this->rest ) {
            $this->rest = new MBR_ISA_REST( $this->query_handler(), $this->rate_limiter() );
        }
        return $this->rest;
    }

    public function frontend() {
        if ( null === $this->frontend ) {
            $this->frontend = new MBR_ISA_Frontend();
        }
        return $this->frontend;
    }

    public function admin_intents() {
        if ( null === $this->admin_intents ) {
            $this->admin_intents = new MBR_ISA_Admin_Intents( $this->intents() );
        }
        return $this->admin_intents;
    }

    public function admin_synonyms() {
        if ( null === $this->admin_synonyms ) {
            $this->admin_synonyms = new MBR_ISA_Admin_Synonyms( $this->synonyms(), $this->tokeniser() );
        }
        return $this->admin_synonyms;
    }

    public function admin_theme() {
        if ( null === $this->admin_theme ) {
            $this->admin_theme = new MBR_ISA_Admin_Theme();
        }
        return $this->admin_theme;
    }

    // --- Admin-post handlers -------------------------------------------------

    public function handle_full_reindex() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( 'mbr_isa_full_reindex' );

        $stats = $this->indexer()->full_reindex();
        $this->indexer()->set_last_full_index_now();

        set_transient( 'mbr_isa_reindex_result', $stats, 60 );

        wp_safe_redirect( admin_url( 'tools.php?page=mbr-isa-diagnostic&reindexed=1' ) );
        exit;
    }

    public function handle_save_widget_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( 'mbr_isa_save_widget_settings' );

        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        // Enabled toggle.
        $settings['widget_enabled'] = ! empty( $_POST['widget_enabled'] ) ? 1 : 0;

        // Position — whitelist.
        $position = isset( $_POST['widget_position'] ) ? (string) $_POST['widget_position'] : 'bottom-right';
        if ( ! in_array( $position, [ 'bottom-right', 'bottom-left' ], true ) ) {
            $position = 'bottom-right';
        }
        $settings['widget_position'] = $position;

        // Free-text fields — sanitise and length-cap.
        $title       = isset( $_POST['widget_title'] )       ? sanitize_text_field( wp_unslash( $_POST['widget_title'] ) )       : '';
        $greeting    = isset( $_POST['widget_greeting'] )    ? sanitize_text_field( wp_unslash( $_POST['widget_greeting'] ) )    : '';
        $placeholder = isset( $_POST['widget_placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_placeholder'] ) ) : '';

        $settings['widget_title']       = mb_substr( $title, 0, 80 );
        $settings['widget_greeting']    = mb_substr( $greeting, 0, 300 );
        $settings['widget_placeholder'] = mb_substr( $placeholder, 0, 80 );

        update_option( 'mbr_isa_settings', $settings );

        wp_safe_redirect( admin_url( 'tools.php?page=mbr-isa-diagnostic&widget-saved=1#mbr-isa-widget-settings' ) );
        exit;
    }

    // --- Diagnostic page -----------------------------------------------------

    public function register_diagnostic_page() {
        add_management_page(
            __( 'MBR ISA Diagnostic', 'mbr-isa' ),
            __( 'MBR ISA Diagnostic', 'mbr-isa' ),
            'manage_options',
            'mbr-isa-diagnostic',
            [ $this, 'render_diagnostic_page' ]
        );
    }

    public function render_diagnostic_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;

        // Table existence check.
        $expected_tables = [
            $wpdb->prefix . 'mbrisa_terms',
            $wpdb->prefix . 'mbrisa_documents',
            $wpdb->prefix . 'mbrisa_postings',
            $wpdb->prefix . 'mbrisa_queries',
        ];
        $table_statuses = [];
        foreach ( $expected_tables as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
            $table_statuses[ $table ] = $exists;
        }

        // Tokeniser test.
        $tok_input  = '';
        $tok_output = null;
        if ( isset( $_POST['mbr_isa_test_input'] ) && check_admin_referer( 'mbr_isa_tokenise_test' ) ) {
            $tok_input  = sanitize_textarea_field( wp_unslash( $_POST['mbr_isa_test_input'] ) );
            $tok_output = $this->tokeniser()->tokenise_with_trace( $tok_input );
        }

        // Search test.
        $search_input  = '';
        $search_output = null;
        if ( isset( $_POST['mbr_isa_search_input'] ) && check_admin_referer( 'mbr_isa_search_test' ) ) {
            $search_input  = sanitize_text_field( wp_unslash( $_POST['mbr_isa_search_input'] ) );
            $search_output = $this->indexer()->search( $search_input, 10 );
        }

        // Chat test (new — runs the full query handler pipeline).
        $chat_input  = '';
        $chat_output = null;
        if ( isset( $_POST['mbr_isa_chat_input'] ) && check_admin_referer( 'mbr_isa_chat_test' ) ) {
            $chat_input  = sanitize_text_field( wp_unslash( $_POST['mbr_isa_chat_input'] ) );
            $chat_output = $this->query_handler()->handle( $chat_input, 'admin-diagnostic' );
        }

        $index_status = get_option( 'mbr_isa_index_status', [] );
        $reindex_msg  = get_transient( 'mbr_isa_reindex_result' );
        if ( $reindex_msg ) {
            delete_transient( 'mbr_isa_reindex_result' );
        }

        $rest_url = rest_url( MBR_ISA_REST::NAMESPACE_V1 . '/ask' );

        // --- Feedback stats & recent queries ----------------------------
        $queries_table = $wpdb->prefix . 'mbrisa_queries';
        $feedback_stats = $wpdb->get_row(
            "SELECT
                COUNT(*)                                           AS total,
                SUM(CASE WHEN feedback = 1  THEN 1 ELSE 0 END)     AS thumbs_up,
                SUM(CASE WHEN feedback = -1 THEN 1 ELSE 0 END)     AS thumbs_down,
                SUM(CASE WHEN feedback IS NULL THEN 1 ELSE 0 END)  AS no_feedback,
                SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END)  AS zero_results,
                SUM(CASE WHEN intent_matched IS NOT NULL THEN 1 ELSE 0 END) AS intent_hits
             FROM {$queries_table}
             WHERE created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
            ARRAY_A
        );
        if ( ! is_array( $feedback_stats ) ) {
            $feedback_stats = [
                'total' => 0, 'thumbs_up' => 0, 'thumbs_down' => 0,
                'no_feedback' => 0, 'zero_results' => 0, 'intent_hits' => 0,
            ];
        }

        $recent_queries = $wpdb->get_results(
            "SELECT id, query_text, intent_matched, result_count, top_score, feedback, created_at
             FROM {$queries_table}
             ORDER BY created_at DESC
             LIMIT 30",
            ARRAY_A
        );
        if ( ! is_array( $recent_queries ) ) {
            $recent_queries = [];
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MBR Intelligent Site Assistant — Diagnostic', 'mbr-isa' ); ?></h1>
            <p><?php esc_html_e( 'Development diagnostic view. Replaced by the real admin UI in a later session.', 'mbr-isa' ); ?></p>

            <p style="margin:0 0 1em;">
                <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . MBR_ISA_Admin_Intents::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Manage intents →', 'mbr-isa' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . MBR_ISA_Admin_Synonyms::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Manage synonyms →', 'mbr-isa' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . MBR_ISA_Admin_Theme::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Appearance →', 'mbr-isa' ); ?>
                </a>
            </p>

            <h2><?php esc_html_e( 'Plugin Status', 'mbr-isa' ); ?></h2>
            <table class="widefat striped" style="max-width:700px;">
                <tbody>
                    <tr><th><?php esc_html_e( 'Plugin Version', 'mbr-isa' ); ?></th><td><code><?php echo esc_html( MBR_ISA_VERSION ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'DB Version', 'mbr-isa' ); ?></th><td><code><?php echo esc_html( get_option( 'mbr_isa_db_version', '0' ) ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'PHP Version', 'mbr-isa' ); ?></th><td><code><?php echo esc_html( PHP_VERSION ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'REST endpoint', 'mbr-isa' ); ?></th><td><code><?php echo esc_html( $rest_url ); ?></code></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Database Tables', 'mbr-isa' ); ?></h2>
            <table class="widefat striped" style="max-width:700px;">
                <thead><tr><th><?php esc_html_e( 'Table', 'mbr-isa' ); ?></th><th><?php esc_html_e( 'Status', 'mbr-isa' ); ?></th></tr></thead>
                <tbody>
                    <?php foreach ( $table_statuses as $table => $exists ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $table ); ?></code></td>
                            <td>
                                <?php if ( $exists ) : ?>
                                    <span style="color:#2e7d32;font-weight:bold;">✓ Exists</span>
                                <?php else : ?>
                                    <span style="color:#c62828;font-weight:bold;">✗ Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Index Status', 'mbr-isa' ); ?></h2>
            <table class="widefat striped" style="max-width:700px;">
                <tbody>
                    <tr><th><?php esc_html_e( 'Documents indexed', 'mbr-isa' ); ?></th><td><?php echo (int) ( $index_status['documents'] ?? 0 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Unique terms in dictionary', 'mbr-isa' ); ?></th><td><?php echo (int) ( $index_status['terms'] ?? 0 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Total postings', 'mbr-isa' ); ?></th><td><?php echo (int) ( $index_status['postings'] ?? 0 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Last full reindex', 'mbr-isa' ); ?></th><td><?php echo esc_html( $index_status['last_full_index'] ?? 'Never' ); ?></td></tr>
                </tbody>
            </table>

            <?php if ( $reindex_msg ) : ?>
                <div class="notice notice-success" style="margin-top:1em;"><p>
                    <?php echo esc_html( sprintf(
                        __( 'Reindex complete: %1$d documents in %2$s seconds.', 'mbr-isa' ),
                        (int) ( $reindex_msg['documents'] ?? 0 ),
                        (string) ( $reindex_msg['duration'] ?? '?' )
                    ) ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;" id="mbr-isa-reindex-form">
                <?php wp_nonce_field( 'mbr_isa_full_reindex' ); ?>
                <input type="hidden" name="action" value="mbr_isa_full_reindex">
                <button type="submit" class="button button-primary" id="mbr-isa-reindex-button">
                    <span class="mbr-isa-btn-label"><?php esc_html_e( 'Run Full Reindex', 'mbr-isa' ); ?></span>
                    <span class="mbr-isa-btn-spinner" aria-hidden="true"></span>
                </button>
                <span style="color:#666;margin-left:1em;" id="mbr-isa-reindex-status">
                    <?php esc_html_e( 'Indexes all published posts and pages.', 'mbr-isa' ); ?>
                </span>
            </form>

            <?php
            // --- Widget settings section --------------------------------
            $current_settings = get_option( 'mbr_isa_settings', [] );
            $w_enabled        = ! empty( $current_settings['widget_enabled'] );
            $w_position       = isset( $current_settings['widget_position'] )    ? (string) $current_settings['widget_position']    : 'bottom-right';
            $w_title          = isset( $current_settings['widget_title'] )       ? (string) $current_settings['widget_title']       : '';
            $w_greeting       = isset( $current_settings['widget_greeting'] )    ? (string) $current_settings['widget_greeting']    : '';
            $w_placeholder    = isset( $current_settings['widget_placeholder'] ) ? (string) $current_settings['widget_placeholder'] : '';
            $widget_saved     = isset( $_GET['widget-saved'] ) && '1' === $_GET['widget-saved'];
            ?>

            <h2 id="mbr-isa-widget-settings" style="margin-top:2em;"><?php esc_html_e( 'Widget Settings', 'mbr-isa' ); ?></h2>
            <p><?php esc_html_e( 'Configure the public-facing chat widget. When enabled, the floating bubble appears on every page of the site. You can also embed the widget inline with the shortcode below.', 'mbr-isa' ); ?></p>

            <?php if ( $widget_saved ) : ?>
                <div class="notice notice-success" style="margin-top:1em;"><p>
                    <?php esc_html_e( 'Widget settings saved.', 'mbr-isa' ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
                <?php wp_nonce_field( 'mbr_isa_save_widget_settings' ); ?>
                <input type="hidden" name="action" value="mbr_isa_save_widget_settings">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Floating widget', 'mbr-isa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="widget_enabled" value="1" <?php checked( $w_enabled ); ?>>
                                    <?php esc_html_e( 'Show the floating chat bubble on the site.', 'mbr-isa' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When off, the widget only appears where the shortcode is explicitly used.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-widget-position"><?php esc_html_e( 'Position', 'mbr-isa' ); ?></label></th>
                            <td>
                                <select id="mbr-isa-widget-position" name="widget_position">
                                    <option value="bottom-right" <?php selected( $w_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'mbr-isa' ); ?></option>
                                    <option value="bottom-left"  <?php selected( $w_position, 'bottom-left'  ); ?>><?php esc_html_e( 'Bottom left',  'mbr-isa' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-widget-title"><?php esc_html_e( 'Widget title', 'mbr-isa' ); ?></label></th>
                            <td>
                                <input type="text" id="mbr-isa-widget-title" name="widget_title" value="<?php echo esc_attr( $w_title ); ?>" class="regular-text" maxlength="80" placeholder="<?php esc_attr_e( 'Site Assistant', 'mbr-isa' ); ?>">
                                <p class="description"><?php esc_html_e( 'Shown in the panel header. Leave blank for default.', 'mbr-isa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-widget-greeting"><?php esc_html_e( 'Greeting message', 'mbr-isa' ); ?></label></th>
                            <td>
                                <textarea id="mbr-isa-widget-greeting" name="widget_greeting" rows="2" class="large-text" maxlength="300" placeholder="<?php esc_attr_e( 'Hi! I can help you find things on this site. What are you looking for?', 'mbr-isa' ); ?>"><?php echo esc_textarea( $w_greeting ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'First message the assistant shows. Leave blank for default.', 'mbr-isa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-widget-placeholder"><?php esc_html_e( 'Input placeholder', 'mbr-isa' ); ?></label></th>
                            <td>
                                <input type="text" id="mbr-isa-widget-placeholder" name="widget_placeholder" value="<?php echo esc_attr( $w_placeholder ); ?>" class="regular-text" maxlength="80" placeholder="<?php esc_attr_e( 'Ask a question…', 'mbr-isa' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Shortcode', 'mbr-isa' ); ?></th>
                            <td>
                                <code>[mbr_isa_chat]</code>
                                <p class="description">
                                    <?php esc_html_e( 'Paste into any page, post, or widget to embed the chat inline. Supports attributes: title, greeting, placeholder, height. Example:', 'mbr-isa' ); ?>
                                    <br>
                                    <code>[mbr_isa_chat title="Ask us anything" height="600px"]</code>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Widget Settings', 'mbr-isa' ); ?></button></p>
            </form>

            <style>
                .mbr-isa-btn-spinner {
                    display: none;
                    width: 14px; height: 14px;
                    margin-left: 8px;
                    border: 2px solid rgba(255,255,255,0.35);
                    border-top-color: #fff;
                    border-radius: 50%;
                    vertical-align: middle;
                    animation: mbr-isa-spin 0.8s linear infinite;
                }
                #mbr-isa-reindex-button.is-loading .mbr-isa-btn-spinner { display: inline-block; }
                #mbr-isa-reindex-button.is-loading { opacity: 0.75; cursor: wait; }
                @keyframes mbr-isa-spin { to { transform: rotate(360deg); } }
                #mbr-isa-reindex-status.is-working { color: #cc5500; font-weight: 600; }

                /* Chat bubble styling for the diagnostic chat tester */
                .mbr-isa-chat-turn { margin: 0 0 1em; max-width: 800px; }
                .mbr-isa-chat-user {
                    background: #1e88e5; color: #fff;
                    padding: 10px 14px; border-radius: 14px 14px 2px 14px;
                    display: inline-block; max-width: 80%;
                }
                .mbr-isa-chat-bot {
                    background: #2b2b38; color: #cdd6f4;
                    padding: 10px 14px; border-radius: 14px 14px 14px 2px;
                    display: inline-block; max-width: 90%;
                }
                .mbr-isa-chat-meta { color:#999; font-size:11px; margin-top:4px; }
                .mbr-isa-chat-results { margin-top:8px; padding-left:0; list-style:none; }
                .mbr-isa-chat-results li {
                    background: #1e1e2e; color: #cdd6f4;
                    padding: 8px 12px; border-radius: 8px; margin-bottom: 6px;
                }
                .mbr-isa-chat-results a { color:#89b4fa; text-decoration: none; font-weight: 600; }
                .mbr-isa-chat-results mark { background:#f9e2af; color:#1e1e2e; padding:0 2px; border-radius:2px; }
                .mbr-isa-badge {
                    display: inline-block; padding: 2px 8px; border-radius: 10px;
                    font-size: 11px; font-weight: 600; text-transform: uppercase;
                }
                .mbr-isa-badge-high   { background:#2e7d32; color:#fff; }
                .mbr-isa-badge-medium { background:#f9a825; color:#000; }
                .mbr-isa-badge-low    { background:#ef6c00; color:#fff; }
                .mbr-isa-badge-none   { background:#c62828; color:#fff; }
                .mbr-isa-badge-intent { background:#6a1b9a; color:#fff; }
            </style>

            <script>
                (function () {
                    var form   = document.getElementById('mbr-isa-reindex-form');
                    var button = document.getElementById('mbr-isa-reindex-button');
                    var status = document.getElementById('mbr-isa-reindex-status');
                    if (!form || !button || !status) return;
                    form.addEventListener('submit', function () {
                        button.classList.add('is-loading');
                        button.disabled = true;
                        status.classList.add('is-working');
                        status.textContent = '<?php echo esc_js( __( "Reindexing… this page will refresh when complete.", "mbr-isa" ) ); ?>';
                    });
                })();
            </script>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Feedback & Queries (last 7 days)', 'mbr-isa' ); ?></h2>
            <table class="widefat striped" style="max-width:700px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Total queries', 'mbr-isa' ); ?></th>
                        <td><strong><?php echo (int) $feedback_stats['total']; ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Thumbs up', 'mbr-isa' ); ?></th>
                        <td><span style="color:#2e7d32;font-weight:600;">👍 <?php echo (int) $feedback_stats['thumbs_up']; ?></span></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Thumbs down', 'mbr-isa' ); ?></th>
                        <td><span style="color:#c62828;font-weight:600;">👎 <?php echo (int) $feedback_stats['thumbs_down']; ?></span></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'No feedback given', 'mbr-isa' ); ?></th>
                        <td><?php echo (int) $feedback_stats['no_feedback']; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Zero-result queries', 'mbr-isa' ); ?></th>
                        <td>
                            <?php
                            $zero = (int) $feedback_stats['zero_results'];
                            $total = max( 1, (int) $feedback_stats['total'] );
                            $pct  = round( ( $zero / $total ) * 100, 1 );
                            echo (int) $zero;
                            if ( $zero > 0 ) {
                                echo ' <span style="color:#888;">(' . esc_html( $pct ) . '%)</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Intent hits', 'mbr-isa' ); ?></th>
                        <td><?php echo (int) $feedback_stats['intent_hits']; ?></td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:1.5em;"><?php esc_html_e( 'Recent queries', 'mbr-isa' ); ?></h3>
            <?php if ( empty( $recent_queries ) ) : ?>
                <p><em><?php esc_html_e( 'No queries logged yet. Make sure "Log queries" is enabled in settings.', 'mbr-isa' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1000px;">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php esc_html_e( 'When', 'mbr-isa' ); ?></th>
                            <th><?php esc_html_e( 'Query', 'mbr-isa' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( 'Intent', 'mbr-isa' ); ?></th>
                            <th style="width:70px;text-align:right;"><?php esc_html_e( 'Results', 'mbr-isa' ); ?></th>
                            <th style="width:70px;text-align:right;"><?php esc_html_e( 'Top score', 'mbr-isa' ); ?></th>
                            <th style="width:90px;text-align:center;"><?php esc_html_e( 'Feedback', 'mbr-isa' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent_queries as $row ) :
                            $fb = $row['feedback'];
                            $fb_icon = '<span style="color:#999;">—</span>';
                            if ( '1' === (string) $fb ) {
                                $fb_icon = '<span style="color:#2e7d32;font-weight:600;" title="Thumbs up">👍</span>';
                            } elseif ( '-1' === (string) $fb ) {
                                $fb_icon = '<span style="color:#c62828;font-weight:600;" title="Thumbs down">👎</span>';
                            } elseif ( '0' === (string) $fb ) {
                                $fb_icon = '<span style="color:#888;" title="Neutral">•</span>';
                            }
                            $results_count = (int) $row['result_count'];
                            $row_style = '';
                            if ( 0 === $results_count ) {
                                $row_style = 'background:rgba(198,40,40,0.06);';
                            } elseif ( '-1' === (string) $fb ) {
                                $row_style = 'background:rgba(198,40,40,0.1);';
                            }
                        ?>
                            <tr style="<?php echo esc_attr( $row_style ); ?>">
                                <td style="color:#666;font-size:12px;">
                                    <?php echo esc_html( mysql2date( 'M j, H:i', $row['created_at'] ) ); ?>
                                </td>
                                <td><code style="background:transparent;padding:0;"><?php echo esc_html( $row['query_text'] ); ?></code></td>
                                <td>
                                    <?php if ( ! empty( $row['intent_matched'] ) ) : ?>
                                        <code style="background:#6a1b9a;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;"><?php echo esc_html( $row['intent_matched'] ); ?></code>
                                    <?php else : ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ( 0 === $results_count ) : ?>
                                        <strong style="color:#c62828;">0</strong>
                                    <?php else : ?>
                                        <?php echo (int) $results_count; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;color:#666;font-family:monospace;font-size:12px;">
                                    <?php echo null === $row['top_score'] ? '—' : esc_html( round( (float) $row['top_score'], 2 ) ); ?>
                                </td>
                                <td style="text-align:center;"><?php echo $fb_icon; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#888;font-size:12px;margin-top:0.5em;">
                    <?php esc_html_e( 'Showing the 30 most recent queries. Rows shaded red had zero results or a thumbs-down — those are the ones to look at first (add an intent or synonym).', 'mbr-isa' ); ?>
                </p>
            <?php endif; ?>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Tokeniser Tester', 'mbr-isa' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'mbr_isa_tokenise_test' ); ?>
                <textarea name="mbr_isa_test_input" rows="3" cols="80" style="font-family:monospace;"><?php echo esc_textarea( $tok_input ); ?></textarea>
                <p><button type="submit" class="button"><?php esc_html_e( 'Tokenise', 'mbr-isa' ); ?></button></p>
            </form>
            <?php if ( is_array( $tok_output ) ) : ?>
                <table class="widefat striped" style="max-width:900px;">
                    <tbody>
                        <tr><th style="width:200px;">Original</th><td><code><?php echo esc_html( $tok_output['original'] ); ?></code></td></tr>
                        <tr><th>Cleaned</th><td><code><?php echo esc_html( $tok_output['cleaned'] ); ?></code></td></tr>
                        <tr><th>Split</th><td><code><?php echo esc_html( implode( ' | ', $tok_output['split'] ) ); ?></code></td></tr>
                        <tr><th>After Stopwords</th><td><code><?php echo esc_html( implode( ' | ', $tok_output['after_stopwords'] ) ); ?></code></td></tr>
                        <tr><th>After Stemming</th><td><code><?php echo esc_html( implode( ' | ', $tok_output['after_stemming'] ) ); ?></code></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Search Tester (raw BM25)', 'mbr-isa' ); ?></h2>
            <p><?php esc_html_e( 'Bypasses intents and synonyms. Useful for debugging ranking.', 'mbr-isa' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'mbr_isa_search_test' ); ?>
                <input type="text" name="mbr_isa_search_input" value="<?php echo esc_attr( $search_input ); ?>" style="width:60%;">
                <button type="submit" class="button"><?php esc_html_e( 'Search', 'mbr-isa' ); ?></button>
            </form>

            <?php if ( is_array( $search_output ) ) : ?>
                <?php if ( ! empty( $search_output['trace'] ) ) : ?>
                    <h3 style="margin-top:1em;"><?php esc_html_e( 'Query trace', 'mbr-isa' ); ?></h3>
                    <pre style="background:#1e1e2e;color:#cdd6f4;padding:1em;border-radius:6px;overflow:auto;font-size:12px;"><?php
                        echo esc_html( wp_json_encode( $search_output['trace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                    ?></pre>
                <?php endif; ?>

                <h3><?php esc_html_e( 'Results', 'mbr-isa' ); ?></h3>
                <?php if ( empty( $search_output['results'] ) ) : ?>
                    <p><em><?php esc_html_e( 'No results.', 'mbr-isa' ); ?></em></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width:1000px;">
                        <thead>
                            <tr><th>#</th><th>Score</th><th>Title</th><th>Type</th><th>Excerpt</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $search_output['results'] as $i => $r ) : ?>
                                <tr>
                                    <td><?php echo (int) $i + 1; ?></td>
                                    <td><code><?php echo esc_html( $r['score'] ); ?></code></td>
                                    <td><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank"><?php echo esc_html( $r['title'] ); ?></a></td>
                                    <td><?php echo esc_html( $r['post_type'] ); ?></td>
                                    <td><?php echo esc_html( mb_substr( $r['excerpt'], 0, 150 ) ); ?>…</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <h2 style="margin-top:2em;"><?php esc_html_e( 'Chat Tester (full pipeline)', 'mbr-isa' ); ?></h2>
            <p><?php esc_html_e( 'Runs intents → tokenise → synonym expansion → BM25 → responder. This is what the public widget will do.', 'mbr-isa' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'mbr_isa_chat_test' ); ?>
                <input type="text" name="mbr_isa_chat_input" placeholder="e.g. how do I contact you" value="<?php echo esc_attr( $chat_input ); ?>" style="width:60%;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Ask', 'mbr-isa' ); ?></button>
            </form>

            <?php if ( is_array( $chat_output ) ) : ?>
                <div style="margin-top:1.5em;">
                    <div class="mbr-isa-chat-turn" style="text-align:right;">
                        <div class="mbr-isa-chat-user"><?php echo esc_html( $chat_input ); ?></div>
                    </div>
                    <div class="mbr-isa-chat-turn">
                        <div class="mbr-isa-chat-bot">
                            <?php
                            // Confidence / intent badge.
                            if ( isset( $chat_output['type'] ) && 'intent' === $chat_output['type'] ) {
                                echo '<span class="mbr-isa-badge mbr-isa-badge-intent">intent: ' . esc_html( $chat_output['intent_id'] ?? '' ) . '</span> ';
                            } elseif ( isset( $chat_output['confidence'] ) ) {
                                $conf = $chat_output['confidence'];
                                echo '<span class="mbr-isa-badge mbr-isa-badge-' . esc_attr( $conf ) . '">' . esc_html( $conf ) . '</span> ';
                            }
                            ?>
                            <div style="margin-top:6px;"><?php echo esc_html( $chat_output['message'] ?? '' ); ?></div>

                            <?php if ( ! empty( $chat_output['results'] ) ) : ?>
                                <ul class="mbr-isa-chat-results">
                                    <?php foreach ( $chat_output['results'] as $r ) : ?>
                                        <li>
                                            <a href="<?php echo esc_url( $r['url'] ?? '#' ); ?>" target="_blank"><?php echo esc_html( $r['title'] ?? '' ); ?></a>
                                            <div style="margin-top:4px; font-size:13px; line-height:1.5;">
                                                <?php
                                                // Snippet is already HTML-escaped + <mark> wrapped by the responder.
                                                echo wp_kses( $r['snippet'] ?? '', [ 'mark' => [] ] );
                                                ?>
                                            </div>
                                            <div style="color:#888;font-size:11px;margin-top:4px;">
                                                score: <code><?php echo esc_html( $r['score'] ?? '' ); ?></code>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ( ! empty( $chat_output['suggestions'] ) ) : ?>
                                <div class="mbr-isa-chat-meta">
                                    <?php esc_html_e( 'Suggested next steps:', 'mbr-isa' ); ?>
                                    <?php echo esc_html( implode( ' • ', $chat_output['suggestions'] ) ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <details style="margin-top:1em; max-width:900px;">
                    <summary style="cursor:pointer; color:#666;"><?php esc_html_e( 'Raw response payload (what the widget would receive)', 'mbr-isa' ); ?></summary>
                    <pre style="background:#1e1e2e;color:#cdd6f4;padding:1em;border-radius:6px;overflow:auto;font-size:12px;"><?php
                        echo esc_html( wp_json_encode( $chat_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
                    ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }
}