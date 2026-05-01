<?php
/**
 * Admin UI for chat-widget appearance.
 *
 * Adds a Tools → MBR ISA Appearance page where admins can pick a
 * colour preset and toggle the glassmorphism effect, with an
 * interactive live preview rendered using the actual widget CSS.
 *
 * Storage: writes to keys 'theme_preset' and 'theme_glass' inside the
 * existing mbr_isa_settings option, preserving all other widget settings.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Admin_Theme {

    const PAGE_SLUG    = 'mbr-isa-appearance';
    const OPTION_KEY   = 'mbr_isa_settings';
    const ACTION_SAVE  = 'mbr_isa_save_theme';
    const NOTICE_KEY   = 'mbr_isa_theme_notice';

    /**
     * Available presets. Order here drives the order of cards on the page.
     *
     * Each preset has:
     *   - label:    human-readable name
     *   - swatches: ordered list of hex colours used for the palette preview
     *               (kept in this PHP file so the admin doesn't need to parse
     *               chat-widget.css to render the cards)
     *   - kind:     'dark' or 'light' — for the small badge on each card
     */
    private function get_presets() {
        return [
            'mocha' => [
                'label'    => __( 'Mocha', 'mbr-isa' ),
                'kind'     => 'dark',
                'swatches' => [ '#1e1e2e', '#313244', '#cba6f7', '#89b4fa', '#cdd6f4' ],
            ],
            'slate-light' => [
                'label'    => __( 'Slate Light', 'mbr-isa' ),
                'kind'     => 'light',
                'swatches' => [ '#ffffff', '#dee2e6', '#6f42c1', '#0d6efd', '#212529' ],
            ],
            'ocean' => [
                'label'    => __( 'Ocean', 'mbr-isa' ),
                'kind'     => 'light',
                'swatches' => [ '#f0f7ff', '#b3d4ed', '#0096c7', '#00a896', '#0a3a5c' ],
            ],
            'sunset' => [
                'label'    => __( 'Sunset', 'mbr-isa' ),
                'kind'     => 'light',
                'swatches' => [ '#fff8f3', '#f5c4a3', '#d65d3a', '#f4845f', '#4a2818' ],
            ],
            'forest' => [
                'label'    => __( 'Forest', 'mbr-isa' ),
                'kind'     => 'dark',
                'swatches' => [ '#1a2820', '#2d4639', '#6ed079', '#88c1a0', '#d4e8dc' ],
            ],
        ];
    }

    public function register_hooks() {
        add_action( 'admin_menu',                          [ $this, 'register_page' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE,     [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts',               [ $this, 'maybe_enqueue_widget_css' ] );
    }

    public function register_page() {
        add_management_page(
            __( 'MBR ISA Appearance', 'mbr-isa' ),
            __( 'MBR ISA Appearance', 'mbr-isa' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Load the public chat-widget CSS on the appearance page only,
     * so the live preview renders with the same styles as the front end.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function maybe_enqueue_widget_css( $hook ) {
        // Hook for management pages is "tools_page_{slug}".
        if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'mbr-isa-chat-preview',
            MBR_ISA_URL . 'assets/css/chat-widget.css',
            [],
            MBR_ISA_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $current_preset = isset( $settings['theme_preset'] ) ? (string) $settings['theme_preset'] : 'mocha';
        if ( ! array_key_exists( $current_preset, $this->get_presets() ) ) {
            $current_preset = 'mocha';
        }
        $current_glass = ! empty( $settings['theme_glass'] );
        $notice        = $this->consume_notice();
        $presets       = $this->get_presets();

        ?>
        <div class="wrap mbr-isa-appearance-page">
            <h1><?php esc_html_e( 'MBR Intelligent Site Assistant — Appearance', 'mbr-isa' ); ?></h1>

            <p class="description" style="max-width:780px;">
                <?php esc_html_e( 'Choose a colour preset and optional glass effect for the public chat widget. The preview below updates as you change the controls — save to apply on the front end.', 'mbr-isa' ); ?>
            </p>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible" style="margin-top:1em;">
                    <p><?php echo wp_kses_post( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:1.5em;"><?php esc_html_e( 'Live preview', 'mbr-isa' ); ?></h2>
            <div class="mbr-isa-preview-stage" id="mbr-isa-preview-stage">
                <?php $this->render_preview_widget( $current_preset, $current_glass ); ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mbr-isa-theme-form" style="max-width:900px;margin-top:2em;">
                <?php wp_nonce_field( self::ACTION_SAVE ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">

                <h2><?php esc_html_e( 'Colour preset', 'mbr-isa' ); ?></h2>

                <div class="mbr-isa-preset-grid">
                    <?php foreach ( $presets as $slug => $info ) : ?>
                        <label class="mbr-isa-preset-card <?php echo $slug === $current_preset ? 'is-selected' : ''; ?>" data-preset="<?php echo esc_attr( $slug ); ?>">
                            <input type="radio" name="theme_preset" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $current_preset, $slug ); ?>>
                            <span class="mbr-isa-preset-swatches">
                                <?php foreach ( $info['swatches'] as $hex ) : ?>
                                    <span class="mbr-isa-preset-swatch" style="background:<?php echo esc_attr( $hex ); ?>;"></span>
                                <?php endforeach; ?>
                            </span>
                            <span class="mbr-isa-preset-meta">
                                <span class="mbr-isa-preset-name"><?php echo esc_html( $info['label'] ); ?></span>
                                <span class="mbr-isa-preset-kind mbr-isa-preset-kind--<?php echo esc_attr( $info['kind'] ); ?>"><?php echo esc_html( $info['kind'] ); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <h2 style="margin-top:2em;"><?php esc_html_e( 'Effects', 'mbr-isa' ); ?></h2>
                <label class="mbr-isa-glass-toggle">
                    <input type="checkbox" name="theme_glass" id="mbr-isa-glass-toggle" value="1" <?php checked( $current_glass ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Glassmorphism', 'mbr-isa' ); ?></strong>
                        <br>
                        <span class="description">
                            <?php esc_html_e( 'Translucent panel with a backdrop blur. Looks best over rich page backgrounds (hero images, gradients). Modern browsers only — older browsers fall back to a solid panel.', 'mbr-isa' ); ?>
                        </span>
                    </span>
                </label>

                <p style="margin-top:2em;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save appearance', 'mbr-isa' ); ?></button>
                </p>
            </form>
        </div>

        <style>
            .mbr-isa-appearance-page .mbr-isa-preview-stage {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
                background-size: 400% 400%;
                animation: mbr-isa-gradient-shift 18s ease infinite;
                border-radius: 8px;
                padding: 32px;
                min-height: 540px;
                display: flex;
                align-items: flex-start;
                justify-content: center;
                box-shadow: inset 0 0 60px rgba(0,0,0,0.15);
                max-width: 900px;
            }
            @keyframes mbr-isa-gradient-shift {
                0%   { background-position: 0% 50%; }
                50%  { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }
            .mbr-isa-appearance-page .mbr-isa-preview-stage .mbr-isa-chat--inline {
                width: 380px;
                max-width: 100%;
            }
            .mbr-isa-appearance-page .mbr-isa-preview-stage .mbr-isa-chat--inline .mbr-isa-chat__panel {
                height: 480px;
            }

            .mbr-isa-appearance-page .mbr-isa-preset-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 12px;
                max-width: 900px;
                margin-top: 8px;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-card {
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 12px;
                background: #fff;
                border: 2px solid #c3c4c7;
                border-radius: 6px;
                cursor: pointer;
                transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
                position: relative;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-card:hover {
                border-color: #2271b1;
                transform: translateY(-1px);
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            }
            .mbr-isa-appearance-page .mbr-isa-preset-card.is-selected {
                border-color: #2271b1;
                box-shadow: 0 0 0 2px rgba(34,113,177,0.18);
            }
            .mbr-isa-appearance-page .mbr-isa-preset-card input[type="radio"] {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-swatches {
                display: flex;
                gap: 0;
                height: 40px;
                border-radius: 4px;
                overflow: hidden;
                box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
            }
            .mbr-isa-appearance-page .mbr-isa-preset-swatch {
                flex: 1;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-meta {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-name {
                font-weight: 600;
                color: #1d2327;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-kind {
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 2px 6px;
                border-radius: 8px;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-kind--dark {
                background: #1d2327;
                color: #fff;
            }
            .mbr-isa-appearance-page .mbr-isa-preset-kind--light {
                background: #f0f0f1;
                color: #1d2327;
            }

            .mbr-isa-appearance-page .mbr-isa-glass-toggle {
                display: flex;
                gap: 12px;
                align-items: flex-start;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                padding: 14px 16px;
                max-width: 600px;
                cursor: pointer;
            }
            .mbr-isa-appearance-page .mbr-isa-glass-toggle input { margin-top: 4px; }
        </style>

        <script>
        (function () {
            var stage   = document.getElementById( 'mbr-isa-preview-stage' );
            var glassCb = document.getElementById( 'mbr-isa-glass-toggle' );
            var radios  = document.querySelectorAll( '.mbr-isa-appearance-page input[name="theme_preset"]' );
            var cards   = document.querySelectorAll( '.mbr-isa-preset-card' );
            if ( ! stage ) return;

            var presetSlugs = <?php echo wp_json_encode( array_keys( $presets ) ); ?>;

            function applyTheme() {
                var chat = stage.querySelector( '.mbr-isa-chat' );
                if ( ! chat ) return;

                // Remove existing theme classes.
                presetSlugs.forEach( function ( slug ) {
                    chat.classList.remove( 'mbr-isa-chat--theme-' + slug );
                } );
                chat.classList.remove( 'mbr-isa-chat--glass' );

                // Apply selected preset.
                var selected = document.querySelector( '.mbr-isa-appearance-page input[name="theme_preset"]:checked' );
                if ( selected ) {
                    chat.classList.add( 'mbr-isa-chat--theme-' + selected.value );
                }

                // Apply glass toggle.
                if ( glassCb && glassCb.checked ) {
                    chat.classList.add( 'mbr-isa-chat--glass' );
                }

                // Reflect selection on the preset cards.
                cards.forEach( function ( c ) {
                    var input = c.querySelector( 'input[type="radio"]' );
                    c.classList.toggle( 'is-selected', !! ( input && input.checked ) );
                } );
            }

            radios.forEach( function ( r ) { r.addEventListener( 'change', applyTheme ); } );
            cards.forEach( function ( c ) {
                c.addEventListener( 'click', function () {
                    var input = c.querySelector( 'input[type="radio"]' );
                    if ( input ) {
                        input.checked = true;
                        applyTheme();
                    }
                } );
            } );
            if ( glassCb ) glassCb.addEventListener( 'change', applyTheme );
        })();
        </script>
        <?php
    }

    /**
     * Render a static, non-interactive chat-widget preview using the same
     * CSS classes as the real widget. Inline mode so it doesn't fix to
     * the viewport.
     *
     * @param string $preset
     * @param bool   $glass
     */
    private function render_preview_widget( $preset, $glass ) {
        $classes = [
            'mbr-isa-chat',
            'mbr-isa-chat--inline',
            'mbr-isa-chat--inline',
            'mbr-isa-chat--theme-' . sanitize_html_class( $preset ),
        ];
        if ( $glass ) {
            $classes[] = 'mbr-isa-chat--glass';
        }
        $classes = array_unique( $classes );
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-mbr-isa-mode="inline" data-mbr-isa-position="inline">
            <div class="mbr-isa-chat__panel" role="dialog" aria-label="Preview">
                <header class="mbr-isa-chat__header">
                    <h3 class="mbr-isa-chat__title"><?php esc_html_e( 'Site Assistant', 'mbr-isa' ); ?></h3>
                </header>
                <div class="mbr-isa-chat__log" role="log">
                    <div class="mbr-isa-chat__turn mbr-isa-chat__turn--bot">
                        <div class="mbr-isa-chat__bubble-msg">
                            <?php esc_html_e( 'Hi! I can help you find things on this site. What are you looking for?', 'mbr-isa' ); ?>
                        </div>
                    </div>
                    <div class="mbr-isa-chat__turn mbr-isa-chat__turn--user">
                        <div class="mbr-isa-chat__bubble-msg">
                            <?php esc_html_e( 'How do I get in touch?', 'mbr-isa' ); ?>
                        </div>
                    </div>
                    <div class="mbr-isa-chat__turn mbr-isa-chat__turn--bot">
                        <div class="mbr-isa-chat__bubble-msg">
                            <?php esc_html_e( 'You can reach us via the contact form in the main menu, or use the link below.', 'mbr-isa' ); ?>
                        </div>
                    </div>
                </div>
                <form class="mbr-isa-chat__form" onsubmit="return false;">
                    <input type="text" class="mbr-isa-chat__input" placeholder="<?php esc_attr_e( 'Ask a question…', 'mbr-isa' ); ?>" disabled aria-label="Preview input">
                    <button type="button" class="mbr-isa-chat__send" disabled aria-label="Send (preview)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                            <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                        </svg>
                    </button>
                </form>
                <footer class="mbr-isa-chat__footer">
                    <span class="mbr-isa-chat__footer-text">
                        <?php esc_html_e( 'Preview mode', 'mbr-isa' ); ?>
                    </span>
                </footer>
            </div>
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

        $settings = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $preset = isset( $_POST['theme_preset'] ) ? sanitize_key( wp_unslash( $_POST['theme_preset'] ) ) : 'mocha';
        if ( ! array_key_exists( $preset, $this->get_presets() ) ) {
            $preset = 'mocha';
        }

        $settings['theme_preset'] = $preset;
        $settings['theme_glass']  = ! empty( $_POST['theme_glass'] ) ? 1 : 0;

        update_option( self::OPTION_KEY, $settings );

        $this->set_notice( 'success', __( 'Appearance settings saved.', 'mbr-isa' ) );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

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
