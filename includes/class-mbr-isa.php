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
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-chunker.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-cli.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-pdf-extractor.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-indexer.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-alt-audit.php';
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

    /**
     * @since 0.9.10
     * @var MBR_ISA_Alt_Audit|null
     */
    private $alt_audit = null;

    private $tokeniser     = null;
    private $bm25          = null;
    private $pdf_extractor = null;
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

    /**
     * Hook suffix of the Diagnostics screen, so admin assets load there
     * and nowhere else.
     *
     * @since 0.9.20
     * @var string|null
     */
    private $diagnostic_hook = null;

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
        $this->check_plugin_upgrades();
        $this->register_hooks();
    }

    /**
     * Run settings migrations after a plugin update.
     *
     * WordPress does not fire the activation hook on update, so mbr_isa_version
     * still holds the previously installed version here — which makes it a
     * reliable trigger for migrations that touch settings rather than schema.
     *
     * @return void
     */
    private function check_plugin_upgrades() {
        $installed = (string) get_option( 'mbr_isa_version', '0' );

        if ( version_compare( $installed, MBR_ISA_VERSION, '>=' ) ) {
            return;
        }

        if ( version_compare( $installed, '0.8.2', '<' ) ) {
            MBR_ISA_Activator::migrate_to_082( $installed );
        }

        if ( version_compare( $installed, '0.8.3', '<' ) ) {
            MBR_ISA_Activator::migrate_to_083( $installed );
        }

        if ( version_compare( $installed, '0.9.7', '<' ) ) {
            MBR_ISA_Activator::migrate_to_097( $installed );
        }

        if ( version_compare( $installed, '0.9.8', '<' ) ) {
            MBR_ISA_Activator::migrate_to_098( $installed );
        }

        if ( version_compare( $installed, '0.9.9', '<' ) ) {
            MBR_ISA_Activator::migrate_to_099( $installed );
        }

        update_option( 'mbr_isa_version', MBR_ISA_VERSION );
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

        // Parent menu must register before any submenus attach to it.
        add_action( 'admin_menu', [ $this, 'register_parent_menu' ], 9 );

        // Submenu order in the sidebar follows hook-registration order at the
        // same priority, so register them in the order we want them displayed:
        // Intents -> Synonyms -> Diagnostics -> Appearance.
        $this->admin_intents()->register_hooks();
        $this->admin_synonyms()->register_hooks();
        add_action( 'admin_menu', [ $this, 'register_diagnostic_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        $this->admin_theme()->register_hooks();

        add_action( 'rest_api_init', [ $this->rest(), 'register_routes' ] );
        add_action( 'admin_post_mbr_isa_full_reindex',          [ $this, 'handle_full_reindex' ] );
        add_action( 'admin_post_mbr_isa_save_widget_settings',  [ $this, 'handle_save_widget_settings' ] );
        add_action( 'admin_post_mbr_isa_save_content_settings', [ $this, 'handle_save_content_settings' ] );
        add_action( 'admin_post_mbr_isa_save_privacy_settings', [ $this, 'handle_save_privacy_settings' ] );

        $this->alt_audit()->register_hooks();

        // Query-log retention. The hook was being cleared on deactivate and
        // uninstall since 0.6, but nothing ever scheduled it — so the log grew
        // without limit. Scheduling is idempotent, so running it on init is
        // safe and also repairs installs whose cron entry was lost.
        add_action( 'init', [ $this, 'maybe_schedule_query_log_cleanup' ] );
        add_action( 'mbr_isa_cleanup_query_log', [ $this, 'run_query_log_cleanup' ] );

        // Stale-index purge. Scheduled by the 0.9.7 migration and re-armed by
        // its own handler until the table has been walked once.
        add_action( 'mbr_isa_purge_stale_index', [ $this, 'run_stale_index_purge' ] );

        add_action( 'admin_notices', [ $this, 'render_upgrade_notice' ] );
    }

    /**
     * One-time notice after upgrading to 0.8.2.
     *
     * The visibility fixes gate what *enters* the index; they cannot retract
     * what a previous version already put there. Until a reindex runs, a
     * password-protected post indexed under 0.8.1 is still searchable, so the
     * notice is not dismissible by ignoring it — it clears when the reindex
     * happens.
     *
     * @return void
     */
    public function render_upgrade_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notice_082 = (bool) get_transient( 'mbr_isa_notice_082' );
        $notice_083 = (bool) get_transient( 'mbr_isa_notice_083' );

        if ( ! $notice_082 && ! $notice_083 ) {
            return;
        }

        $status = get_option( 'mbr_isa_index_status', [] );
        $needs  = is_array( $status ) && ! empty( $status['reindex_required'] );

        if ( ! $needs ) {
            delete_transient( 'mbr_isa_notice_082' );
            delete_transient( 'mbr_isa_notice_083' );
            return;
        }

        // Straight to the tab holding the Run Full Reindex button.
        $url = self::diagnostic_url( 'status' );

        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__( 'MBR Intelligent Site Assistant', 'mbr-isa' )
            . '</strong> — '
            . esc_html__( 'Your search index needs rebuilding before it matches how this version indexes content.', 'mbr-isa' )
            . '</p>';

        if ( $notice_083 ) {
            echo '<p>'
                . esc_html__( 'Version 0.8.2 was too strict about which PDFs it would index: it required the WordPress attachment relationship, which is only set for files uploaded from inside the post editor. PDFs added through the Media Library were skipped even when published pages linked to them. This release fixes that, and your "Which PDFs" setting has been moved to the corrected option.', 'mbr-isa' )
                . '</p>';
        }

        if ( $notice_082 ) {
            echo '<p>'
                . esc_html__( 'This version also stops password-protected posts being indexed. Content indexed by an earlier version is still present and still searchable until you rebuild.', 'mbr-isa' )
                . '</p>';
        }

        echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Go to Diagnostics and run a full reindex', 'mbr-isa' )
            . '</a></p></div>';
    }

    // --- Query log retention -------------------------------------------------

    /**
     * Ensure the daily cleanup event exists.
     *
     * @return void
     */
    public function maybe_schedule_query_log_cleanup() {
        if ( ! wp_next_scheduled( 'mbr_isa_cleanup_query_log' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mbr_isa_cleanup_query_log' );
        }
    }

    // --- Stale index purge ---------------------------------------------------

    /**
     * Walk the index removing rows whose source is no longer publicly readable.
     *
     * Cursored and self-rearming: each run examines a bounded slice and, if
     * more remains, schedules itself again. Cron gets its own request, so a
     * large index costs a handful of short background runs instead of one
     * long admin page load.
     *
     * This is housekeeping, not a security control. Nothing is exposed while
     * it is pending — search() re-tests every row before returning it — so a
     * site whose WP-Cron is broken is untidy rather than leaking, and a full
     * reindex achieves the same thing immediately.
     *
     * @since 0.9.7
     *
     * @return array Result of the batch.
     */
    public function run_stale_index_purge() {
        $cursor = (int) get_option( 'mbr_isa_purge_cursor', 0 );

        $result = $this->indexer()->purge_ineligible_documents( $cursor, 200 );

        if ( ! empty( $result['done'] ) ) {
            delete_option( 'mbr_isa_purge_cursor' );
            return $result;
        }

        update_option( 'mbr_isa_purge_cursor', (int) $result['cursor'], false );

        if ( ! wp_next_scheduled( 'mbr_isa_purge_stale_index' ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mbr_isa_purge_stale_index' );
        }

        return $result;
    }

    /**
     * Retention period in days, or 0 for "keep indefinitely".
     *
     * @return int
     */
    public static function query_log_retention_days() {
        $settings = get_option( 'mbr_isa_settings', [] );
        $days     = isset( $settings['query_log_retention_days'] )
            ? (int) $settings['query_log_retention_days']
            : 30;

        return in_array( $days, [ 0, 7, 30, 90 ], true ) ? $days : 30;
    }

    /**
     * Delete query-log rows older than the configured retention period.
     *
     * Deletes in bounded batches rather than one statement: the table has no
     * upper size on an install upgrading from 0.8.1, where it may hold years
     * of rows, and a single unbounded DELETE on a large table can hold locks
     * long enough to be noticed. The created_at index makes each batch cheap.
     *
     * @return int Number of rows deleted.
     */
    public function run_query_log_cleanup() {
        global $wpdb;

        $days = self::query_log_retention_days();
        if ( $days <= 0 ) {
            return 0; // Retention disabled — keep everything.
        }

        $table  = $wpdb->prefix . 'mbrisa_queries';
        // created_at is written in site-local time, so the cutoff has to be
        // expressed the same way. wp_date() formats a real UTC timestamp in
        // the site's timezone, which is exactly that — without the falsified
        // offset timestamp current_time('timestamp') returns.
        $cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $deleted = 0;
        for ( $batch = 0; $batch < 20; $batch++ ) {
            $rows = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000",
                    $cutoff
                )
            );

            if ( ! $rows ) {
                break;
            }

            $deleted += (int) $rows;

            if ( (int) $rows < 1000 ) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Save the privacy settings (query logging on/off, retention period).
     *
     * @return void
     */
    public function handle_save_privacy_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( 'mbr_isa_save_privacy_settings' );

        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $settings['log_queries'] = ! empty( $_POST['log_queries'] ) ? 1 : 0;

        $days = isset( $_POST['query_log_retention_days'] ) ? (int) $_POST['query_log_retention_days'] : 30;
        $settings['query_log_retention_days'] = in_array( $days, [ 0, 7, 30, 90 ], true ) ? $days : 30;

        update_option( 'mbr_isa_settings', $settings );

        // Apply a shortened retention period immediately rather than leaving
        // the excess rows in place until the next cron run.
        $this->run_query_log_cleanup();

        wp_safe_redirect( self::diagnostic_url( 'privacy', [ 'privacy-saved' => 1 ] ) );
        exit;
    }

    // --- Top-level admin menu ------------------------------------------------

    /**
     * Parent slug for the "MBR Site Assistant" top-level admin menu.
     *
     * Deliberately set to the Intents page slug so that:
     *   - clicking the parent menu lands on the Intents screen
     *   - WordPress does not auto-generate a duplicate first submenu entry
     */
    const PARENT_SLUG = 'mbr-isa-intents';

    public function register_parent_menu() {
        add_menu_page(
            __( 'MBR Site Assistant', 'mbr-isa' ),
            __( 'MBR Site Assistant', 'mbr-isa' ),
            'manage_options',
            self::PARENT_SLUG,
            '',
            'dashicons-format-chat',
            null
        );
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

    public function pdf_extractor() {
        if ( null === $this->pdf_extractor ) {
            $this->pdf_extractor = new MBR_ISA_PDF_Extractor();
        }
        return $this->pdf_extractor;
    }

    /**
     * Alt text audit service.
     *
     * @since 0.9.10
     *
     * @return MBR_ISA_Alt_Audit
     */
    public function alt_audit() {
        if ( null === $this->alt_audit ) {
            $this->alt_audit = new MBR_ISA_Alt_Audit();
        }
        return $this->alt_audit;
    }

    public function indexer() {
        if ( null === $this->indexer ) {
            $this->indexer = new MBR_ISA_Indexer( $this->tokeniser(), $this->bm25(), $this->pdf_extractor() );
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

        wp_safe_redirect( self::diagnostic_url( 'status', [ 'reindexed' => 1 ] ) );
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
        $position = isset( $_POST['widget_position'] ) ? sanitize_key( wp_unslash( $_POST['widget_position'] ) ) : 'bottom-right';
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

        wp_safe_redirect( self::diagnostic_url( 'widget', [ 'widget-saved' => 1 ] ) );
        exit;
    }

    public function handle_save_content_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorised' );
        }
        check_admin_referer( 'mbr_isa_save_content_settings' );

        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        /*
         * Post types. Unchecking everything is a legitimate configuration
         * (PDF-only search), so an empty selection is stored as an empty array
         * rather than being silently reset to the defaults.
         *
         * Submitted slugs are sanitised and then checked against the set the
         * form is allowed to offer: publicly-viewable registered types, plus
         * whatever was already saved. The latter keeps a type belonging to a
         * temporarily-inactive plugin from being dropped, while still refusing
         * an arbitrary slug posted by hand.
         */
        $previous_types = $settings['enabled_post_types'] ?? [ 'post', 'page' ];
        if ( ! is_array( $previous_types ) ) {
            $previous_types = [ 'post', 'page' ];
        }
        $previous_types = array_map( 'strval', $previous_types );

        $public_types = array_keys( get_post_types( [ 'public' => true ], 'names' ) );
        $public_types = array_diff( $public_types, [ 'attachment' ] );

        $allowed_types = array_unique( array_merge( $public_types, $previous_types ) );

        $submitted_types = isset( $_POST['enabled_post_types'] ) && is_array( $_POST['enabled_post_types'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_post_types'] ) )
            : [];

        $settings['enabled_post_types'] = array_values(
            array_unique( array_intersect( $submitted_types, $allowed_types ) )
        );

        $settings['index_pdfs'] = ! empty( $_POST['index_pdfs'] ) ? 1 : 0;

        /*
         * PDF visibility. Anything unrecognised falls back to 'linked' — the
         * safe-but-workable default. Note that the restrictive option is
         * 'attached', not the default: 0.8.2 made 'attached' the default and
         * that excluded most legitimately public PDFs, because post_parent is
         * unset on anything not uploaded from inside the post editor.
         */
        $visibility = isset( $_POST['pdf_visibility'] )
            ? sanitize_key( wp_unslash( $_POST['pdf_visibility'] ) )
            : 'linked';
        $settings['pdf_visibility'] = in_array( $visibility, [ 'linked', 'attached', 'all' ], true )
            ? $visibility
            : 'linked';

        $max_mb = isset( $_POST['pdf_max_filesize_mb'] ) ? (int) $_POST['pdf_max_filesize_mb'] : 20;
        if ( $max_mb < 1 ) {
            $max_mb = 1;
        }
        if ( $max_mb > 100 ) {
            $max_mb = 100;
        }
        $settings['pdf_max_filesize_mb'] = $max_mb;

        $settings['index_images'] = ! empty( $_POST['index_images'] ) ? 1 : 0;

        /*
         * Image visibility. Same three modes as PDFs, deliberately stored
         * under its own key: 'all' means something much larger for images
         * than it does for documents, and a site that wants every PDF in the
         * library searchable rarely wants every cropped thumbnail with it.
         */
        $image_visibility = isset( $_POST['image_visibility'] )
            ? sanitize_key( wp_unslash( $_POST['image_visibility'] ) )
            : 'linked';
        $settings['image_visibility'] = in_array( $image_visibility, [ 'linked', 'attached', 'all' ], true )
            ? $image_visibility
            : 'linked';

        $settings['image_require_alt'] = ! empty( $_POST['image_require_alt'] ) ? 1 : 0;

        update_option( 'mbr_isa_settings', $settings );

        wp_safe_redirect( self::diagnostic_url( 'content', [ 'content-saved' => 1 ] ) );
        exit;
    }

    // --- Diagnostic page -----------------------------------------------------

    public function register_diagnostic_page() {
        $this->diagnostic_hook = add_submenu_page(
            self::PARENT_SLUG,
            __( 'MBR ISA Diagnostics', 'mbr-isa' ),
            __( 'MBR ISA Diagnostics', 'mbr-isa' ),
            'manage_options',
            'mbr-isa-diagnostic',
            [ $this, 'render_diagnostic_page' ]
        );
    }

    /**
     * Load the admin stylesheet and script on the Diagnostics screen.
     *
     * Both were inline until 0.9.20, which moved the Diagnostics blocks out;
     * 0.9.21 finished the job with the intents, synonyms and Appearance
     * screens, so no admin screen carries inline CSS or script now. None of
     * it was cacheable and all of it was refused by a strict admin
     * Content-Security-Policy. Values PHP used to print into the markup
     * travel through wp_localize_script(); the Appearance screen has its own
     * script and localises separately, from MBR_ISA_Admin_Theme.
     *
     * @since 0.9.20
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        $our_screens = [
            'mbr-isa-diagnostic',
            MBR_ISA_Admin_Intents::PAGE_SLUG,
            MBR_ISA_Admin_Synonyms::PAGE_SLUG,
            MBR_ISA_Admin_Theme::PAGE_SLUG,
        ];
        if ( ! in_array( $page, $our_screens, true ) ) {
            return;
        }

        wp_enqueue_style(
            'mbr-isa-admin',
            MBR_ISA_URL . 'assets/css/mbr-isa-admin.css',
            [],
            MBR_ISA_VERSION
        );

        // The behaviour is all Diagnostics-specific, so only that screen
        // loads it; the stylesheet is shared because the card and anchor
        // rules apply to the intents and synonyms screens too.
        if ( null === $this->diagnostic_hook || $hook !== $this->diagnostic_hook ) {
            return;
        }

        wp_enqueue_script(
            'mbr-isa-admin',
            MBR_ISA_URL . 'assets/js/mbr-isa-admin.js',
            [],
            MBR_ISA_VERSION,
            true
        );

        wp_localize_script(
            'mbr-isa-admin',
            'mbrIsaAdmin',
            [
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'altNonce'  => wp_create_nonce( 'mbr_isa_alt_audit' ),
                'i18n'      => [
                    'saving'     => __( 'Saving...', 'mbr-isa' ),
                    'saved'      => __( 'Saved', 'mbr-isa' ),
                    'failed'     => __( 'Could not save', 'mbr-isa' ),
                    'reindexing' => __( 'Reindexing… this page will refresh when complete.', 'mbr-isa' ),
                ],
            ]
        );
    }

    /**
     * The Diagnostics tabs, in the order they appear.
     *
     * The screen grew a section at a time until it was a single scroll of
     * unrelated controls, which made the thing you wanted hard to find and the
     * thing you did not want easy to change by accident. Each tab now renders
     * on its own, so only the active tab's queries run — the feedback
     * statistics and the alt text worklist are the expensive ones, and neither
     * is touched unless you are looking at it.
     *
     * @since 0.9.20
     * @return array<string,string> tab slug => label
     */
    private function diagnostic_tabs() {
        return [
            'status'   => __( 'Status', 'mbr-isa' ),
            'content'  => __( 'Content', 'mbr-isa' ),
            'alt-text' => __( 'Alt Text', 'mbr-isa' ),
            'privacy'  => __( 'Privacy', 'mbr-isa' ),
            'widget'   => __( 'Widget', 'mbr-isa' ),
            'feedback' => __( 'Feedback', 'mbr-isa' ),
            'testers'  => __( 'Testers', 'mbr-isa' ),
        ];
    }

    /**
     * URL of a Diagnostics tab, optionally carrying extra query arguments.
     *
     * @since 0.9.20
     * @param string $tab  Tab slug.
     * @param array  $args Additional query arguments.
     * @return string
     */
    public static function diagnostic_url( $tab = 'status', array $args = [] ) {
        return add_query_arg(
            array_merge( [ 'page' => 'mbr-isa-diagnostic', 'tab' => $tab ], $args ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * Which tab to show. Compared against the known list, so an unrecognised
     * value falls back to Status rather than rendering nothing.
     *
     * @since 0.9.20
     * @return string
     */
    private function current_diagnostic_tab() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        return array_key_exists( $tab, $this->diagnostic_tabs() ) ? $tab : 'status';
    }

    /**
     * Whether each of the four custom tables exists.
     *
     * @return array<string,bool>
     */
    private function table_statuses() {
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

        return $table_statuses;
    }

    public function render_diagnostic_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs    = $this->diagnostic_tabs();
        $current = $this->current_diagnostic_tab();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MBR Intelligent Site Assistant — Diagnostics', 'mbr-isa' ); ?></h1>
            <p><?php esc_html_e( 'Status, content sources, privacy and the testers, grouped into tabs.', 'mbr-isa' ); ?></p>

            <p style="margin:0 0 1em;">
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MBR_ISA_Admin_Intents::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Manage intents →', 'mbr-isa' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MBR_ISA_Admin_Synonyms::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Manage synonyms →', 'mbr-isa' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MBR_ISA_Admin_Theme::PAGE_SLUG ) ); ?>">
                    <?php esc_html_e( 'Appearance →', 'mbr-isa' ); ?>
                </a>
            </p>

            <nav class="nav-tab-wrapper mbr-isa-tabs" aria-label="<?php esc_attr_e( 'Diagnostics sections', 'mbr-isa' ); ?>">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( self::diagnostic_url( $slug ) ); ?>"
                       class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
                       <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="mbr-isa-tab-panel">
            <?php
            switch ( $current ) {
                case 'content':
                    $this->render_tab_content();
                    break;
                case 'alt-text':
                    $this->render_tab_alt_text();
                    break;
                case 'privacy':
                    $this->render_tab_privacy();
                    break;
                case 'widget':
                    $this->render_tab_widget();
                    break;
                case 'feedback':
                    $this->render_tab_feedback();
                    break;
                case 'testers':
                    $this->render_tab_testers();
                    break;
                case 'status':
                default:
                    $this->render_tab_status();
                    break;
            }
            ?>
            </div>
        </div>
        <?php
    }

    /** Plugin information, table health, index counts and the reindex button. */
    private function render_tab_status() {
        $table_statuses = $this->table_statuses();
        $index_status = get_option( 'mbr_isa_index_status', [] );
        $reindex_msg  = get_transient( 'mbr_isa_reindex_result' );
        if ( $reindex_msg ) {
            delete_transient( 'mbr_isa_reindex_result' );
        }

        $rest_url = rest_url( MBR_ISA_REST::NAMESPACE_V1 . '/ask' );
        ?>
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
                <?php $reindex_failed = (int) ( $reindex_msg['failed'] ?? 0 ); ?>
                <div class="notice notice-<?php echo $reindex_failed ? 'error' : 'success'; ?>" style="margin-top:1em;"><p>
                    <?php echo esc_html( sprintf(
                        /* translators: 1: document count, 2: chunk count, 3: duration in seconds */
                        __( 'Reindex complete: %1$d documents as %2$d chunks in %3$s seconds.', 'mbr-isa' ),
                        (int) ( $reindex_msg['documents'] ?? 0 ),
                        (int) ( $reindex_msg['chunks'] ?? 0 ),
                        (string) ( $reindex_msg['duration'] ?? '?' )
                    ) ); ?>
                    <?php if ( $reindex_failed ) : ?>
                        <br><strong><?php echo esc_html( sprintf(
                            /* translators: 1: number of failed items, 2: number attempted */
                            __( '%1$d of %2$d items could not be written to the index. This usually means the database schema is out of date — check the DB Version above, and deactivate and reactivate the plugin to re-run the upgrade.', 'mbr-isa' ),
                            $reindex_failed,
                            (int) ( $reindex_msg['attempted'] ?? 0 )
                        ) ); ?></strong>
                    <?php endif; ?>
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
                    <?php esc_html_e( 'Indexes all published content of the post types selected on the Content tab (and PDFs, if enabled).', 'mbr-isa' ); ?>
                </span>
            </form>

        <?php
    }

    /** Which content the assistant indexes. */
    private function render_tab_content() {
        ?>
            <?php
            // --- Content sources section --------------------------------
            $content_settings   = get_option( 'mbr_isa_settings', [] );
            $pdf_enabled         = ! empty( $content_settings['index_pdfs'] );
            $pdf_max_mb          = isset( $content_settings['pdf_max_filesize_mb'] ) ? (int) $content_settings['pdf_max_filesize_mb'] : 20;
            $pdf_visibility      = isset( $content_settings['pdf_visibility'] ) ? (string) $content_settings['pdf_visibility'] : 'linked';

            /*
             * Eligibility counter. A setting that silently matches nothing is
             * the hardest kind to debug — 0.8.2 shipped exactly that — so the
             * screen reports how many PDFs actually qualify right now rather
             * than leaving it to be discovered after a reindex returns nothing.
             */
            $pdf_total    = 0;
            $pdf_sampled  = 0;
            $pdf_eligible = 0;
            if ( $pdf_enabled ) {
                $pdf_ids = get_posts( [
                    'post_type'        => 'attachment',
                    'post_mime_type'   => 'application/pdf',
                    'post_status'      => 'inherit',
                    'posts_per_page'   => 500,
                    'fields'           => 'ids',
                    'suppress_filters' => true,
                ] );
                $pdf_total = count( $pdf_ids );

                /*
                 * Tested on a bounded sample, not on all of them.
                 *
                 * The 'linked' rule costs two unindexable LIKE scans per
                 * unattached file — one over post_content, one joined over
                 * post_meta — with no cache in front of them on this path.
                 * At 500 files that is up to a thousand full scans in a
                 * single page load, and the page it takes down is the only
                 * screen from which the setting can be corrected. A sample
                 * answers the question this figure exists to answer, which is
                 * whether the setting matches anything at all.
                 */
                $sample       = array_slice( $pdf_ids, 0, 100 );
                $pdf_sampled  = count( $sample );
                $pdf_eligible = $this->indexer()->count_eligible_pdfs( $sample );
            }
            // --- Images -------------------------------------------------
            $image_enabled      = ! empty( $content_settings['index_images'] );
            $image_visibility   = isset( $content_settings['image_visibility'] ) ? (string) $content_settings['image_visibility'] : 'linked';
            $image_require_alt  = ! isset( $content_settings['image_require_alt'] ) || ! empty( $content_settings['image_require_alt'] );

            /*
             * Eligibility counter, as for PDFs — but reporting two numbers
             * rather than one, because an image has two independent ways to
             * be excluded and they need different remedies. A shortfall under
             * "which images" is fixed on this screen; a shortfall under alt
             * text is fixed in the Media Library, and is worth knowing about
             * on its own terms.
             *
             * Tested on a bounded sample for the same reason the PDF figure
             * is, and more urgently: the visibility rule runs the same pair of
             * unindexable LIKE scans per file, and a Media Library holds far
             * more images than documents. Testing every one of them would
             * time out the screen on precisely the sites where the setting
             * most needs checking.
             */
            $image_total    = 0;
            $image_sampled  = 0;
            $image_eligible = 0;
            $image_no_text  = 0;
            if ( $image_enabled ) {
                $image_ids = get_posts( [
                    'post_type'        => 'attachment',
                    'post_mime_type'   => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
                    'post_status'      => 'inherit',
                    'posts_per_page'   => 500,
                    'fields'           => 'ids',
                    'suppress_filters' => true,
                ] );
                $image_total = count( $image_ids );

                $image_sample   = array_slice( $image_ids, 0, 100 );
                $image_sampled  = count( $image_sample );
                $image_counts   = $this->indexer()->count_eligible_images( $image_sample );
                $image_eligible = (int) $image_counts['eligible'];
                $image_no_text  = (int) $image_counts['no_text'];
            }

            $content_saved       = isset( $_GET['content-saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['content-saved'] ) );

            // Currently enabled post types (falling back to the shipped default).
            $enabled_types = $content_settings['enabled_post_types'] ?? [ 'post', 'page' ];
            if ( ! is_array( $enabled_types ) ) {
                $enabled_types = [ 'post', 'page' ];
            }
            $enabled_types = array_map( 'strval', $enabled_types );

            /*
             * Offer only publicly-viewable post types. Non-public types are
             * excluded by design: their entries would have no reachable
             * permalink, and indexing them would expose internal records
             * through a public REST endpoint that performs no capability
             * check at query time.
             *
             * 'attachment' is excluded here because PDFs have their own
             * dedicated toggle below.
             */
            $available_types = get_post_types( [ 'public' => true ], 'objects' );
            unset( $available_types['attachment'] );

            // Any enabled type that is no longer registered (e.g. its plugin is
            // deactivated). Surfaced explicitly so saving cannot silently drop it.
            $orphaned_types = array_values( array_diff(
                $enabled_types,
                array_keys( $available_types )
            ) );
            ?>

            <h2 id="mbr-isa-content-settings" style="margin-top:2em;"><?php esc_html_e( 'Content Sources', 'mbr-isa' ); ?></h2>
            <p><?php esc_html_e( 'Choose which content the assistant indexes.', 'mbr-isa' ); ?></p>

            <?php if ( $content_saved ) : ?>
                <div class="notice notice-success" style="margin-top:1em;"><p>
                    <?php esc_html_e( 'Content settings saved. Run a full reindex to apply the change to existing files.', 'mbr-isa' ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
                <?php wp_nonce_field( 'mbr_isa_save_content_settings' ); ?>
                <input type="hidden" name="action" value="mbr_isa_save_content_settings">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Post types', 'mbr-isa' ); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e( 'Post types to index', 'mbr-isa' ); ?></legend>
                                    <?php foreach ( $available_types as $type_slug => $type_obj ) : ?>
                                        <?php
                                        $type_label = isset( $type_obj->labels->name ) && $type_obj->labels->name
                                            ? $type_obj->labels->name
                                            : $type_slug;
                                        ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr( $type_slug ); ?>" <?php checked( in_array( $type_slug, $enabled_types, true ) ); ?>>
                                            <?php echo esc_html( $type_label ); ?>
                                            <code style="margin-left:4px;"><?php echo esc_html( $type_slug ); ?></code>
                                        </label>
                                    <?php endforeach; ?>

                                    <?php if ( ! empty( $orphaned_types ) ) : ?>
                                        <hr style="margin:10px 0;">
                                        <p class="description" style="margin-bottom:4px;">
                                            <strong><?php esc_html_e( 'Enabled but not currently registered:', 'mbr-isa' ); ?></strong>
                                            <?php esc_html_e( 'these were set previously but no post type of that name exists right now — most often because the plugin or theme that registered it is inactive. Untick to remove.', 'mbr-isa' ); ?>
                                        </p>
                                        <?php foreach ( $orphaned_types as $orphan ) : ?>
                                            <label style="display:block;margin-bottom:4px;">
                                                <input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr( $orphan ); ?>" checked>
                                                <code><?php echo esc_html( $orphan ); ?></code>
                                                <span class="description"><?php esc_html_e( '(not registered)', 'mbr-isa' ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e( 'Which post types the assistant indexes. Only published content is indexed. Custom post types appear here automatically once registered.', 'mbr-isa' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( 'Only publicly-viewable post types are listed. Results are returned through a public endpoint, so private or internal post types are deliberately not offered.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'PDF files', 'mbr-isa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="index_pdfs" value="1" <?php checked( $pdf_enabled ); ?>>
                                    <?php esc_html_e( 'Index PDF files from the Media Library.', 'mbr-isa' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Extracts the text layer from each PDF. Scanned/image-only PDFs with no text layer are skipped automatically. Results link straight to the file.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Which PDFs', 'mbr-isa' ); ?></th>
                            <td>
                                <fieldset>
                                    <label style="display:block;margin-bottom:.5em;">
                                        <input type="radio" name="pdf_visibility" value="linked" <?php checked( 'linked', $pdf_visibility ); ?>>
                                        <?php esc_html_e( 'PDFs published somewhere on the site (recommended)', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Indexed when a published, unprotected page either owns the file or links to it. Covers files uploaded through the Media Library, which is most of them.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                    <label style="display:block;margin-bottom:.5em;">
                                        <input type="radio" name="pdf_visibility" value="attached" <?php checked( 'attached', $pdf_visibility ); ?>>
                                        <?php esc_html_e( 'Only PDFs attached to a published page or post (strict)', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Uses the WordPress attachment relationship only, which is set when a file is uploaded from inside the post editor. Files added through Media &rsaquo; Add New, or brought in by an import, are excluded even if pages link to them.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                    <label style="display:block;">
                                        <input type="radio" name="pdf_visibility" value="all" <?php checked( 'all', $pdf_visibility ); ?>>
                                        <?php esc_html_e( 'Every PDF in the Media Library', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Includes files nothing links to — drafts, superseded documents, anything uploaded and forgotten. Choose this only if the whole library is meant to be public.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e( 'Media Library files are reachable by URL whether or not anything links to them. This setting decides whether their text also becomes searchable through the assistant.', 'mbr-isa' ); ?>
                                </p>
                                <?php if ( $pdf_enabled ) : ?>
                                    <p class="description" style="margin-top:.6em;">
                                        <strong><?php esc_html_e( 'Currently eligible:', 'mbr-isa' ); ?></strong>
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: 1: eligible count, 2: number of PDFs tested, 3: total PDFs in the library */
                                            __( '%1$d of the %2$d most recent PDFs tested (%3$d in the Media Library).', 'mbr-isa' ),
                                            $pdf_eligible,
                                            $pdf_sampled,
                                            $pdf_total
                                        ) );
                                        ?>
                                        <?php if ( $pdf_sampled > 0 && 0 === $pdf_eligible ) : ?>
                                            <br><span style="color:#c62828;">
                                                <?php esc_html_e( 'No PDFs qualify under the current setting, so none will be indexed. If you expected some, try the recommended option above.', 'mbr-isa' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Images', 'mbr-isa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="index_images" value="1" <?php checked( $image_enabled ); ?>>
                                    <?php esc_html_e( 'Index images from the Media Library.', 'mbr-isa' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Images are indexed on their alt text, caption, description and filename — everything a person has written about the picture. Nothing is read from the image itself. Results link to the page the image appears on, not to the file.', 'mbr-isa' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( 'JPEG, PNG, GIF, WebP and AVIF. SVG files are not indexed.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Which images', 'mbr-isa' ); ?></th>
                            <td>
                                <fieldset>
                                    <label style="display:block;margin-bottom:.5em;">
                                        <input type="radio" name="image_visibility" value="linked" <?php checked( 'linked', $image_visibility ); ?>>
                                        <?php esc_html_e( 'Images published somewhere on the site (recommended)', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Indexed when a published, unprotected page either owns the image or displays it.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                    <label style="display:block;margin-bottom:.5em;">
                                        <input type="radio" name="image_visibility" value="attached" <?php checked( 'attached', $image_visibility ); ?>>
                                        <?php esc_html_e( 'Only images attached to a published page or post (strict)', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Uses the WordPress attachment relationship only. Images added through Media &rsaquo; Add New, or brought in by an import, are excluded even where pages display them.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                    <label style="display:block;">
                                        <input type="radio" name="image_visibility" value="all" <?php checked( 'all', $image_visibility ); ?>>
                                        <?php esc_html_e( 'Every image in the Media Library', 'mbr-isa' ); ?>
                                        <br><span class="description" style="margin-left:1.8em;display:block;">
                                            <?php esc_html_e( 'Rarely what you want. A Media Library accumulates logos, backgrounds, superseded artwork and images uploaded to pages that were never published — all of which become searchable under this setting.', 'mbr-isa' ); ?>
                                        </span>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Image alt text', 'mbr-isa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="image_require_alt" value="1" <?php checked( $image_require_alt ); ?>>
                                    <?php esc_html_e( 'Only index images that have alt text.', 'mbr-isa' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Alt text is the one field written specifically to describe what is in the picture, so it is by some distance the best thing to search on. Leaving this ticked indexes the images somebody has described and skips the rest.', 'mbr-isa' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( 'Untick it and a caption, a description, or a filename that actually says something will do instead. Filenames like IMG_4821 or screenshot-2026-08-18 are ignored either way — they would only ever match the wrong thing.', 'mbr-isa' ); ?>
                                </p>

                                <?php if ( $image_enabled ) : ?>
                                    <p class="description" style="margin-top:.6em;">
                                        <strong><?php esc_html_e( 'Currently eligible:', 'mbr-isa' ); ?></strong>
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: 1: eligible count, 2: number of images tested, 3: total images in the library */
                                            __( '%1$d of the %2$d most recent images tested (%3$d in the Media Library).', 'mbr-isa' ),
                                            $image_eligible,
                                            $image_sampled,
                                            $image_total
                                        ) );
                                        ?>

                                        <?php if ( $image_no_text > 0 ) : ?>
                                            <br>
                                            <?php
                                            echo esc_html( sprintf(
                                                /* translators: %d: number of images skipped for having no description */
                                                _n(
                                                    '%d image is published but has nothing written about it, so it is skipped. Adding alt text in the Media Library brings it in.',
                                                    '%d images are published but have nothing written about them, so they are skipped. Adding alt text in the Media Library brings them in.',
                                                    $image_no_text,
                                                    'mbr-isa'
                                                ),
                                                $image_no_text
                                            ) );
                                            ?>
                                        <?php endif; ?>

                                        <?php if ( $image_sampled > 0 && 0 === $image_eligible ) : ?>
                                            <br><span style="color:#c62828;">
                                                <?php esc_html_e( 'No images in the sample qualify under the current settings. If you expected some, check the alt text requirement above.', 'mbr-isa' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-pdf-max"><?php esc_html_e( 'Maximum PDF size', 'mbr-isa' ); ?></label></th>
                            <td>
                                <input type="number" id="mbr-isa-pdf-max" name="pdf_max_filesize_mb" value="<?php echo esc_attr( (string) $pdf_max_mb ); ?>" min="1" max="100" step="1" class="small-text">
                                <?php esc_html_e( 'MB', 'mbr-isa' ); ?>
                                <p class="description">
                                    <?php esc_html_e( 'PDFs larger than this are skipped to protect memory on shared hosting. Range 1–100.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit" style="margin:0;">
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Save content settings', 'mbr-isa' ); ?></button>
                </p>
            </form>

        <?php
    }

    /** Images with no alt text, ranked by how many published pages use them. */
    private function render_tab_alt_text() {
        ?>
            <?php
            // --- Alt text audit ------------------------------------------
            $alt_audit   = $this->alt_audit();
            $alt_result  = $alt_audit->last_result();
            $alt_scanned = isset( $_GET['alt-scanned'] ) && '1' === sanitize_key( wp_unslash( $_GET['alt-scanned'] ) );
            ?>

            <h2 id="mbr-isa-alt-audit" style="margin-top:2em;"><?php esc_html_e( 'Alt Text Audit', 'mbr-isa' ); ?></h2>

            <p class="description" style="max-width:46em;">
                <?php esc_html_e( 'Finds images with no alt text and sorts them by how many published pages actually display each one, so the images worth describing come first.', 'mbr-isa' ); ?>
            </p>
            <p class="description" style="max-width:46em;">
                <?php esc_html_e( 'This plugin does not write alt text for you. It cannot see images — anything it invented would be guessed from the filename or the surrounding page, and alt text that describes the wrong thing is worse for a screen reader user than none at all. An image that is purely decorative is meant to have empty alt text, and only a person looking at it can tell the difference.', 'mbr-isa' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
                <?php wp_nonce_field( 'mbr_isa_alt_audit_scan' ); ?>
                <input type="hidden" name="action" value="mbr_isa_alt_audit_scan">
                <button type="submit" class="button button-secondary"><?php esc_html_e( 'Scan the Media Library', 'mbr-isa' ); ?></button>
                <?php if ( $alt_result && ! empty( $alt_result['decorative'] ) ) : ?>
                    <button type="submit" name="show_decorative" value="1" class="button button-link" style="margin-left:.4em;">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %s: number of images marked decorative */
                            _n( 'Include the %s image marked decorative',
                                'Include the %s images marked decorative',
                                (int) $alt_result['decorative'], 'mbr-isa' ),
                            number_format_i18n( (int) $alt_result['decorative'] )
                        ) );
                        ?>
                    </button>
                <?php endif; ?>
                <?php if ( $alt_result ) : ?>
                    <span class="description" style="margin-left:.8em;">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: human-readable time difference, 2: number of published posts scanned */
                            __( 'Last scanned %1$s ago, across %2$s published posts.', 'mbr-isa' ),
                            human_time_diff( (int) $alt_result['generated'], time() ),
                            number_format_i18n( (int) $alt_result['posts_scanned'] )
                        ) );
                        ?>
                    </span>
                <?php endif; ?>
            </form>

            <?php if ( $alt_scanned && $alt_result && empty( $alt_result['rows'] ) ) : ?>
                <div class="notice notice-success inline"><p>
                    <?php esc_html_e( 'Every image in the Media Library has alt text. Nothing to do.', 'mbr-isa' ); ?>
                </p></div>
            <?php endif; ?>

            <?php if ( $alt_result && ! empty( $alt_result['rows'] ) ) : ?>

                <p class="description">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: number of rows listed, 2: total images found missing alt text */
                        __( 'Showing %1$s of %2$s images with no alt text, most-used first.', 'mbr-isa' ),
                        number_format_i18n( (int) $alt_result['shown'] ),
                        number_format_i18n( (int) $alt_result['total_missing'] )
                    ) );
                    ?>
                </p>

                <table class="widefat striped mbr-isa-alt-table">
                    <thead>
                        <tr>
                            <th style="width:70px;"><?php esc_html_e( 'Image', 'mbr-isa' ); ?></th>
                            <th style="width:26%;"><?php esc_html_e( 'File', 'mbr-isa' ); ?></th>
                            <th style="width:120px;"><?php esc_html_e( 'Used on', 'mbr-isa' ); ?></th>
                            <th><?php esc_html_e( 'Alt text', 'mbr-isa' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $alt_result['rows'] as $row ) : ?>
                        <?php
                        $thumb_url  = wp_get_attachment_image_url( (int) $row['id'], 'thumbnail' );
                        $full_url   = wp_get_attachment_image_url( (int) $row['id'], 'large' );
                        $context_id = (int) $row['context_id'];
                        ?>
                        <tr data-mbr-isa-att="<?php echo esc_attr( (string) $row['id'] ); ?>">
                            <td>
                                <?php if ( $thumb_url ) : ?>
                                    <a href="<?php echo esc_url( $full_url ? $full_url : $thumb_url ); ?>" target="_blank" rel="noopener noreferrer">
                                        <img src="<?php echo esc_url( $thumb_url ); ?>" alt=""
                                             style="width:60px;height:60px;object-fit:cover;border-radius:4px;display:block;">
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size:11px;"><?php echo esc_html( $row['filename'] ); ?></code>

                                <?php if ( '' !== trim( (string) $row['caption'] ) ) : ?>
                                    <br><span class="description" style="font-size:11px;">
                                        <?php esc_html_e( 'Caption:', 'mbr-isa' ); ?>
                                        <?php echo esc_html( wp_trim_words( (string) $row['caption'], 14 ) ); ?>
                                    </span>
                                <?php elseif ( '' !== trim( (string) $row['description'] ) ) : ?>
                                    <br><span class="description" style="font-size:11px;">
                                        <?php esc_html_e( 'Description:', 'mbr-isa' ); ?>
                                        <?php echo esc_html( wp_trim_words( (string) $row['description'], 14 ) ); ?>
                                    </span>
                                <?php endif; ?>

                                <br>
                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['id'] . '&action=edit' ) ); ?>"
                                   target="_blank" rel="noopener noreferrer" style="font-size:11px;">
                                    <?php esc_html_e( 'Open in Media Library', 'mbr-isa' ); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ( (int) $row['uses'] > 0 ) : ?>
                                    <strong><?php echo esc_html( number_format_i18n( (int) $row['uses'] ) ); ?></strong>
                                    <?php echo esc_html( _n( 'page', 'pages', (int) $row['uses'], 'mbr-isa' ) ); ?>
                                    <?php if ( $context_id > 0 ) : ?>
                                        <br><a href="<?php echo esc_url( (string) get_permalink( $context_id ) ); ?>"
                                               target="_blank" rel="noopener noreferrer" style="font-size:11px;">
                                            <?php echo esc_html( wp_trim_words( (string) get_the_title( $context_id ), 6 ) ); ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="description" style="font-size:11px;">
                                        <?php esc_html_e( 'Not on any published page', 'mbr-isa' ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="mbr-isa-alt-input large-text"
                                       value="" maxlength="250"
                                       placeholder="<?php esc_attr_e( 'Describe what the image shows...', 'mbr-isa' ); ?>">
                                <button type="button" class="button button-small mbr-isa-alt-save" style="margin-top:4px;">
                                    <?php esc_html_e( 'Save', 'mbr-isa' ); ?>
                                </button>
                                <?php if ( ! empty( $row['decorative'] ) ) : ?>
                                    <button type="button" class="button button-small mbr-isa-alt-undecorative" style="margin-top:4px;">
                                        <?php esc_html_e( 'Not decorative', 'mbr-isa' ); ?>
                                    </button>
                                <?php else : ?>
                                    <button type="button" class="button button-small mbr-isa-alt-decorative" style="margin-top:4px;">
                                        <?php esc_html_e( 'Decorative', 'mbr-isa' ); ?>
                                    </button>
                                <?php endif; ?>
                                <span class="mbr-isa-alt-status description" style="margin-left:.5em;"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="description" style="margin-top:.8em;max-width:46em;">
                    <?php esc_html_e( '"Decorative" stores empty alt text deliberately, which tells a screen reader to skip the image. That is the right answer for spacers, background flourishes and borders, and the wrong one for anything carrying meaning. Marked images drop out of this list; re-scan including them to undo.', 'mbr-isa' ); ?>
                </p>


            <?php endif; ?>

        <?php
    }

    /** Query logging and retention. */
    private function render_tab_privacy() {
        global $wpdb;

        $table_statuses = $this->table_statuses();
        ?>
            <?php
            // --- Privacy / query log section -----------------------------
            $privacy_settings = get_option( 'mbr_isa_settings', [] );
            $log_enabled      = ! empty( $privacy_settings['log_queries'] );
            $retention_days   = self::query_log_retention_days();
            $privacy_saved    = isset( $_GET['privacy-saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['privacy-saved'] ) );

            $queries_table = $wpdb->prefix . 'mbrisa_queries';
            $log_rows      = 0;
            $log_oldest    = null;
            if ( ! empty( $table_statuses[ $queries_table ] ) ) {
                $log_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queries_table}" );
                $log_oldest = $wpdb->get_var( "SELECT MIN(created_at) FROM {$queries_table}" );
            }

            $retention_options = [
                7  => __( '7 days', 'mbr-isa' ),
                30 => __( '30 days', 'mbr-isa' ),
                90 => __( '90 days', 'mbr-isa' ),
                0  => __( 'Keep indefinitely', 'mbr-isa' ),
            ];
            ?>

            <h2 id="mbr-isa-privacy-settings" style="margin-top:2em;"><?php esc_html_e( 'Privacy &amp; Query Log', 'mbr-isa' ); ?></h2>
            <p><?php esc_html_e( 'The query log records what visitors typed, so it can contain whatever they chose to put in the box — including personal details they were never asked for. Keep only what you will actually use.', 'mbr-isa' ); ?></p>

            <?php if ( $privacy_saved ) : ?>
                <div class="notice notice-success" style="margin-top:1em;"><p>
                    <?php esc_html_e( 'Privacy settings saved. Any rows older than the new retention period have been removed.', 'mbr-isa' ); ?>
                </p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:700px;margin-bottom:1em;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Rows currently stored', 'mbr-isa' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $log_rows ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Oldest entry', 'mbr-isa' ); ?></th>
                        <td><?php echo esc_html( $log_oldest ? (string) $log_oldest : __( 'None', 'mbr-isa' ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Next scheduled cleanup', 'mbr-isa' ); ?></th>
                        <td>
                            <?php
                            $next_cleanup = wp_next_scheduled( 'mbr_isa_cleanup_query_log' );
                            echo esc_html(
                                $next_cleanup
                                    ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cleanup ), 'Y-m-d H:i' )
                                    : __( 'Not scheduled', 'mbr-isa' )
                            );
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
                <?php wp_nonce_field( 'mbr_isa_save_privacy_settings' ); ?>
                <input type="hidden" name="action" value="mbr_isa_save_privacy_settings">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Query logging', 'mbr-isa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="log_queries" value="1" <?php checked( $log_enabled ); ?>>
                                    <?php esc_html_e( 'Record queries so they can be reviewed for tuning.', 'mbr-isa' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Stores the query text, the matched result, and a salted hash of the visitor\'s IP address. The raw IP is never written to the database.', 'mbr-isa' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( 'Switching this off also disables the thumbs up/down rating buttons, which record against a logged row.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mbr-isa-retention"><?php esc_html_e( 'Keep entries for', 'mbr-isa' ); ?></label></th>
                            <td>
                                <select id="mbr-isa-retention" name="query_log_retention_days">
                                    <?php foreach ( $retention_options as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $retention_days, $value ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Older entries are deleted by a daily background task. Saving this form applies the new period immediately.', 'mbr-isa' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( '"Keep indefinitely" means exactly that — the table will grow for as long as the plugin is active. Only choose it if you have a reason to hold the history.', 'mbr-isa' ); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit" style="margin:0;">
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Save privacy settings', 'mbr-isa' ); ?></button>
                </p>
            </form>

        <?php
    }

    /** Widget placement and wording. */
    private function render_tab_widget() {
        ?>
            <?php
            // --- Widget settings section --------------------------------
            $current_settings = get_option( 'mbr_isa_settings', [] );
            $w_enabled        = ! empty( $current_settings['widget_enabled'] );
            $w_position       = isset( $current_settings['widget_position'] )    ? (string) $current_settings['widget_position']    : 'bottom-right';
            $w_title          = isset( $current_settings['widget_title'] )       ? (string) $current_settings['widget_title']       : '';
            $w_greeting       = isset( $current_settings['widget_greeting'] )    ? (string) $current_settings['widget_greeting']    : '';
            $w_placeholder    = isset( $current_settings['widget_placeholder'] ) ? (string) $current_settings['widget_placeholder'] : '';
            $widget_saved     = isset( $_GET['widget-saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['widget-saved'] ) );
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

        <?php
    }

    /** Seven-day statistics and the most recent queries. */
    private function render_tab_feedback() {
        global $wpdb;

        // --- Feedback stats & recent queries ----------------------------
        $queries_table = $wpdb->prefix . 'mbrisa_queries';
        // NOW() is the database server's clock; created_at is WordPress's
        // site-local time. Where the two differ the seven-day window is skewed
        // by the difference, so the cutoff is computed in PHP against the same
        // clock the rows were written with.
        $stats_cutoff   = wp_date( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
        $feedback_stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*)                                           AS total,
                    SUM(CASE WHEN feedback = 1  THEN 1 ELSE 0 END)     AS thumbs_up,
                    SUM(CASE WHEN feedback = -1 THEN 1 ELSE 0 END)     AS thumbs_down,
                    SUM(CASE WHEN feedback IS NULL THEN 1 ELSE 0 END)  AS no_feedback,
                    SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END)  AS zero_results,
                    SUM(CASE WHEN intent_matched IS NOT NULL THEN 1 ELSE 0 END) AS intent_hits
                 FROM {$queries_table}
                 WHERE created_at >= %s",
                $stats_cutoff
            ),
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
                                <td style="text-align:center;"><?php echo wp_kses_post( $fb_icon ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#888;font-size:12px;margin-top:0.5em;">
                    <?php esc_html_e( 'Showing the 30 most recent queries. Rows shaded red had zero results or a thumbs-down — those are the ones to look at first (add an intent or synonym).', 'mbr-isa' ); ?>
                </p>
            <?php endif; ?>

        <?php
    }

    /** Tokeniser, raw search and full-pipeline chat testers. */
    private function render_tab_testers() {
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
        ?>
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
        <?php
    }
}