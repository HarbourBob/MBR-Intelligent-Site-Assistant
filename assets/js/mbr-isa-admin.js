/*
 * Admin behaviour for MBR Intelligent Site Assistant.
 *
 * Extracted from inline <script> blocks in 0.9.20, along with the admin CSS.
 * Values that used to be printed into the page by PHP now arrive through
 * wp_localize_script as window.mbrIsaAdmin, so this file is static and
 * cacheable. This file covers the Diagnostics screen; the Appearance screen
 * has its own, mbr-isa-appearance.js, extracted in 0.9.21.
 */
( function () {
    'use strict';

    var cfg = window.mbrIsaAdmin || {};
    if ( ! cfg.i18n ) {
        return;
    }

    /* --- Full reindex button: spinner and disabled state ----------------- */
    (function () {
        var form   = document.getElementById('mbr-isa-reindex-form');
        var button = document.getElementById('mbr-isa-reindex-button');
        var status = document.getElementById('mbr-isa-reindex-status');
        if (!form || !button || !status) return;
        form.addEventListener('submit', function () {
            button.classList.add('is-loading');
            button.disabled = true;
            status.classList.add('is-working');
            status.textContent = cfg.i18n.reindexing;
        });
    })();

    /* --- Alt Text Audit: inline save, Decorative, Enter-to-save ---------- */
    ( function () {
        var ajaxUrl = cfg.ajaxUrl;
        var nonce   = cfg.altNonce;
        var saving  = cfg.i18n.saving;
        var saved   = cfg.i18n.saved;
        var failed  = cfg.i18n.failed;
    
        function save( row, value, decorative ) {
            var status = row.querySelector( '.mbr-isa-alt-status' );
            var id     = row.getAttribute( 'data-mbr-isa-att' );
            status.textContent = saving;
    
            var body = new URLSearchParams();
            body.append( 'action', 'mbr_isa_save_alt' );
            body.append( 'nonce', nonce );
            body.append( 'post_id', id );
            body.append( 'alt', value );
            if ( decorative ) {
                body.append( 'decorative', '1' );
            }
    
            fetch( ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            } ).then( function ( r ) {
                return r.json();
            } ).then( function ( json ) {
                if ( json && json.success ) {
                    status.textContent = saved;
                    row.style.opacity = '0.55';
                } else {
                    status.textContent = failed;
                }
            } ).catch( function () {
                status.textContent = failed;
            } );
        }
    
        document.querySelectorAll( '.mbr-isa-alt-table tr[data-mbr-isa-att]' ).forEach( function ( row ) {
            var input = row.querySelector( '.mbr-isa-alt-input' );
    
            row.querySelector( '.mbr-isa-alt-save' ).addEventListener( 'click', function () {
                save( row, input.value, false );
            } );
    
            var mark = row.querySelector( '.mbr-isa-alt-decorative' );
            if ( mark ) {
                mark.addEventListener( 'click', function () {
                    input.value = '';
                    save( row, '', true );
                } );
            }
    
            // Clears the marker without writing alt text, so the
            // image returns to the worklist undescribed.
            var unmark = row.querySelector( '.mbr-isa-alt-undecorative' );
            if ( unmark ) {
                unmark.addEventListener( 'click', function () {
                    input.value = '';
                    save( row, '', false );
                } );
            }
    
            // Enter saves, so a long list can be worked through
            // without reaching for the mouse.
            input.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'Enter' ) {
                    e.preventDefault();
                    save( row, input.value, false );
                }
            } );
        } );
    } )();
} )();
