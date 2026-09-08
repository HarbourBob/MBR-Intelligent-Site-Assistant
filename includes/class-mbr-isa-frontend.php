<?php
/**
 * Frontend — public-facing chat widget.
 *
 * Responsibilities:
 *   - Register the [mbr_isa_chat] shortcode for inline embedding.
 *   - Render a floating-bubble widget in wp_footer when enabled in settings.
 *   - Enqueue chat-widget CSS and JS only when actually needed
 *     (shortcode present on the current page, or floating widget enabled).
 *   - Localise REST URL, nonce, and widget config into window.mbrAisa.
 *
 * The widget itself is progressive: if JS fails to load, the shortcode
 * renders a plain HTML form that falls back to a server-side search page.
 * (Plain-form fallback is wired up at the markup level but the receiving
 * page is out of scope for this session — the form just links to site
 * search for now.)
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Frontend {

    const SHORTCODE      = 'mbr_isa_chat';
    const HANDLE_STYLE   = 'mbr-isa-chat';
    const HANDLE_SCRIPT  = 'mbr-isa-chat';
    const JS_OBJECT_NAME = 'mbrAisa';

    /**
     * Have assets been enqueued for this request?
     *
     * Used to avoid double-enqueue when both the shortcode and the
     * floating widget are on the same page.
     *
     * @var bool
     */
    private $assets_enqueued = false;

    /**
     * Register all frontend hooks.
     *
     * Called by the orchestrator in MBR_ISA::register_hooks().
     *
     * @return void
     */
    public function register_hooks() {
        add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'wp_footer',          [ $this, 'maybe_render_floating_widget' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    /**
     * [mbr_isa_chat] shortcode callback.
     *
     * Supported attributes:
     *   title       — header text (overrides global setting)
     *   greeting    — opening message from the assistant
     *   placeholder — input placeholder text
     *   height      — CSS height for the panel (e.g. "500px")
     *
     * @param array|string $atts
     * @return string
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            [
                'title'       => '',
                'greeting'    => '',
                'placeholder' => '',
                'height'      => '',
            ],
            is_array( $atts ) ? $atts : [],
            self::SHORTCODE
        );

        // Ensure assets get loaded even if we missed the early check
        // (shortcode inside a widget area, template tag, etc.).
        $this->enqueue_assets();

        $settings = $this->get_widget_config();
        if ( '' !== $atts['title'] ) {
            $settings['title'] = $atts['title'];
        }
        if ( '' !== $atts['greeting'] ) {
            $settings['greeting'] = $atts['greeting'];
        }
        if ( '' !== $atts['placeholder'] ) {
            $settings['placeholder'] = $atts['placeholder'];
        }

        // Passed through as a custom property rather than a ready-made style
        // attribute: the glass sliders need custom properties on the same
        // element, and two style attributes on one tag is not markup.
        $style_vars = [];
        if ( '' !== $atts['height'] ) {
            $safe_height = preg_replace( '/[^0-9a-z%\.\-]/i', '', $atts['height'] );
            if ( '' !== $safe_height ) {
                $style_vars['--mbr-isa-panel-height'] = $safe_height;
            }
        }

        return $this->render_markup(
            [
                'mode'          => 'inline',
                'position'      => 'inline',
                'title'         => $settings['title'],
                'greeting'      => $settings['greeting'],
                'placeholder'   => $settings['placeholder'],
                'theme_preset'  => $settings['theme_preset'],
                'theme_glass'   => $settings['theme_glass'],
                'style_vars'    => $style_vars,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Floating widget
    // -------------------------------------------------------------------------

    /**
     * Render the floating bubble widget if enabled.
     *
     * Hooked to wp_footer.
     *
     * @return void
     */
    public function maybe_render_floating_widget() {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( empty( $settings['widget_enabled'] ) ) {
            return;
        }

        // Skip on feed, embed, favicon, robots, etc.
        if ( is_feed() || is_embed() || is_robots() ) {
            return;
        }

        // Make sure assets are loaded (floating widget was enabled after
        // wp_enqueue_scripts fired, or conditional check missed it).
        $this->enqueue_assets();

        $config = $this->get_widget_config();

        echo $this->render_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_markup() escapes every interpolation.
            [
                'mode'         => 'floating',
                'position'     => $config['position'],
                'title'        => $config['title'],
                'greeting'     => $config['greeting'],
                'placeholder'  => $config['placeholder'],
                'theme_preset' => $config['theme_preset'],
                'theme_glass'  => $config['theme_glass'],
                'extra_attrs'  => '',
            ]
        ); // Markup is internally escaped.
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    /**
     * Conditionally enqueue assets at the normal enqueue hook.
     *
     * Fired on wp_enqueue_scripts. Checks whether the floating widget
     * is enabled or the current singular post contains the shortcode.
     * Widget areas and template-embedded shortcodes are caught by the
     * shortcode callback enqueueing as a fallback.
     *
     * @return void
     */
    public function maybe_enqueue_assets() {
        $settings       = get_option( 'mbr_isa_settings', [] );
        $widget_enabled = ! empty( $settings['widget_enabled'] );

        $should_enqueue = $widget_enabled;

        if ( ! $should_enqueue && is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
                $should_enqueue = true;
            }
        }

        if ( $should_enqueue ) {
            $this->enqueue_assets();
        }
    }

    /**
     * Actually register + enqueue the widget assets.
     *
     * Idempotent — safe to call multiple times per request.
     *
     * @return void
     */
    public function enqueue_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE_STYLE,
            MBR_ISA_URL . 'assets/css/chat-widget.css',
            [],
            self::asset_version( 'assets/css/chat-widget.css' )
        );

        wp_enqueue_script(
            self::HANDLE_SCRIPT,
            MBR_ISA_URL . 'assets/js/chat-widget.js',
            [],
            self::asset_version( 'assets/js/chat-widget.js' ),
            true
        );

        wp_localize_script(
            self::HANDLE_SCRIPT,
            self::JS_OBJECT_NAME,
            $this->build_js_config()
        );

        $this->assets_enqueued = true;
    }

    /**
     * Cache-busting version for a bundled asset.
     *
     * The plugin version alone is not enough. A stylesheet can change without
     * the version changing — an appearance fix, or a hand-deployed patch —
     * and browsers, page caches and CDNs all key on the query string, so the
     * old file keeps being served to everybody who already has it. The file's
     * own modification time changes exactly when the file does, which is the
     * property actually wanted here.
     *
     * Falls back to the plugin version where the file cannot be stat'd, so a
     * host with a restrictive open_basedir degrades to the previous behaviour
     * rather than emitting no version at all.
     *
     * @param string $relative_path Path relative to the plugin directory.
     * @return string
     */
    // -------------------------------------------------------------------------
    // Glass effect strength
    // -------------------------------------------------------------------------

    /**
     * Bounds and defaults for the two glass sliders.
     *
     * The defaults are the values the stylesheet hard-coded before the sliders
     * existed, so a site that never opens the Appearance screen is unaffected.
     *
     * Blur is capped at 40px because backdrop-filter is composited per frame
     * and a large radius over a busy page is expensive on modest hardware —
     * the panel scrolls, so this is not a one-off cost. The opacity floor of
     * 10% keeps the panel visible as a panel: at zero it is a pane of glass
     * with text floating on it, which is unreadable over most photographs.
     *
     * @return array
     */
    public static function glass_bounds() {
        return [
            'blur'    => [ 'min' => 0,  'max' => 40,  'default' => 20, 'step' => 1 ],
            'opacity' => [ 'min' => 10, 'max' => 100, 'default' => 72, 'step' => 1 ],
        ];
    }

    /**
     * Clamp a stored or submitted glass value to its range.
     *
     * Opacity is held as a whole percentage rather than a float. A slider
     * produces integers, integers survive a round trip through an option and
     * back into CSS without any locale or float-formatting question, and the
     * conversion to an alpha happens in exactly one place below.
     *
     * @param string $which 'blur' or 'opacity'.
     * @param mixed  $value Raw value.
     * @return int
     */
    public static function sanitize_glass_value( $which, $value ) {
        $bounds = self::glass_bounds();
        if ( ! isset( $bounds[ $which ] ) ) {
            return 0;
        }
        $spec = $bounds[ $which ];

        if ( ! is_scalar( $value ) || '' === $value || ! is_numeric( $value ) ) {
            return $spec['default'];
        }

        return (int) max( $spec['min'], min( $spec['max'], (int) round( (float) $value ) ) );
    }

    /**
     * The two glass values as stored, with defaults applied.
     *
     * @return array{blur:int,opacity:int}
     */
    public static function get_glass_settings() {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        $bounds = self::glass_bounds();

        return [
            'blur'    => isset( $settings['theme_glass_blur'] )
                ? self::sanitize_glass_value( 'blur', $settings['theme_glass_blur'] )
                : $bounds['blur']['default'],
            'opacity' => isset( $settings['theme_glass_opacity'] )
                ? self::sanitize_glass_value( 'opacity', $settings['theme_glass_opacity'] )
                : $bounds['opacity']['default'],
        ];
    }

    /**
     * The custom properties the glass rules read, ready for a style attribute.
     *
     * number_format() rather than string interpolation of a float: a division
     * result rendered under a locale using a decimal comma would be silently
     * invalid CSS, and the rule would fall back to the stylesheet default with
     * nothing to show for it.
     *
     * @return array
     */
    public static function glass_style_vars() {
        $glass = self::get_glass_settings();

        return [
            '--mbr-isa-glass-blur'    => (int) $glass['blur'] . 'px',
            '--mbr-isa-glass-opacity' => number_format( $glass['opacity'] / 100, 2, '.', '' ),
        ];
    }

    private static function asset_version( $relative_path ) {
        $file = MBR_ISA_DIR . $relative_path;
        $mtime = is_readable( $file ) ? @filemtime( $file ) : false;

        return $mtime ? (string) $mtime : MBR_ISA_VERSION;
    }

    // -------------------------------------------------------------------------
    // Config assembly
    // -------------------------------------------------------------------------

    /**
     * Resolve widget display settings with sane defaults.
     *
     * @return array
     */
    private function get_widget_config() {
        $settings = get_option( 'mbr_isa_settings', [] );

        $defaults = [
            'title'        => __( 'Site Assistant', 'mbr-isa' ),
            'greeting'     => __( 'Hi! I can help you find things on this site. What are you looking for?', 'mbr-isa' ),
            'placeholder'  => __( 'Ask a question…', 'mbr-isa' ),
            'position'     => 'bottom-right',
            'theme_preset' => 'mocha',
            'theme_glass'  => false,
        ];

        $preset_raw = ! empty( $settings['theme_preset'] ) ? (string) $settings['theme_preset'] : $defaults['theme_preset'];
        if ( ! in_array( $preset_raw, [ 'mocha', 'slate-light', 'ocean', 'sunset', 'forest' ], true ) ) {
            $preset_raw = $defaults['theme_preset'];
        }

        $resolved = [
            'title'        => ! empty( $settings['widget_title'] )       ? (string) $settings['widget_title']       : $defaults['title'],
            'greeting'     => ! empty( $settings['widget_greeting'] )    ? (string) $settings['widget_greeting']    : $defaults['greeting'],
            'placeholder'  => ! empty( $settings['widget_placeholder'] ) ? (string) $settings['widget_placeholder'] : $defaults['placeholder'],
            'position'     => ! empty( $settings['widget_position'] )    ? (string) $settings['widget_position']    : $defaults['position'],
            'theme_preset' => $preset_raw,
            'theme_glass'  => ! empty( $settings['theme_glass'] ),
        ];

        // Validate position against known values.
        if ( ! in_array( $resolved['position'], [ 'bottom-right', 'bottom-left' ], true ) ) {
            $resolved['position'] = $defaults['position'];
        }

        return $resolved;
    }

    /**
     * Build the JS config object to expose to the client.
     *
     * @return array
     */
    private function build_js_config() {
        return [
            'restUrl'     => esc_url_raw( rest_url( 'mbr-isa/v1/ask' ) ),
            'feedbackUrl' => esc_url_raw( rest_url( 'mbr-isa/v1/feedback' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),

            /*
             * Whether the widget may keep the session ID in sessionStorage.
             *
             * PECR regulation 6 covers all storage on a visitor's device, not
             * only cookies, and the strictly-necessary exemption is narrow:
             * it covers storage needed to deliver the service the visitor
             * asked for. This identifier is not that — the assistant answers
             * questions perfectly well without it. It exists to group a
             * visitor's queries together in the query log so the site owner
             * can tune the index, which is analytics.
             *
             * So persistence follows the logging setting. With logging off
             * there is no log to group, storing anything on the visitor's
             * device buys nothing, and switching logging off now switches the
             * storage off with it.
             */
            'persistSession' => ! empty( get_option( 'mbr_isa_settings', [] )['log_queries'] ),

            'strings'     => [
                'sending'         => __( 'Searching…', 'mbr-isa' ),
                'networkErr'      => __( 'I could not reach the server. Please try again.', 'mbr-isa' ),
                'genericErr'      => __( 'Something went wrong. Please try again.', 'mbr-isa' ),
                'rateLimited'     => __( 'You are asking too quickly. Please wait a moment.', 'mbr-isa' ),
                'emptyInput'      => __( 'Please type a question first.', 'mbr-isa' ),
                'openLabel'       => __( 'Open site assistant', 'mbr-isa' ),
                'closeLabel'      => __( 'Close site assistant', 'mbr-isa' ),
                'sendLabel'       => __( 'Send question', 'mbr-isa' ),
                'youLabel'        => __( 'You', 'mbr-isa' ),
                'botLabel'        => __( 'Assistant', 'mbr-isa' ),
                'resultsLabel'    => __( 'Suggested results:', 'mbr-isa' ),
                'suggestLabel'    => __( 'You could also:', 'mbr-isa' ),
                'feedbackPrompt'  => __( 'Was this helpful?', 'mbr-isa' ),
                'feedbackYes'     => __( 'Yes, this helped', 'mbr-isa' ),
                'feedbackNo'      => __( 'No, this did not help', 'mbr-isa' ),
                'feedbackThanks'  => __( 'Thanks for the feedback.', 'mbr-isa' ),
                'lightboxLabel'   => __( 'Image preview', 'mbr-isa' ),
                'lightboxClose'   => __( 'Close image preview', 'mbr-isa' ),
                'lightboxViewOn'  => __( 'View the page this image appears on', 'mbr-isa' ),
                'lightboxOpen'    => __( 'Open larger preview', 'mbr-isa' ),
                'moreMentions'    => __( 'More mentions in this document', 'mbr-isa' ),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Markup
    // -------------------------------------------------------------------------

    /**
     * Render the chat widget HTML for a given mode.
     *
     * @param array $args {
     *     @type string $mode        'inline' or 'floating'
     *     @type string $position    'inline' | 'bottom-right' | 'bottom-left'
     *     @type string $title       Header text
     *     @type string $greeting    First assistant message
     *     @type string $placeholder Input placeholder
     *     @type string $extra_attrs Extra HTML attrs on the root element
     * }
     * @return string
     */
    private function render_markup( array $args ) {
        $mode         = $args['mode'];
        $position     = $args['position'];
        $title        = (string) $args['title'];
        $greeting     = (string) $args['greeting'];
        $placeholder  = (string) $args['placeholder'];
        $extra_attrs  = isset( $args['extra_attrs'] ) ? (string) $args['extra_attrs'] : '';
        $theme_preset = isset( $args['theme_preset'] ) ? (string) $args['theme_preset'] : 'mocha';
        $theme_glass  = ! empty( $args['theme_glass'] );
        $style_vars   = isset( $args['style_vars'] ) && is_array( $args['style_vars'] ) ? $args['style_vars'] : [];

        // Whitelist preset slug — defensive in case a future caller forgets
        // to validate. Falls back to mocha (the original Catppuccin default).
        if ( ! in_array( $theme_preset, [ 'mocha', 'slate-light', 'ocean', 'sunset', 'forest' ], true ) ) {
            $theme_preset = 'mocha';
        }

        $instance_id = 'mbr-isa-' . wp_generate_uuid4();

        $container_classes = [
            'mbr-isa-chat',
            'mbr-isa-chat--' . $mode,
            'mbr-isa-chat--' . $position,
            'mbr-isa-chat--theme-' . $theme_preset,
        ];
        if ( $theme_glass ) {
            $container_classes[] = 'mbr-isa-chat--glass';
            $style_vars += self::glass_style_vars();
        }
        $class_attr = implode( ' ', array_map( 'sanitize_html_class', $container_classes ) );

        // Built here rather than in the callers so there is one style attribute
        // on the container whatever combination of height and glass applies.
        $style_attr = '';
        if ( $style_vars ) {
            $declarations = [];
            foreach ( $style_vars as $property => $value ) {
                $declarations[] = $property . ': ' . $value . ';';
            }
            $style_attr = ' style="' . esc_attr( implode( ' ', $declarations ) ) . '"';
        }

        ob_start();
        ?>
        <div
            class="<?php echo esc_attr( $class_attr ); ?>"
            data-mbr-isa-mode="<?php echo esc_attr( $mode ); ?>"
            data-mbr-isa-position="<?php echo esc_attr( $position ); ?>"
            id="<?php echo esc_attr( $instance_id ); ?>"
            <?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built through esc_attr() above. ?>
            <?php echo $extra_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller-escaped; empty in every current caller. ?>
        >
            <?php if ( 'floating' === $mode ) : ?>
                <button
                    type="button"
                    class="mbr-isa-chat__bubble"
                    aria-label="<?php esc_attr_e( 'Open site assistant', 'mbr-isa' ); ?>"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr( $instance_id ); ?>-panel"
                >
                    <svg class="mbr-isa-chat__bubble-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M12 3c-4.97 0-9 3.58-9 8 0 1.94.78 3.72 2.09 5.12-.13 1.18-.55 2.79-1.56 4.04-.17.22-.02.54.25.52 1.9-.16 3.69-.74 4.95-1.54.99.31 2.06.48 3.17.48 4.97 0 9-3.58 9-8s-4.03-8-9-8zm-4 9a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm4 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm4 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/>
                    </svg>
                </button>
            <?php endif; ?>

            <div
                class="mbr-isa-chat__panel"
                id="<?php echo esc_attr( $instance_id ); ?>-panel"
                role="dialog"
                aria-label="<?php echo esc_attr( $title ); ?>"
                <?php if ( 'floating' === $mode ) : ?>aria-hidden="true"<?php endif; ?>
            >
                <header class="mbr-isa-chat__header">
                    <h3 class="mbr-isa-chat__title"><?php echo esc_html( $title ); ?></h3>
                    <?php if ( 'floating' === $mode ) : ?>
                        <button
                            type="button"
                            class="mbr-isa-chat__close"
                            aria-label="<?php esc_attr_e( 'Close site assistant', 'mbr-isa' ); ?>"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="18" height="18">
                                <path fill="currentColor" d="M18.3 5.71 12 12.01l-6.3-6.3L4.29 7.12 10.59 13.43 4.29 19.74l1.41 1.41L12 14.85l6.3 6.3 1.41-1.41-6.3-6.31 6.3-6.3z"/>
                            </svg>
                        </button>
                    <?php endif; ?>
                </header>

                <div class="mbr-isa-chat__log" role="log" aria-live="polite" aria-atomic="false">
                    <?php if ( '' !== $greeting ) : ?>
                        <div class="mbr-isa-chat__turn mbr-isa-chat__turn--bot">
                            <div class="mbr-isa-chat__bubble-msg"><?php echo esc_html( $greeting ); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <form class="mbr-isa-chat__form" method="post" action="#" novalidate>
                    <label class="mbr-isa-chat__sr-only" for="<?php echo esc_attr( $instance_id ); ?>-input">
                        <?php esc_html_e( 'Your question', 'mbr-isa' ); ?>
                    </label>
                    <input
                        type="text"
                        id="<?php echo esc_attr( $instance_id ); ?>-input"
                        class="mbr-isa-chat__input"
                        placeholder="<?php echo esc_attr( $placeholder ); ?>"
                        autocomplete="off"
                        maxlength="500"
                    >
                    <button
                        type="submit"
                        class="mbr-isa-chat__send"
                        aria-label="<?php esc_attr_e( 'Send question', 'mbr-isa' ); ?>"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="18" height="18">
                            <path fill="currentColor" d="M3.4 20.4 20.85 12.92a1 1 0 0 0 0-1.84L3.4 3.6a1 1 0 0 0-1.4 1.09L4 11l9 1-9 1-2 6.31a1 1 0 0 0 1.4 1.09z"/>
                        </svg>
                    </button>
                </form>

                <footer class="mbr-isa-chat__footer">
                    <span class="mbr-isa-chat__footer-text">
                        <?php
                        printf(
                            /* translators: %s: Little Web Shack link */
                            esc_html__( 'Powered by %s', 'mbr-isa' ),
                            '<a href="https://littlewebshack.com/mbr-intelligent-site-assistant/" target="_blank" rel="noopener noreferrer" class="mbr-isa-chat__footer-link">' . esc_html__( 'MBR Intelligent Site Assistant', 'mbr-isa' ) . '</a>'
                        );
                        ?>
                    </span>
                </footer>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
