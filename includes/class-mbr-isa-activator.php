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
     * Settings migration for 0.9.7.
     *
     * 0.9.7 widens what counts as publicly readable — the post type must now
     * be viewable on the front end, and a filter lets access-control plugins
     * veto a post outright. Those rules govern what *enters* the index; they
     * cannot retract what earlier rules already wrote.
     *
     * Two things follow, and the order matters:
     *
     *   1. Nothing leaks in the meantime. search() re-tests every row against
     *      the live post before returning it (see
     *      MBR_ISA_Indexer::is_result_visible()), so an index built under the
     *      old rules cannot serve content the new rules exclude. This is the
     *      part that makes the upgrade safe, and it needs no migration at
     *      all — which is exactly why it was worth building.
     *
     *   2. The index is still wrong, and should be made right. A purge pass
     *      is scheduled to remove the stale rows. It runs on cron rather than
     *      on this admin page load because it walks the whole documents
     *      table, and holding an admin request open to do tidying is how a
     *      migration turns into a support ticket.
     *
     * Previous releases handled the equivalent situation by flagging
     * reindex_required and asking the administrator to act — which left the
     * old index queryable until they did. That gap is what this arrangement
     * removes.
     *
     * @since 0.9.7
     *
     * @param string $from_version Previously installed plugin version.
     * @return void
     */
    public static function migrate_to_097( $from_version ) {
        self::schedule_stale_index_purge();
    }

    /**
     * Settings migration for 0.9.8.
     *
     * 0.9.8 closes the last place where the plugin held a second, looser
     * definition of "public": the PDF reference scan decided in SQL, which
     * cannot run the mbr_isa_can_index_post filter, so a file linked only
     * from a restricted page was indexed. The corrected test lives in
     * MBR_ISA_Indexer::any_candidate_readable().
     *
     * The same two-part reasoning as 0.9.7 applies, and for the same reason.
     * Nothing is exposed while this is pending — search re-tests every result
     * against today's rules before returning it, and those rules are the
     * corrected ones from the moment the files are in place. What remains is
     * an index holding rows that would not be written today, so a purge pass
     * is scheduled to remove them in the background.
     *
     * Sites arriving from before 0.9.7 have already had a purge scheduled by
     * migrate_to_097(). Scheduling is guarded on wp_next_scheduled(), so the
     * second call is a no-op rather than a duplicate pass.
     *
     * @since 0.9.8
     *
     * @param string $from_version Previously installed plugin version.
     * @return void
     */
    public static function migrate_to_098( $from_version ) {
        self::schedule_stale_index_purge();
    }

    /**
     * Settings migration for 0.9.9.
     *
     * 0.9.9 adds image indexing. Unlike the two migrations above this one
     * takes nothing away and exposes nothing — the feature ships switched
     * off, so an upgraded site behaves exactly as it did until somebody
     * chooses otherwise on the Diagnostics screen.
     *
     * The settings are written explicitly rather than left to their code
     * defaults, for the same reason 0.8.2 wrote its two: a setting that only
     * exists as a default is a setting the site owner cannot see, review or
     * overrule. Writing them puts all three on the screen from the first page
     * load after the upgrade.
     *
     * No purge and no forced reindex. Nothing already in the index becomes
     * wrong because images can now be added to it.
     *
     * @since 0.9.9
     *
     * @param string $from_version Previously installed plugin version.
     * @return void
     */
    public static function migrate_to_099( $from_version ) {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $changed = false;

        $defaults = [
            'index_images'      => 0,
            'image_visibility'  => 'linked',
            'image_require_alt' => 1,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( ! isset( $settings[ $key ] ) ) {
                $settings[ $key ] = $value;
                $changed          = true;
            }
        }

        if ( $changed ) {
            update_option( 'mbr_isa_settings', $settings );
        }
    }

    /**
     * Queue a background walk of the index, starting from the first row.
     *
     * Cron rather than this admin page load: the pass walks the whole
     * documents table, and holding an admin request open to do tidying is how
     * a migration turns into a support ticket. The handler re-arms itself
     * until the table has been walked once.
     *
     * @since 0.9.8
     *
     * @return void
     */
    private static function schedule_stale_index_purge() {
        // Purge from the beginning of the table.
        update_option( 'mbr_isa_purge_cursor', 0, false );

        if ( ! wp_next_scheduled( 'mbr_isa_purge_stale_index' ) ) {
            wp_schedule_single_event( time() + 30, 'mbr_isa_purge_stale_index' );
        }
    }

    /**
     * Settings migration for 0.8.3.
     *
     * 0.8.2 introduced 'pdf_visibility' with a default of 'attached', which
     * turned out to be far stricter than intended: post_parent is only set on
     * files uploaded from inside the post editor, so files added through the
     * Media Library — the majority on most sites — were excluded even when
     * published pages linked to them. Sites that took that default get moved
     * to 'linked', which is what 'attached' was meant to express.
     *
     * A value the site owner chose deliberately is left alone. Only the exact
     * default written by the 0.8.2 migration is corrected, and only when
     * coming from 0.8.2 — so anyone who read the setting and picked 'attached'
     * on purpose keeps it.
     *
     * @param string $from_version Previously installed plugin version.
     * @return void
     */
    public static function migrate_to_083( $from_version ) {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $current = isset( $settings['pdf_visibility'] )
            ? (string) $settings['pdf_visibility']
            : '';

        // Fresh installs and 0.8.2 upgrades both land on 'attached' without
        // the owner having chosen it.
        if ( '' === $current || 'attached' === $current ) {
            $settings['pdf_visibility'] = 'linked';
            update_option( 'mbr_isa_settings', $settings );
        }

        // Only sites that ran 0.8.2 with PDF indexing on have a shortfall in
        // the index to make good.
        if ( ! empty( $settings['index_pdfs'] ) ) {
            $status = get_option( 'mbr_isa_index_status', [] );
            if ( is_array( $status ) ) {
                $status['reindex_required'] = '0.8.3';
                update_option( 'mbr_isa_index_status', $status );
            }
            set_transient( 'mbr_isa_notice_083', 1, MONTH_IN_SECONDS );
        }
    }

    /**
     * Settings migration for 0.8.2.
     *
     * Writes the two new settings explicitly rather than letting them fall
     * through to their code defaults. Both change existing behaviour — one
     * narrows what gets indexed, the other starts deleting old rows — and a
     * setting that only exists as a default is a setting the site owner cannot
     * see, review or overrule. Writing them puts both on the Diagnostics
     * screen where they belong.
     *
     * Existing installs get a 90-day retention period rather than the 30 days
     * a fresh install starts with: their log may hold years of history that
     * was never opted into being deleted, and the more generous window leaves
     * time to export or change the setting before anything goes.
     *
     * @param string $from_version Previously installed plugin version.
     * @return void
     */
    public static function migrate_to_082( $from_version ) {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $changed = false;

        if ( ! isset( $settings['pdf_visibility'] ) ) {
            $settings['pdf_visibility'] = 'attached';
            $changed = true;
        }

        if ( ! isset( $settings['query_log_retention_days'] ) ) {
            $settings['query_log_retention_days'] = 90;
            $changed = true;
        }

        if ( $changed ) {
            update_option( 'mbr_isa_settings', $settings );
        }

        // One-time admin notice. Both migrated settings change what the plugin
        // does with existing data, so the site owner is told rather than left
        // to discover it.
        set_transient( 'mbr_isa_notice_082', 1, MONTH_IN_SECONDS );

        // The index needs rebuilding: anything already stored predates the
        // password and attachment visibility gates, so removing the leak
        // requires a reindex, not just a code change.
        $status = get_option( 'mbr_isa_index_status', [] );
        if ( is_array( $status ) ) {
            $status['reindex_required'] = '0.8.2';
            update_option( 'mbr_isa_index_status', $status );
        }
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

        // v3 -> v4: page_number, so a PDF result can open at the right page.
        self::ensure_column(
            $documents_table,
            'page_number',
            "ALTER TABLE {$documents_table} ADD COLUMN page_number SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_contents"
        );

        // v4 -> v5: page_top, so a PDF result can open at roughly the right
        // place on the page rather than always at its top. Zero means "no
        // offset", so existing rows keep the old behaviour until a reindex
        // fills them in — no data migration is needed.
        //
        // Must come after the page_number step: the ALTER positions the new
        // column AFTER page_number, so on a site coming from schema 3 that
        // column has to exist first.
        self::ensure_column(
            $documents_table,
            'page_top',
            "ALTER TABLE {$documents_table} ADD COLUMN page_top SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER page_number"
        );

        // v5 -> v6: doc_kind, distinguishing text, PDF and image rows.
        //
        // 'text' is the right default for every existing row. Images did not
        // exist before 0.9.9, and although rows already in the table may be
        // PDFs mislabelled as text, nothing reads doc_kind for PDFs — it
        // exists so image rows can be found at scoring time and told apart in
        // the widget. Labelling the PDFs correctly is worth doing but is not
        // urgent, so it is left to the next reindex rather than paid for with
        // an UPDATE across the whole table during an admin page load.
        self::ensure_column(
            $documents_table,
            'doc_kind',
            "ALTER TABLE {$documents_table} ADD COLUMN doc_kind VARCHAR(10) NOT NULL DEFAULT 'text' AFTER post_type"
        );

        // v5 -> v6: index is_contents.
        //
        // The contents-page demotion reads this column on every visitor
        // search, and it was the last unbounded query left on that path after
        // 0.9.7 cached the corpus statistics. Without a key it is a full scan
        // of the chunk table per query, which on a large PDF library becomes
        // the dominant cost of a search.
        self::ensure_index(
            $documents_table,
            'is_contents',
            "ALTER TABLE {$documents_table} ADD KEY is_contents (is_contents)"
        );
    }

    /**
     * Add an index if it is missing.
     *
     * Belt and braces for dbDelta, exactly as ensure_column() is. dbDelta does
     * add new keys, but it fails silently when it cannot parse a definition,
     * and a key that quietly did not appear is indistinguishable from one that
     * did until somebody profiles a slow query months later.
     *
     * @since 0.9.9
     *
     * @param string $table Full table name.
     * @param string $index Index name to check for.
     * @param string $sql   ALTER statement to run when it is absent.
     * @return void
     */
    private static function ensure_index( $table, $index, $sql ) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
                $table,
                $index
            )
        );

        if ( ! $exists ) {
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Caller-supplied DDL with an interpolated table name from $wpdb->prefix.
        }
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
            doc_kind VARCHAR(10) NOT NULL DEFAULT 'text',
            title VARCHAR(500) NOT NULL,
            excerpt VARCHAR(2000) DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            content_hash CHAR(32) NOT NULL,
            is_contents TINYINT(1) NOT NULL DEFAULT 0,
            page_number SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            page_top SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            indexed_at DATETIME NOT NULL,
            PRIMARY KEY  (doc_id),
            UNIQUE KEY post_chunk (post_id,chunk_index),
            KEY post_type (post_type),
            KEY doc_kind (doc_kind),
            KEY is_contents (is_contents)
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
            'index_images'       => false, // Images indexed on filename and alt text. Opt-in.
            'image_visibility'   => 'linked',  // Images a published, unprotected post owns or links to.
            'image_require_alt'  => true,      // Only index images somebody has described.
            'pdf_max_filesize_mb' => 20,   // Skip PDFs larger than this to protect memory.
            'pdf_visibility'     => 'linked',   // PDFs a published, unprotected post owns or links to.
            'query_log_retention_days' => 30,   // 0 = keep indefinitely.
            'contents_prefer_body'         => true, // A contents page never stands in for the chapter it lists.
            'pdf_link_position'            => true, // Scroll PDF links to the passage, not just the page.
            'pdf_link_zoom'                => 100,  // Zoom % used by a positioned PDF link.
            'intent_supplementary_results' => true, // Show search hits beneath an intent answer.
            'intent_supplementary_max'     => 3,    // How many, at most.
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