/*
 * Appearance screen: live preview, preset switching and the glass sliders.
 *
 * Extracted from an inline <script> in 0.9.21, completing the move begun in
 * 0.9.20. The preset list and the glass bounds used to be printed into the
 * page with wp_json_encode(); they now arrive through wp_localize_script as
 * window.mbrIsaAppearance, so this file is static, cacheable, and survives a
 * strict admin Content-Security-Policy.
 */
( function () {
    'use strict';

    var cfg = window.mbrIsaAppearance || {};
    if ( ! cfg.glassBounds ) {
        return;
    }

    (function () {
        var stage   = document.getElementById( 'mbr-isa-preview-stage' );
        var glassCb = document.getElementById( 'mbr-isa-glass-toggle' );
        var radios  = document.querySelectorAll( '.mbr-isa-appearance-page input[name="theme_preset"]' );
        var cards   = document.querySelectorAll( '.mbr-isa-preset-card' );
        if ( ! stage ) return;
    
        var presetSlugs = cfg.presetSlugs;
        var glassBounds = cfg.glassBounds;
    
        var sliderBox  = document.getElementById( 'mbr-isa-glass-sliders' );
        var blurInput  = document.getElementById( 'mbr-isa-glass-blur' );
        var opacInput  = document.getElementById( 'mbr-isa-glass-opacity' );
        var blurOut    = document.getElementById( 'mbr-isa-glass-blur-out' );
        var opacOut    = document.getElementById( 'mbr-isa-glass-opacity-out' );
        var resetBtn   = document.getElementById( 'mbr-isa-glass-reset' );
    
        /*
         * The sliders write the same two custom properties the stylesheet
         * reads, onto the same element the front end writes them onto, so
         * the preview is the real rule rather than an approximation of it.
         */
        function applyGlassValues( chat ) {
            if ( ! blurInput || ! opacInput ) return;
    
            chat.style.setProperty( '--mbr-isa-glass-blur', blurInput.value + 'px' );
            chat.style.setProperty( '--mbr-isa-glass-opacity', ( opacInput.value / 100 ).toFixed( 2 ) );
    
            if ( blurOut ) blurOut.textContent = blurInput.value + 'px';
            if ( opacOut ) opacOut.textContent = opacInput.value + '%';
        }
    
        function syncSliderState() {
            var on = !! ( glassCb && glassCb.checked );
            if ( blurInput ) blurInput.disabled = ! on;
            if ( opacInput ) opacInput.disabled = ! on;
            if ( resetBtn )  resetBtn.disabled  = ! on;
            if ( sliderBox ) {
                if ( on ) {
                    sliderBox.removeAttribute( 'data-disabled' );
                } else {
                    sliderBox.setAttribute( 'data-disabled', '1' );
                }
            }
        }
    
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
    
            // Apply glass toggle and its two strength values.
            if ( glassCb && glassCb.checked ) {
                chat.classList.add( 'mbr-isa-chat--glass' );
            }
            applyGlassValues( chat );
            syncSliderState();
    
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
    
        // 'input' rather than 'change': the preview should track the thumb
        // as it is dragged, which is the whole point of a slider here.
        [ blurInput, opacInput ].forEach( function ( el ) {
            if ( el ) el.addEventListener( 'input', applyTheme );
        } );
    
        if ( resetBtn ) {
            resetBtn.addEventListener( 'click', function () {
                if ( blurInput ) blurInput.value = glassBounds.blur.default;
                if ( opacInput ) opacInput.value = glassBounds.opacity.default;
                applyTheme();
            } );
        }
    
        syncSliderState();
    })();
} )();
