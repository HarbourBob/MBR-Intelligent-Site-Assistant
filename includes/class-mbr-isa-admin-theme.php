<?php
/**
 * Admin UI for chat-widget appearance.
 *
 * Adds a MBR Site Assistant → MBR ISA Appearance page where admins can pick a
 * colour preset, toggle the glassmorphism effect and set its blur and
 * opacity, with an interactive live preview rendered using the actual
 * widget CSS.
 *
 * Storage: writes to keys 'theme_preset', 'theme_glass', 'theme_glass_blur'
 * and 'theme_glass_opacity' inside the existing mbr_isa_settings option,
 * preserving all other widget settings.
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
     * Hook suffix returned by add_submenu_page(). Stored so the enqueue
     * check can match exactly whatever WordPress generates, regardless of
     * how the parent menu's title is sanitised into the prefix.
     *
     * @var string
     */
    private $page_hook = '';

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
        $this->page_hook = add_submenu_page(
            MBR_ISA::PARENT_SLUG,
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
        // Compare against the hook captured from add_submenu_page() so we
        // remain correct regardless of the parent menu title's sanitised form.
        if ( empty( $this->page_hook ) || $this->page_hook !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'mbr-isa-chat-preview',
            MBR_ISA_URL . 'assets/css/chat-widget.css',
            [],
            MBR_ISA_VERSION
        );

        /*
         * The preview's behaviour, extracted from an inline <script> in
         * 0.9.21. The preset list and the glass bounds used to be printed
         * into the page with wp_json_encode(); they travel through
         * wp_localize_script() now, which is what made the script static
         * enough to move out of the markup at all.
         */
        wp_enqueue_script(
            'mbr-isa-appearance',
            MBR_ISA_URL . 'assets/js/mbr-isa-appearance.js',
            [],
            MBR_ISA_VERSION,
            true
        );

        wp_localize_script(
            'mbr-isa-appearance',
            'mbrIsaAppearance',
            [
                'presetSlugs' => array_keys( $this->get_presets() ),
                'glassBounds' => MBR_ISA_Frontend::glass_bounds(),
            ]
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
        $glass_bounds  = MBR_ISA_Frontend::glass_bounds();
        $glass_values  = MBR_ISA_Frontend::get_glass_settings();
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
                <?php $this->render_preview_widget( $current_preset, $current_glass, $glass_values ); ?>
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

                <div class="mbr-isa-glass-sliders" id="mbr-isa-glass-sliders" <?php echo $current_glass ? '' : 'data-disabled="1"'; ?>>

                    <div class="mbr-isa-slider-row">
                        <label for="mbr-isa-glass-blur">
                            <strong><?php esc_html_e( 'Blur', 'mbr-isa' ); ?></strong>
                            <output for="mbr-isa-glass-blur" id="mbr-isa-glass-blur-out"><?php echo esc_html( $glass_values['blur'] ); ?>px</output>
                        </label>
                        <input
                            type="range"
                            name="theme_glass_blur"
                            id="mbr-isa-glass-blur"
                            min="<?php echo esc_attr( $glass_bounds['blur']['min'] ); ?>"
                            max="<?php echo esc_attr( $glass_bounds['blur']['max'] ); ?>"
                            step="<?php echo esc_attr( $glass_bounds['blur']['step'] ); ?>"
                            value="<?php echo esc_attr( $glass_values['blur'] ); ?>"
                            <?php disabled( ! $current_glass ); ?>
                        >
                        <span class="description">
                            <?php esc_html_e( 'How far the panel blurs what is behind it. Higher costs more to composite while the page scrolls, so keep it modest on image-heavy pages.', 'mbr-isa' ); ?>
                        </span>
                    </div>

                    <div class="mbr-isa-slider-row">
                        <label for="mbr-isa-glass-opacity">
                            <strong><?php esc_html_e( 'Opacity', 'mbr-isa' ); ?></strong>
                            <output for="mbr-isa-glass-opacity" id="mbr-isa-glass-opacity-out"><?php echo esc_html( $glass_values['opacity'] ); ?>%</output>
                        </label>
                        <input
                            type="range"
                            name="theme_glass_opacity"
                            id="mbr-isa-glass-opacity"
                            min="<?php echo esc_attr( $glass_bounds['opacity']['min'] ); ?>"
                            max="<?php echo esc_attr( $glass_bounds['opacity']['max'] ); ?>"
                            step="<?php echo esc_attr( $glass_bounds['opacity']['step'] ); ?>"
                            value="<?php echo esc_attr( $glass_values['opacity'] ); ?>"
                            <?php disabled( ! $current_glass ); ?>
                        >
                        <span class="description">
                            <?php esc_html_e( 'How solid the panel is. Lower lets more of the page through — check your text is still readable over the busiest background it will sit on.', 'mbr-isa' ); ?>
                        </span>
                    </div>

                    <p class="description mbr-isa-slider-note">
                        <?php
                        printf(
                            /* translators: 1: default blur in px, 2: default opacity as a percentage. */
                            esc_html__( 'Both values scale the whole effect — the header, footer and message bubbles keep their proportions to the panel rather than all blurring equally. Defaults are %1$dpx and %2$d%%.', 'mbr-isa' ),
                            (int) $glass_bounds['blur']['default'],
                            (int) $glass_bounds['opacity']['default']
                        );
                        ?>
                        <button type="button" class="button-link" id="mbr-isa-glass-reset"><?php esc_html_e( 'Reset to defaults', 'mbr-isa' ); ?></button>
                    </p>
                </div>

                <p style="margin-top:2em;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save appearance', 'mbr-isa' ); ?></button>
                </p>
            </form>
        </div>


        <?php
    }

    /**
     * Render a static, non-interactive chat-widget preview using the same
     * CSS classes as the real widget. Inline mode so it doesn't fix to
     * the viewport.
     *
     * @param string $preset
     * @param bool   $glass
     * @param array  $glass_values Blur (px) and opacity (%) for the effect.
     */
    private function render_preview_widget( $preset, $glass, $glass_values = [] ) {
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

        // Seeded server-side so the preview is correct before any script runs
        // — the JS then keeps it in step as the sliders move.
        $blur    = isset( $glass_values['blur'] ) ? (int) $glass_values['blur'] : 20;
        $opacity = isset( $glass_values['opacity'] ) ? (int) $glass_values['opacity'] : 72;
        $style   = sprintf(
            '--mbr-isa-glass-blur: %dpx; --mbr-isa-glass-opacity: %s;',
            $blur,
            number_format( $opacity / 100, 2, '.', '' )
        );
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style ); ?>" data-mbr-isa-mode="inline" data-mbr-isa-position="inline">
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
                            <?php
                            // Hard-coded sample HTML in preview — escape rules
                            // don't apply because no user input is involved.
                            // Includes a sample <a> so the link styling is
                            // visible per theme.
                            echo wp_kses(
                                __( 'You can reach us via the <a href="#">contact form</a> in the main menu, or use the link below.', 'mbr-isa' ),
                                // No 'onclick'. The string is translatable, so the value reaching
			// wp_kses() at runtime comes from whatever .mo file is loaded —
			// allowing an event handler on it would let a malicious or
			// compromised translation run script in an administrator's session.
			[ 'a' => [ 'href' => true ] ]
                            );
                            ?>
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

        // Saved whether or not glass is currently on, so turning it off and
        // back on again returns the panel to how it was set rather than to
        // the defaults. Both are clamped to their published range — a range
        // input is trivially edited before submission.
        $settings['theme_glass_blur'] = MBR_ISA_Frontend::sanitize_glass_value(
            'blur',
            isset( $_POST['theme_glass_blur'] ) ? wp_unslash( $_POST['theme_glass_blur'] ) : null
        );
        $settings['theme_glass_opacity'] = MBR_ISA_Frontend::sanitize_glass_value(
            'opacity',
            isset( $_POST['theme_glass_opacity'] ) ? wp_unslash( $_POST['theme_glass_opacity'] ) : null
        );

        update_option( self::OPTION_KEY, $settings );

        $this->set_notice(
            'success',
            __( 'Appearance settings saved. <strong>If your changes don\'t appear on the front end immediately</strong>, clear any caching plugins (WP Rocket, SiteGround Optimizer, LiteSpeed, W3 Total Cache, etc.) and any CDN cache, then hard-refresh the front end (Ctrl+Shift+R or Cmd+Shift+R).', 'mbr-isa' )
        );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
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
