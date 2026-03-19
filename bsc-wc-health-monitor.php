<?php
/**
 * Plugin Name:  BSC - Office Hub - WC Health Monitor
 * Description:  Überwacht WooCommerce-Shop-Gesundheit: Varnish-Cache, mu-Plugin-Status,
 *               blockierte Bestellanfragen, fällige Updates, ausstehende Kommentare
 *               und Bestellstatistiken. Meldet alles automatisch an den BSC Office Hub.
 * Version:      2.4.0
 * Author:       Bavarian Soap Company / Woidsiederei / Michael Wühr
 * License:      GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Update URI:   https://github.com/michaelwuehr/bsc-wc-health-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Konstanten ───────────────────────────────────────────────────────────────

define( 'BSCHWM_VERSION',          '2.4.0' );
define( 'BSCHWM_OPTION_SETTINGS',  'bschwm_settings' );
define( 'BSCHWM_OPTION_CACHE',     'bschwm_last_cache' );
define( 'BSCHWM_OPTION_BLOCKS',    'bschwm_last_blocks' );
define( 'BSCHWM_OPTION_UPDATES',   'bschwm_last_updates' );
define( 'BSCHWM_OPTION_COMMENTS',  'bschwm_last_comments' );
define( 'BSCHWM_OPTION_ORDERS',    'bschwm_last_orders' );
define( 'BSCHWM_OPTION_HEALTH',   'bschwm_last_health' );
define( 'BSCHWM_OPTION_BLK_SNAP',  'bschwm_block_snapshot' );
define( 'BSCHWM_CRON_HOOK',        'bschwm_scheduled_check' );
define( 'BSCHWM_SINGLE_HOOK',      'bschwm_single_check' );
define( 'BSCHWM_GITHUB_REPO',      'michaelwuehr/bsc-wc-health-monitor' );
define( 'BSCHWM_UPDATE_TRANSIENT', 'bschwm_github_release' );

// ─── Einstellungen ────────────────────────────────────────────────────────────

// ─── Custom Cron Schedules ────────────────────────────────────────────────────

add_filter( 'cron_schedules', function ( array $schedules ): array {
    $schedules['bschwm_30min'] = [ 'interval' => 1800,  'display' => 'Alle 30 Minuten' ];
    $schedules['bschwm_1h']    = [ 'interval' => 3600,  'display' => 'Stündlich (60 Min.)' ];
    $schedules['bschwm_2h']    = [ 'interval' => 7200,  'display' => 'Alle 2 Stunden' ];
    $schedules['bschwm_6h']    = [ 'interval' => 21600, 'display' => 'Alle 6 Stunden' ];
    return $schedules;
} );

function bschwm_interval_options(): array {
    return [
        'bschwm_30min' => 'Alle 30 Minuten',
        'bschwm_1h'    => 'Stündlich (60 Min.) – Standard',
        'bschwm_2h'    => 'Alle 2 Stunden',
        'bschwm_6h'    => 'Alle 6 Stunden',
        'daily'        => 'Täglich',
    ];
}

function bschwm_reschedule_cron( string $interval ): void {
    wp_clear_scheduled_hook( BSCHWM_CRON_HOOK );
    wp_schedule_event( time(), $interval, BSCHWM_CRON_HOOK );
}

// ─── Einstellungen ────────────────────────────────────────────────────────────

function bschwm_default_settings(): array {
    return [
        'site_url'      => get_site_url(),
        'hub_url'       => '',
        'hub_secret'    => '',
        'cron_interval' => 'bschwm_1h',
    ];
}

function bschwm_get_settings(): array {
    return wp_parse_args(
        get_option( BSCHWM_OPTION_SETTINGS, [] ),
        bschwm_default_settings()
    );
}

// ─── Hub Push (generisch) ─────────────────────────────────────────────────────

function bschwm_push_to_hub( string $endpoint_path, array $payload ): void {
    $settings = bschwm_get_settings();
    if ( empty( $settings['hub_url'] ) ) {
        return;
    }

    $endpoint = rtrim( $settings['hub_url'], '/' ) . $endpoint_path;

    $response = wp_remote_post( $endpoint, [
        'timeout' => 10,
        'headers' => [
            'Content-Type'    => 'application/json',
            'X-BSCHWM-Secret' => $settings['hub_secret'],
            'X-BSCHWM-Source' => parse_url( get_site_url(), PHP_URL_HOST ),
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[BSCHWM] Hub Push fehlgeschlagen (' . $endpoint_path . '): ' . $response->get_error_message() );
    } elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
        error_log( '[BSCHWM] Hub Push HTTP-Fehler (' . $endpoint_path . '): '
            . wp_remote_retrieve_response_code( $response )
            . ' — ' . wp_remote_retrieve_body( $response ) );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 1: CACHE-INTEGRITÄT
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_run_cache_check( string $trigger = 'manual' ): array {
    $checks   = [];
    $errors   = [];
    $warnings = [];

    // ── Check 1: fix-session-cache-limiter.php ────────────────────────────────
    $session_fix_path = WPMU_PLUGIN_DIR . '/fix-session-cache-limiter.php';
    $session_fix_ok   = false;
    if ( file_exists( $session_fix_path ) ) {
        $content        = file_get_contents( $session_fix_path );
        $session_fix_ok = str_contains( $content, 'session.cache_limiter' )
                       && str_contains( $content, 'session.use_cookies' );
    }
    $checks['session_fix'] = $session_fix_ok;
    if ( ! $session_fix_ok ) {
        $errors[] = 'fix-session-cache-limiter.php fehlt oder unvollständig — Cache-Control: no-store + Set-Cookie: PHPSESSID möglich!';
    }

    // ── Check 2: fix-fb-no-setcookie.php ─────────────────────────────────────
    $fb_fix_path = WPMU_PLUGIN_DIR . '/fix-fb-no-setcookie.php';
    $fb_fix_ok   = false;
    if ( file_exists( $fb_fix_path ) ) {
        $content   = file_get_contents( $fb_fix_path );
        $fb_fix_ok = str_contains( $content, 'param_builder_server_setup' )
                  && str_contains( $content, 'facebook_for_woocommerce_integration_pixel_enabled' );
    }
    $checks['fb_fix'] = $fb_fix_ok;
    if ( ! $fb_fix_ok ) {
        $errors[] = 'fix-fb-no-setcookie.php fehlt oder unvollständig — Set-Cookie: _fbp möglich!';
    }

    // ── Check 3: Facebook-Funktionsname noch vorhanden ────────────────────────
    $fb_tracker = WP_PLUGIN_DIR . '/facebook-for-woocommerce/facebook-commerce-events-tracker.php';
    $fb_fn_ok   = true;
    if ( file_exists( $fb_tracker ) ) {
        $content  = file_get_contents( $fb_tracker );
        $fb_fn_ok = str_contains( $content, 'param_builder_server_setup' );
        if ( ! $fb_fn_ok ) {
            $errors[] = 'Facebook Plugin aktualisiert: param_builder_server_setup() nicht mehr gefunden! '
                      . 'fix-fb-no-setcookie.php muss angepasst werden.';
        }
    }
    $checks['fb_function_present'] = $fb_fn_ok;

    // ── Check 4: Varnish Self-Check ───────────────────────────────────────────
    $settings = bschwm_get_settings();
    $test_url = trailingslashit( $settings['site_url'] );

    $r1 = wp_remote_head( $test_url, [
        'timeout'     => 10,
        'redirection' => 0,
        'sslverify'   => false,
        'headers'     => [ 'User-Agent' => 'BSCHWM-Check/' . BSCHWM_VERSION ],
    ] );
    sleep( 2 );
    $r2 = wp_remote_head( $test_url, [
        'timeout'     => 10,
        'redirection' => 0,
        'sslverify'   => false,
        'headers'     => [ 'User-Agent' => 'BSCHWM-Check/' . BSCHWM_VERSION ],
    ] );

    $varnish_ok      = false;
    $varnish_details = [];

    if ( ! is_wp_error( $r1 ) && ! is_wp_error( $r2 ) ) {
        $h1  = wp_remote_retrieve_headers( $r1 );
        $h2  = wp_remote_retrieve_headers( $r2 );
        $vc1 = strtolower( (string) ( $h1['x-varnish-cache'] ?? '' ) );
        $xc1 = strtolower( (string) ( $h1['x-cacheable']    ?? '' ) );
        $vc2 = strtolower( (string) ( $h2['x-varnish-cache'] ?? '' ) );
        $xc2 = strtolower( (string) ( $h2['x-cacheable']    ?? '' ) );
        $sc  = (string) ( $h1['set-cookie']    ?? '' );
        $cc  = (string) ( $h1['cache-control'] ?? '' );

        if ( empty( $vc1 ) && empty( $vc2 ) ) {
            $warnings[]                = 'Kein x-varnish-cache Header im Self-Check. Externer curl-Test empfohlen.';
            $checks['varnish_caching'] = null;
            $varnish_ok                = true;
        } else {
            $varnish_ok = ( $vc2 === 'hit' ) && ( $xc2 === 'yes' );
            if ( ! $varnish_ok ) {
                $errors[] = "Varnish cached nicht! Request 2: x-varnish-cache={$vc2}, x-cacheable={$xc2}";
            }
            $checks['varnish_caching'] = $varnish_ok;
        }
        if ( $sc ) {
            $errors[] = 'Set-Cookie in Antwort: ' . substr( $sc, 0, 100 );
        }
        if ( str_contains( strtolower( $cc ), 'no-store' ) ) {
            $errors[] = 'Cache-Control: no-store in Antwort!';
        }
        $varnish_details = [
            'r1_varnish'    => $vc1 ?: null,
            'r1_cacheable'  => $xc1 ?: null,
            'r2_varnish'    => $vc2 ?: null,
            'r2_cacheable'  => $xc2 ?: null,
            'set_cookie'    => $sc  ?: null,
            'cache_control' => $cc  ?: null,
        ];
    } else {
        $errmsg                    = is_wp_error( $r1 ) ? $r1->get_error_message() : $r2->get_error_message();
        $errors[]                  = "Varnish Self-Check fehlgeschlagen: {$errmsg}";
        $checks['varnish_caching'] = false;
        $varnish_details           = [ 'http_error' => $errmsg ];
    }

    $has_critical = ! $checks['session_fix']
                 || ! $checks['fb_fix']
                 || ( $checks['varnish_caching'] === false );

    $status = 'ok';
    if ( ! empty( $errors ) ) {
        $status = $has_critical ? 'error' : 'warning';
    } elseif ( ! empty( $warnings ) ) {
        $status = 'warning';
    }

    $result = [
        'status'          => $status,
        'trigger'         => $trigger,
        'timestamp'       => current_time( 'c' ),
        'source'          => parse_url( get_site_url(), PHP_URL_HOST ),
        'checks'          => $checks,
        'varnish_details' => $varnish_details,
        'errors'          => $errors,
        'warnings'        => $warnings,
        'plugin_version'  => BSCHWM_VERSION,
    ];

    update_option( BSCHWM_OPTION_CACHE, $result );
    bschwm_push_to_hub( '/api/v1/monitoring/cache-status', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 2: BLOCKIERTE BESTELLANFRAGEN
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_run_block_check( string $trigger = 'manual' ): array {
    global $wpdb;

    $snapshot = get_option( BSCHWM_OPTION_BLK_SNAP, [] );
    $blocks   = [];
    $alerts   = [];
    $summary  = 'ok';

    // ── PayPal reCAPTCHA ──────────────────────────────────────────────────────
    $ppcp_counter = (int) $wpdb->get_var(
        "SELECT option_value FROM {$wpdb->options}
         WHERE option_name = 'ppcp_recaptcha_rejection_counter' LIMIT 1"
    );
    $ppcp_raw     = $wpdb->get_var(
        "SELECT option_value FROM {$wpdb->options}
         WHERE option_name = 'woocommerce_ppcp-recaptcha_settings' LIMIT 1"
    );
    $ppcp_cfg     = maybe_unserialize( $ppcp_raw );
    $ppcp_enabled = ( $ppcp_cfg['enabled'] ?? 'no' ) === 'yes';
    $prev_ppcp    = (int) ( $snapshot['ppcp_recaptcha']['counter'] ?? $ppcp_counter );
    $ppcp_delta   = $ppcp_counter - $prev_ppcp;

    $ppcp_status = 'ok';
    if ( $ppcp_enabled ) {
        $ppcp_status = 'warning';
        $alerts[]    = 'PayPal reCAPTCHA ist aktiv — Blockierungen möglich.';
        $summary     = 'warning';
    }
    if ( $ppcp_delta > 0 ) {
        $ppcp_status = 'error';
        $alerts[]    = "PayPal reCAPTCHA: {$ppcp_delta} neue Bestellung(en) blockiert (Gesamt: {$ppcp_counter}).";
        $summary     = 'error';
    }

    $blocks['ppcp_recaptcha'] = [
        'label'         => 'PayPal reCAPTCHA (Fraud Protection)',
        'status'        => $ppcp_status,
        'enabled'       => $ppcp_enabled,
        'counter_total' => $ppcp_counter,
        'counter_delta' => $ppcp_delta,
        'prev_snapshot' => $prev_ppcp,
    ];

    // ── WooCommerce Checkout-Fehler ───────────────────────────────────────────
    $wc_errors   = bschwm_parse_wc_checkout_errors( WP_CONTENT_DIR . '/uploads/wc-logs/' );
    $prev_wc_cnt = (int) ( $snapshot['wc_checkout_errors']['recent_count'] ?? 0 );
    $wc_delta    = max( 0, $wc_errors['count'] - $prev_wc_cnt );
    $wc_status   = 'ok';

    if ( $wc_errors['count'] > 0 ) {
        $wc_status = $wc_delta > 0 ? 'error' : 'warning';
        if ( $wc_delta > 0 ) {
            $alerts[] = "WooCommerce Logs: {$wc_delta} neue Checkout-Fehler in den letzten 48h.";
            $summary  = 'error';
        } elseif ( $summary === 'ok' ) {
            $summary = 'warning';
        }
    }

    $blocks['wc_checkout_errors'] = [
        'label'        => 'WooCommerce Checkout-Fehler (wc-logs)',
        'status'       => $wc_status,
        'recent_count' => $wc_errors['count'],
        'delta'        => $wc_delta,
        'last_seen'    => $wc_errors['last_seen'],
        'examples'     => $wc_errors['examples'],
    ];

    // ── Snapshot aktualisieren ────────────────────────────────────────────────
    update_option( BSCHWM_OPTION_BLK_SNAP, [
        'ppcp_recaptcha'     => [ 'counter'      => $ppcp_counter ],
        'wc_checkout_errors' => [ 'recent_count' => $wc_errors['count'] ],
    ] );

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'blocks'         => $blocks,
        'alerts'         => $alerts,
        'plugin_version' => BSCHWM_VERSION,
    ];

    update_option( BSCHWM_OPTION_BLOCKS, $result );
    bschwm_push_to_hub( '/api/v1/monitoring/order-blocks', $result );

    return $result;
}

function bschwm_parse_wc_checkout_errors( string $log_dir ): array {
    $result = [ 'count' => 0, 'last_seen' => null, 'examples' => [] ];
    if ( ! is_dir( $log_dir ) ) {
        return $result;
    }

    $cutoff    = time() - ( 48 * HOUR_IN_SECONDS );
    $patterns  = [ '/checkout/i', '/order.*fail|fail.*order/i', '/payment.*error|error.*payment/i', '/blocked|rejected|declined/i' ];
    $log_files = glob( $log_dir . '*.log' ) ?: [];
    usort( $log_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

    foreach ( array_slice( $log_files, 0, 5 ) as $file ) {
        if ( filemtime( $file ) < $cutoff ) {
            continue;
        }
        foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
            $matched = false;
            foreach ( $patterns as $regex ) {
                if ( preg_match( $regex, $line ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched || ! preg_match( '/\b(ERROR|CRITICAL)\b/i', $line ) ) {
                continue;
            }
            $result['count']++;
            if ( preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m ) ) {
                if ( ! $result['last_seen'] || $m[1] > $result['last_seen'] ) {
                    $result['last_seen'] = $m[1];
                }
            }
            if ( count( $result['examples'] ) < 3 ) {
                $result['examples'][] = substr( trim( $line ), 0, 150 );
            }
        }
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 3: UPDATES
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_run_update_check( string $trigger = 'manual' ): array {
    wp_update_plugins();
    wp_update_themes();

    $updates = [];
    $summary = 'ok';
    $alerts  = [];

    // ── WordPress Core ────────────────────────────────────────────────────────
    $core_update  = get_site_transient( 'update_core' );
    $core_updates = [];
    if ( isset( $core_update->updates ) ) {
        foreach ( $core_update->updates as $update ) {
            if ( isset( $update->response ) && $update->response === 'upgrade' ) {
                $core_updates[] = [
                    'current' => get_bloginfo( 'version' ),
                    'new'     => $update->current ?? '?',
                    'type'    => 'major',
                ];
            }
        }
    }
    $core_count = count( $core_updates );
    if ( $core_count > 0 ) {
        $alerts[] = "WordPress Core-Update verfügbar: " . ( $core_updates[0]['new'] ?? '?' );
        $summary  = 'warning';
    }
    $updates['core'] = [
        'status' => $core_count > 0 ? 'warning' : 'ok',
        'count'  => $core_count,
        'items'  => $core_updates,
    ];

    // ── Plugins ───────────────────────────────────────────────────────────────
    $plugin_update  = get_site_transient( 'update_plugins' );
    $plugin_updates = [];
    if ( ! empty( $plugin_update->response ) ) {
        foreach ( $plugin_update->response as $plugin_file => $data ) {
            $plugin_data      = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            $plugin_updates[] = [
                'slug'    => dirname( $plugin_file ),
                'name'    => $plugin_data['Name'] ?? $plugin_file,
                'current' => $plugin_data['Version'] ?? '?',
                'new'     => $data->new_version ?? '?',
            ];
        }
    }
    $plugin_count = count( $plugin_updates );
    if ( $plugin_count > 0 ) {
        $alerts[] = "{$plugin_count} Plugin-Update(s) verfügbar.";
        $summary  = 'warning';
    }
    $updates['plugins'] = [
        'status' => $plugin_count > 0 ? 'warning' : 'ok',
        'count'  => $plugin_count,
        'items'  => $plugin_updates,
    ];

    // ── Themes ────────────────────────────────────────────────────────────────
    $theme_update  = get_site_transient( 'update_themes' );
    $theme_updates = [];
    if ( ! empty( $theme_update->response ) ) {
        foreach ( $theme_update->response as $theme_slug => $data ) {
            $theme           = wp_get_theme( $theme_slug );
            $theme_updates[] = [
                'slug'    => $theme_slug,
                'name'    => $theme->get( 'Name' ) ?: $theme_slug,
                'current' => $theme->get( 'Version' ) ?: '?',
                'new'     => $data['new_version'] ?? '?',
            ];
        }
    }
    $theme_count = count( $theme_updates );
    if ( $theme_count > 0 ) {
        $alerts[] = "{$theme_count} Theme-Update(s) verfügbar.";
        $summary  = 'warning';
    }
    $updates['themes'] = [
        'status' => $theme_count > 0 ? 'warning' : 'ok',
        'count'  => $theme_count,
        'items'  => $theme_updates,
    ];

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'total_pending'  => $core_count + $plugin_count + $theme_count,
        'updates'        => $updates,
        'alerts'         => $alerts,
        'plugin_version' => BSCHWM_VERSION,
    ];

    update_option( BSCHWM_OPTION_UPDATES, $result );
    bschwm_push_to_hub( '/api/v1/monitoring/updates', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 4: KOMMENTARE
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_run_comment_check( string $trigger = 'manual' ): array {
    $counts = wp_count_comments();

    $pending  = (int) ( $counts->moderated     ?? 0 );
    $spam     = (int) ( $counts->spam          ?? 0 );
    $trash    = (int) ( $counts->trash         ?? 0 );
    $approved = (int) ( $counts->approved      ?? 0 );
    $total    = (int) ( $counts->total_comments ?? 0 );

    $alerts  = [];
    $summary = 'ok';

    if ( $pending > 0 ) {
        $alerts[] = "{$pending} Kommentar(e) warten auf Freigabe.";
        $summary  = 'warning';
    }
    if ( $spam > 10 ) {
        $alerts[] = "{$spam} Spam-Kommentare aufgelaufen — Spam-Ordner leeren empfohlen.";
        if ( $summary !== 'error' ) {
            $summary = 'warning';
        }
    }

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'comments'       => [
            'pending'  => $pending,
            'spam'     => $spam,
            'trash'    => $trash,
            'approved' => $approved,
            'total'    => $total,
        ],
        'alerts'         => $alerts,
        'plugin_version' => BSCHWM_VERSION,
    ];

    update_option( BSCHWM_OPTION_COMMENTS, $result );
    bschwm_push_to_hub( '/api/v1/monitoring/comments', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 5: BESTELLSTATISTIKEN
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_run_order_check( string $trigger = 'manual' ): array {
    $wc_stati = [
        'pending'        => 'Ausstehend',
        'processing'     => 'In Bearbeitung',
        'on-hold'        => 'Wartend',
        'completed'      => 'Abgeschlossen',
        'cancelled'      => 'Storniert',
        'refunded'       => 'Erstattet',
        'failed'         => 'Fehlgeschlagen',
        'checkout-draft' => 'Entwurf',
    ];

    $by_status = [];
    $total     = 0;
    $alerts    = [];
    $summary   = 'ok';

    foreach ( $wc_stati as $slug => $label ) {
        $count              = (int) wc_orders_count( $slug );
        $by_status[ $slug ] = [ 'label' => $label, 'count' => $count ];
        $total             += $count;
    }

    $failed  = $by_status['failed']['count']  ?? 0;
    $pending = $by_status['pending']['count'] ?? 0;
    $on_hold = $by_status['on-hold']['count'] ?? 0;

    if ( $failed > 0 ) {
        $alerts[] = "{$failed} fehlgeschlagene Bestellung(en) vorhanden.";
        $summary  = 'warning';
    }
    if ( $pending > 5 ) {
        $alerts[] = "{$pending} Bestellungen ausstehend (Zahlungseingang unklar).";
        $summary  = 'warning';
    }
    if ( $on_hold > 3 ) {
        $alerts[] = "{$on_hold} Bestellungen auf Hold — manuelle Prüfung empfohlen.";
        if ( $summary !== 'error' ) {
            $summary = 'warning';
        }
    }

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'total'          => $total,
        'by_status'      => $by_status,
        'alerts'         => $alerts,
        'plugin_version' => BSCHWM_VERSION,
    ];

    update_option( BSCHWM_OPTION_ORDERS, $result );
    bschwm_push_to_hub( '/api/v1/monitoring/orders', $result );

    return $result;
}

// ─── Alle Module ausführen ────────────────────────────────────────────────────

// ═══════════════════════════════════════════════════════════════════════════════
// HEALTH CHECKS v2.3.0
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_check_cron(): array {
    $disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    if ( $disabled ) {
        return [
            'status'           => 'error',
            'wp_cron_disabled' => true,
            'next_run_delta_s' => null,
            'alerts'           => [ 'WP-Cron ist deaktiviert (DISABLE_WP_CRON=true)' ],
        ];
    }
    $next         = wp_next_scheduled( BSCHWM_CRON_HOOK );
    $delta        = $next ? ( $next - time() ) : null;
    $settings     = bschwm_get_settings();
    $interval_map = [
        'bschwm_30min' => 1800,
        'bschwm_1h'    => 3600,
        'bschwm_2h'    => 7200,
        'bschwm_6h'    => 21600,
        'daily'        => 86400,
    ];
    $interval = $interval_map[ $settings['cron_interval'] ] ?? 3600;
    $status   = 'ok';
    $alerts   = [];
    // $delta < 0: Cron ist überfällig; null: nicht eingeplant; > 2h: zu lange
    if ( $delta === null || $delta < 0 || $delta > $interval * 2 ) {
        $status   = 'warning';
        $alerts[] = 'Nächster Cron-Lauf nicht planmäßig oder überfällig';
    }
    // Hinweis: wird dieser Check während eines Cron-Laufs ausgeführt, kann $delta
    // kurzzeitig null sein bevor WordPress neu einplant – bekannter False-Positive.
    return [
        'status'           => $status,
        'wp_cron_disabled' => false,
        'next_run_delta_s' => $delta,
        'alerts'           => $alerts,
    ];
}


function bschwm_check_double_orders( int $window_minutes = 10 ): array {
    if ( ! class_exists( 'WC_Order_Query' ) ) {
        return [ 'status' => 'ok', 'count' => 0, 'window_minutes' => $window_minutes, 'examples' => [] ];
    }
    $orders = wc_get_orders( [
        'status'       => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
        'date_created' => '>' . strtotime( '-24 hours' ),
        'limit'        => -1,
        'return'       => 'objects',
    ] );
    // Gruppieren nach billing_email + order_total
    $groups = [];
    foreach ( $orders as $order ) {
        $date_created = $order->get_date_created();
        if ( ! $date_created ) {
            continue; // Bestellung ohne Datum überspringen
        }
        $key            = strtolower( $order->get_billing_email() ) . '|' . number_format( (float) $order->get_total(), 2 );
        $groups[ $key ][] = $date_created->getTimestamp();
    }
    $duplicates = [];
    foreach ( $groups as $key => $timestamps ) {
        if ( count( $timestamps ) < 2 ) {
            continue;
        }
        sort( $timestamps );
        for ( $i = 1; $i < count( $timestamps ); $i++ ) {
            if ( ( $timestamps[ $i ] - $timestamps[ $i - 1 ] ) < ( $window_minutes * 60 ) ) {
                // E-Mail anonymisieren: user@example.com → u***@example.com
                $key_parts = explode( '|', $key, 2 );
                $email     = $key_parts[0] ?? '';
                $amount    = $key_parts[1] ?? '0.00';
                $parts     = explode( '@', $email );
                $anon      = substr( $parts[0], 0, 1 ) . '***@' . ( $parts[1] ?? '' );
                $duplicates[] = $anon . ' (€' . $amount . ')';
                break;
            }
        }
    }
    $count = count( $duplicates );
    return [
        'status'         => $count > 0 ? 'warning' : 'ok',
        'count'          => $count,
        'window_minutes' => $window_minutes,
        'examples'       => array_slice( $duplicates, 0, 5 ),
    ];
}


function bschwm_has_tax_rate( float $rate, string $class ): bool {
    global $wpdb;
    $tax_class = ( $class === '' ) ? '' : $class;
    // DECIMAL(10,4)-Spalte: Rundungstoleranz ±0.001 statt exakter Float-Vergleich
    $count     = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE ABS(tax_rate - %f) < 0.001 AND tax_rate_class = %s",
        $rate,
        $tax_class
    ) );
    return $count > 0;
}


function bschwm_check_german_market_tax(): bool {
    if ( ! class_exists( 'WGM_Tax' ) ) {
        return true; // Plugin nicht aktiv → Check irrelevant, kein Fehler
    }
    return method_exists( 'WGM_Tax', 'get_tax_rates' ) && ! empty( WGM_Tax::get_tax_rates() );
}


function bschwm_check_tax(): array {
    $checks = [
        'tax_enabled'          => 'yes' === get_option( 'woocommerce_calc_taxes' ),
        'prices_include_tax'   => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
        'display_shop_incl'    => 'incl' === get_option( 'woocommerce_tax_display_shop' ),
        'display_cart_incl'    => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
        'standard_rate_19'     => bschwm_has_tax_rate( 19.0, '' ),
        'reduced_rate_7'       => bschwm_has_tax_rate( 7.0, 'reduced-rate' ),
        'german_market_active' => class_exists( 'WGM_Tax' ),
        'german_market_tax_ok' => bschwm_check_german_market_tax(),
    ];
    $alerts = [];
    foreach ( $checks as $k => $v ) {
        if ( ! $v ) {
            $alerts[] = "Check fehlgeschlagen: {$k}";
        }
    }
    return [
        'status' => empty( $alerts ) ? 'ok' : ( count( $alerts ) > 2 ? 'error' : 'warning' ),
        'alerts' => $alerts,
        'checks' => $checks,
    ];
}


function bschwm_check_sepa(): array {
    global $wpdb;
    $token_table = $wpdb->prefix . 'woocommerce_payment_tokens';

    // WooPayments SEPA
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE type = %s", 'sepa_debit' ) );
    if ( $count > 0 ) {
        return [ 'status' => 'ok', 'plugin' => 'woocommerce-payments', 'mandate_count' => $count, 'alerts' => [] ];
    }

    // Stripe WC SEPA
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE type = %s", 'stripe_sepa' ) );
    if ( $count > 0 ) {
        return [ 'status' => 'ok', 'plugin' => 'woo-stripe-payment', 'mandate_count' => $count, 'alerts' => [] ];
    }

    // Mollie Mandate (eigene Tabelle)
    $mollie_table = $wpdb->prefix . 'mollie_pending_payment';
    $exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mollie_table ) );
    if ( $exists === $mollie_table ) {
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$mollie_table}`" ); // phpcs:ignore -- Tabellenname aus $wpdb->prefix (trusted) + Konstante
        if ( $count > 0 ) {
            return [ 'status' => 'ok', 'plugin' => 'mollie', 'mandate_count' => $count, 'alerts' => [] ];
        }
    }

    return [
        'status'        => 'warning',
        'plugin'        => 'none',
        'mandate_count' => 0,
        'alerts'        => [ 'Kein SEPA-Plugin gefunden oder keine Mandate' ],
    ];
}


function bschwm_run_all_checks( string $trigger = 'manual' ): void {
    bschwm_run_cache_check( $trigger );
    bschwm_run_block_check( $trigger );
    bschwm_run_update_check( $trigger );
    bschwm_run_comment_check( $trigger );
    bschwm_run_order_check( $trigger );

    // Health-Checks (v2.3.0)
    $cron_result   = bschwm_check_cron();
    $double_result = bschwm_check_double_orders();
    $tax_result    = bschwm_check_tax();
    $sepa_result   = bschwm_check_sepa();

    // Worst-Status über alle Sub-Checks berechnen
    $status_order = [ 'ok' => 0, 'warning' => 1, 'error' => 2 ];
    $health_status = 'ok';
    foreach ( [ $cron_result, $double_result, $tax_result, $sepa_result ] as $sub ) {
        $sub_status = $sub['status'] ?? 'ok';
        if ( ( $status_order[ $sub_status ] ?? 0 ) > ( $status_order[ $health_status ] ?? 0 ) ) {
            $health_status = $sub_status;
        }
    }

    $health_payload = [
        'status'         => $health_status,
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'trigger'        => $trigger,
        'timestamp'      => gmdate( 'c' ),
        'plugin_version' => BSCHWM_VERSION,
        'cron'           => $cron_result,
        'double_orders'  => $double_result,
        'tax'            => $tax_result,
        'sepa'           => $sepa_result,
    ];
    bschwm_push_to_hub( '/api/v1/monitoring/health', $health_payload );
    update_option( BSCHWM_OPTION_HEALTH, $health_payload );
}

// ═══════════════════════════════════════════════════════════════════════════════
// GITHUB AUTO-UPDATE
// ═══════════════════════════════════════════════════════════════════════════════

function bschwm_fetch_github_release(): ?object {
    $cached = get_transient( BSCHWM_UPDATE_TRANSIENT );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . BSCHWM_GITHUB_REPO . '/releases/latest',
        [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'BSCHWM/' . BSCHWM_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! isset( $body->tag_name ) ) {
        return null;
    }

    // ZIP-Asset aus Release-Assets ermitteln, Fallback auf Standard-URL
    $zip_url = null;
    foreach ( $body->assets ?? [] as $asset ) {
        if ( str_ends_with( $asset->name, '.zip' ) ) {
            $zip_url = $asset->browser_download_url;
            break;
        }
    }
    if ( ! $zip_url ) {
        $zip_url = 'https://github.com/' . BSCHWM_GITHUB_REPO . '/releases/latest/download/bsc-wc-health-monitor.zip';
    }

    $release = (object) [
        'version'      => ltrim( $body->tag_name, 'v' ),
        'tag'          => $body->tag_name,
        'download_url' => $zip_url,
        'details_url'  => $body->html_url,
        'changelog'    => $body->body ?? '',
        'published_at' => $body->published_at ?? '',
    ];

    set_transient( BSCHWM_UPDATE_TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
    return $release;
}

/**
 * WordPress in den Update-Transient einhängen:
 * Wenn GitHub eine neuere Version hat → update_plugins-Transient befüllen.
 */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_file = plugin_basename( __FILE__ );
    $release     = bschwm_fetch_github_release();

    if ( $release && version_compare( $release->version, BSCHWM_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'        => 'bsc-wc-health-monitor',
            'plugin'      => $plugin_file,
            'new_version' => $release->version,
            'url'         => $release->details_url,
            'package'     => $release->download_url,
        ];
    } else {
        $transient->no_update[ $plugin_file ] = (object) [
            'slug'        => 'bsc-wc-health-monitor',
            'plugin'      => $plugin_file,
            'new_version' => BSCHWM_VERSION,
            'url'         => 'https://github.com/' . BSCHWM_GITHUB_REPO,
            'package'     => '',
        ];
    }

    return $transient;
} );

/**
 * Plugin-Info-Modal im WP-Backend (Details & Changelog).
 */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'bsc-wc-health-monitor' ) {
        return $result;
    }

    $release = bschwm_fetch_github_release();

    $raw_base = 'https://raw.githubusercontent.com/' . BSCHWM_GITHUB_REPO . '/main/assets';

    return (object) [
        'name'          => 'BSC – Office Hub – WC Health Monitor',
        'slug'          => 'bsc-wc-health-monitor',
        'version'       => $release ? $release->version : BSCHWM_VERSION,
        'author'        => 'Bavarian Soap Company / Woidsiederei / Michael Wühr',
        'homepage'      => 'https://github.com/' . BSCHWM_GITHUB_REPO,
        'download_link' => $release ? $release->download_url : '',
        'last_updated'  => $release ? $release->published_at : '',
        'icons'         => [
            '1x'  => $raw_base . '/icon-128x128.png',
            '2x'  => $raw_base . '/icon-256x256.png',
        ],
        'banners'       => [
            'low'  => $raw_base . '/icon-256x256.png',
            'high' => $raw_base . '/icon-256x256.png',
        ],
        'sections'      => [
            'description' => 'WooCommerce Shop-Gesundheitsüberwachung: Varnish-Cache-Integrität, blockierte Bestellungen, fällige Updates, ausstehende Kommentare und Bestellstatistiken.',
            'changelog'   => $release && $release->changelog
                ? '<pre style="white-space:pre-wrap">' . esc_html( $release->changelog ) . '</pre>'
                : '<p>Changelog auf GitHub verfügbar.</p>',
        ],
    ];
}, 10, 3 );

// ─── Trigger: Plugin-Update ───────────────────────────────────────────────────

add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
    if ( ( $options['type'] ?? '' ) === 'plugin' ) {
        wp_schedule_single_event( time() + 30, BSCHWM_SINGLE_HOOK, [ 'plugin_update' ] );
    }
}, 10, 2 );

add_action( BSCHWM_SINGLE_HOOK, function ( string $trigger ) {
    bschwm_run_all_checks( $trigger );
} );

// ─── Trigger: Täglicher Cron ──────────────────────────────────────────────────

add_action( BSCHWM_CRON_HOOK, function () {
    bschwm_run_all_checks( 'cron' );
} );

register_activation_hook( __FILE__, function () {
    $settings = bschwm_get_settings();
    if ( ! wp_next_scheduled( BSCHWM_CRON_HOOK ) ) {
        wp_schedule_event( time(), $settings['cron_interval'], BSCHWM_CRON_HOOK );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( BSCHWM_CRON_HOOK );
    wp_clear_scheduled_hook( BSCHWM_SINGLE_HOOK );
} );

// ─── Plugin-Icon in der WP Plugin-Liste ──────────────────────────────────────
// WordPress zeigt Icons für Nicht-wp.org-Plugins nicht nativ an.
// Wir injizieren das Icon per JS nur auf der Plugins-Seite.

add_action( 'admin_footer', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'plugins' ) {
        return;
    }
    $icon_url = 'https://raw.githubusercontent.com/' . BSCHWM_GITHUB_REPO . '/main/assets/icon-128x128.png';
    ?>
    <script>
    (function () {
        var row = document.querySelector('tr[data-plugin="bsc-wc-health-monitor/bsc-wc-health-monitor.php"]');
        if (!row) return;
        var strong = row.querySelector('.plugin-title strong');
        if (!strong) return;
        var img = document.createElement('img');
        img.src = <?= json_encode( $icon_url ); ?>;
        img.alt = '';
        img.style.cssText = 'width:38px;height:38px;border-radius:6px;object-fit:cover;vertical-align:middle;margin-right:10px;flex-shrink:0;';
        strong.style.display = 'flex';
        strong.style.alignItems = 'center';
        strong.insertBefore(img, strong.firstChild);
    })();
    </script>
    <?php
} );

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN UI
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $modules = [
        BSCHWM_OPTION_CACHE    => [ 'icon' => '🔴', 'label' => 'Cache Integrity' ],
        BSCHWM_OPTION_BLOCKS   => [ 'icon' => '🚫', 'label' => 'Blockierte Bestellungen' ],
        BSCHWM_OPTION_UPDATES  => [ 'icon' => '🔄', 'label' => 'Updates verfügbar' ],
        BSCHWM_OPTION_COMMENTS => [ 'icon' => '💬', 'label' => 'Kommentare' ],
        BSCHWM_OPTION_ORDERS   => [ 'icon' => '🛒', 'label' => 'Bestellungen' ],
        BSCHWM_OPTION_HEALTH   => [ 'icon' => '❤️', 'label' => 'Health Checks' ],
    ];

    $url = admin_url( 'options-general.php?page=bsc-wc-health-monitor' );

    foreach ( $modules as $option => $meta ) {
        $result = get_option( $option );
        if ( ! $result || $result['status'] === 'ok' ) {
            continue;
        }
        $class  = $result['status'] === 'error' ? 'notice-error' : 'notice-warning';
        $ts     = human_time_diff( strtotime( $result['timestamp'] ), current_time( 'timestamp' ) );
        $alerts = $result['alerts'] ?? $result['errors'] ?? $result['warnings'] ?? [];
        $list   = ! empty( $alerts ) ? '• ' . implode( '<br>• ', array_map( 'esc_html', $alerts ) ) : '';

        printf(
            '<div class="notice %s is-dismissible"><p><strong>%s BSC WC Health Monitor – %s</strong> (vor %s)%s<br><a href="%s">→ Details ansehen</a></p></div>',
            esc_attr( $class ),
            $meta['icon'],
            $meta['label'],
            esc_html( $ts ),
            $list ? '<br>' . $list : '',
            esc_url( $url )
        );
    }
} );

add_action( 'admin_menu', function () {
    add_options_page(
        'BSC WC Health Monitor',
        'WC Health Monitor',
        'manage_options',
        'bsc-wc-health-monitor',
        'bschwm_render_admin_page'
    );
} );

function bschwm_render_admin_page(): void {
    if ( isset( $_POST['bschwm_save'] ) && check_admin_referer( 'bschwm_save' ) ) {
        $allowed_intervals = array_keys( bschwm_interval_options() );
        $new_interval      = in_array( $_POST['cron_interval'] ?? '', $allowed_intervals, true )
            ? $_POST['cron_interval']
            : 'bschwm_1h';

        update_option( BSCHWM_OPTION_SETTINGS, [
            'site_url'      => esc_url_raw( trim( $_POST['site_url']    ?? '' ) ),
            'hub_url'       => esc_url_raw( trim( $_POST['hub_url']     ?? '' ) ),
            'hub_secret'    => sanitize_text_field( trim( $_POST['hub_secret'] ?? '' ) ),
            'cron_interval' => $new_interval,
        ] );
        bschwm_reschedule_cron( $new_interval );
        echo '<div class="notice notice-success inline"><p>Einstellungen gespeichert.</p></div>';
    }

    if ( isset( $_POST['bschwm_check'] ) && check_admin_referer( 'bschwm_check' ) ) {
        bschwm_run_all_checks( 'manual' );
        echo '<div class="notice notice-info inline"><p>Alle Checks abgeschlossen.</p></div>';
    }

    $cache    = get_option( BSCHWM_OPTION_CACHE );
    $blocks   = get_option( BSCHWM_OPTION_BLOCKS );
    $updates  = get_option( BSCHWM_OPTION_UPDATES );
    $comments = get_option( BSCHWM_OPTION_COMMENTS );
    $orders   = get_option( BSCHWM_OPTION_ORDERS );
    $health   = get_option( BSCHWM_OPTION_HEALTH );
    $s        = bschwm_get_settings();
    ?>
    <div class="wrap">
        <h1>BSC – Office Hub – WC Health Monitor</h1>
        <p>Vollständige Übersicht der Shop-Gesundheit. Alle Daten werden automatisch an den BSC Office Hub gemeldet.</p>

        <form method="post" style="margin-bottom:16px">
            <?php wp_nonce_field( 'bschwm_check' ); ?>
            <input type="hidden" name="bschwm_check" value="1">
            <button type="submit" class="button button-primary">&#9654; Alle Checks jetzt ausführen</button>
        </form>

        <?php
        bschwm_render_section( 'Cache-Integrität',        $cache,    'bschwm_render_cache' );
        bschwm_render_section( 'Blockierte Bestellungen', $blocks,   'bschwm_render_blocks' );
        bschwm_render_section( 'Fällige Updates',         $updates,  'bschwm_render_updates' );
        bschwm_render_section( 'Kommentare',              $comments, 'bschwm_render_comments' );
        bschwm_render_section( 'Bestellstatistiken',      $orders,   'bschwm_render_orders' );
        bschwm_render_section( 'Health Checks',           $health,   'bschwm_render_health' );
        ?>

        <hr>
        <h2>Einstellungen</h2>
        <form method="post">
            <?php wp_nonce_field( 'bschwm_save' ); ?>
            <input type="hidden" name="bschwm_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="site_url">Site URL</label></th>
                    <td>
                        <input type="url" id="site_url" name="site_url" class="regular-text"
                            value="<?= esc_attr( $s['site_url'] ); ?>" required>
                        <p class="description">Öffentliche URL für den Varnish Self-Check.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hub_url">Office Hub URL</label></th>
                    <td>
                        <input type="url" id="hub_url" name="hub_url" class="regular-text"
                            value="<?= esc_attr( $s['hub_url'] ); ?>"
                            placeholder="http://192.168.x.x:8445">
                        <p class="description">
                            Basis-URL des BSC Office Hub. Leer lassen um Push zu deaktivieren.<br>
                            Endpunkte: <code>/cache-status</code> · <code>/order-blocks</code> · <code>/updates</code> · <code>/comments</code> · <code>/orders</code>
                            (alle unter <code>/api/v1/monitoring/</code>)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cron_interval">Prüf-Intervall</label></th>
                    <td>
                        <select id="cron_interval" name="cron_interval">
                            <?php foreach ( bschwm_interval_options() as $value => $label ) : ?>
                                <option value="<?= esc_attr( $value ); ?>" <?= selected( $s['cron_interval'], $value, false ); ?>>
                                    <?= esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Wie oft sollen alle Checks automatisch an den Office Hub gemeldet werden? (Standard: 60 Min.)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hub_secret">Hub Secret</label></th>
                    <td>
                        <input type="password" id="hub_secret" name="hub_secret" class="regular-text"
                            value="<?= esc_attr( $s['hub_secret'] ); ?>">
                        <p class="description">Muss mit <code>BSCHWM_SECRET</code> im Office Hub übereinstimmen.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Speichern' ); ?>
        </form>
    </div>
    <?php
}

// ─── Hilfsfunktion: Sektion rendern ──────────────────────────────────────────

function bschwm_render_section( string $title, mixed $data, string $render_fn ): void {
    $status_colors = [ 'ok' => '#00a32a', 'warning' => '#996800', 'error' => '#d63638' ];
    $status        = $data['status'] ?? 'unknown';
    $color         = $status_colors[ $status ] ?? '#555';
    $ts            = $data ? esc_html( date_i18n( 'd.m.Y H:i:s', strtotime( $data['timestamp'] ) ) ) : '—';

    echo "<h2 style='margin-top:24px'>{$title} <span style='font-size:13px;color:{$color};font-weight:normal'>● " . esc_html( strtoupper( $status ) ) . " · {$ts}</span></h2>";

    if ( $data ) {
        echo $render_fn( $data );
    } else {
        echo '<p><em>Noch kein Check durchgeführt.</em></p>';
    }
}

// ─── Render: Cache ────────────────────────────────────────────────────────────

function bschwm_render_cache( array $r ): string {
    $check_labels = [
        'session_fix'         => 'fix-session-cache-limiter.php vorhanden & vollständig',
        'fb_fix'              => 'fix-fb-no-setcookie.php vorhanden & vollständig',
        'fb_function_present' => 'Facebook Plugin: param_builder_server_setup() vorhanden',
        'varnish_caching'     => 'Varnish cacht korrekt (Request 2 = HIT)',
    ];

    $rows = '';
    foreach ( $r['checks'] as $key => $val ) {
        $icon  = $val === null ? '?' : ( $val ? 'OK' : 'FAIL' );
        $label = esc_html( $check_labels[ $key ] ?? $key );
        $color = $val === null ? '#555' : ( $val ? '#00a32a' : '#d63638' );
        $rows .= "<tr><td style='width:48px;padding:6px 8px;color:{$color};font-weight:bold'>{$icon}</td><td style='padding:6px 8px'>{$label}</td></tr>\n";
    }

    $vd  = $r['varnish_details'] ?? [];
    $vdr = '';
    if ( ! empty( $vd ) && ! isset( $vd['http_error'] ) ) {
        $vdr = sprintf(
            '<tr><td colspan="2" style="padding:4px 8px 8px;color:#555"><small>Req 1: <code>%s/%s</code> → Req 2: <code>%s/%s</code>%s%s</small></td></tr>',
            esc_html( $vd['r1_varnish'] ?? '?' ), esc_html( $vd['r1_cacheable'] ?? '?' ),
            esc_html( $vd['r2_varnish'] ?? '?' ), esc_html( $vd['r2_cacheable'] ?? '?' ),
            $vd['set_cookie']    ? ' | Set-Cookie: <code>' . esc_html( substr( $vd['set_cookie'], 0, 60 ) ) . '</code>' : '',
            $vd['cache_control'] ? ' | CC: <code>' . esc_html( $vd['cache_control'] ) . '</code>' : ''
        );
    } elseif ( isset( $vd['http_error'] ) ) {
        $vdr = '<tr><td colspan="2" style="color:#d63638;padding:4px 8px 8px"><small>HTTP-Fehler: ' . esc_html( $vd['http_error'] ) . '</small></td></tr>';
    }

    $msgs = '';
    foreach ( $r['errors']   ?? [] as $m ) {
        $msgs .= '<tr><td colspan="2" style="padding:4px 8px;color:#d63638">FEHLER: ' . esc_html( $m ) . '</td></tr>';
    }
    foreach ( $r['warnings'] ?? [] as $m ) {
        $msgs .= '<tr><td colspan="2" style="padding:4px 8px;color:#996800">WARNUNG: ' . esc_html( $m ) . '</td></tr>';
    }

    return bschwm_table( $rows . $vdr . $msgs );
}

// ─── Render: Blockierungen ────────────────────────────────────────────────────

function bschwm_render_blocks( array $r ): string {
    $rows = '';

    $ppcp = $r['blocks']['ppcp_recaptcha'] ?? null;
    if ( $ppcp ) {
        $icon      = bschwm_status_icon( $ppcp['status'] );
        $enabled   = $ppcp['enabled'] ? '<span style="color:#d63638">aktiv</span>' : '<span style="color:#00a32a">inaktiv</span>';
        $delta_str = $ppcp['counter_delta'] > 0
            ? '<strong style="color:#d63638">+' . (int) $ppcp['counter_delta'] . ' neu</strong>'
            : '<span style="color:#00a32a">keine neuen</span>';
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . '<strong>' . esc_html( $ppcp['label'] ) . '</strong><br><small>'
            . "Status: {$enabled} | Gesamt: <code>" . (int) $ppcp['counter_total'] . "</code> | Seit letztem Check: {$delta_str}"
            . '</small></td></tr>';
    }

    $wc = $r['blocks']['wc_checkout_errors'] ?? null;
    if ( $wc ) {
        $icon  = bschwm_status_icon( $wc['status'] );
        $last  = $wc['last_seen'] ? esc_html( date_i18n( 'd.m.Y H:i', strtotime( $wc['last_seen'] ) ) ) : '—';
        $delta = (int) $wc['delta'];
        $dstr  = $delta > 0 ? '<strong style="color:#d63638">+' . $delta . ' neu</strong>' : '<span style="color:#00a32a">keine neuen</span>';
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . '<strong>' . esc_html( $wc['label'] ) . '</strong><br><small>'
            . "48h-Fehler: <code>{$wc['recent_count']}</code> | Neu: {$dstr} | Letzter: {$last}";
        if ( ! empty( $wc['examples'] ) ) {
            $rows .= '<details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1">Beispiele</summary><ul style="margin:4px 0 0 16px">';
            foreach ( $wc['examples'] as $ex ) {
                $rows .= '<li><code style="font-size:11px;word-break:break-all">' . esc_html( $ex ) . '</code></li>';
            }
            $rows .= '</ul></details>';
        }
        $rows .= '</small></td></tr>';
    }

    return bschwm_table( $rows );
}

// ─── Render: Updates ─────────────────────────────────────────────────────────

function bschwm_render_updates( array $r ): string {
    $rows     = '';
    $sections = [ 'core' => 'WordPress Core', 'plugins' => 'Plugins', 'themes' => 'Themes' ];

    foreach ( $sections as $key => $label ) {
        $data  = $r['updates'][ $key ] ?? null;
        if ( ! $data ) {
            continue;
        }
        $icon  = bschwm_status_icon( $data['status'] );
        $count = (int) $data['count'];
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . "<strong>{$label}</strong>: ";

        if ( $count === 0 ) {
            $rows .= '<span style="color:#00a32a">aktuell</span>';
        } else {
            $rows .= "<strong style='color:#996800'>{$count} Update(s) verfügbar</strong>";
            if ( ! empty( $data['items'] ) ) {
                $rows .= '<details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1">Details</summary><ul style="margin:4px 0 0 16px">';
                foreach ( $data['items'] as $item ) {
                    $name  = esc_html( $item['name'] ?? $item['slug'] ?? '?' );
                    $cur   = esc_html( $item['current'] ?? '?' );
                    $new   = esc_html( $item['new'] ?? '?' );
                    $rows .= "<li>{$name}: <code>{$cur}</code> → <code>{$new}</code></li>";
                }
                $rows .= '</ul></details>';
            }
        }
        $rows .= '</td></tr>';
    }

    return bschwm_table( $rows );
}

// ─── Render: Kommentare ───────────────────────────────────────────────────────

function bschwm_render_comments( array $r ): string {
    $c    = $r['comments'];
    $rows = '';

    $items = [
        [ 'Ausstehend (zur Freigabe)', $c['pending'],  $c['pending'] > 0 ? 'warning' : 'ok' ],
        [ 'Spam',                      $c['spam'],     $c['spam'] > 10   ? 'warning' : 'ok' ],
        [ 'Papierkorb',                $c['trash'],    'ok' ],
        [ 'Genehmigt',                 $c['approved'], 'ok' ],
        [ 'Gesamt',                    $c['total'],    'ok' ],
    ];

    foreach ( $items as [ $label, $count, $status ] ) {
        $icon  = bschwm_status_icon( $status );
        $color = $status === 'ok' ? '#555' : '#996800';
        $rows .= "<tr><td style='width:28px;padding:6px 8px'>{$icon}</td>"
            . "<td style='padding:6px 8px'>{$label}</td>"
            . "<td style='padding:6px 8px;color:{$color}'><strong>{$count}</strong></td></tr>";
    }

    return bschwm_table( $rows );
}

// ─── Render: Bestellungen ─────────────────────────────────────────────────────

function bschwm_render_orders( array $r ): string {
    $rows = "<tr style='background:#f0f0f1'>"
        . "<th style='padding:6px 8px'>Status</th>"
        . "<th style='padding:6px 8px'>Anzahl</th>"
        . "</tr>";

    $alert_stati = [ 'failed' => 'error', 'pending' => 'warning', 'on-hold' => 'warning' ];

    foreach ( $r['by_status'] as $slug => $data ) {
        $status = $alert_stati[ $slug ] ?? 'ok';
        if ( $status !== 'ok' && $data['count'] === 0 ) {
            $status = 'ok';
        }
        $icon  = bschwm_status_icon( $status );
        $color = $status === 'error' ? '#d63638' : ( $status === 'warning' ? '#996800' : '#555' );
        $rows .= "<tr>"
            . "<td style='padding:6px 8px'>{$icon} " . esc_html( $data['label'] ) . "</td>"
            . "<td style='padding:6px 8px;color:{$color}'><strong>" . (int) $data['count'] . "</strong></td>"
            . "</tr>";
    }

    $rows .= "<tr style='border-top:2px solid #ddd'>"
        . "<td style='padding:8px;font-weight:bold'>Gesamt</td>"
        . "<td style='padding:8px;font-weight:bold'>" . (int) $r['total'] . "</td>"
        . "</tr>";

    return bschwm_table( $rows );
}

// ─── Render: Health Checks ───────────────────────────────────────────────────

function bschwm_render_health( array $r ): string {
    $rows = '';

    // ── WP-Cron ──────────────────────────────────────────────────────────────
    $cron = $r['cron'] ?? null;
    if ( $cron ) {
        $icon      = bschwm_status_icon( $cron['status'] );
        $delta     = $cron['next_run_delta_s'];
        $disabled  = (bool) ( $cron['wp_cron_disabled'] ?? false );
        if ( $disabled ) {
            $delta_str = '<strong style="color:#d63638">DEAKTIVIERT (DISABLE_WP_CRON)</strong>';
        } elseif ( $delta === null ) {
            $delta_str = '<span style="color:#d63638">nicht eingeplant</span>';
        } elseif ( $delta < 0 ) {
            $delta_str = '<span style="color:#d63638">überfällig seit ' . gmdate( 'H:i:s', abs( $delta ) ) . '</span>';
        } else {
            $delta_str = 'in ' . gmdate( 'H:i:s', $delta );
        }
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>WP-Cron</strong><br><small>Nächster geplanter Lauf: ' . $delta_str;
        if ( ! empty( $cron['alerts'] ) ) {
            $rows .= '<br><span style="color:#996800">⚠ ' . esc_html( implode( ' | ', $cron['alerts'] ) ) . '</span>';
        }
        $rows .= '</small></td></tr>';
    }

    // ── Doppelte Bestellungen ─────────────────────────────────────────────────
    $double = $r['double_orders'] ?? null;
    if ( $double ) {
        $icon  = bschwm_status_icon( $double['status'] );
        $count = (int) $double['count'];
        $cstr  = $count > 0
            ? '<strong style="color:#996800">' . $count . ' Duplikat(e)</strong>'
            : '<span style="color:#00a32a">keine</span>';
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>Doppelte Bestellungen</strong> <small style="color:#555">(Fenster: ' . (int) $double['window_minutes'] . ' Min.)</small><br>'
            . '<small>Gefunden: ' . $cstr;
        if ( ! empty( $double['examples'] ) ) {
            $rows .= ' <details style="display:inline-block;margin-left:8px"><summary style="cursor:pointer;color:#2271b1">Beispiele anzeigen</summary>'
                . '<ul style="margin:4px 0 0 16px">';
            foreach ( $double['examples'] as $ex ) {
                $rows .= '<li><code>' . esc_html( $ex ) . '</code></li>';
            }
            $rows .= '</ul></details>';
        }
        $rows .= '</small></td></tr>';
    }

    // ── MwSt.-Konfiguration ───────────────────────────────────────────────────
    $tax = $r['tax'] ?? null;
    if ( $tax ) {
        $icon         = bschwm_status_icon( $tax['status'] );
        $check_labels = [
            'tax_enabled'          => 'Steuerberechnung aktiv',
            'prices_include_tax'   => 'Bruttopreise (inkl. MwSt.)',
            'display_shop_incl'    => 'Shop-Preisanzeige: inkl. MwSt.',
            'display_cart_incl'    => 'Warenkorb-Preisanzeige: inkl. MwSt.',
            'standard_rate_19'     => 'Steuersatz 19% (Standard) vorhanden',
            'reduced_rate_7'       => 'Steuersatz 7% (ermäßigt) vorhanden',
            'german_market_active' => 'German Market Plugin aktiv',
            'german_market_tax_ok' => 'German Market Steuern konfiguriert',
        ];
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>MwSt.-Konfiguration</strong><br>'
            . '<table style="margin-top:6px;font-size:12px;border-collapse:collapse">';
        foreach ( $tax['checks'] ?? [] as $key => $val ) {
            $color = $val ? '#00a32a' : '#d63638';
            $badge = $val ? 'OK' : 'FAIL';
            $label = esc_html( $check_labels[ $key ] ?? $key );
            $rows .= "<tr><td style='color:{$color};font-weight:bold;padding:2px 10px 2px 0;width:44px'>{$badge}</td>"
                . "<td style='padding:2px 0'>{$label}</td></tr>";
        }
        $rows .= '</table></td></tr>';
    }

    // ── SEPA-Mandate ──────────────────────────────────────────────────────────
    $sepa = $r['sepa'] ?? null;
    if ( $sepa ) {
        $icon     = bschwm_status_icon( $sepa['status'] );
        $plugin   = esc_html( $sepa['plugin'] ?? 'none' );
        $mandates = (int) ( $sepa['mandate_count'] ?? 0 );
        $mstr     = $mandates > 0
            ? '<span style="color:#00a32a"><strong>' . $mandates . '</strong></span>'
            : '<span style="color:#996800"><strong>0</strong></span>';
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>SEPA-Mandate</strong><br>'
            . '<small>Gateway-Plugin: <code>' . $plugin . '</code> | Aktive Mandate: ' . $mstr;
        if ( ! empty( $sepa['alerts'] ) ) {
            $rows .= '<br><span style="color:#996800">⚠ ' . esc_html( implode( ' | ', $sepa['alerts'] ) ) . '</span>';
        }
        $rows .= '</small></td></tr>';
    }

    return bschwm_table( $rows );
}

// ─── Hilfsfunktionen UI ───────────────────────────────────────────────────────

function bschwm_table( string $rows ): string {
    return "<table class='widefat' style='max-width:680px;border-collapse:collapse;margin-bottom:16px'><tbody>{$rows}</tbody></table>";
}

function bschwm_status_icon( string $status ): string {
    return match( $status ) {
        'ok'      => '<span style="color:#00a32a;font-weight:bold">OK</span>',
        'warning' => '<span style="color:#996800;font-weight:bold">WARN</span>',
        'error'   => '<span style="color:#d63638;font-weight:bold">ERR</span>',
        default   => '<span style="color:#555">?</span>',
    };
}
