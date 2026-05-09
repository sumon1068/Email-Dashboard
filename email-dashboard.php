<?php
/**
 * Plugin Name: Email Dashboard
 * Plugin URI: https://wppassion.com/plugins/email-dashboard/
 * Description: Send HTML emails directly from the WordPress admin dashboard. Supports rich text formatting, recipient names, and a full sent email log.
 * Version:     1.0.0
 * Author: WP Passion
 * Author URI: https://wppassion.com/
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: email-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPPED_SCHEMA_VERSION', '1.0.0' );

// ---------------------------------------------------------------------------
// 1. Create / upgrade custom tables on activation
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, 'wpped_create_tables' );

function wpped_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$wpdb->prefix}wpped_settings (
        option_name  VARCHAR(191)  NOT NULL,
        option_value LONGTEXT      NOT NULL DEFAULT '',
        PRIMARY KEY  (option_name)
    ) {$charset};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}wpped_email_log (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sent_at     DATETIME            NOT NULL,
        from_addr   VARCHAR(255)        NOT NULL DEFAULT '',
        to_addr     VARCHAR(255)        NOT NULL DEFAULT '',
        subject     VARCHAR(500)        NOT NULL DEFAULT '',
        body        LONGTEXT            NOT NULL,
        status      VARCHAR(20)         NOT NULL DEFAULT 'sent',
        PRIMARY KEY (id)
    ) {$charset};" );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'wpped_schema_version' ) !== WPPED_SCHEMA_VERSION ) {
        wpped_create_tables();
        update_option( 'wpped_schema_version', WPPED_SCHEMA_VERSION );
    }
} );

register_activation_hook( __FILE__, function () {
    set_transient( 'wpped_activation_redirect', true, 30 );
} );

add_action( 'admin_init', function () {
    if ( get_transient( 'wpped_activation_redirect' ) ) {
        delete_transient( 'wpped_activation_redirect' );
        wp_safe_redirect( admin_url( 'admin.php?page=email-dashboard' ) );
        exit;
    }
} );

// ---------------------------------------------------------------------------
// 2. Custom settings helpers
// ---------------------------------------------------------------------------
function wpped_get_setting( $key, $default = '' ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->prefix}wpped_settings WHERE option_name = %s",
            $key
        )
    );
    return $row ? $row->option_value : $default;
}

function wpped_update_setting( $key, $value ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->replace(
        $wpdb->prefix . 'wpped_settings',
        [ 'option_name' => $key, 'option_value' => $value ],
        [ '%s', '%s' ]
    );
}

// ---------------------------------------------------------------------------
// 3. Admin menu
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_menu_page(
        'Email Dashboard',
        'Email Dashboard',
        'manage_options',
        'email-dashboard',
        'wpped_render_page',
        'dashicons-email-alt',
        80
    );
} );

// ---------------------------------------------------------------------------
// 4. Parse "Name <email>" or plain "email"
//    Returns [ display_name, email_address ]
// ---------------------------------------------------------------------------
function wpped_parse_address_raw( $raw ) {
    $input = trim( wp_unslash( $raw ) );

    if ( preg_match( '/^(.+?)\s*<([^>]+@[^>]+)>\s*$/u', $input, $m ) ) {
        $name  = trim( wp_check_invalid_utf8( $m[1] ) );
        $email = sanitize_email( trim( $m[2] ) );
        if ( is_email( $email ) ) {
            return [ $name, $email ];
        }
    }

    $email = sanitize_email( $input );
    return [ '', $email ];
}

// ---------------------------------------------------------------------------
// 5. Insert a row into the email log
// ---------------------------------------------------------------------------
function wpped_log_email( $from_name, $from_email, $to_name, $to_email, $subject, $body, $status = 'sent' ) {
    global $wpdb;

    $from_addr = ( $from_name !== '' ) ? "{$from_name} <{$from_email}>" : $from_email;
    $to_addr   = ( $to_name   !== '' ) ? "{$to_name} <{$to_email}>"     : $to_email;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->insert(
        $wpdb->prefix . 'wpped_email_log',
        [
            'sent_at'   => current_time( 'mysql' ),
            'from_addr' => $from_addr,
            'to_addr'   => $to_addr,
            'subject'   => $subject,
            'body'      => $body,
            'status'    => $status,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s' ]
    );

    // Keep only the latest 200 entries
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}wpped_email_log
         WHERE id NOT IN (
             SELECT id FROM (
                 SELECT id FROM {$wpdb->prefix}wpped_email_log ORDER BY id DESC LIMIT 200
             ) AS keep
         )"
    );
}

// ---------------------------------------------------------------------------
// 6. Handle log delete / clear actions
// ---------------------------------------------------------------------------
function wpped_handle_log_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if (
        isset( $_GET['wpped_action'], $_GET['wpped_log_id'], $_GET['wpped_nonce'] ) &&
        $_GET['wpped_action'] === 'delete_log' &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wpped_nonce'] ) ), 'wpped_delete_log_' . absint( $_GET['wpped_log_id'] ) )
    ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $wpdb->prefix . 'wpped_email_log',
            [ 'id' => absint( $_GET['wpped_log_id'] ) ],
            [ '%d' ]
        );
        wp_safe_redirect( admin_url( 'admin.php?page=email-dashboard&wpped_tab=log&wpped_deleted=1' ) );
        exit;
    }

    if (
        isset( $_POST['wpped_clear_log'], $_POST['wpped_clear_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpped_clear_nonce'] ) ), 'wpped_clear_log' )
    ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpped_email_log" );
        wp_safe_redirect( admin_url( 'admin.php?page=email-dashboard&wpped_tab=log&wpped_cleared=1' ) );
        exit;
    }
}
add_action( 'admin_init', 'wpped_handle_log_actions' );

// ---------------------------------------------------------------------------
// 7. Force TinyMCE to use <p> tags
// ---------------------------------------------------------------------------
add_filter( 'tiny_mce_before_init', function( $settings, $editor_id ) {
    if ( $editor_id === 'wpped_body' ) {
        $settings['forced_root_block']       = 'p';
        $settings['force_p_newlines']        = true;
        $settings['remove_linebreaks']       = true;
        $settings['convert_newlines_to_brs'] = false;
    }
    return $settings;
}, 10, 2 );

// ---------------------------------------------------------------------------
// 8. Process TinyMCE body into clean HTML paragraphs
// ---------------------------------------------------------------------------
function wpped_process_body( $raw ) {
    $body = wp_kses_post( wp_unslash( $raw ) );

    // If TinyMCE produced proper <p> tags, use as-is
    if ( preg_match( '/<p[\s>]/i', $body ) ) {
        return $body;
    }

    // Otherwise convert <div> blocks to paragraphs via wpautop
    $body = preg_replace( '/<\/div>\s*(<div[^>]*>)?/i', "\n\n", $body );
    $body = preg_replace( '/<div[^>]*>/i', '', $body );
    $body = wpautop( trim( $body ) );

    return $body;
}

// ---------------------------------------------------------------------------
// 9. JS in admin_footer
// ---------------------------------------------------------------------------
add_action( 'admin_footer', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_email-dashboard' ) return;
    ?>
    <script>
    (function($){

        // Sync TinyMCE before submit
        $('form').on('submit', function(){
            if ( typeof tinymce !== 'undefined' && tinymce.get('wpped_body') ) {
                tinymce.get('wpped_body').save();
            }
            return true;
        });

        // ── Log entry modal ──────────────────────────────────────────────────
        var $overlay = $('#wpped-modal-overlay');
        var $iframe  = $('#wpped-modal-iframe');
        var wppedCurrentEntry = {};
        <?php $compose_base = admin_url( 'admin.php?page=email-dashboard&wpped_tab=compose' ); ?>
        var wppedComposeBase = '<?php echo esc_js( $compose_base ); ?>';

        function wppedOpenModal( data ) {
            wppedCurrentEntry = data;
            var statusColor = data.status === 'sent' ? '#0a7227' : '#b32d2e';
            var statusBg    = data.status === 'sent' ? '#edfaef' : '#fce8e8';

            $('#wpped-m-date').text( data.date || '—' );
            $('#wpped-m-status').html(
                '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:700;text-transform:uppercase;color:' +
                statusColor + ';background:' + statusBg + ';">' +
                $('<span>').text( data.status ).html() + '</span>'
            );
            $('#wpped-m-from').text( data.from || '—' );
            $('#wpped-m-to').text( data.to || '—' );
            $('#wpped-m-subject').text( data.subject || '—' );

            var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
            iframeDoc.open();
            iframeDoc.write(
                '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
                '<style>body{font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;padding:12px 16px;margin:0;}' +
                'p{margin:0 0 1em 0;}p:last-child{margin-bottom:0;}</style>' +
                '</head><body>' + ( data.body || '<em style="color:#999">No body stored.</em>' ) + '</body></html>'
            );
            iframeDoc.close();

            $iframe.css( 'min-height', '120px' );
            setTimeout( function(){
                try {
                    var h = $iframe[0].contentDocument.body.scrollHeight;
                    $iframe.css( 'height', Math.max( h + 24, 120 ) + 'px' );
                } catch(e) {}
            }, 80 );

            $overlay.addClass( 'wpped-open' );
            $( '#wpped-modal-close' ).focus();
        }

        function wppedCloseModal() {
            $overlay.removeClass( 'wpped-open' );
            var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
            iframeDoc.open(); iframeDoc.write(''); iframeDoc.close();
        }

        $( document ).on( 'click', '.wpped-view-btn', function(){
            try {
                wppedOpenModal( JSON.parse( $(this).attr('data-entry') ) );
            } catch(e) {
                alert( 'Could not load entry data.' );
            }
        });

        $( '#wpped-modal-close, #wpped-modal-close-btn' ).on( 'click', wppedCloseModal );
        $overlay.on( 'click', function(e){ if ( e.target === this ) wppedCloseModal(); });
        $( document ).on( 'keydown', function(e){ if ( e.key === 'Escape' ) wppedCloseModal(); });

        $( '#wpped-send-again-btn' ).on( 'click', function(){
            var d = wppedCurrentEntry;
            try {
                sessionStorage.setItem( 'wppedPrefill', JSON.stringify({
                    to:      d.to      || '',
                    subject: d.subject || ''
                }));
            } catch(e) {}
            window.location.href = wppedComposeBase;
        });

        // On compose tab: apply prefill from log
        (function(){
            try {
                var raw = sessionStorage.getItem( 'wppedPrefill' );
                if ( ! raw ) return;
                var d = JSON.parse( raw );
                sessionStorage.removeItem( 'wppedPrefill' );
                if ( d.to )      { $( '#wpped_to' ).val( d.to ); }
                if ( d.subject ) { $( '#wpped_subject' ).val( d.subject ); }
                if ( d.to || d.subject ) {
                    $( '#wpped-prefill-notice' ).show();
                }
            } catch(e) {}
        })();

        // Client-side log search
        $( '#wpped-log-search' ).on( 'input', function(){
            var q = $(this).val().toLowerCase().trim();
            $( '#wpped-log-tbody tr' ).each( function(){
                if ( ! q ) { $(this).show(); return; }
                $(this).toggle( $(this).text().toLowerCase().indexOf(q) !== -1 );
            });
            var visible = $( '#wpped-log-tbody tr:visible' ).length;
            $( '#wpped-log-count' ).text( q ? visible + ' result(s)' : '' );
        });

    })(jQuery);
    </script>

    <style>
        .wpped-log-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .wpped-log-table { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; min-width:480px; }
        .wpped-log-table th, .wpped-log-table td {
            padding:8px 10px; text-align:left; vertical-align:middle;
            border-bottom:1px solid #e5e7eb;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        }
        .wpped-log-table thead th { background:#f6f7f7; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; color:#555; }
        .wpped-log-table tbody tr:hover { background:#f9f9f9; }
        .wpped-log-table .col-date    { width:130px; }
        .wpped-log-table .col-status  { width:60px; }
        .wpped-log-table .col-to      { width:20%; }
        .wpped-log-table .col-subject { width:22%; }
        .wpped-log-table .col-body    { width:auto; color:#777; font-size:12px; }
        .wpped-log-table .col-actions { width:105px; white-space:nowrap; }
        .wpped-status-badge { display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:700;text-transform:uppercase; }
        .wpped-actions { display:flex; gap:4px; }
        .wpped-search-wrap { display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        #wpped-log-search { width:240px; max-width:100%; }
        #wpped-log-count { font-size:12px; color:#888; }

        #wpped-modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
            z-index:100000; align-items:center; justify-content:center;
        }
        #wpped-modal-overlay.wpped-open { display:flex; }
        #wpped-modal {
            background:#fff; border-radius:6px; width:92%; max-width:720px;
            max-height:90vh; display:flex; flex-direction:column;
            box-shadow:0 8px 40px rgba(0,0,0,.3);
        }
        #wpped-modal-header {
            display:flex; align-items:center; justify-content:space-between;
            padding:16px 20px; border-bottom:1px solid #e5e7eb;
        }
        #wpped-modal-header h2 { margin:0; font-size:15px; }
        #wpped-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#888; line-height:1; padding:0 4px; }
        #wpped-modal-close:hover { color:#222; }
        #wpped-modal-body { overflow-y:auto; padding:20px; flex:1; }
        .wpped-modal-field { margin-bottom:14px; }
        .wpped-modal-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#666; margin-bottom:4px; }
        .wpped-modal-value { font-size:13px; color:#222; word-break:break-word; background:#f6f7f7; border-radius:4px; padding:8px 10px; }
        #wpped-modal-iframe-wrap { border:1px solid #ddd; border-radius:4px; overflow:hidden; margin-top:4px; }
        #wpped-modal-iframe { width:100%; min-height:120px; border:none; display:block; }
        #wpped-modal-footer { padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:8px; flex-shrink:0; }

        @media (max-width:600px) {
            #wpped-modal { width:98%; max-height:95vh; }
            #wpped-modal-footer { flex-direction:column; }
            #wpped-modal-footer .button { text-align:center; }
        }
    </style>
    <?php
} );

// ---------------------------------------------------------------------------
// 10. Handle form submission
// ---------------------------------------------------------------------------
function wpped_handle_submission() {
    $result = [ 'sent' => false, 'message' => '', 'debug' => '' ];

    if ( ! isset( $_POST['wpped_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpped_nonce'] ) ), 'wpped_send_email' ) ) {
        $result['message'] = 'Security check failed. Please refresh and try again.';
        return $result;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        $result['message'] = 'You do not have permission to send emails.';
        return $result;
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $from_raw = wp_unslash( $_POST['wpped_from'] ?? '' );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $to_raw   = wp_unslash( $_POST['wpped_to']   ?? '' );
    $subject  = sanitize_text_field( wp_unslash( $_POST['wpped_subject'] ?? '' ) );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $body     = wpped_process_body( wp_unslash( $_POST['wpped_body'] ?? '' ) );

    list( $from_name, $from_email ) = wpped_parse_address_raw( $from_raw );
    list( $to_name,   $to_email   ) = wpped_parse_address_raw( $to_raw );

    if ( ! is_email( $from_email ) ) {
        $result['message'] = 'Please enter a valid From email address.';
        return $result;
    }
    if ( ! is_email( $to_email ) ) {
        $result['message'] = 'Please enter a valid To address. Accepted formats: email@example.com or Name <email@example.com>';
        return $result;
    }
    if ( empty( $subject ) ) {
        $result['message'] = 'Subject cannot be empty.';
        return $result;
    }
    if ( empty( trim( wp_strip_all_tags( $body ) ) ) ) {
        $result['message'] = 'Message body cannot be empty.';
        return $result;
    }

    wpped_update_setting( 'from_address', wp_unslash( $from_raw ) );

    $set_from_email = function() use ( $from_email ) { return $from_email; };
    $set_from_name  = function() use ( $from_name )  { return $from_name; };

    add_filter( 'wp_mail_from',      $set_from_email, 99 );
    add_filter( 'wp_mail_from_name', $set_from_name,  99 );

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    // Hook into PHPMailer directly to set the To address with unicode display name.
    // wp_mail()'s $to argument only accepts plain email — display names with non-ASCII
    // characters (Bengali, Arabic, CJK etc.) must be set via PHPMailer's addAddress()
    // which handles RFC 2047 encoding internally.
    $wpped_to_name  = $to_name;
    $wpped_to_email = $to_email;
    $phpmailer_hook = function( $phpmailer ) use ( $wpped_to_name, $wpped_to_email ) {
        // Clear the plain To address wp_mail() added, then re-add with display name
        $phpmailer->clearAddresses();
        $phpmailer->addAddress( $wpped_to_email, $wpped_to_name );
    };
    add_action( 'phpmailer_init', $phpmailer_hook );

    $email_style = '<style>
        body { font-family: Arial, sans-serif; font-size: 15px; color: #222; line-height: 1.6; }
        p { margin: 0 0 1em 0; }
        p:last-child { margin-bottom: 0; }
        strong, b { font-weight: bold; }
        em, i { font-style: italic; }
        ul, ol { margin: 0 0 1em 1.5em; padding: 0; }
        a { color: #2271b1; }
        blockquote { border-left: 3px solid #ccc; margin: 0 0 1em 0; padding-left: 1em; color: #555; }
    </style>';

    $html_body = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $email_style . '</head><body>' . $body . '</body></html>';

    add_action( 'wp_mail_failed', function( $error ) use ( &$result ) {
        $result['debug'] = $error->get_error_message();
    } );

    $sent = wp_mail( $to_email, $subject, $html_body, $headers );

    remove_filter( 'wp_mail_from',      $set_from_email, 99 );
    remove_filter( 'wp_mail_from_name', $set_from_name,  99 );
    remove_action( 'phpmailer_init', $phpmailer_hook );

    wpped_log_email( $from_name, $from_email, $to_name, $to_email, $subject, $body, $sent ? 'sent' : 'failed' );

    if ( $sent ) {
        $result['sent']    = true;
        $result['message'] = '✓ Email sent successfully!';
    } else {
        global $phpmailer;
        $smtp_err     = ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : '';
        $error_detail = $result['debug'] ?: $smtp_err ?: 'No error details available — check your server mail logs.';
        $result['message'] = 'Failed to send email. Error: ' . $error_detail;
    }

    return $result;
}

// ---------------------------------------------------------------------------
// 11. Render the email log tab
// ---------------------------------------------------------------------------
function wpped_render_log_tab() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM `' . esc_sql( $wpdb->prefix . 'wpped_email_log' ) . '` ORDER BY sent_at DESC LIMIT %d',
            200
        )
    );

    if ( isset( $_GET['wpped_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>Log entry deleted.</p></div>';
    }
    if ( isset( $_GET['wpped_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>All log entries cleared.</p></div>';
    }
    ?>

    <!-- Modal -->
    <div id="wpped-modal-overlay" role="dialog" aria-modal="true">
        <div id="wpped-modal">
            <div id="wpped-modal-header">
                <h2>Email Details</h2>
                <button id="wpped-modal-close" aria-label="Close">&times;</button>
            </div>
            <div id="wpped-modal-body">
                <div class="wpped-modal-field"><div class="wpped-modal-label">Date &amp; Time</div><div class="wpped-modal-value" id="wpped-m-date"></div></div>
                <div class="wpped-modal-field"><div class="wpped-modal-label">Status</div><div class="wpped-modal-value" id="wpped-m-status"></div></div>
                <div class="wpped-modal-field"><div class="wpped-modal-label">From</div><div class="wpped-modal-value" id="wpped-m-from"></div></div>
                <div class="wpped-modal-field"><div class="wpped-modal-label">To</div><div class="wpped-modal-value" id="wpped-m-to"></div></div>
                <div class="wpped-modal-field"><div class="wpped-modal-label">Subject</div><div class="wpped-modal-value" id="wpped-m-subject"></div></div>
                <div class="wpped-modal-field">
                    <div class="wpped-modal-label">Email Body</div>
                    <div id="wpped-modal-iframe-wrap">
                        <iframe id="wpped-modal-iframe" sandbox="allow-same-origin" title="Email body"></iframe>
                    </div>
                </div>
            </div>
            <div id="wpped-modal-footer">
                <button type="button" id="wpped-modal-close-btn" class="button button-secondary">Close</button>
                <button type="button" id="wpped-send-again-btn" class="button button-primary">&#8617; Send Again</button>
            </div>
        </div>
    </div>

    <div style="margin-top:16px;">
        <?php if ( ! empty( $logs ) ) : ?>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                <div class="wpped-search-wrap" style="margin-bottom:0;">
                    <input type="search" id="wpped-log-search" class="regular-text"
                        placeholder="Search by recipient, subject or body…"
                        style="width:260px;max-width:100%;" />
                    <span id="wpped-log-count"></span>
                </div>
                <form method="post" onsubmit="return confirm('Delete ALL log entries? This cannot be undone.');" style="margin:0;">
                    <?php wp_nonce_field( 'wpped_clear_log', 'wpped_clear_nonce' ); ?>
                    <input type="submit" name="wpped_clear_log" class="button button-secondary"
                        value="Clear All Logs" style="color:#b32d2e;border-color:#b32d2e;" />
                </form>
            </div>
        <?php endif; ?>

        <?php if ( empty( $logs ) ) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:32px;text-align:center;color:#888;">
                No emails logged yet. Sent emails will appear here.
            </div>
        <?php else : ?>
            <div class="wpped-log-wrap">
                <table class="wpped-log-table widefat striped">
                    <thead>
                        <tr>
                            <th class="col-date">Date &amp; Time</th>
                            <th class="col-status">Status</th>
                            <th class="col-to">To</th>
                            <th class="col-subject">Subject</th>
                            <th class="col-body">Body preview</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="wpped-log-tbody">
                        <?php foreach ( $logs as $log ) :
                            $delete_nonce = wp_create_nonce( 'wpped_delete_log_' . absint( $log->id ) );
                            $delete_url   = admin_url(
                                'admin.php?page=email-dashboard&wpped_tab=log'
                                . '&wpped_action=delete_log'
                                . '&wpped_log_id=' . absint( $log->id )
                                . '&wpped_nonce=' . $delete_nonce
                            );
                            $status_color = ( $log->status === 'sent' ) ? '#0a7227' : '#b32d2e';
                            $status_bg    = ( $log->status === 'sent' ) ? '#edfaef' : '#fce8e8';
                            $body_plain   = wp_strip_all_tags( $log->body );
                            $body_preview = mb_strlen( $body_plain ) > 45
                                ? mb_substr( $body_plain, 0, 45 ) . '…'
                                : $body_plain;
                            $modal_data = json_encode( [
                                'date'    => $log->sent_at,
                                'status'  => $log->status,
                                'from'    => $log->from_addr,
                                'to'      => $log->to_addr,
                                'subject' => $log->subject,
                                'body'    => $log->body,
                            ], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS );
                        ?>
                        <tr>
                            <td class="col-date" style="font-size:12px;"><?php echo esc_html( $log->sent_at ); ?></td>
                            <td class="col-status">
                                <span class="wpped-status-badge" style="color:<?php echo esc_attr($status_color); ?>;background:<?php echo esc_attr($status_bg); ?>;">
                                    <?php echo esc_html( $log->status ); ?>
                                </span>
                            </td>
                            <td class="col-to" style="font-size:12px;" title="<?php echo esc_attr($log->to_addr); ?>"><?php echo esc_html($log->to_addr); ?></td>
                            <td class="col-subject" style="font-size:12px;" title="<?php echo esc_attr($log->subject); ?>"><?php echo esc_html($log->subject); ?></td>
                            <td class="col-body" title="<?php echo esc_attr($body_plain); ?>"><?php echo esc_html($body_preview); ?></td>
                            <td class="col-actions">
                                <div class="wpped-actions">
                                    <button type="button" class="button button-small wpped-view-btn"
                                        data-entry="<?php echo esc_attr($modal_data); ?>">View</button>
                                    <a href="<?php echo esc_url($delete_url); ?>"
                                       class="button button-small" style="color:#b32d2e;"
                                       onclick="return confirm('Delete this log entry?');">Delete</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:#888;font-size:12px;margin-top:8px;">Showing last 200 entries. Hover any truncated cell to see full text.</p>
        <?php endif; ?>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 12. Render page (with tabs)
// ---------------------------------------------------------------------------
function wpped_render_page() {
    $active_tab = isset( $_GET['wpped_tab'] ) ? sanitize_key( $_GET['wpped_tab'] ) : 'compose';

    $result = null;
    if ( $active_tab === 'compose' && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wpped_send'] ) ) {
        $result = wpped_handle_submission();
    }

    $post_verified = isset( $_POST['wpped_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpped_nonce'] ) ), 'wpped_send_email' );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $from       = $post_verified && isset( $_POST['wpped_from'] )    ? wp_unslash( $_POST['wpped_from'] )    : wpped_get_setting( 'from_address' );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $to         = $post_verified && isset( $_POST['wpped_to'] )      ? wp_unslash( $_POST['wpped_to'] )      : '';
    $subject    = $post_verified && isset( $_POST['wpped_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wpped_subject'] ) ) : '';
    $body       = $post_verified && isset( $_POST['wpped_body'] )    ? wp_kses_post( wp_unslash( $_POST['wpped_body'] ) ) : '';

    $compose_url = admin_url( 'admin.php?page=email-dashboard&wpped_tab=compose' );
    $log_url     = admin_url( 'admin.php?page=email-dashboard&wpped_tab=log' );
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-email-alt" style="font-size:28px;vertical-align:middle;margin-right:6px;"></span>
            Email Dashboard
        </h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:0;">
            <a href="<?php echo esc_url( $compose_url ); ?>" class="nav-tab <?php echo $active_tab === 'compose' ? 'nav-tab-active' : ''; ?>">Compose</a>
            <a href="<?php echo esc_url( $log_url ); ?>"     class="nav-tab <?php echo $active_tab === 'log'     ? 'nav-tab-active' : ''; ?>">Sent Log</a>
        </nav>

        <?php if ( $active_tab === 'log' ) : ?>

            <?php wpped_render_log_tab(); ?>

        <?php else : ?>

            <div id="wpped-prefill-notice" style="display:none;" class="notice notice-info is-dismissible">
                <p>Fields pre-filled from a previous log entry. Review before sending.</p>
            </div>

            <?php if ( $result !== null ) : ?>
                <div class="notice notice-<?php echo $result['sent'] ? 'success' : 'error'; ?> is-dismissible" style="max-width:760px;">
                    <p><?php echo esc_html( $result['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:0 4px 4px 4px;padding:24px 28px;max-width:760px;">
                <form method="post" id="wpped-form">
                    <?php wp_nonce_field( 'wpped_send_email', 'wpped_nonce' ); ?>

                    <table class="form-table" role="presentation">

                        <tr>
                            <th scope="row" style="width:120px;"><label for="wpped_from">From <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" id="wpped_from" name="wpped_from"
                                    value="<?php echo esc_attr( $from ); ?>"
                                    placeholder="Your Name &lt;you@yourdomain.com&gt;"
                                    class="regular-text" style="width:100%;" required />
                                <p class="description">The email address the recipient will see as the sender. Accepts <code>email@example.com</code> or <code>Name &lt;email@example.com&gt;</code>. Saved automatically after each send.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="wpped_to">To <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" id="wpped_to" name="wpped_to"
                                    value="<?php echo esc_attr( $to ); ?>"
                                    placeholder="Recipient Name &lt;recipient@example.com&gt;"
                                    class="regular-text" style="width:100%;" required />
                                <p class="description">The recipient's email address. Accepts <code>email@example.com</code> or <code>Name &lt;email@example.com&gt;</code>.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="wpped_subject">Subject <span style="color:red;">*</span></label></th>
                            <td>
                                <input type="text" id="wpped_subject" name="wpped_subject"
                                    value="<?php echo esc_attr( $subject ); ?>"
                                    placeholder="Email subject"
                                    class="regular-text" style="width:100%;" required />
                            </td>
                        </tr>

                    </table>

                    <div style="margin-top:8px;">
                        <label style="display:block;font-weight:600;margin-bottom:8px;">
                            Message <span style="color:red;">*</span>
                        </label>
                        <?php
                        wp_editor( $body, 'wpped_body', [
                            'media_buttons' => false,
                            'textarea_name' => 'wpped_body',
                            'textarea_rows' => 15,
                            'teeny'         => false,
                            'tinymce'       => [
                                'forced_root_block'       => 'p',
                                'force_p_newlines'        => true,
                                'remove_linebreaks'       => true,
                                'convert_newlines_to_brs' => false,
                                'toolbar1'                => 'bold italic underline | bullist numlist | blockquote | link unlink | removeformat',
                                'toolbar2'                => '',
                                'statusbar'               => false,
                            ],
                            'quicktags' => true,
                        ] );
                        ?>
                    </div>

                    <p style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
                        <input type="submit" name="wpped_send" class="button button-primary button-large"
                            value="Send Email"
                            onclick="return confirm('Send this email now?');" />
                    </p>

                </form>
            </div>

            <p style="margin-top:16px;color:#888;font-size:12px;max-width:760px;">
                Emails are sent via <code>wp_mail()</code> as HTML. For reliable delivery, use an SMTP plugin such as WP Mail SMTP.
            </p>

        <?php endif; ?>
    </div>
    <?php
}
