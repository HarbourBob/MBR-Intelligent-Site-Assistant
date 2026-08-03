<?php
/**
 * Activation handler — creates database tables, seeds defaults.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Activator {

    /**
     * Run on plugin activation.
     *
     * @return void
     */
    public static function activate() {
        self::create_tables();
        self::seed_default_options();

        update_option( 'mbr_isa_version',    MBR_ISA_VERSION );
        update_option( 'mbr_isa_db_version', MBR_ISA_DB_VERSION );
    }

    /**
     * Run on DB schema upgrade (version bump of MBR_ISA_DB_VERSION).
     *
     * For session one this is equivalent to create_tables(). Future
     * schema versions will branch on $from_version to perform migrations.
     *
     * @param string $from_version Previously installed DB version.
     * @return void
     */
    public static function run_schema_upgrade( $from_version ) {
        global $wpdb;

        $documents_table = $wpdb->prefix . 'mbrisa_documents';

        // v1 -> v2: the documents table gains chunk_index and its unique key
        // changes from (post_id) to (post_id, chunk_index). dbDelta adds new
        // columns and keys but never drops an existing unique key, so remove
        // the old one explicitly before letting dbDelta apply the new schema.
        if ( version_compare( $from_version, '2', '<' ) ) {
            $has_old_key = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
                    $documents_table,
                    'post_id'
                )
            );
            if ( $has_old_key ) {
                $wpdb->query( "ALTER TABLE {$documents_table} DROP INDEX post_id" );
            }
        }

        self::create_tables();

        // v2 -> v3: the documents table gains is_contents. dbDelta normally
        // adds it as part of create_tables() above, but it silently does
        // nothing when it cannot parse a definition, and a missing column
        // makes every document insert fail — which looks like "indexing runs
        // but returns nothing". Verify and add it directly if needed.
        self::ensure_column(
            $documents_table,
            'is_contents',
            "ALTER TABLE {$documents_table} ADD COLUMN is_contents TINYINT(1) NOT NULL DEFAULT 0 AFTER content_hash"
        );
    }

    /**
     * Add a column if it is missing. Belt-and-braces for dbDelta.
     *
     * @param string $table  Full table name.
     * @param string $column Column name to check.
     * @param string $sql    ALTER statement to run when absent.
     * @return void
     */
    private static function ensure_column( $table, $column, $sql ) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
                $table,
                $column
            )
        );

        if ( ! $exists ) {
            $wpdb->query( $sql );
        }
    }

    /**
     * Create the four core tables using dbDelta.
     *
     * dbDelta is idempotent: safe to run repeatedly, will alter existing
     * tables to match the declared schema.
     *
     * @return void
     */
    private static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $terms_table     = $wpdb->prefix . 'mbrisa_terms';
        $documents_table = $wpdb->prefix . 'mbrisa_documents';
        $postings_table  = $wpdb->prefix . 'mbrisa_postings';
        $queries_table   = $wpdb->prefix . 'mbrisa_queries';

        // Terms dictionary.
        $terms_sql = "CREATE TABLE {$terms_table} (
            term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            term VARCHAR(100) NOT NULL,
            document_frequency INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (term_id),
            UNIQUE KEY term (term)
        ) {$charset_collate};";

        // Documents (indexed posts/pages). From schema v2 a post may occupy
        // several rows — one per passage chunk — distinguished by chunk_index.
        $documents_sql = "CREATE TABLE {$documents_table} (
            doc_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            chunk_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(20) NOT NULL,
            title VARCHAR(500) NOT NULL,
            excerpt VARCHAR(2000) DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            content_hash CHAR(32) NOT NULL,
            is_contents TINYINT(1) NOT NULL DEFAULT 0,
            indexed_at DATETIME NOT NULL,
            PRIMARY KEY  (doc_id),
            UNIQUE KEY post_chunk (post_id,chunk_index),
            KEY post_type (post_type)
        ) {$charset_collate};";

        // Postings (inverted index).
        $postings_sql = "CREATE TABLE {$postings_table} (
            term_id BIGINT UNSIGNED NOT NULL,
            doc_id BIGINT UNSIGNED NOT NULL,
            term_frequency SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            field VARCHAR(10) NOT NULL DEFAULT 'content',
            PRIMARY KEY  (term_id,doc_id,field),
            KEY doc_id (doc_id)
        ) {$charset_collate};";

        // Query log.
        $queries_sql = "CREATE TABLE {$queries_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            query_text VARCHAR(500) NOT NULL,
            normalised_tokens VARCHAR(500) DEFAULT NULL,
            top_doc_id BIGINT UNSIGNED DEFAULT NULL,
            top_score FLOAT DEFAULT NULL,
            result_count SMALLINT UNSIGNED DEFAULT 0,
            intent_matched VARCHAR(50) DEFAULT NULL,
            feedback TINYINT DEFAULT NULL,
            user_ip_hash CHAR(64) DEFAULT NULL,
            session_id CHAR(32) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY session_id (session_id)
        ) {$charset_collate};";

        dbDelta( $terms_sql );
        dbDelta( $documents_sql );
        dbDelta( $postings_sql );
        dbDelta( $queries_sql );
    }

    /**
     * Seed default options if they don't already exist.
     *
     * @return void
     */
    private static function seed_default_options() {
        $default_settings = [
            'enabled_post_types' => [ 'post', 'page' ],
            'bm25_k1'            => 1.2,
            'bm25_b'             => 0.75,
            'field_weight_title' => 3.0,
            'field_weight_body'  => 1.0,
            'field_weight_excerpt' => 1.5,
            'widget_position'    => 'bottom-right',
            'widget_enabled'     => false, // Off until indexing + querying is in place.
            'log_queries'        => true,
            'rate_limit_per_min' => 30,
            'index_pdfs'         => false, // Off by default; opt-in on the diagnostics page.
            'pdf_max_filesize_mb' => 20,   // Skip PDFs larger than this to protect memory.
        ];

        if ( false === get_option( 'mbr_isa_settings' ) ) {
            add_option( 'mbr_isa_settings', $default_settings );
        }

        // Intents and synonyms seeded in later sessions when the matching engines land.
        if ( false === get_option( 'mbr_isa_intents' ) ) {
            add_option( 'mbr_isa_intents', [] );
        }

        if ( false === get_option( 'mbr_isa_synonyms' ) ) {
            add_option( 'mbr_isa_synonyms', [] );
        }

        if ( false === get_option( 'mbr_isa_index_status' ) ) {
            add_option( 'mbr_isa_index_status', [
                'last_full_index' => null,
                'documents'       => 0,
                'terms'           => 0,
                'postings'        => 0,
            ] );
        }
    }
}