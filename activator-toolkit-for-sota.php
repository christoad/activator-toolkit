<?php
/**
 * Plugin Name: Activator Toolkit for SOTA & POTA
 * Plugin URI: https://www.ki6cr.com/sota-magic-plugin-for-wordpress/
 * Description: Display your SOTA and POTA activations: GPX track maps with elevation chart, hiking statistics, contact tables, and an interactive contact map. Accepts SOTA CSV, ADIF, and ADI log files. No other plugins required.
 * Version: 1.2.0
 * Author: KI6CR
 * Author URI: https://ki6cr.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: activator-toolkit-for-sota
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('SOTA_MAGIC_PLUGIN_FILE', __FILE__);
require_once plugin_dir_path(__FILE__) . 'includes/pota.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings-page.php';

// Create (or upgrade) the locations cache table
function sota_magic_create_locations_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'sota_magic_locations';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        cache_key varchar(50) NOT NULL,
        lat decimal(10,6) NOT NULL,
        lon decimal(10,6) NOT NULL,
        label varchar(150) NOT NULL DEFAULT '',
        source varchar(20) NOT NULL DEFAULT 'qrz',
        expires_at datetime DEFAULT NULL,
        cached_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY cache_key (cache_key)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('sota_magic_db_version', '1.0');
}
register_activation_hook(__FILE__, 'sota_magic_create_locations_table');
add_action('plugins_loaded', function() {
    if (get_option('sota_magic_db_version') !== '1.0') {
        sota_magic_create_locations_table();
    }
});

/**
 * Encrypt a credential using AES-256-CBC keyed from WordPress secret keys.
 * Encrypted values are prefixed with 'enc:' for migration detection.
 */
function sota_magic_encrypt_credential($value) {
    if (empty($value) || !function_exists('openssl_encrypt')) return $value;
    $key = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY), 0, 32);
    $iv  = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
    return 'enc:' . base64_encode($iv . $enc);
}

/**
 * Decrypt a credential encrypted by sota_magic_encrypt_credential().
 * Falls back to returning the raw value if not encrypted (plain text migration).
 */
function sota_magic_decrypt_credential($value) {
    if (empty($value)) return '';
    if (strpos($value, 'enc:') !== 0) return $value; // plain text fallback
    if (!function_exists('openssl_decrypt')) return '';
    $key     = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY), 0, 32);
    $decoded = base64_decode(substr($value, 4));
    $iv      = substr($decoded, 0, 16);
    $enc     = substr($decoded, 16);
    $result  = openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv);
    return ($result !== false) ? $result : '';
}

// Add Settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    array_unshift($links, '<a href="options-general.php?page=activator-toolkit-settings">Settings</a>');
    return $links;
});

// Allow GPX and ADIF uploads
add_filter('upload_mimes', function($mimes) {
    $mimes['gpx']  = 'application/gpx+xml';
    $mimes['adif'] = 'text/plain';
    $mimes['adi']  = 'text/plain';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function($data, $file, $filename) {
    $ext = strtolower( pathinfo($filename, PATHINFO_EXTENSION) );
    if ($ext === 'gpx') {
        $data['ext']  = 'gpx';
        $data['type'] = 'application/gpx+xml';
    } elseif (in_array($ext, ['adif', 'adi'], true)) {
        $data['ext']  = $ext;
        $data['type'] = 'text/plain';
    }
    return $data;
}, 10, 3);

// Sanitize helpers for register_setting
function sota_magic_sanitize_float( $value ) {
    return (string) floatval( $value );
}
function sota_magic_sanitize_unit_system( $value ) {
    return in_array( $value, [ 'metric', 'imperial' ], true ) ? $value : 'metric';
}
function sota_magic_sanitize_program( $value ) {
    return $value === 'pota' ? 'pota' : 'sota';
}
function sota_magic_sanitize_map_layer( $value ) {
    return in_array( $value, [ 'topo', 'osm', 'carto' ], true ) ? $value : 'carto';
}
function sota_magic_sanitize_password( $value ) {
    return $value; // stored encrypted; do not alter via settings API
}

/**
 * Returns a minimal inline SVG icon by name.
 * All icons: 24×24 viewBox, stroke-based, inherits currentColor.
 *
 * @param string $name  Icon slug (mountain|hike|radio|timer|trend-up|walk|peak|
 *                      pause|chart|pin|map|antenna|home|warning|info|gear|distance).
 * @param int    $size  Rendered px size (default 20).
 * @return string       SVG markup, not user-supplied; safe to echo unescaped.
 */
function sota_magic_svg_icon( $name, $size = 20 ) {
    $s   = (int) $size;
    $att = 'width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" '
         . 'stroke-linejoin="round" style="vertical-align:middle;flex-shrink:0;" '
         . 'aria-hidden="true"';
    $icons = [
        'mountain'  => '<path d="M3 20L12 4L21 20H3Z"/><path d="M9 20L12 13L15 17"/>',
        'hike'      => '<circle cx="12" cy="4" r="1.5" fill="currentColor" stroke="none"/>'
                     . '<line x1="12" y1="5.5" x2="11" y2="13"/>'
                     . '<rect x="8" y="5.5" width="3.5" height="5.5" rx="1"/>'
                     . '<line x1="16" y1="7" x2="18" y2="22"/>'
                     . '<line x1="11" y1="9" x2="16" y2="8"/>'
                     . '<line x1="11" y1="13" x2="8" y2="22"/>'
                     . '<line x1="11" y1="13" x2="14" y2="22"/>',        // hiker with backpack + walking stick
        'radio'     => '<rect x="7" y="8" width="8" height="13" rx="2"/>'
                     . '<line x1="12" y1="8" x2="12" y2="3"/>'
                     . '<rect x="9" y="10" width="4" height="3" rx="0.5"/>'
                     . '<circle cx="11" cy="17" r="1.5" fill="currentColor" stroke="none"/>',  // walkie-talkie, vertical antenna
        'timer'     => '<circle cx="12" cy="13" r="7"/>'
                     . '<path d="M12 10v3.5l2 2"/>'
                     . '<line x1="9" y1="3" x2="15" y2="3"/>'
                     . '<line x1="12" y1="6" x2="12" y2="3"/>',
        'trend-up'  => '<polyline points="22,7 13,16 8,11 2,17"/>'
                     . '<polyline points="17,7 22,7 22,12"/>',
        'walk'      => '<path d="M5 17A7 7 0 1 1 19 17"/>'
                     . '<path d="M12 17L9 12"/>'
                     . '<circle cx="12" cy="17" r="1.2" fill="currentColor" stroke="none"/>'
                     . '<line x1="5" y1="17" x2="7" y2="17"/>'
                     . '<line x1="12" y1="10" x2="12" y2="12"/>'
                     . '<line x1="19" y1="17" x2="17" y2="17"/>',          // speedometer gauge
        'peak'      => '<path d="M2 20L8 9L12 15L16 7L22 20H2Z"/>',            // double-mountain silhouette
        'pause'     => '<rect x="6" y="4" width="4" height="16" rx="1"/>'
                     . '<rect x="14" y="4" width="4" height="16" rx="1"/>',
        'chart'     => '<line x1="2" y1="20" x2="22" y2="20"/>'
                     . '<rect x="4" y="12" width="4" height="8"/>'
                     . '<rect x="10" y="7" width="4" height="13"/>'
                     . '<rect x="16" y="4" width="4" height="16"/>',
        'pin'       => '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>'
                     . '<circle cx="12" cy="10" r="3"/>',
        'map'       => '<polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>'
                     . '<line x1="8" y1="2" x2="8" y2="18"/>'
                     . '<line x1="16" y1="6" x2="16" y2="22"/>',
        'antenna'   => '<circle cx="12" cy="8" r="1.5" fill="currentColor" stroke="none"/>'
                     . '<line x1="12" y1="9.5" x2="7" y2="22"/>'
                     . '<line x1="12" y1="9.5" x2="17" y2="22"/>'
                     . '<line x1="10" y1="14" x2="14" y2="14"/>'
                     . '<line x1="9" y1="19" x2="15" y2="19"/>'
                     . '<line x1="10" y1="14" x2="15" y2="19"/>'
                     . '<line x1="14" y1="14" x2="9" y2="19"/>'
                     . '<path d="M8.5 6 A 3 3 0 0 0 8.5 10.5"/>'
                     . '<path d="M7.5 4.5 A 4 4 0 0 0 7.5 12"/>'
                     . '<path d="M15.5 6 A 3 3 0 0 1 15.5 10.5"/>'
                     . '<path d="M16.5 4.5 A 4 4 0 0 1 16.5 12"/>',       // broadcast tower: clean A-frame + C-arcs
        'home'      => '<path d="M3 9.5L12 3L21 9.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/>'
                     . '<path d="M9 21V12h6v9"/>',
        'warning'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                     . '<line x1="12" y1="9" x2="12" y2="13"/>'
                     . '<line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'      => '<circle cx="12" cy="12" r="10"/>'
                     . '<line x1="12" y1="16" x2="12" y2="12"/>'
                     . '<line x1="12" y1="8" x2="12.01" y2="8"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/>'
                     . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06'
                     . 'a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09'
                     . 'A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06-.06a2 2 0 0 1-2.83-2.83'
                     . 'l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09'
                     . 'A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83'
                     . 'l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09'
                     . 'a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83'
                     . 'l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09'
                     . 'a1.65 1.65 0 0 0-1.51 1z"/>',
        'distance'  => '<line x1="3" y1="12" x2="21" y2="12"/>'
                     . '<polyline points="16,7 21,12 16,17"/>'
                     . '<polyline points="8,7 3,12 8,17"/>',
    ];
    $inner = isset( $icons[ $name ] ) ? $icons[ $name ] : '';
    if ( '' === $inner ) return '';
    return '<svg ' . $att . '>' . $inner . '</svg>';
}

/**
 * Outputs an SVG icon. Thin wrapper around sota_magic_svg_icon() so call sites
 * don't each need a phpcs:ignore; output is hardcoded, no user input involved.
 */
function sota_magic_echo_svg( $name, $size = 20 ) {
    echo sota_magic_svg_icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG, no user input
}

// SETTINGS
add_action('admin_menu', function() {
    add_options_page('Activator Toolkit Settings', 'Activator Toolkit', 'manage_options', 'activator-toolkit-settings', 'sota_magic_settings_page');
});

add_action('admin_init', function() {
    $options = [
        'sota_headline_gpx'           => [ 'sanitize_text_field',            'Activation GPS Track' ],
        'sota_headline_csv'           => [ 'sanitize_text_field',            'Activation Contacts' ],
        'sota_headline_map'           => [ 'sanitize_text_field',            'Contact Map' ],
        'sota_bg_color'               => [ 'sanitize_hex_color',             '#ffffff' ],
        'sota_text_color'             => [ 'sanitize_hex_color',             '#333333' ],
        'sota_is_transparent'         => [ 'absint',                         0 ],
        'sota_use_theme_font'         => [ 'absint',                         0 ],
        'sota_s2s_highlight'          => [ 'sanitize_hex_color',             '#ffebee' ],
        'sota_s2s_text_color'         => [ 'sanitize_hex_color',             '#d32f2f' ],
        'sota_show_contact_map'       => [ 'absint',                         1 ],
        'sota_block_width'            => [ 'sanitize_text_field',            '' ],
        'sota_hamqth_username'        => [ 'sanitize_text_field',            '' ],
        'sota_hamqth_password'        => [ 'sota_magic_sanitize_password',   '' ],
        'sota_qrz_username'           => [ 'sanitize_text_field',            '' ],
        'sota_qrz_password'           => [ 'sota_magic_sanitize_password',   '' ],
        'sota_show_gpx_stats'         => [ 'absint',                         1 ],
        'sota_stationary_threshold'   => [ 'sota_magic_sanitize_float',      '0.3' ],
        'sota_unit_system'            => [ 'sota_magic_sanitize_unit_system','metric' ],
        'sota_activation_zone_radius' => [ 'absint',                         50 ],
        'sota_rest_threshold_minutes' => [ 'absint',                         3 ],
        'sota_use_azapi'              => [ 'absint',                         1 ],
        'sota_debug_mode'             => [ 'absint',                         0 ],
        'sota_debug_mode_public'      => [ 'absint',                         0 ],
        'sota_default_map_layer'      => [ 'sota_magic_sanitize_map_layer',  'carto' ],
        'sota_default_program'        => [ 'sota_magic_sanitize_program',    'sota' ],
        'pota_p2p_highlight'          => [ 'sanitize_hex_color',             '#e8f5e9' ],
        'pota_p2p_text_color'         => [ 'sanitize_hex_color',             '#2e7d32' ],
    ];
    foreach ( $options as $key => [ $sanitize, $default ] ) {
        register_setting( 'sota_magic_group', $key, [ 'sanitize_callback' => $sanitize ] );
        if ( get_option( $key ) === false ) update_option( $key, $default );
    }
});

/**
 * Query activation.zone API for summit activation zone polygon
 * Returns array of lat/lon coordinates defining the polygon, or null on failure
 */
function sota_magic_get_activation_zone_from_api($summit_ref, $summit_lat, $summit_lon, $summit_alt) {
    // Format summit reference - try keeping slashes, only remove dash
    $summit_ref_clean = str_replace('-', '', $summit_ref);
    
    $debug = "Original: $summit_ref | Cleaned: $summit_ref_clean | ";
    
    // Prepare API request
    $api_url = 'https://api.activation.zone';
    
    // API requires specific fields
    $data_array = array(
        'summit_ref' => $summit_ref_clean,
        'summit_lat' => floatval($summit_lat),
        'summit_long' => floatval($summit_lon),
        'summit_alt' => intval(round($summit_alt)),  // Must be integer!
        'deg_delta' => 0.040,  // Matches activation.zone web UI default for precise terrain polygon
        'sota_summit_alt_thres' => 25  // SOTA rule: 25m vertical drop
    );
    
    $json_data = json_encode($data_array);
    $debug .= "JSON: " . $json_data . " | Calling API | ";
    
    $wp_response = wp_remote_post( $api_url, [
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'       => $json_data,
        'user-agent' => 'SOTA-Magic-Plugin/0.517',
    ] );

    if ( is_wp_error( $wp_response ) ) {
        $debug .= "API call failed: " . $wp_response->get_error_message();
        return ['polygon' => null, 'debug' => $debug];
    }
    
    $result = wp_remote_retrieve_body( $wp_response );
    $debug .= "Response received (" . strlen($result) . " bytes) | ";

    // Check HTTP response code
    $http_status = wp_remote_retrieve_response_code( $wp_response );
    $debug .= "Status: " . $http_status . " | ";

    // Parse response
    $response = json_decode($result, true);
    if (!$response) {
        $debug .= "JSON decode failed | Raw response: " . substr($result, 0, 200);
        return ['polygon' => null, 'debug' => $debug];
    }
    
    $debug .= "Keys: " . implode(', ', array_keys($response)) . " | ";
    
    // Check for error/detail in response
    if (isset($response['error'])) {
        $debug .= "API Error: " . $response['error'];
        return ['polygon' => null, 'debug' => $debug];
    }
    
    if (isset($response['detail'])) {
        $debug .= "API Detail: " . (is_array($response['detail']) ? json_encode($response['detail']) : $response['detail']) . " | ";
    }
    
    // Extract polygon coordinates from response
    // Try different possible response formats
    if (isset($response['az'])) {
        $debug .= "Found 'az' key | Type: " . gettype($response['az']) . " | ";
        
        if (is_array($response['az'])) {
            $debug .= "AZ is array | ";
            
            // Show what keys/structure az has
            if (count($response['az']) > 0) {
                $first_item = reset($response['az']);
                if (is_array($first_item)) {
                    $debug .= "First item is array with keys: " . implode(',', array_keys($first_item)) . " | ";
                } else {
                    $debug .= "First item type: " . gettype($first_item) . " | ";
                }
            }
            
            $debug .= "Count: " . count($response['az']) . " | ";
            
            if (isset($response['az']['coordinates'])) {
                $debug .= "Found az.coordinates";
                return ['polygon' => $response['az']['coordinates'], 'debug' => $debug];
            } elseif (isset($response['az']['geometry'])) {
                $debug .= "Found az.geometry";
                if (isset($response['az']['geometry']['coordinates'])) {
                    return ['polygon' => $response['az']['geometry']['coordinates'], 'debug' => $debug];
                }
            } elseif (isset($response['az'][0]) && is_array($response['az'][0])) {
                // Check if it's a GeoJSON feature collection
                if (isset($response['az'][0]['geometry'])) {
                    $debug .= "Found az[0].geometry";
                    return ['polygon' => $response['az'][0]['geometry']['coordinates'], 'debug' => $debug];
                }
                // Or just direct coordinate array
                $debug .= "AZ appears to be direct coordinate array";
                return ['polygon' => $response['az'], 'debug' => $debug];
            }
        } elseif (is_string($response['az'])) {
            $debug .= "AZ is string (length " . strlen($response['az']) . ") | ";
            
            // Check if it's WKT format (starts with POLYGON)
            if (strpos($response['az'], 'POLYGON') === 0) {
                $debug .= "Detected WKT POLYGON format | ";
                
                // Parse WKT: POLYGON ((-117.899 34.346, -117.900 34.346, ...))
                // Extract the coordinates from between the parentheses
                if (preg_match('/POLYGON\s*\(\(([^)]+)\)\)/', $response['az'], $matches)) {
                    $coords_string = $matches[1];
                    $debug .= "Extracted coords string | ";
                    
                    // Split by comma to get individual points
                    $points = explode(',', $coords_string);
                    $coordinates = array();
                    
                    foreach ($points as $point) {
                        $point = trim($point);
                        // Split by space to get lon, lat
                        $parts = preg_split('/\s+/', $point);
                        if (count($parts) >= 2) {
                            // WKT is [lon, lat], which is what we want for GeoJSON
                            $coordinates[] = array(floatval($parts[0]), floatval($parts[1]));
                        }
                    }
                    
                    if (count($coordinates) > 0) {
                        $debug .= "Parsed " . count($coordinates) . " coordinate pairs from WKT";
                        return ['polygon' => array($coordinates), 'debug' => $debug];  // Wrap in array for polygon format
                    }
                }
                $debug .= "Failed to parse WKT | ";
            } else {
                // Try JSON decode
                $debug .= "Not WKT, trying JSON decode | ";
                $az_decoded = json_decode($response['az'], true);
                if ($az_decoded === null) {
                    $debug .= "JSON decode failed (error: " . json_last_error_msg() . ") | ";
                } else {
                    $debug .= "Decoded successfully, keys: " . implode(',', array_keys($az_decoded)) . " | ";
                    if (isset($az_decoded['coordinates'])) {
                        return ['polygon' => $az_decoded['coordinates'], 'debug' => $debug];
                    } elseif (isset($az_decoded['geometry']['coordinates'])) {
                        return ['polygon' => $az_decoded['geometry']['coordinates'], 'debug' => $debug];
                    }
                }
            }
        }
    } elseif (isset($response['coordinates']) && is_array($response['coordinates'])) {
        $debug .= "Found coordinates";
        return ['polygon' => $response['coordinates'], 'debug' => $debug];
    } elseif (isset($response['geometry']['coordinates']) && is_array($response['geometry']['coordinates'])) {
        $debug .= "Found geometry.coordinates";
        return ['polygon' => $response['geometry']['coordinates'], 'debug' => $debug];
    }
    
    $debug .= "No valid coordinates found in known keys";
    return ['polygon' => null, 'debug' => $debug];
}

/**
 * Activation zone cache (wp_options, custom expiry, autoload off).
 * Returns the cached 'data' (an array; empty array means "known to have no polygon") or null on a miss.
 */
function sota_magic_az_cache_read( $key ) {
    $entry = get_option( $key );
    if ( is_array( $entry ) && isset( $entry['expires'] ) && time() < $entry['expires'] ) {
        return $entry['data'];
    }
    return null;
}

function sota_magic_az_cache_write( $key, $data, $ttl ) {
    update_option( $key, [ 'data' => $data, 'expires' => time() + $ttl ], false );
}

/**
 * SOTLAS high-precision (~1m) terrain-derived activation zone (az.sotl.as).
 * Returns [ 'polygon' => GeoJSON coordinates|null, 'debug' => string ].
 * The storage behind az.sotl.as answers HTTP 403 (not 404) when a summit has no polygon,
 * so any non-200 response counts as "not available". Hits are cached 365 days, misses 30 days
 * because SOTLAS keeps adding boundaries for more associations.
 */
function sota_magic_get_sotlas_zone( $summit_ref ) {
    $summit_ref = strtoupper( trim( $summit_ref ) );
    $cache_key  = 'sota_magic_azsl_' . sanitize_key( $summit_ref );
    $cached     = sota_magic_az_cache_read( $cache_key );
    if ( $cached !== null ) {
        return $cached
            ? [ 'polygon' => $cached, 'debug' => 'SOTLAS: loaded from cache' ]
            : [ 'polygon' => null,    'debug' => 'SOTLAS: no boundary for this summit (checked recently)' ];
    }

    // W6/CT-014 -> assoc W6, region CT, number 014 (the "/" is part of the ref, never encode the whole ref)
    $parts = explode( '/', $summit_ref, 2 );
    $rest  = isset( $parts[1] ) ? explode( '-', $parts[1], 2 ) : [];
    if ( count( $parts ) !== 2 || count( $rest ) !== 2 ) {
        return [ 'polygon' => null, 'debug' => 'SOTLAS: summit reference not in ASSOC/REGION-NUMBER form' ];
    }
    $url = 'https://az.sotl.as/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $rest[0] ) . '/' . rawurlencode( $rest[1] ) . '.geojson';

    $response = wp_remote_get( $url, [
        'timeout'    => 15,
        'user-agent' => 'Activator-Toolkit-for-SOTA/1.0 (WordPress plugin; +https://wordpress.org/plugins/activator-toolkit-for-sota)',
        'headers'    => [ 'Accept' => 'application/json' ],
    ] );
    if ( is_wp_error( $response ) ) {
        return [ 'polygon' => null, 'debug' => 'SOTLAS: request failed (' . $response->get_error_message() . ')' ]; // not cached, may be temporary
    }
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        sota_magic_az_cache_write( $cache_key, [], 30 * DAY_IN_SECONDS );
        return [ 'polygon' => null, 'debug' => 'SOTLAS: no boundary available (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ')' ];
    }

    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    $coords = $data['geometry']['coordinates']
        ?? $data['coordinates']
        ?? $data['features'][0]['geometry']['coordinates']
        ?? null;
    // MultiPolygon: keep the first polygon (coordinates nested one level deeper)
    if ( is_array( $coords ) && isset( $coords[0][0][0] ) && is_array( $coords[0][0][0] ) ) $coords = $coords[0];
    if ( ! is_array( $coords ) || ! isset( $coords[0][0] ) ) {
        sota_magic_az_cache_write( $cache_key, [], 30 * DAY_IN_SECONDS );
        return [ 'polygon' => null, 'debug' => 'SOTLAS: response contained no usable polygon' ];
    }

    sota_magic_az_cache_write( $cache_key, $coords, 365 * DAY_IN_SECONDS );
    return [ 'polygon' => $coords, 'debug' => 'SOTLAS: high-precision boundary fetched (' . count( $coords[0] ) . ' points), cached 365 days' ];
}

/**
 * Find the best activation zone polygon for a summit.
 *
 * @param string $mode 'auto' (SOTLAS, then Activation.Zone), 'sotlas', or 'activation_zone'.
 *                     When nothing is found the caller falls back to the radius circle.
 * @return array [ 'polygon' => array|null, 'source' => 'sotlas'|'activation_zone'|'', 'debug' => string ]
 */
function sota_magic_resolve_activation_zone( $summit_ref, $summit_lat, $summit_lon, $summit_alt, $mode = 'auto' ) {
    $debug = [];

    if ( 'auto' === $mode || 'sotlas' === $mode ) {
        $sotlas  = sota_magic_get_sotlas_zone( $summit_ref );
        $debug[] = $sotlas['debug'];
        if ( $sotlas['polygon'] ) {
            return [ 'polygon' => $sotlas['polygon'], 'source' => 'sotlas', 'debug' => implode( ' | ', $debug ) ];
        }
    }

    if ( 'auto' === $mode || 'activation_zone' === $mode ) {
        $cache_key = 'sota_magic_az_' . sanitize_key( $summit_ref );
        $cached    = sota_magic_az_cache_read( $cache_key );
        if ( $cached ) {
            $debug[] = 'Activation.Zone: loaded from cache';
            return [ 'polygon' => $cached, 'source' => 'activation_zone', 'debug' => implode( ' | ', $debug ) ];
        }
        $api_result = sota_magic_get_activation_zone_from_api( $summit_ref, $summit_lat, $summit_lon, $summit_alt );
        if ( is_array( $api_result ) ) {
            $debug[] = 'Activation.Zone: ' . $api_result['debug'];
            if ( ! empty( $api_result['polygon'] ) ) {
                sota_magic_az_cache_write( $cache_key, $api_result['polygon'], 365 * DAY_IN_SECONDS );
                return [ 'polygon' => $api_result['polygon'], 'source' => 'activation_zone', 'debug' => implode( ' | ', $debug ) ];
            }
        }
    }

    $debug[] = 'Using radius circle';
    return [ 'polygon' => null, 'source' => '', 'debug' => implode( ' | ', $debug ) ];
}

/**
 * Check if a point is inside a polygon using ray casting algorithm
 * @param float $lat Point latitude
 * @param float $lon Point longitude
 * @param array $polygon Array of [lat, lon] pairs defining polygon vertices
 * @return bool True if point is inside polygon
 */
function sota_magic_point_in_polygon($lat, $lon, $polygon) {
    if (!is_array($polygon) || count($polygon) < 3) {
        return false;
    }
    
    $inside = false;
    $count = count($polygon);
    
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $vertex_i = $polygon[$i];
        $vertex_j = $polygon[$j];
        
        // Handle different coordinate formats [lon, lat] or [lat, lon]
        // GeoJSON typically uses [lon, lat] but we'll check both
        if (is_array($vertex_i) && count($vertex_i) >= 2) {
            // Assume [lon, lat] format (GeoJSON standard)
            $lat_i = $vertex_i[1];
            $lon_i = $vertex_i[0];
            $lat_j = $vertex_j[1];
            $lon_j = $vertex_j[0];
        } else {
            continue;
        }
        
        // Ray casting algorithm
        if ((($lon_i > $lon) != ($lon_j > $lon)) &&
            ($lat < ($lat_j - $lat_i) * ($lon - $lon_i) / ($lon_j - $lon_i) + $lat_i)) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}

/**
 * Analyze GPX track to determine hiking vs stationary time
 * Uses hybrid approach: activation zone around summit peak + time threshold for rest breaks
 */
/**
 * Analyze GPX track to determine hiking vs stationary time
 * Uses hybrid approach: activation.zone API (if enabled) or radius fallback
 */
function sota_magic_analyze_gpx_track($gpx_url, $csv_url = null, $force_radius = false, $summit_ref_override = null, $zone_mode = 'auto') {
    $stationary_threshold = floatval(get_option('sota_stationary_threshold', 0.3)); // km/h
    $activation_zone_radius = floatval(get_option('sota_activation_zone_radius', 50)); // meters
    $rest_threshold_minutes = floatval(get_option('sota_rest_threshold_minutes', 10)); // minutes
    $rest_threshold_seconds = $rest_threshold_minutes * 60;
    $use_azapi = get_option('sota_use_azapi', 1);
    
    // Download and parse GPX
    $gpx_response = wp_remote_get( $gpx_url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $gpx_response ) ) return null;
    $gpx_content = wp_remote_retrieve_body( $gpx_response );
    if ( ! $gpx_content ) return null;

    $xml = @simplexml_load_string($gpx_content);
    if (!$xml) {
        return null;
    }
    
    // Register namespaces
    $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
    
    // Get all trackpoints
    $trackpoints = $xml->xpath('//gpx:trkpt');
    if (!$trackpoints || count($trackpoints) < 2) {
        return null;
    }
    
    // First pass: collect all points and find summit (highest elevation)
    $points = [];
    $max_elevation = -999999;
    $min_elevation = 999999;
    $summit_lat = null;
    $summit_lon = null;
    
    foreach ($trackpoints as $point) {
        $lat = floatval($point['lat']);
        $lon = floatval($point['lon']);
        $ele = floatval($point->ele);
        $time = strtotime((string)$point->time);
        
        $points[] = [
            'lat' => $lat,
            'lon' => $lon,
            'ele' => $ele,
            'time' => $time
        ];
        
        if ($ele > $max_elevation) {
            $max_elevation = $ele;
            $summit_lat = $lat;
            $summit_lon = $lon;
        }
        if ($ele < $min_elevation) {
            $min_elevation = $ele;
        }
    }
    
    // --- Step 1: Extract summit reference ---
    $summit_ref = null;
    if ( ! empty( $summit_ref_override ) ) {
        $summit_ref = strtoupper( $summit_ref_override );
    } elseif ( $csv_url ) {
        $csv_response = wp_remote_get( $csv_url, [ 'timeout' => 15 ] );
        if ( ! is_wp_error( $csv_response ) ) {
            $csv_body = wp_remote_retrieve_body( $csv_response );
            foreach ( explode( "\n", $csv_body ) as $csv_line ) {
                $row = str_getcsv( trim( $csv_line ) );
                if ( ! empty( $row[0] ) && $row[0] === 'V2' && ! empty( $row[2] ) ) {
                    $summit_ref = strtoupper( $row[2] );
                    break;
                }
            }
        }
    }

    // --- Step 2: Fetch official summit coordinates from SOTA API (always when ref available) ---
    // This ensures the activation zone center is the official summit, not the GPX high point,
    // regardless of whether the activation.zone polygon API or radius fallback is used.
    if ($summit_ref) {
        $sota_api_url  = 'https://api2.sota.org.uk/api/summits/' . $summit_ref;
        $sota_wp_resp  = wp_remote_get( $sota_api_url, [ 'timeout' => 30, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
        $sota_response = ! is_wp_error( $sota_wp_resp ) ? wp_remote_retrieve_body( $sota_wp_resp ) : '';
        if ($sota_response) {
            $sota_data = json_decode($sota_response, true);
            if ($sota_data && isset($sota_data['latitude']) && isset($sota_data['longitude'])) {
                $summit_lat = floatval($sota_data['latitude']);
                $summit_lon = floatval($sota_data['longitude']);
                // Use official altitude if available; otherwise keep GPX-derived max elevation
                if (isset($sota_data['altM']) && floatval($sota_data['altM']) > 0) {
                    $max_elevation = floatval($sota_data['altM']);
                }
            }
        }
    }

    // --- Step 3: Get activation zone polygon (only when API enabled and not force-radius) ---
    $activation_zone_polygon = null;
    $using_api = false;
    $api_debug_message = 'Not attempted';

    $zone_source = 'radius';
    $zone_forced = in_array($zone_mode, ['sotlas', 'activation_zone'], true);
    if ($summit_ref && !$force_radius && 'radius' !== $zone_mode && ($use_azapi || $zone_forced)) {
        $zone = sota_magic_resolve_activation_zone($summit_ref, $summit_lat, $summit_lon, $max_elevation, $zone_mode);
        $api_debug_message = $zone['debug'];
        if (!empty($zone['polygon'])) {
            $activation_zone_polygon = $zone['polygon'];
            $using_api   = true;
            $zone_source = $zone['source'];
        }
    }

    // Second pass: analyze segments and classify time
    $total_time = 0;
    $hiking_time = 0;
    $activation_time = 0;
    $rest_break_time = 0;
    $total_distance = 0;
    $hiking_distance = 0;
    $elevation_gain = 0;
    $elevation_loss = 0;
    $prev_elevation = null;
    
    // Track stationary periods outside activation zone
    $current_rest_start = null;
    $current_rest_duration = 0;
    
    // Debug: count points in zone
    $points_in_zone = 0;
    $stationary_in_zone = 0;
    $max_speed_in_zone = 0;
    $min_speed_in_zone = 999;
    
    for ($i = 1; $i < count($points); $i++) {
        $prev_point = $points[$i - 1];
        $curr_point = $points[$i];
        
        // Calculate distance and time
        $distance = sota_magic_haversine_distance($prev_point['lat'], $prev_point['lon'], $curr_point['lat'], $curr_point['lon']);
        $time_diff = $curr_point['time'] - $prev_point['time'];
        
        if ($time_diff <= 0) continue;
        
        // Calculate speed in km/h
        $speed = ($distance / 1000) / ($time_diff / 3600);
        
        // Calculate elevation change
        if ($prev_elevation !== null) {
            $ele_diff = $curr_point['ele'] - $prev_elevation;
            if ($ele_diff > 0) {
                $elevation_gain += $ele_diff;
            } else {
                $elevation_loss += abs($ele_diff);
            }
        }
        $prev_elevation = $curr_point['ele'];
        
        // Check if current point is in activation zone
        $in_activation_zone = false;
        
        if ($using_api && $activation_zone_polygon) {
            // Use API polygon - unwrap the outer array if needed
            $polygon_coords = is_array($activation_zone_polygon[0]) && isset($activation_zone_polygon[0][0]) && is_array($activation_zone_polygon[0][0]) 
                ? $activation_zone_polygon[0]  // Unwrap if double-nested
                : $activation_zone_polygon;     // Use as-is if single level
            
            $in_activation_zone = sota_magic_point_in_polygon($curr_point['lat'], $curr_point['lon'], $polygon_coords);

            if ($in_activation_zone) {
                $points_in_zone++;
                if ($is_stationary) $stationary_in_zone++;
                if ($speed > $max_speed_in_zone) $max_speed_in_zone = $speed;
                if ($speed < $min_speed_in_zone) $min_speed_in_zone = $speed;
            }
        } else {
            // Use fallback radius method
            $dist_from_summit = sota_magic_haversine_distance($summit_lat, $summit_lon, $curr_point['lat'], $curr_point['lon']);
            $in_activation_zone = ($dist_from_summit <= $activation_zone_radius);
        }
        
        $total_distance += $distance;
        $total_time += $time_diff;
        
        // Classify the segment
        $is_stationary = ($speed <= $stationary_threshold);
        
        if ($in_activation_zone) {
            // In activation zone = activation time (regardless of speed)
            // You're activating whether sitting still or moving around setting up
            $activation_time += $time_diff;
            
            // Reset rest tracking
            $current_rest_start = null;
            $current_rest_duration = 0;
            
        } else if ($is_stationary) {
            // Stationary outside activation zone = potential rest break
            if ($current_rest_start === null) {
                // Start of a stationary period
                $current_rest_start = $prev_point['time'];
                $current_rest_duration = $time_diff;
            } else {
                // Continuing stationary period
                $current_rest_duration += $time_diff;
            }
            
            // Always add to hiking time (all stationary time counts as hiking)
            $hiking_time += $time_diff;
            
            // Check if this stationary period qualifies as a rest break
            $was_below_threshold = ($current_rest_duration - $time_diff) < $rest_threshold_seconds;
            $is_now_above_threshold = $current_rest_duration >= $rest_threshold_seconds;
            
            if ($was_below_threshold && $is_now_above_threshold) {
                // Just crossed threshold - add entire accumulated duration to rest_break_time
                $rest_break_time += $current_rest_duration;
            } else if ($is_now_above_threshold) {
                // Already above threshold - add this segment to rest_break_time
                $rest_break_time += $time_diff;
            }
            // If below threshold, we add to hiking_time but not rest_break_time (short stop)
            
        } else {
            // Moving = hiking time
            $hiking_time += $time_diff;
            $hiking_distance += $distance;
            
            // Reset rest tracking when we start moving again
            $current_rest_start = null;
            $current_rest_duration = 0;
        }
    }
    
    // Calculate average speeds
    $avg_speed = $total_time > 0 ? ($total_distance / 1000) / ($total_time / 3600) : 0;
    $hiking_speed = $hiking_time > 0 ? ($hiking_distance / 1000) / ($hiking_time / 3600) : 0;

    // Sample track points for map/chart rendering (max 800 keeps JSON compact)
    $total_points = count($points);
    $max_map_pts = 800;
    $sampled_track = [];
    if ($total_points <= $max_map_pts) {
        foreach ($points as $p) {
            $sampled_track[] = [round($p['lat'], 6), round($p['lon'], 6), round($p['ele'], 1)];
        }
    } else {
        $step = $total_points / $max_map_pts;
        for ($si = 0; $si < $max_map_pts - 1; $si++) {
            $p = $points[(int)round($si * $step)];
            $sampled_track[] = [round($p['lat'], 6), round($p['lon'], 6), round($p['ele'], 1)];
        }
        $p = $points[$total_points - 1];
        $sampled_track[] = [round($p['lat'], 6), round($p['lon'], 6), round($p['ele'], 1)];
    }

    return [
        'total_time' => $total_time,
        'hiking_time' => $hiking_time,
        'stationary_time' => $activation_time,
        'rest_break_time' => $rest_break_time,
        'total_distance' => $total_distance / 1000,
        'hiking_distance' => $hiking_distance / 1000,
        'max_elevation' => $max_elevation,
        'min_elevation' => $min_elevation,
        'elevation_gain' => $elevation_gain,
        'elevation_loss' => $elevation_loss,
        'avg_speed' => $avg_speed,
        'hiking_speed' => $hiking_speed,
        'num_points' => count($points),
        'summit_lat' => $summit_lat,
        'summit_lon' => $summit_lon,
        'using_api' => $using_api,
        'zone_source' => $zone_source,
        'activation_zone_polygon' => $activation_zone_polygon,
        'activation_zone_radius' => $activation_zone_radius,
        'summit_ref' => $summit_ref,
        'api_debug' => $api_debug_message,
        'polygon_check_debug' => isset($polygon_coords) ? 'Vertices: ' . count($polygon_coords) . ', First: ' . json_encode($polygon_coords[0]) : 'No polygon',
        'points_in_zone' => isset($points_in_zone) ? $points_in_zone : 0,
        'stationary_in_zone' => isset($stationary_in_zone) ? $stationary_in_zone : 0,
        'speed_range_in_zone' => isset($min_speed_in_zone) && $min_speed_in_zone < 999 ? round($min_speed_in_zone, 2) . '-' . round($max_speed_in_zone, 2) . ' km/h' : 'N/A',
        'track_points' => $sampled_track,
    ];
}

/**
 * Parse GPX file and return sampled track points as [[lat, lon, ele], ...].
 * Used when GPX stats are disabled but we still need coordinates for the map.
 */
function sota_get_gpx_track_points($gpx_url, $max_points = 800) {
    $gpx_response = wp_remote_get( $gpx_url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $gpx_response ) ) return [];
    $gpx_content = wp_remote_retrieve_body( $gpx_response );
    if (!$gpx_content) return [];
    $xml = @simplexml_load_string($gpx_content);
    if (!$xml) return [];
    $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
    $trackpoints = $xml->xpath('//gpx:trkpt');
    if (!$trackpoints || count($trackpoints) < 2) return [];

    $raw = [];
    foreach ($trackpoints as $pt) {
        $lat = floatval($pt['lat']);
        $lon = floatval($pt['lon']);
        if ($lat === 0.0 && $lon === 0.0) continue; // skip GPS cold-start artifacts
        $raw[] = [round($lat, 6), round($lon, 6), round(floatval($pt->ele), 1)];
    }

    $n = count($raw);
    if ($n <= $max_points) return $raw;

    $out = [];
    $step = $n / $max_points;
    for ($i = 0; $i < $max_points - 1; $i++) {
        $out[] = $raw[(int)round($i * $step)];
    }
    $out[] = $raw[$n - 1]; // always include last point
    return $out;
}

/**
 * Calculate distance between two lat/lon points using Haversine formula
 * Returns distance in meters
 */
function sota_magic_haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meters
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lon / 2) * sin($delta_lon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius * $c;
}

/**
 * Format seconds into human-readable time
 */
function sota_magic_format_time_duration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    } else {
        return sprintf('%dm', $minutes);
    }
}

/**
 * Convert kilometers to miles
 */
function sota_magic_km_to_miles($km) {
    return $km * 0.621371;
}

/**
 * Convert meters to feet
 */
function sota_magic_meters_to_feet($meters) {
    return $meters * 3.28084;
}

/**
 * Format distance based on unit preference
 */
function sota_magic_format_distance($km, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $miles = sota_magic_km_to_miles($km);
        return number_format($miles, 2) . ' mi';
    }
    return number_format($km, 2) . ' km';
}

/**
 * Format elevation based on unit preference
 */
function sota_magic_format_elevation($meters, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $feet = sota_magic_meters_to_feet($meters);
        return number_format($feet, 0) . ' ft';
    }
    return number_format($meters, 0) . ' m';
}

/**
 * Format speed based on unit preference
 */
function sota_magic_format_speed($kmh, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $mph = sota_magic_km_to_miles($kmh);
        return number_format($mph, 1) . ' mph';
    }
    return number_format($kmh, 1) . ' km/h';
}

/**
 * Get distance unit label
 */
function sota_magic_get_distance_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'mi' : 'km';
}

/**
 * Get elevation unit label
 */
function sota_magic_get_elevation_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'ft' : 'm';
}

/**
 * Get speed unit label
 */
function sota_magic_get_speed_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'mph' : 'km/h';
}

/**
 * Extract a single field value from an ADIF record string.
 * Uses the declared byte-length for precision: <FIELD:N>value
 */
function sota_magic_adif_field( $record, $field ) {
    if ( preg_match( '/<' . preg_quote( $field, '/' ) . ':(\d+)[^>]*>/i', $record, $m, PREG_OFFSET_CAPTURE ) ) {
        $tag_end = $m[0][1] + strlen( $m[0][0] );
        $len     = (int) $m[1][0];
        return trim( substr( $record, $tag_end, $len ) );
    }
    return '';
}

/**
 * Download and parse an ADIF log file, returning a normalised contacts array
 * matching the structure produced by the CSV parser.
 *
 * @param string $log_url             WordPress media URL pointing to .adi/.adif file.
 * @param string $summit_ref_override Summit ref to use when MY_SOTA_REF is absent.
 * @param string $park_ref_override   Park ref(s) to use when no POTA park is in the record.
 * @return array
 */
function sota_magic_parse_adif_contacts( $log_url, $summit_ref_override = '', $park_ref_override = '' ) {
    $response = wp_remote_get( $log_url, [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) ) return [];
    $body = wp_remote_retrieve_body( $response );
    if ( ! $body ) return [];

    // Strip header (everything up to and including <EOH>)
    $body = preg_replace( '/^.*?<EOH>/is', '', $body );

    $raw_records = preg_split( '/<EOR>/i', $body );
    $contacts    = [];

    foreach ( $raw_records as $record ) {
        $record = trim( $record );
        if ( '' === $record ) continue;

        $call = sota_magic_adif_field( $record, 'call' );
        if ( '' === $call ) continue;

        // Date: YYYYMMDD → DD/MM/YY
        $raw_date = sota_magic_adif_field( $record, 'qso_date' );
        $date     = '';
        if ( strlen( $raw_date ) === 8 ) {
            $date = substr( $raw_date, 6, 2 ) . '/' . substr( $raw_date, 4, 2 ) . '/' . substr( $raw_date, 2, 2 );
        }

        // Time: HHMMSS → HH:MM
        $raw_time = sota_magic_adif_field( $record, 'time_on' );
        $time     = ( strlen( $raw_time ) >= 4 )
            ? substr( $raw_time, 0, 2 ) . ':' . substr( $raw_time, 2, 2 )
            : $raw_time;

        // Frequency: prefer <FREQ> (MHz), fall back to <BAND>
        $freq = sota_magic_adif_field( $record, 'freq' );
        if ( '' === $freq ) $freq = sota_magic_adif_field( $record, 'band' );
        // Normalize MHz to min 3 / max 5 decimal places (144.2 → 144.200; 14.0675 → 14.0675); leave band labels alone
        if ( is_numeric( $freq ) ) {
            $parts   = explode( '.', $freq );
            $decimal = isset( $parts[1] ) ? strlen( $parts[1] ) : 0;
            $freq    = number_format( (float) $freq, max( 3, min( 5, $decimal ) ), '.', '' );
        }

        $mode        = sota_magic_adif_field( $record, 'mode' );
        $my_sota_ref = strtoupper( sota_magic_adif_field( $record, 'my_sota_ref' ) );
        if ( '' === $my_sota_ref ) $my_sota_ref = strtoupper( $summit_ref_override );
        $sota_ref    = strtoupper( sota_magic_adif_field( $record, 'sota_ref' ) );
        $comments    = sota_magic_adif_field( $record, 'comment' );
        if ( '' === $comments ) $comments = sota_magic_adif_field( $record, 'notes' );
        $gridsquare  = sota_magic_adif_field( $record, 'gridsquare' );

        // POTA: MY_SIG/MY_SIG_INFO describe the activator's park(s); SIG/SIG_INFO the other station's.
        $my_sig   = strtoupper( sota_magic_adif_field( $record, 'my_sig' ) );
        $their_sig = strtoupper( sota_magic_adif_field( $record, 'sig' ) );
        $my_park  = sota_magic_adif_field( $record, 'my_pota_ref' );
        if ( '' === $my_park && ( 'POTA' === $my_sig || '' === $my_sig ) ) $my_park = sota_magic_adif_field( $record, 'my_sig_info' );
        $their_park = sota_magic_adif_field( $record, 'pota_ref' );
        if ( '' === $their_park && ( 'POTA' === $their_sig || '' === $their_sig ) ) $their_park = sota_magic_adif_field( $record, 'sig_info' );
        $my_park    = implode( ',', sota_magic_park_refs( $my_park ) );
        $their_park = implode( ',', sota_magic_park_refs( $their_park ) );
        if ( '' === $my_park ) $my_park = implode( ',', sota_magic_park_refs( $park_ref_override ) );

        $contacts[] = [
            'my_summit'    => $my_sota_ref,
            'date'         => $date,
            'time'         => $time,
            'frequency'    => $freq,
            'mode'         => $mode,
            'callsign'     => strtoupper( $call ),
            'their_summit' => $sota_ref,
            'comments'     => $comments,
            'gridsquare'   => $gridsquare,
            'my_park'      => $my_park,
            'their_park'   => $their_park,
        ];
    }

    return $contacts;
}

// BLOCK REGISTRATION
add_action('init', function() {
    wp_register_style( 'activator-toolkit', plugins_url( 'activator-toolkit.css', __FILE__ ), [], '1.0.4' );
    register_block_type('ki6cr/sota-data', [
        'editor_script'   => 'sota-editor-js',
        'style'           => 'activator-toolkit',
        'render_callback' => 'sota_magic_render_sota_data',
        'supports'        => [ 'align' => [ 'wide', 'full' ] ],
    ]);
});

// AJAX: Clear callsign location cache (preserves S2S summit cache)
add_action('wp_ajax_sota_magic_clear_location_cache', function() {
    check_ajax_referer('sota_magic_clear_location_cache');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE source NOT IN ('sota','pota')" );
    $wpdb->query( "DELETE FROM $table WHERE source NOT IN ('sota','pota')" );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    wp_send_json_success($count . ' cached location(s) cleared. Fresh lookups will run on next map load.');
});

// AJAX: Delete a single callsign location from cache
add_action('wp_ajax_sota_magic_delete_single_location', function() {
    check_ajax_referer('sota_magic_delete_single_location');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');
    $cache_key = isset($_POST['cache_key']) ? sanitize_text_field(wp_unslash($_POST['cache_key'])) : '';
    if (!$cache_key || !preg_match('/^(loc_|qrz_)/', $cache_key)) wp_send_json_error('Invalid cache key.');
    global $wpdb;
    $table = $wpdb->prefix . 'sota_magic_locations';
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($table, ['cache_key' => $cache_key], ['%s']);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    wp_send_json_success('Deleted.');
});

// Contact map helper functions (shared by the HTML shell and data endpoint)

function sota_magic_location_read( $cache_key ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT lat, lon, label, source FROM $table WHERE cache_key = %s AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
        $cache_key
    ) );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function sota_magic_location_write( $cache_key, $lat, $lon, $label, $source, $expires_seconds = 0 ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    $wpdb->replace( $table, [
        'cache_key'  => $cache_key,
        'lat'        => $lat,
        'lon'        => $lon,
        'label'      => $label,
        'source'     => $source,
        'expires_at' => $expires_seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_seconds ) : null,
        'cached_at'  => gmdate( 'Y-m-d H:i:s' ),
    ] );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function sota_magic_extract_grid_square( $text ) {
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2}[A-X]{2})(?![A-Z0-9])/i', $text, $m ) ) return strtoupper( $m[1] );
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2})(?![A-Z0-9])/i',         $text, $m ) ) return strtoupper( $m[1] );
    return null;
}

function sota_magic_maidenhead_to_latlon( $grid ) {
    $g   = strtoupper( $grid );
    $lon = ( ord( $g[0] ) - ord( 'A' ) ) * 20 - 180;
    $lat = ( ord( $g[1] ) - ord( 'A' ) ) * 10 - 90;
    $lon += ( ord( $g[2] ) - ord( '0' ) ) * 2;
    $lat += ( ord( $g[3] ) - ord( '0' ) );
    if ( strlen( $g ) >= 6 ) {
        $lon += ( ord( $g[4] ) - ord( 'A' ) ) * ( 5.0 / 60 ) + ( 5.0 / 60 / 2 );
        $lat += ( ord( $g[5] ) - ord( 'A' ) ) * ( 2.5 / 60 ) + ( 2.5 / 60 / 2 );
    } else {
        $lon += 1.0;
        $lat += 0.5;
    }
    return [ 'lat' => round( $lat, 6 ), 'lon' => round( $lon, 6 ) ];
}

function sota_magic_get_band_color( $frequency ) {
    $freq = floatval( $frequency );
    if ( $freq >= 1.8   && $freq < 2.0   ) return '#8B4513';
    if ( $freq >= 3.5   && $freq < 4.0   ) return '#FFA500';
    if ( $freq >= 7.0   && $freq < 7.3   ) return '#FFD700';
    if ( $freq >= 10.1  && $freq < 10.15 ) return '#FFFF00';
    if ( $freq >= 14.0  && $freq < 14.35 ) return '#00FF00';
    if ( $freq >= 18.068 && $freq < 18.168 ) return '#00CED1';
    if ( $freq >= 21.0  && $freq < 21.45 ) return '#0000FF';
    if ( $freq >= 24.89 && $freq < 24.99 ) return '#4B0082';
    if ( $freq >= 28.0  && $freq < 29.7  ) return '#8B00FF';
    if ( $freq >= 50.0  && $freq < 54.0  ) return '#FF1493';
    if ( $freq >= 144.0 && $freq < 148.0 ) return '#FF69B4';
    if ( $freq >= 222.0 && $freq < 225.0 ) return '#FFB6C1';
    if ( $freq >= 420.0 && $freq < 450.0 ) return '#FFC0CB';
    return '#999999';
}

// AJAX: Serve the contact map iframe page (HTML shell only; data loads asynchronously)
function sota_magic_render_contact_map_ajax() {
    if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
        wp_die( 'Invalid request.', '', [ 'response' => 403 ] );
    }
    include plugin_dir_path( __FILE__ ) . 'contact-map.php';
    wp_die();
}
add_action( 'wp_ajax_sota_magic_contact_map',        'sota_magic_render_contact_map_ajax' );
add_action( 'wp_ajax_nopriv_sota_magic_contact_map', 'sota_magic_render_contact_map_ajax' );

// AJAX: Return contact map data as JSON (called asynchronously by contact-map.js)
function sota_magic_contact_map_data_ajax() {
    if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
        wp_send_json( [ 'error' => 'Invalid nonce' ], 403 );
    }

    $debug_mode = ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' );
    $csv_url    = isset( $_GET['csv'] )        ? esc_url_raw( wp_unslash( $_GET['csv'] ) )               : '';
    $log_format = isset( $_GET['format'] )     ? sanitize_key( wp_unslash( $_GET['format'] ) )            : 'csv';
    $summit_ref = isset( $_GET['summit_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['summit_ref'] ) ) : '';
    $park_ref   = isset( $_GET['park_ref'] )   ? sanitize_text_field( wp_unslash( $_GET['park_ref'] ) )   : '';
    $program    = ( isset( $_GET['program'] ) && 'pota' === $_GET['program'] ) ? 'pota' : 'sota';
    if ( 'pota' === $program ) $log_format = 'adif'; // POTA logs are ADIF only

    if ( ! $csv_url ) {
        wp_send_json( [ 'error' => 'No log file specified' ] );
    }

    $qrz_user    = get_option( 'sota_qrz_username' );
    $qrz_pass    = sota_magic_decrypt_credential( get_option( 'sota_qrz_password' ) );
    $hamqth_user = get_option( 'sota_hamqth_username' );
    $hamqth_pass = sota_magic_decrypt_credential( get_option( 'sota_hamqth_password' ) );

    // Parse log file
    $contacts = [];
    if ( $log_format === 'adif' ) {
        $contacts = sota_magic_parse_adif_contacts( $csv_url, $summit_ref, $park_ref );
    } else {
        $csv_response = wp_remote_get( $csv_url, [ 'timeout' => 15 ] );
        if ( ! is_wp_error( $csv_response ) ) {
            $csv_body = wp_remote_retrieve_body( $csv_response );
            foreach ( explode( "\n", $csv_body ) as $csv_line ) {
                $row = str_getcsv( trim( $csv_line ) );
                if ( ! empty( $row[0] ) && $row[0] === 'V2' ) {
                    $contacts[] = [
                        'my_summit'    => strtoupper( $row[2] ?? '' ),
                        'date'         => $row[3] ?? '',
                        'time'         => $row[4] ?? '',
                        'frequency'    => $row[5] ?? '',
                        'mode'         => $row[6] ?? '',
                        'callsign'     => $row[7] ?? '',
                        'their_summit' => strtoupper( trim( $row[8] ?? '' ) ),
                        'comments'     => trim( $row[9] ?? '' ),
                    ];
                }
            }
        }
    }

    // POTA: the activator's park(s) come from the POTA API (cached 90 days). First park is the origin.
    $summit         = null;
    $extra_origins  = [];
    if ( 'pota' === $program ) {
        $my_parks = sota_magic_park_refs( $park_ref );
        foreach ( $contacts as $c ) {
            foreach ( sota_magic_park_refs( $c['my_park'] ?? '' ) as $ref ) {
                if ( ! in_array( $ref, $my_parks, true ) ) $my_parks[] = $ref;
            }
        }
        foreach ( $my_parks as $ref ) {
            $info = sota_magic_get_park_info( $ref );
            if ( ! $info ) continue;
            $origin = [ 'lat' => $info['lat'], 'lon' => $info['lon'], 'name' => $info['name'], 'ref' => $ref, 'kind' => 'park' ];
            if ( ! $summit ) $summit = $origin; else $extra_origins[] = $origin;
        }
    }

    // Get summit location from SOTA API (cached 90 days)
    if ( 'sota' === $program && ! empty( $contacts[0]['my_summit'] ) ) {
        $summit_ref_val = $contacts[0]['my_summit'];
        $summit_key     = 'summit_' . sanitize_key( $summit_ref_val );
        $summit_row     = sota_magic_location_read( $summit_key );
        if ( $summit_row ) {
            $summit = [ 'lat' => floatval( $summit_row->lat ), 'lon' => floatval( $summit_row->lon ), 'name' => $summit_row->label ?: $summit_ref_val, 'ref' => $summit_ref_val ];
        } else {
            $api_url  = 'https://api2.sota.org.uk/api/summits/' . $summit_ref_val;
            $wp_resp  = wp_remote_get( $api_url, [ 'timeout' => 30, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
            $response = ! is_wp_error( $wp_resp ) ? wp_remote_retrieve_body( $wp_resp ) : false;
            if ( $response !== false ) {
                $summit_data = json_decode( $response, true );
                if ( $summit_data && isset( $summit_data['latitude'], $summit_data['longitude'] ) ) {
                    $summit_name = $summit_data['name'] ?? $summit_ref_val;
                    $summit = [ 'lat' => floatval( $summit_data['latitude'] ), 'lon' => floatval( $summit_data['longitude'] ), 'name' => $summit_name, 'ref' => $summit_ref_val ];
                    sota_magic_location_write( $summit_key, $summit['lat'], $summit['lon'], $summit_name, 'sota', 90 * DAY_IN_SECONDS );
                }
            }
        }
    }

    // Get QRZ session
    $qrz_session = null;
    if ( $qrz_user && $qrz_pass ) {
        $login_wp = wp_remote_get( 'https://xmldata.qrz.com/xml/current/?username=' . rawurlencode( $qrz_user ) . '&password=' . rawurlencode( $qrz_pass ), [ 'timeout' => 15 ] );
        $login_body = ! is_wp_error( $login_wp ) ? wp_remote_retrieve_body( $login_wp ) : false;
        if ( $login_body ) { preg_match( '/<Key>([^<]+)<\/Key>/', $login_body, $qrz_m ); if ( ! empty( $qrz_m[1] ) ) $qrz_session = $qrz_m[1]; }
    }

    // Get HamQTH session
    $hamqth_session = null;
    if ( $hamqth_user && $hamqth_pass ) {
        $hamqth_login_wp   = wp_remote_get( 'https://www.hamqth.com/xml.php?u=' . rawurlencode( $hamqth_user ) . '&p=' . rawurlencode( $hamqth_pass ), [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
        $hamqth_login_body = ! is_wp_error( $hamqth_login_wp ) ? wp_remote_retrieve_body( $hamqth_login_wp ) : false;
        if ( $hamqth_login_body ) { preg_match( '/<session_id>([^<]+)<\/session_id>/', $hamqth_login_body, $hqth_m ); if ( ! empty( $hqth_m[1] ) ) $hamqth_session = $hqth_m[1]; }
    }

    // Resolve contact locations
    $contact_locations = [];
    $unresolved        = [];
    $lookup_fail_debug = [];

    foreach ( $contacts as $contact ) {
        $callsign   = $contact['callsign'];
        $is_s2s     = 'sota' === $program && ! empty( $contact['their_summit'] );
        $band_color = sota_magic_get_band_color( $contact['frequency'] );

        // Priority 1 (POTA): P2P, use the other station's park coordinates
        if ( 'pota' === $program ) {
            $their_parks = sota_magic_park_refs( $contact['their_park'] ?? '' );
            $their_info  = $their_parks ? sota_magic_get_park_info( $their_parks[0] ) : null;
            if ( $their_info ) {
                $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $their_info['lat'], 'lon' => $their_info['lon'], 'summit' => '', 'park' => $their_info['ref'], 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'is_p2p' => true, 'color' => $band_color, 'location_source' => 'pota', 'cached' => ! empty( $their_info['cached'] ) ];
                continue;
            }
            // Park lookup failed: fall through to grid / callsign lookups below
        }

        // Priority 1: S2S, from SOTA API summit coordinates
        if ( $is_s2s ) {
            $their_ref = $contact['their_summit'];
            $s2s_key   = 'summit_' . sanitize_key( $their_ref );
            $s2s_row   = sota_magic_location_read( $s2s_key );
            if ( $s2s_row ) {
                $contact_locations[] = [ 'callsign' => $callsign, 'lat' => floatval( $s2s_row->lat ), 'lon' => floatval( $s2s_row->lon ), 'summit' => $their_ref, 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => true, 'color' => $band_color, 'location_source' => 'sota', 'cached' => true ];
            } else {
                $their_wp   = wp_remote_get( 'https://api2.sota.org.uk/api/summits/' . $their_ref, [ 'timeout' => 15, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
                $their_body = ! is_wp_error( $their_wp ) ? wp_remote_retrieve_body( $their_wp ) : false;
                if ( $their_body !== false ) {
                    $their_data = json_decode( $their_body, true );
                    if ( $their_data && isset( $their_data['latitude'], $their_data['longitude'] ) ) {
                        $s2s_lat = floatval( $their_data['latitude'] ); $s2s_lon = floatval( $their_data['longitude'] );
                        sota_magic_location_write( $s2s_key, $s2s_lat, $s2s_lon, $their_ref, 'sota', 90 * DAY_IN_SECONDS );
                        $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $s2s_lat, 'lon' => $s2s_lon, 'summit' => $their_ref, 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => true, 'color' => $band_color, 'location_source' => 'sota', 'cached' => false ];
                    } else { $unresolved[] = [ 'callsign' => $callsign, 'reason' => 'SOTA API returned no coordinates for ' . $their_ref ]; }
                } else { $unresolved[] = [ 'callsign' => $callsign, 'reason' => 'SOTA API unreachable for ' . $their_ref ]; }
            }
            continue;
        }

        // Priority 2: grid square, from the ADIF gridsquare field, then comments
        $grid = sota_magic_extract_grid_square( $contact['gridsquare'] ?? '' )
             ?? sota_magic_extract_grid_square( $contact['comments'] );
        if ( $grid ) {
            $coords = sota_magic_maidenhead_to_latlon( $grid );
            $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $coords['lat'], 'lon' => $coords['lon'], 'summit' => '', 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'color' => $band_color, 'location_source' => 'grid', 'grid' => $grid, 'cached' => true ];
            continue;
        }

        // Priority 3: check unified cache (also check legacy qrz_ key)
        $loc_key    = 'loc_' . sanitize_key( strtolower( $callsign ) );
        $legacy_key = 'qrz_' . sanitize_key( strtolower( $callsign ) );
        $cached_row = sota_magic_location_read( $loc_key ) ?? sota_magic_location_read( $legacy_key );
        if ( $cached_row ) {
            $contact_locations[] = [ 'callsign' => $callsign, 'lat' => floatval( $cached_row->lat ), 'lon' => floatval( $cached_row->lon ), 'summit' => '', 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'color' => $band_color, 'location_source' => $cached_row->source, 'cached' => true ];
            continue;
        }

        $fail_reasons = [];

        // Priority 4: Callook.info, free, US callsigns only
        $callook_wp   = wp_remote_get( 'https://callook.info/' . rawurlencode( $callsign ) . '/json', [ 'timeout' => 10, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
        $callook_body = ! is_wp_error( $callook_wp ) ? wp_remote_retrieve_body( $callook_wp ) : false;
        if ( $callook_body ) {
            $callook_data = json_decode( $callook_body, true );
            if ( isset( $callook_data['status'] ) && $callook_data['status'] === 'VALID' && ! empty( $callook_data['location']['latitude'] ) && ! empty( $callook_data['location']['longitude'] ) ) {
                $cl_lat = floatval( $callook_data['location']['latitude'] ); $cl_lon = floatval( $callook_data['location']['longitude'] );
                sota_magic_location_write( $loc_key, $cl_lat, $cl_lon, $callsign, 'callook', 0 );
                $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $cl_lat, 'lon' => $cl_lon, 'summit' => '', 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'color' => $band_color, 'location_source' => 'callook', 'cached' => false ];
                usleep( 250000 ); continue;
            }
            $fail_reasons[] = 'Callook: not a US callsign';
        } else { $fail_reasons[] = 'Callook: request failed'; }

        // Priority 5: HamQTH, free account, international
        if ( $hamqth_session ) {
            $hqth_wp   = wp_remote_get( 'https://www.hamqth.com/xml.php?id=' . rawurlencode( $hamqth_session ) . '&callsign=' . rawurlencode( $callsign ) . '&prg=Activator-Toolkit-for-SOTA', [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
            $hqth_body = ! is_wp_error( $hqth_wp ) ? wp_remote_retrieve_body( $hqth_wp ) : false;
            if ( $hqth_body ) {
                preg_match( '/<latitude>([^<]+)<\/latitude>/', $hqth_body, $hqth_lat_m );
                preg_match( '/<longitude>([^<]+)<\/longitude>/', $hqth_body, $hqth_lon_m );
                if ( ! empty( $hqth_lat_m[1] ) && ! empty( $hqth_lon_m[1] ) ) {
                    $hq_lat = floatval( $hqth_lat_m[1] ); $hq_lon = floatval( $hqth_lon_m[1] );
                    sota_magic_location_write( $loc_key, $hq_lat, $hq_lon, $callsign, 'hamqth', 0 );
                    $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $hq_lat, 'lon' => $hq_lon, 'summit' => '', 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'color' => $band_color, 'location_source' => 'hamqth', 'cached' => false ];
                    usleep( 250000 ); continue;
                }
                $fail_reasons[] = 'HamQTH: no coordinates in response';
                if ( $debug_mode ) $lookup_fail_debug[ $callsign ] = substr( $hqth_body, 0, 1000 );
            } else { $fail_reasons[] = 'HamQTH: request failed'; }
            usleep( 250000 );
        } else { $fail_reasons[] = 'HamQTH: not configured'; }

        // Priority 6: QRZ.com, paid subscription, international
        if ( $qrz_session ) {
            $qrz_wp   = wp_remote_get( 'https://xmldata.qrz.com/xml/current/?s=' . rawurlencode( $qrz_session ) . '&callsign=' . rawurlencode( $callsign ), [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
            $qrz_body = ! is_wp_error( $qrz_wp ) ? wp_remote_retrieve_body( $qrz_wp ) : false;
            if ( $qrz_body ) {
                preg_match( '/<lat>([^<]+)<\/lat>/', $qrz_body, $lat_m ); preg_match( '/<lon>([^<]+)<\/lon>/', $qrz_body, $lon_m );
                if ( ! empty( $lat_m[1] ) && ! empty( $lon_m[1] ) ) {
                    $qz_lat = floatval( $lat_m[1] ); $qz_lon = floatval( $lon_m[1] );
                    sota_magic_location_write( $loc_key, $qz_lat, $qz_lon, $callsign, 'qrz', 0 );
                    $contact_locations[] = [ 'callsign' => $callsign, 'lat' => $qz_lat, 'lon' => $qz_lon, 'summit' => '', 'mode' => $contact['mode'], 'frequency' => $contact['frequency'], 'is_s2s' => false, 'color' => $band_color, 'location_source' => 'qrz', 'cached' => false ];
                    usleep( 500000 ); continue;
                }
                $fail_reasons[] = 'QRZ: no coordinates in response';
                if ( $debug_mode ) $lookup_fail_debug[ $callsign ] = substr( $qrz_body, 0, 1000 );
            } else { $fail_reasons[] = 'QRZ: request failed'; }
            usleep( 500000 );
        } else { $fail_reasons[] = 'QRZ: not configured'; }

        $unresolved[] = [ 'callsign' => $callsign, 'reason' => implode( '; ', $fail_reasons ) ];
    }

    // Build the map contacts array
    $map_contacts = [];
    foreach ( $contact_locations as $loc ) {
        $dist_miles = null; $dist_km = null;
        if ( $summit ) {
            $dist_m     = sota_magic_haversine_distance( $summit['lat'], $summit['lon'], $loc['lat'], $loc['lon'] );
            $dist_km    = round( $dist_m / 1000, 1 );
            $dist_miles = round( $dist_km * 0.621371, 1 );
        }
        $map_contacts[] = [
            'lat'             => floatval( $loc['lat'] ),
            'lon'             => floatval( $loc['lon'] ),
            'callsign'        => $loc['callsign'],
            's2s_summit'      => $loc['summit'] ?? null,
            'frequency'       => $loc['frequency'],
            'mode'            => $loc['mode'],
            'color'           => $loc['color'],
            'is_s2s'          => (bool) $loc['is_s2s'],
            'is_p2p'          => ! empty( $loc['is_p2p'] ),
            'p2p_park'        => $loc['park'] ?? null,
            'location_source' => $loc['location_source'],
            'grid'            => $loc['grid'] ?? null,
            'dist_miles'      => $dist_miles,
            'dist_km'         => $dist_km,
            'cached'          => isset( $loc['cached'] ) ? (bool) $loc['cached'] : true,
        ];
    }

    // Collect debug metadata
    $debug_meta = null;
    if ( $debug_mode ) {
        $cached_count = 0; $fresh_count = 0;
        foreach ( $contact_locations as $loc ) { if ( ! empty( $loc['cached'] ) ) $cached_count++; else $fresh_count++; }
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $loc_table  = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $loc_table" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $debug_meta = [
            'program'              => $program,
            'summit_found'         => (bool) $summit,
            'summit_ref'           => $summit ? $summit['ref']  : '',
            'summit_lat'           => $summit ? $summit['lat']  : null,
            'summit_lon'           => $summit ? $summit['lon']  : null,
            'raw_contacts_count'   => count( $contacts ),
            'first_my_summit'      => 'pota' === $program ? ( $contacts[0]['my_park'] ?? '(empty)' ) : ( $contacts[0]['my_summit'] ?? '(empty)' ),
            'resolved_count'       => count( $contact_locations ),
            'lines_drawn'          => $summit ? count( $contact_locations ) : 0,
            'cached_count'         => $cached_count,
            'fresh_count'          => $fresh_count,
            'unresolved_count'     => count( $unresolved ),
            'locations_table'      => $wpdb->prefix . 'sota_magic_locations',
            'locations_table_rows' => $total_rows,
            'db_error'             => $wpdb->last_error ?: '',
            'unresolved'           => $unresolved,
            'lookup_fail_debug'    => $lookup_fail_debug,
        ];
    }

    wp_send_json( [
        'program'    => $program,
        'summit'     => $summit ? [ 'lat' => floatval( $summit['lat'] ), 'lon' => floatval( $summit['lon'] ), 'name' => $summit['name'], 'ref' => $summit['ref'], 'kind' => $summit['kind'] ?? 'summit' ] : null,
        'extra_origins' => $extra_origins,
        'contacts'   => $map_contacts,
        'unresolved' => $unresolved,
        'debug'      => $debug_mode,
        'debug_meta' => $debug_meta,
    ] );
}
add_action( 'wp_ajax_sota_magic_contact_map_data',        'sota_magic_contact_map_data_ajax' );
add_action( 'wp_ajax_nopriv_sota_magic_contact_map_data', 'sota_magic_contact_map_data_ajax' );

// Enqueue frontend stylesheet + dynamic color overrides
add_action('wp_enqueue_scripts', function() {

    $bg             = get_option('sota_is_transparent') ? 'transparent' : get_option('sota_bg_color', '#ffffff');
    $text           = get_option('sota_text_color', '#333333');
    $font           = get_option('sota_use_theme_font') ? 'inherit' : 'sans-serif';
    $s2s_bg         = get_option('sota_s2s_highlight', '#ffebee');
    $s2s_text       = get_option('sota_s2s_text_color', '#d32f2f');
    $p2p_bg         = get_option('pota_p2p_highlight', '#e8f5e9');
    $p2p_text       = get_option('pota_p2p_text_color', '#2e7d32');
    $is_transparent = (bool) get_option('sota_is_transparent');
    $box_shadow     = $is_transparent ? '' : 'box-shadow:0 5px 20px rgba(0,0,0,0.1);';
    $stat_bg        = $is_transparent ? 'rgba(255,255,255,0.05)' : esc_attr($bg) . '22';
    $stat_box_bg    = $is_transparent ? 'rgba(255,255,255,0.1)' : '#fff';
    $stat_box_shad  = $is_transparent ? '' : 'box-shadow:0 2px 5px rgba(0,0,0,0.05);';

    $dynamic_css = "
        .sota-main-container{background:" . esc_attr($bg) . ";color:" . esc_attr($text) . ";font-family:" . esc_attr($font) . ";" . $box_shadow . "}
        .sota-main-container h3{color:" . esc_attr($text) . ";border-bottom-color:" . esc_attr($text) . "44;}
        .sota-gpx-stats{background:" . $stat_bg . ";border-color:" . esc_attr($text) . "22;}
        .sota-stat-box{background:" . $stat_box_bg . ";" . $stat_box_shad . "}
        .sota-stat-value{color:" . esc_attr($text) . ";}
        .sota-stat-label{color:" . esc_attr($text) . "99;}
        .sota-stat-secondary{color:" . esc_attr($text) . "77;}
        .sota-table{color:" . esc_attr($text) . ";}
        .sota-table th{border-bottom-color:" . esc_attr($text) . "66;}
        .sota-table td{border-bottom-color:" . esc_attr($text) . "22;}
        .s2s-row td{background:" . esc_attr($s2s_bg) . "!important;color:" . esc_attr($s2s_text) . "!important;}
        .s2s-badge{background:" . esc_attr($s2s_text) . ";}
        .p2p-row td{background:" . esc_attr($p2p_bg) . "!important;color:" . esc_attr($p2p_text) . "!important;}
        .p2p-badge{background:" . esc_attr($p2p_text) . ";}
    ";

    wp_add_inline_style( 'activator-toolkit', $dynamic_css );
});

add_action('enqueue_block_editor_assets', function() {
    wp_register_script('sota-editor-js', '', ['wp-blocks','wp-element','wp-block-editor','wp-editor','wp-components'], '1.1.5', true);
    wp_localize_script('sota-editor-js', 'sotaMagicAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sota_magic_clear_location_cache'),
        'defaultProgram' => get_option('sota_default_program', 'sota'),
    ]);
    wp_add_inline_script('sota-editor-js', "
        wp.blocks.registerBlockType('ki6cr/sota-data', {
            title: 'Activator Toolkit (SOTA / POTA)',
            icon: 'location-alt',
            category: 'text',
            attributes: {
                gpxUrl: {type:'string'},
                csvUrl: {type:'string'},
                forceRadiusZone: {type:'boolean', default:false},
                zoneSource: {type:'string', default:''},
                overrideHikingDistanceEnabled: {type:'boolean', default:false},
                overrideHikingDistance: {type:'string', default:''},
                overrideHikingTimeEnabled: {type:'boolean', default:false},
                overrideHikingTime: {type:'string', default:''},
                overrideActivationTimeEnabled: {type:'boolean', default:false},
                overrideActivationTime: {type:'string', default:''},
                overrideRestBreaksEnabled: {type:'boolean', default:false},
                overrideRestBreaks: {type:'string', default:''},
                overrideTotalTimeEnabled: {type:'boolean', default:false},
                overrideTotalTime: {type:'string', default:''},
                hideGpxStats: {type:'boolean', default:false},
                logFormat: {type:'string', default:'csv'},
                mySummitRef: {type:'string', default:''},
                activationProgram: {type:'string', default:''},
                myParkRef: {type:'string', default:''}
            },
            edit: function(props) {
                var _ms = wp.element.useState(false);
                var showModal = _ms[0];
                var setShowModal = _ms[1];
                var _srf = wp.element.useState(false);
                var showSummitRefModal = _srf[0];
                var setShowSummitRefModal = _srf[1];
                var _sri = wp.element.useState('');
                var summitRefInput = _sri[0];
                var setSummitRefInput = _sri[1];
                var _prf = wp.element.useState(false);
                var showParkRefModal = _prf[0];
                var setShowParkRefModal = _prf[1];
                var _pri = wp.element.useState('');
                var parkRefInput = _pri[0];
                var setParkRefInput = _pri[1];
                var attrs = props.attributes;
                var siteDefault = (sotaMagicAdmin.defaultProgram === 'pota') ? 'pota' : 'sota';
                var legacySota = !!(attrs.gpxUrl || attrs.mySummitRef || (attrs.csvUrl && attrs.logFormat === 'csv'));
                var program = attrs.activationProgram || 'sota';
                var isPota = (program === 'pota');
                function setProgram(p) { props.setAttributes({activationProgram: p}); }
                wp.element.useEffect(function() {
                    // Brand-new, empty blocks record their starting mode; saved blocks are left alone
                    if (!attrs.activationProgram && !legacySota && !attrs.csvUrl && !attrs.myParkRef) {
                        props.setAttributes({activationProgram: siteDefault});
                    }
                }, []);
                var InspectorControls = (wp.blockEditor || wp.editor).InspectorControls;
                var programToggle = wp.element.createElement(wp.components.ButtonGroup, null,
                    wp.element.createElement(wp.components.Button, {variant: isPota ? 'secondary' : 'primary', onClick: function() { setProgram('sota'); }}, 'SOTA (Summits)'),
                    wp.element.createElement(wp.components.Button, {variant: isPota ? 'primary' : 'secondary', onClick: function() { setProgram('pota'); }}, 'POTA (Parks)')
                );
                return wp.element.createElement(wp.element.Fragment, null,
                    wp.element.createElement(InspectorControls, null,
                        wp.element.createElement(wp.components.PanelBody, {title:'Activation Details', initialOpen:true},
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', fontWeight:'600'}}, 'Activation Program'),
                            wp.element.createElement('div', {style:{marginBottom:'16px'}}, programToggle),
                            isPota ? wp.element.createElement(wp.components.TextControl, {
                                label:'Park Reference',
                                help:'Auto-detected from your log file. Edit if needed (e.g. US-3315). For several parks, separate them with commas.',
                                value:props.attributes.myParkRef || '',
                                onChange:function(val){props.setAttributes({myParkRef:val});}
                            }) : wp.element.createElement(wp.components.TextControl, {
                                label:'Summit Reference',
                                help:'Auto-detected from your log file. Edit if needed (e.g. W6/SC-219).',
                                value:props.attributes.mySummitRef || '',
                                onChange:function(val){props.setAttributes({mySummitRef:val});}
                            })
                        )
                    ),
                    wp.element.createElement('div', {
                        style:{padding:'25px', background:'\\x23f5f5f5', border:'2px dashed \\x230073aa', borderRadius:'8px', textAlign:'center'}
                    },
                    wp.element.createElement('h3', {style:{margin:'0 0 10px 0', color:'\\x230073aa'}}, 'Activator Toolkit'),
                    wp.element.createElement('div', {style:{marginBottom:'12px'}}, programToggle),
                    wp.element.createElement('p', {style:{color:'\\x23d32f2f', fontWeight:'bold', margin:'0 0 10px 0'}}, 'Map and table visible in Preview only'),
                    wp.element.createElement('p', {style:{color:'\\x23666', fontSize:'13px', marginBottom:'16px'}}, 'Settings → Activator Toolkit to customize colors, units, and more.'),
                    wp.element.createElement('div', {style:{textAlign:'left', marginBottom:'16px'}},
                        !isPota && wp.element.createElement('div', {style:{background:'\\x23ffffff', border:'1px solid \\x23dddddd', borderRadius:'6px', padding:'12px 14px', marginBottom:'8px', display:'flex', alignItems:'center', gap:'12px'}},
                            wp.element.createElement('div', {style:{flex:'1', minWidth:'0'}},
                                wp.element.createElement('div', {style:{fontWeight:'700', fontSize:'13px', color:'\\x231e1e1e', marginBottom:'3px'}}, '📍 GPS Track (.gpx)'),
                                wp.element.createElement('div', {style:{fontSize:'12px', color:'\\x23666666', lineHeight:'1.5'}}, 'The track file exported from your GPS device or app, such as Garmin, Gaia GPS, or CalTopo.')
                            ),
                            wp.element.createElement(wp.blockEditor.MediaUpload, {
                                onSelect: function(media) { props.setAttributes({gpxUrl: media.url}); },
                                allowedTypes: ['application/gpx+xml', 'text/xml'],
                                render: function(obj) {
                                    return wp.element.createElement(wp.components.Button, {
                                        variant: props.attributes.gpxUrl ? 'primary' : 'secondary',
                                        onClick: obj.open,
                                        style:{flexShrink:'0', whiteSpace:'nowrap'}
                                    }, props.attributes.gpxUrl ? '✓ GPX Uploaded' : 'Upload GPX');
                                }
                            }),
                            props.attributes.gpxUrl && wp.element.createElement(wp.components.Button, {
                                icon: 'trash',
                                label: 'Remove GPX file',
                                isSmall: true,
                                onClick: function() { props.setAttributes({gpxUrl: ''}); },
                                style:{flexShrink:'0', color:'\\x23999999'}
                            })
                        ),
                        wp.element.createElement('div', {style:{background:'\\x23ffffff', border:'1px solid \\x23dddddd', borderRadius:'6px', padding:'12px 14px', display:'flex', alignItems:'center', gap:'12px'}},
                            wp.element.createElement('div', {style:{flex:'1', minWidth:'0'}},
                                wp.element.createElement('div', {style:{fontWeight:'700', fontSize:'13px', color:'\\x231e1e1e', marginBottom:'3px'}}, isPota ? '📋 POTA Log (.adi or .adif)' : '📋 Contacts Log (.csv or .adif)'),
                                wp.element.createElement('div', {style:{fontSize:'12px', color:'\\x23666666', lineHeight:'1.5'}}, isPota ? 'Upload the ADIF log from your POTA activation, the same file you submit to pota.app. Your park reference is detected automatically.' : 'Upload your SOTA formatted CSV or ADIF log file. The format is detected automatically.')
                            ),
                            wp.element.createElement(wp.blockEditor.MediaUpload, {
                                onSelect: function(media) {
                                    var url = media.url;
                                    var ext = url.split('?')[0].split('.').pop().toLowerCase();
                                    var isAdif = (ext === 'adif' || ext === 'adi');
                                    var fmt = (isAdif || isPota) ? 'adif' : 'csv';
                                    if (isPota) {
                                        props.setAttributes({csvUrl: url, logFormat: 'adif', myParkRef: ''});
                                    } else {
                                        props.setAttributes({csvUrl: url, logFormat: fmt, mySummitRef: ''});
                                    }
                                    fetch(url).then(function(r){ return r.text(); }).then(function(content){
                                        var ref = '';
                                        if (isPota) {
                                            var re = /<(my_sig_info|my_pota_ref):(\\d+)[^>]*>/ig, mm, seen = {}, list = [];
                                            while ((mm = re.exec(content)) !== null) {
                                                var v = content.substr(mm.index + mm[0].length, parseInt(mm[2], 10)).trim().toUpperCase();
                                                v.split(/[\\s,;]+/).forEach(function(x){ if (/^[A-Z0-9]{1,4}-\\d{3,6}\x24/.test(x) && !seen[x]) { seen[x] = 1; list.push(x); } });
                                            }
                                            ref = list.join(',');
                                            if (ref) { props.setAttributes({myParkRef: ref}); } else { setParkRefInput(''); setShowParkRefModal(true); }
                                            return;
                                        }
                                        if (isAdif) {
                                            var m = content.match(/<my_sota_ref:\\d+[^>]*>([^\\r\\n<]+)/i);
                                            ref = m ? m[1].trim() : '';
                                        } else {
                                            var lines = content.split('\\n');
                                            for (var i = 0; i < lines.length; i++) {
                                                var p = lines[i].split(',');
                                                if (p[0] && p[0].trim() === 'V2' && p[2] && p[2].trim()) { ref = p[2].trim(); break; }
                                            }
                                        }
                                        if (ref) { props.setAttributes({mySummitRef: ref}); } else { setSummitRefInput(''); setShowSummitRefModal(true); }
                                    }).catch(function(){});
                                },
                                allowedTypes: ['text/csv', 'text/plain'],
                                render: function(obj) {
                                    return wp.element.createElement(wp.components.Button, {
                                        variant: props.attributes.csvUrl ? 'primary' : 'secondary',
                                        onClick: obj.open,
                                        style:{flexShrink:'0', whiteSpace:'nowrap'}
                                    }, props.attributes.csvUrl ? '✓ Log Uploaded' : 'Upload Log File');
                                }
                            }),
                            props.attributes.csvUrl && wp.element.createElement(wp.components.Button, {
                                icon: 'trash',
                                label: 'Remove log file',
                                isSmall: true,
                                onClick: function() { props.setAttributes({csvUrl: '', logFormat: isPota ? 'adif' : 'csv'}); },
                                style:{flexShrink:'0', color:'\\x23999999'}
                            })
                        )
                    ),
                    !isPota && wp.element.createElement('div', {style:{textAlign:'center', marginBottom:'10px'}},
                        wp.element.createElement('button', {
                            onClick: function(e) { e.preventDefault(); setShowModal(true); },
                            style:{background:'none', border:'1px solid \\x230073aa', borderRadius:'20px', color:'\\x230073aa', fontSize:'12px', cursor:'pointer', padding:'3px 14px', lineHeight:'1.6'}
                        }, 'ℹ️ How are statistics calculated?')
                    ),
                    showModal && wp.element.createElement(wp.components.Modal, {
                        title: 'How Statistics Are Calculated',
                        onRequestClose: function() { setShowModal(false); }
                    },
                        wp.element.createElement('div', {style:{fontSize:'13px', lineHeight:'1.65', color:'\\x23333333', maxWidth:'500px'}},

                            wp.element.createElement('h3', {style:{marginTop:'0', marginBottom:'6px', color:'\\x230073aa', fontSize:'14px'}}, 'Activation Zone'),
                            wp.element.createElement('p', {style:{marginTop:'0'}}, 'The activation zone boundary is the foundation: all time stats depend on it. Here is how it is determined, in order:'),
                            wp.element.createElement('ol', {style:{paddingLeft:'18px', margin:'6px 0 0 0'}},
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Summit reference'), ' is read from your CSV file (e.g. W6/CT-001).'),
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Official coordinates'), ' are fetched from the SOTA API (api2.sota.org.uk).'),
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Activation.Zone API'), ' (by N6ARA) returns a precise terrain-based polygon using the official 25m vertical drop rule.'),
                                wp.element.createElement('li', null, wp.element.createElement('strong', null, 'Fallback:'), ' if the Activation.Zone API is unavailable, a radius circle is drawn around the official SOTA summit coordinates. If the SOTA API is also unavailable (or no summit reference was found), the highest GPS point in your track is used instead.')
                            ),

                            wp.element.createElement('hr', {style:{border:'none', borderTop:'1px solid \\x23eeeeee', margin:'14px 0'}}),

                            wp.element.createElement('h3', {style:{margin:'0 0 8px 0', color:'\\x230073aa', fontSize:'14px'}}, 'Time Statistics'),
                            wp.element.createElement('p', {style:{margin:'0 0 6px 0'}}, wp.element.createElement('strong', null, 'Activation Time: '), 'All time spent inside the activation zone, whether you are moving around the summit, setting up, or operating.'),
                            wp.element.createElement('p', {style:{margin:'0 0 6px 0'}}, wp.element.createElement('strong', null, 'Hiking Time: '), 'All time spent outside the zone. Rest breaks are included and shown as a sub-note under hiking time.'),
                            wp.element.createElement('p', {style:{margin:'0'}}, wp.element.createElement('strong', null, 'Total Time: '), 'Elapsed time from the first to the last GPS trackpoint.'),

                            wp.element.createElement('hr', {style:{border:'none', borderTop:'1px solid \\x23eeeeee', margin:'14px 0'}}),

                            wp.element.createElement('h3', {style:{margin:'0 0 8px 0', color:'\\x230073aa', fontSize:'14px'}}, 'Why Stats Might Look Wrong'),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Activation time is 0 or missing'), ': The plugin could not locate the activation zone. Check that your CSV file includes a valid summit reference. Turn on Debug Mode in Settings → Activator Toolkit for details.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Zone looks wrong on the map'), ': Look for a red polygon (API-based) or orange circle (radius fallback) on the map. If you see a circle, the API did not return a zone. Try increasing the radius in Settings, or use the Statistics Override below to force a specific value.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Hiking time seems too high'), ': Rest breaks are included in hiking time. Adjust the rest break threshold in Settings → Activator Toolkit.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'GPS track does not reach the summit'), ': The zone is centred on the official SOTA summit coordinates (or the highest GPS point if the SOTA API was unavailable). If you stopped before the peak, use the Activation Zone radius override below or manually enter the activation time.'
                            )
                        )
                    ),
                    showParkRefModal && wp.element.createElement(wp.components.Modal, {
                        title: 'Park Reference Required',
                        onRequestClose: function() { setShowParkRefModal(false); }
                    },
                        wp.element.createElement('div', {style:{fontSize:'13px', lineHeight:'1.65', color:'\\x23333333', maxWidth:'420px'}},
                            wp.element.createElement('p', {style:{marginTop:'0', marginBottom:'12px'}},
                                'Your log file does not include a park reference (MY_SIG_INFO). Enter it below so the park can be shown on the map. For several parks, separate them with commas.'
                            ),
                            wp.element.createElement('input', {
                                type: 'text',
                                placeholder: 'e.g. US-3315',
                                value: parkRefInput,
                                onChange: function(e) { setParkRefInput(e.target.value); },
                                style:{width:'100%', padding:'6px 8px', fontSize:'14px', borderRadius:'4px', border:'1px solid \\x23cccccc', marginBottom:'14px', boxSizing:'border-box'}
                            }),
                            wp.element.createElement('div', {style:{display:'flex', gap:'8px', justifyContent:'flex-end'}},
                                wp.element.createElement(wp.components.Button, {variant: 'secondary', onClick: function() { setShowParkRefModal(false); }}, 'Skip for now'),
                                wp.element.createElement(wp.components.Button, {
                                    variant: 'primary',
                                    onClick: function() {
                                        if (parkRefInput.trim()) { props.setAttributes({myParkRef: parkRefInput.trim()}); }
                                        setShowParkRefModal(false);
                                    }
                                }, 'Save')
                            )
                        )
                    ),
                    showSummitRefModal && wp.element.createElement(wp.components.Modal, {
                        title: 'Summit Reference Required',
                        onRequestClose: function() { setShowSummitRefModal(false); }
                    },
                        wp.element.createElement('div', {style:{fontSize:'13px', lineHeight:'1.65', color:'\\x23333333', maxWidth:'420px'}},
                            wp.element.createElement('p', {style:{marginTop:'0', marginBottom:'12px'}},
                                'Your log file does not include a summit reference (MY_SOTA_REF / column 3). Enter it below to enable the activation zone and contact map.'
                            ),
                            wp.element.createElement('input', {
                                type: 'text',
                                placeholder: 'e.g. W6/SC-219',
                                value: summitRefInput,
                                onChange: function(e) { setSummitRefInput(e.target.value); },
                                style:{width:'100%', padding:'6px 8px', fontSize:'14px', borderRadius:'4px', border:'1px solid \\x23cccccc', marginBottom:'14px', boxSizing:'border-box'}
                            }),
                            wp.element.createElement('div', {style:{display:'flex', gap:'8px', justifyContent:'flex-end'}},
                                wp.element.createElement(wp.components.Button, {
                                    variant: 'secondary',
                                    onClick: function() { setShowSummitRefModal(false); }
                                }, 'Skip for now'),
                                wp.element.createElement(wp.components.Button, {
                                    variant: 'primary',
                                    onClick: function() {
                                        if (summitRefInput.trim()) { props.setAttributes({mySummitRef: summitRefInput.trim()}); }
                                        setShowSummitRefModal(false);
                                    }
                                }, 'Save')
                            )
                        )
                    ),
                    !isPota && wp.element.createElement('div', {style:{display:'flex', alignItems:'center', gap:'8px', marginBottom:'6px', padding:'8px 10px',
                        background: props.attributes.hideGpxStats ? '\\x23fff3cd' : '\\x23f0f0f0', borderRadius:'4px',
                        border: props.attributes.hideGpxStats ? '1px solid \\x23f0ad4e' : '1px solid \\x23dddddd'}},
                        wp.element.createElement('input', {type:'checkbox', id:'hideGpxStats', checked:!!props.attributes.hideGpxStats,
                            onChange:function(e){props.setAttributes({hideGpxStats:e.target.checked});},
                            style:{width:'16px',height:'16px',cursor:'pointer',flexShrink:'0'}}),
                        wp.element.createElement('label', {htmlFor:'hideGpxStats', style:{fontSize:'13px', fontWeight:'700', cursor:'pointer',
                            color: props.attributes.hideGpxStats ? '\\x23856404' : '\\x23333333', fontFamily:'sans-serif', lineHeight:'1.4'}},
                            props.attributes.hideGpxStats ? '(hidden) GPX hike statistics' : 'Hide GPX hike statistics from post')
                    ),
                    !isPota && wp.element.createElement(wp.components.PanelBody, {title:'Statistics Overrides', initialOpen:false},
                        wp.element.createElement('p', {style:{fontSize:'12px', color:'\\x23555555', marginTop:'0', marginBottom:'12px', lineHeight:'1.5'}},
                            'Check the box next to a field to enable that override. Leave unchecked to use the GPX-calculated value.'
                        ),
                        // --- Activation Zone source ---
                        wp.element.createElement(wp.components.SelectControl, {
                            label: 'Activation Zone Source',
                            help: 'Auto tries SOTLAS (high detail) first, then Activation.Zone, then a simple radius circle. Pick a specific source to force it; if that source has no zone for this summit, the radius circle is used.',
                            value: props.attributes.zoneSource || (props.attributes.forceRadiusZone ? 'radius' : 'auto'),
                            options: [
                                {label: 'Auto (SOTLAS, then Activation.Zone)', value: 'auto'},
                                {label: 'SOTLAS only', value: 'sotlas'},
                                {label: 'Activation.Zone only', value: 'activation_zone'},
                                {label: 'Radius circle only (skip APIs)', value: 'radius'}
                            ],
                            onChange: function(val) { props.setAttributes({zoneSource: val, forceRadiusZone: val === 'radius'}); }
                        }),
                        wp.element.createElement('hr', {style:{border:'none', borderTop:'1px solid \\x23dddddd', margin:'8px 0'}}),
                        // --- Hike Distance ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'6px'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.overrideHikingDistanceEnabled,
                                        onChange:function(e){props.setAttributes({overrideHikingDistanceEnabled:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif',
                                    opacity: props.attributes.overrideHikingDistanceEnabled ? 1 : 0.5}},
                                    'Hike Distance (km/mi):'
                                ),
                                wp.element.createElement('input', {type:'text',
                                    value: props.attributes.overrideHikingDistance || '',
                                    onChange:function(e){props.setAttributes({overrideHikingDistance:e.target.value});},
                                    disabled: !props.attributes.overrideHikingDistanceEnabled,
                                    placeholder:'e.g. 5.2',
                                    style:{display:'table-cell', width:'100%', padding:'5px 8px', fontSize:'13px',
                                        fontFamily:'sans-serif', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                        background: props.attributes.overrideHikingDistanceEnabled ? '\\x23ffffff' : '\\x23eeeeee',
                                        color: props.attributes.overrideHikingDistanceEnabled ? '\\x23000000' : '\\x23aaaaaa'}})
                            )
                        ),
                        // --- Hike Time ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'6px'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.overrideHikingTimeEnabled,
                                        onChange:function(e){props.setAttributes({overrideHikingTimeEnabled:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif',
                                    opacity: props.attributes.overrideHikingTimeEnabled ? 1 : 0.5}},
                                    'Hike Time (H:MM):'
                                ),
                                wp.element.createElement('input', {type:'text',
                                    value: props.attributes.overrideHikingTime || '',
                                    onChange:function(e){props.setAttributes({overrideHikingTime:e.target.value});},
                                    disabled: !props.attributes.overrideHikingTimeEnabled,
                                    placeholder:'e.g. 2:30',
                                    style:{display:'table-cell', width:'100%', padding:'5px 8px', fontSize:'13px',
                                        fontFamily:'sans-serif', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                        background: props.attributes.overrideHikingTimeEnabled ? '\\x23ffffff' : '\\x23eeeeee',
                                        color: props.attributes.overrideHikingTimeEnabled ? '\\x23000000' : '\\x23aaaaaa'}})
                            )
                        ),
                        // --- Activation Time ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'6px'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.overrideActivationTimeEnabled,
                                        onChange:function(e){props.setAttributes({overrideActivationTimeEnabled:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif',
                                    opacity: props.attributes.overrideActivationTimeEnabled ? 1 : 0.5}},
                                    'Activation Time (H:MM):'
                                ),
                                wp.element.createElement('input', {type:'text',
                                    value: props.attributes.overrideActivationTime || '',
                                    onChange:function(e){props.setAttributes({overrideActivationTime:e.target.value});},
                                    disabled: !props.attributes.overrideActivationTimeEnabled,
                                    placeholder:'e.g. 1:15',
                                    style:{display:'table-cell', width:'100%', padding:'5px 8px', fontSize:'13px',
                                        fontFamily:'sans-serif', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                        background: props.attributes.overrideActivationTimeEnabled ? '\\x23ffffff' : '\\x23eeeeee',
                                        color: props.attributes.overrideActivationTimeEnabled ? '\\x23000000' : '\\x23aaaaaa'}})
                            )
                        ),
                        // --- Rest Breaks ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'6px'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.overrideRestBreaksEnabled,
                                        onChange:function(e){props.setAttributes({overrideRestBreaksEnabled:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif',
                                    opacity: props.attributes.overrideRestBreaksEnabled ? 1 : 0.5}},
                                    'Rest Breaks (H:MM):'
                                ),
                                wp.element.createElement('input', {type:'text',
                                    value: props.attributes.overrideRestBreaks || '',
                                    onChange:function(e){props.setAttributes({overrideRestBreaks:e.target.value});},
                                    disabled: !props.attributes.overrideRestBreaksEnabled,
                                    placeholder:'e.g. 0:20',
                                    style:{display:'table-cell', width:'100%', padding:'5px 8px', fontSize:'13px',
                                        fontFamily:'sans-serif', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                        background: props.attributes.overrideRestBreaksEnabled ? '\\x23ffffff' : '\\x23eeeeee',
                                        color: props.attributes.overrideRestBreaksEnabled ? '\\x23000000' : '\\x23aaaaaa'}})
                            )
                        ),
                        // --- Total Time ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'0'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.overrideTotalTimeEnabled,
                                        onChange:function(e){props.setAttributes({overrideTotalTimeEnabled:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif',
                                    opacity: props.attributes.overrideTotalTimeEnabled ? 1 : 0.5}},
                                    'Total Time (H:MM):'
                                ),
                                wp.element.createElement('input', {type:'text',
                                    value: props.attributes.overrideTotalTime || '',
                                    onChange:function(e){props.setAttributes({overrideTotalTime:e.target.value});},
                                    disabled: !props.attributes.overrideTotalTimeEnabled,
                                    placeholder:'e.g. 4:00',
                                    style:{display:'table-cell', width:'100%', padding:'5px 8px', fontSize:'13px',
                                        fontFamily:'sans-serif', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                        background: props.attributes.overrideTotalTimeEnabled ? '\\x23ffffff' : '\\x23eeeeee',
                                        color: props.attributes.overrideTotalTimeEnabled ? '\\x23000000' : '\\x23aaaaaa'}})
                            )
                        ),
                        wp.element.createElement('p', {style:{fontSize:'11px', color:'\\x23888888', fontFamily:'sans-serif', margin:'16px 0 0', paddingTop:'12px', borderTop:'1px solid \\x23e0e0e0'}},
                            'To clear cached contact locations, go to Settings → Activator Toolkit → Callsign Lookup.'
                        )
                    )
                    )
                );
            },
            save: function() { return null; }
        });
    ");
});

// Settings page: enqueue clear-cache button script
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'settings_page_activator-toolkit-settings' ) return;
    wp_register_script( 'sota-settings-js', '', [], '1.0.0', true );
    wp_localize_script( 'sota-settings-js', 'sotaMagicSettings', [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'sota_magic_clear_location_cache' ),
        'deleteNonce' => wp_create_nonce( 'sota_magic_delete_single_location' ),
    ] );
    wp_add_inline_script( 'sota-settings-js', "
        document.addEventListener('DOMContentLoaded', function() {
            // Clear all button
            var btn = document.getElementById('sota-clear-cache-btn');
            var result = document.getElementById('sota-clear-cache-result');
            if (btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Clear all cached callsign locations? This cannot be undone.')) return;
                    btn.disabled = true;
                    btn.textContent = 'Clearing\u2026';
                    result.style.display = 'none';
                    fetch(sotaMagicSettings.ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=sota_magic_clear_location_cache&_ajax_nonce=' + sotaMagicSettings.nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        result.style.display = 'block';
                        result.style.color   = data.success ? '#28a745' : '#c0392b';
                        result.textContent   = data.success ? '\u2713 ' + data.data : '\u2717 Error clearing cache';
                        btn.textContent = 'Clear All Cached Locations';
                        btn.disabled = false;
                        if (data.success) {
                            var tbody = document.querySelector('#sota-cache-table tbody');
                            if (tbody) tbody.innerHTML = '';
                            var table = document.getElementById('sota-cache-table');
                            if (table) table.style.display = 'none';
                            var searchEl = document.getElementById('sota-cache-search');
                            if (searchEl) searchEl.closest('p').style.display = 'none';
                            var countEl = document.getElementById('sota-cache-count');
                            if (countEl) countEl.textContent = '0 callsign location(s) cached.';
                        }
                    });
                });
            }

            // Search/filter
            var searchInput = document.getElementById('sota-cache-search');
            var cacheTable  = document.getElementById('sota-cache-table');
            var noResults   = document.getElementById('sota-no-cache-results');
            if (searchInput && cacheTable) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') e.preventDefault();
                });
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    var rows = cacheTable.querySelectorAll('tbody tr');
                    var visible = 0;
                    rows.forEach(function(row) {
                        var cs = row.dataset.callsign || '';
                        var show = !q || cs.indexOf(q) !== -1;
                        row.style.display = show ? '' : 'none';
                        if (show) visible++;
                    });
                    if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
                });
            }

            // Delete single entry (event delegation)
            document.addEventListener('click', function(e) {
                if (!e.target.classList.contains('sota-delete-cache-entry')) return;
                var delBtn  = e.target;
                var row     = delBtn.closest('tr');
                var key     = delBtn.dataset.cacheKey;
                delBtn.disabled = true;
                delBtn.textContent = 'Deleting\u2026';
                fetch(sotaMagicSettings.ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=sota_magic_delete_single_location&_ajax_nonce=' + sotaMagicSettings.deleteNonce + '&cache_key=' + encodeURIComponent(key)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        row.remove();
                        var countEl = document.getElementById('sota-cache-count');
                        if (countEl) {
                            var n = parseInt(countEl.textContent, 10);
                            if (!isNaN(n)) countEl.textContent = (n - 1) + ' callsign location(s) cached.';
                        }
                        var tbody = document.querySelector('#sota-cache-table tbody');
                        if (tbody && tbody.querySelectorAll('tr:not([style*=\"none\"])').length === 0) {
                            if (noResults) { noResults.style.display = ''; noResults.textContent = 'No entries match your filter.'; }
                        }
                    } else {
                        delBtn.disabled = false;
                        delBtn.textContent = 'Delete';
                    }
                });
            });
        });
    " );
    wp_enqueue_script( 'sota-settings-js' );
} );

// RENDER
function sota_parse_time_override($hhmm) {
    $hhmm = trim($hhmm);
    if (empty($hhmm)) return null;
    $parts = explode(':', $hhmm);
    if (count($parts) === 2) {
        return (intval($parts[0]) * 3600) + (intval($parts[1]) * 60);
    }
    return null;
}

/**
 * Work out which program a block is in. The plugin was SOTA-only before the toggle existed,
 * so any block without an explicit activationProgram is SOTA, no matter what the site default is.
 */
function sota_magic_block_program($atts) {
    return (($atts['activationProgram'] ?? '') === 'pota') ? 'pota' : 'sota';
}

function sota_magic_render_sota_data($atts) {
    if (sota_magic_block_program($atts) === 'pota') return sota_magic_render_pota_data($atts);
    $gpx_url       = $atts['gpxUrl'] ?? '';
    $csv_url       = $atts['csvUrl'] ?? '';
    $log_format    = $atts['logFormat'] ?? 'csv';
    $my_summit_ref = strtoupper( trim($atts['mySummitRef'] ?? '') );
    if (!$gpx_url && !$csv_url) return '';

    // Manual override attributes (only applied when their Enabled flag is checked)
    $zone_mode = $atts['zoneSource'] ?? '';
    if (!in_array($zone_mode, ['auto', 'sotlas', 'activation_zone', 'radius'], true)) {
        $zone_mode = !empty($atts['forceRadiusZone']) ? 'radius' : 'auto'; // blocks saved before the zone source override
    }
    $force_radius_zone      = ('radius' === $zone_mode);
    $override_hiking_dist   = !empty($atts['overrideHikingDistanceEnabled']) ? trim($atts['overrideHikingDistance'] ?? '') : '';
    $override_hiking_time   = !empty($atts['overrideHikingTimeEnabled'])     ? trim($atts['overrideHikingTime'] ?? '')    : '';
    $override_act_time      = !empty($atts['overrideActivationTimeEnabled']) ? trim($atts['overrideActivationTime'] ?? '') : '';
    $override_rest_breaks   = !empty($atts['overrideRestBreaksEnabled'])     ? trim($atts['overrideRestBreaks'] ?? '')    : '';
    $override_total_time    = !empty($atts['overrideTotalTimeEnabled'])      ? trim($atts['overrideTotalTime'] ?? '')     : '';

    $bg       = esc_attr(get_option('sota_is_transparent') ? 'transparent' : get_option('sota_bg_color'));
    $text     = esc_attr(get_option('sota_text_color'));
    $font     = esc_attr(get_option('sota_use_theme_font') ? 'inherit' : 'sans-serif');
    $s2s_bg   = esc_attr(get_option('sota_s2s_highlight'));
    $s2s_text = esc_attr(get_option('sota_s2s_text_color'));
    $show_map = get_option('sota_show_contact_map');
    $show_gpx_stats = get_option('sota_show_gpx_stats');
    $block_width = get_option('sota_block_width', '');
    $width_class = $block_width === 'wide' ? ' alignwide' : ( $block_width === 'full' ? ' alignfull' : '' );
    $width_style = ( is_numeric($block_width) && (int)$block_width > 0 ) ? 'max-width:' . absint($block_width) . 'px;width:100%;' : '';
    $hide_stats_display = !empty($atts['hideGpxStats']);
    $unit_system = get_option('sota_unit_system', 'metric');

    // Analyze GPX if available and stats are enabled
    $gpx_stats = null;
    $track_points = [];
    if ($gpx_url && $show_gpx_stats) {
        $gpx_stats = sota_magic_analyze_gpx_track($gpx_url, $csv_url, $force_radius_zone, $my_summit_ref ?: null, $zone_mode);
        if ($gpx_stats && !empty($gpx_stats['track_points'])) {
            $track_points = $gpx_stats['track_points'];
        }
    }
    // Fallback: parse track points without full stats analysis
    if ($gpx_url && empty($track_points)) {
        $track_points = sota_get_gpx_track_points($gpx_url);
    }

    // Apply manual overrides to GPX stats
    if ($gpx_stats) {
        if (!empty($override_hiking_time)) {
            $secs = sota_parse_time_override($override_hiking_time);
            if ($secs !== null) $gpx_stats['hiking_time'] = $secs;
        }
        if (!empty($override_hiking_dist)) {
            $val = floatval($override_hiking_dist);
            // Convert to km if user is in imperial mode (assumed miles input)
            if ($unit_system === 'imperial') {
                $val = $val * 1.60934;
            }
            $gpx_stats['hiking_distance'] = $val;
        }
        if (!empty($override_act_time)) {
            $secs = sota_parse_time_override($override_act_time);
            if ($secs !== null) $gpx_stats['stationary_time'] = $secs;
        }
        if (!empty($override_rest_breaks)) {
            $secs = sota_parse_time_override($override_rest_breaks);
            if ($secs !== null) $gpx_stats['rest_break_time'] = $secs;
        }
        if (!empty($override_total_time)) {
            $secs = sota_parse_time_override($override_total_time);
            if ($secs !== null) $gpx_stats['total_time'] = $secs;
        }
        // If activation zone is forced to radius, update the display flag
        if ($force_radius_zone) {
            $gpx_stats['using_api'] = false;
        }
        // Recalculate hiking speed from (possibly overridden) distance and time
        $gpx_stats['hiking_speed'] = ($gpx_stats['hiking_time'] > 0)
            ? $gpx_stats['hiking_distance'] / ($gpx_stats['hiking_time'] / 3600)
            : 0;
    }
    
    // Build map iframe URL if needed
    $map_iframe_url = '';
    if ($show_map && $csv_url) {
        $sota_magic_debug_param = (get_option('sota_debug_mode_public') || (get_option('sota_debug_mode') && current_user_can('manage_options'))) ? '&debug=1' : '';
        $map_iframe_url = admin_url('admin-ajax.php') . '?action=sota_magic_contact_map&csv=' . urlencode($csv_url)
            . '&format=' . urlencode($log_format)
            . ( $my_summit_ref ? '&summit_ref=' . urlencode($my_summit_ref) : '' )
            . '&_nonce=' . wp_create_nonce('sota_magic_contact_map') . $sota_magic_debug_param;
    }

    // Unique map ID for this block (static counter survives multiple blocks on one page)
    static $sota_map_counter = 0;
    $sota_map_counter++;
    $map_id = 'sota-gpx-map-' . $sota_map_counter;

    // Enqueue map assets and register per-block init call
    if ($gpx_url && !empty($track_points)) {
        wp_enqueue_style('sota-leaflet', plugins_url('lib/leaflet.css', __FILE__), [], '1.9.4');
        wp_enqueue_script('sota-leaflet-js', plugins_url('lib/leaflet.js', __FILE__), [], '1.9.4', true);
        wp_enqueue_script('sota-chartjs', plugins_url('lib/chart.umd.min.js', __FILE__), [], '4.5.1', true);
        wp_enqueue_script('activator-toolkit-map', plugins_url('activator-toolkit-map.js', __FILE__), ['sota-leaflet-js', 'sota-chartjs'], '1.1.1', true);

        // Build activation zone payload
        $az_data = null;
        if ($gpx_stats) {
            if ($gpx_stats['using_api'] && !empty($gpx_stats['activation_zone_polygon'])) {
                $leaflet_coords = [];
                foreach ($gpx_stats['activation_zone_polygon'][0] as $coord) {
                    $leaflet_coords[] = [$coord[1], $coord[0]]; // [lon,lat] → [lat,lon]
                }
                $az_data = ['mode' => 'polygon', 'coordinates' => $leaflet_coords];
            } else {
                $az_data = ['mode' => 'circle', 'radius' => (float)$gpx_stats['activation_zone_radius']];
            }
        }

        // Summit coordinates
        $summit_lat = $gpx_stats ? (float)$gpx_stats['summit_lat'] : null;
        $summit_lon = $gpx_stats ? (float)$gpx_stats['summit_lon'] : null;
        // Derive from highest track point if stats weren't computed
        if ($summit_lat === null && !empty($track_points)) {
            $highest = null;
            foreach ($track_points as $tp) {
                if ($highest === null || $tp[2] > $highest[2]) $highest = $tp;
            }
            if ($highest) { $summit_lat = $highest[0]; $summit_lon = $highest[1]; }
        }

        $map_data = [
            'trackPoints'    => $track_points,
            'summitLat'      => $summit_lat,
            'summitLon'      => $summit_lon,
            'activationZone' => $az_data,
            'units'          => $unit_system,
            'popupText'      => 'Summit / Activation Zone',
            'defaultLayer'   => get_option('sota_default_map_layer', 'carto'),
        ];

        wp_add_inline_script('activator-toolkit-map',
            'sotaMagicInitMap(' . wp_json_encode($map_id) . ', ' . wp_json_encode($map_data) . ');'
        );

        // Modal escape-key handler, added once per page even with multiple blocks
        static $modal_script_added = false;
        if ( ! $modal_script_added ) {
            wp_add_inline_script( 'activator-toolkit-map', '(function(){document.addEventListener("keydown",function(e){if(e.key==="Escape"){var m=document.getElementById("sota-stats-modal");if(m)m.classList.remove("sota-modal-open");}});})();' );
            $modal_script_added = true;
        }
    }

    ob_start();
    ?>
    <div class="sota-main-container<?php echo esc_attr( $width_class ); ?>"<?php if ( $width_style ) echo ' style="' . esc_attr( $width_style ) . '"'; ?>>
        <?php if ($gpx_url): ?>
            <h3><?php sota_magic_echo_svg('mountain', 22); ?> <?php echo esc_html(get_option('sota_headline_gpx')); ?></h3>
            <?php if (!empty($track_points)): ?>
            <div id="<?php echo esc_attr($map_id); ?>" class="sota-gpx-map"></div>
            <div class="sota-gpx-chart-wrap">
                <canvas id="<?php echo esc_attr($map_id); ?>-chart"></canvas>
            </div>
            <?php else: ?>
            <p style="color:#888;font-style:italic;margin:10px 0 16px;">Map unavailable: the GPX file could not be loaded.</p>
            <?php endif; ?>

            <?php if ($gpx_stats && !$hide_stats_display): ?>
                <div class="sota-stats-header">
                    <span class="sota-stats-header-label">Hike Statistics</span>
                    <button class="sota-help-btn" onclick="document.getElementById('sota-stats-modal').classList.add('sota-modal-open')">
                        <?php sota_magic_echo_svg('info', 13); ?> How is this calculated?
                    </button>
                </div>
                <div class="sota-gpx-stats">
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('hike', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_time_duration($gpx_stats['hiking_time'])); ?></div>
                        <div class="sota-stat-label">Hiking Time</div>
                        <div class="sota-stat-secondary">
                            <?php echo esc_html(sota_magic_format_distance($gpx_stats['hiking_distance'], $unit_system)); ?>
                            <?php if ($gpx_stats['rest_break_time'] > 0): ?>
                                <br><em style="font-size:11px;opacity:0.8;">(<?php echo esc_html(sota_magic_format_time_duration($gpx_stats['rest_break_time'])); ?> breaks)</em>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('radio', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_time_duration($gpx_stats['stationary_time'])); ?></div>
                        <div class="sota-stat-label">Activation Time</div>
                        <div class="sota-stat-secondary">
                            <?php if ($gpx_stats['using_api']): ?>
                                <span title="<?php echo esc_attr($gpx_stats['zone_source'] === 'sotlas' ? 'Using the high-precision SOTLAS activation zone' : 'Using the activation.zone terrain-based activation zone'); ?>">✓ <?php echo esc_html($gpx_stats['zone_source'] === 'sotlas' ? 'SOTLAS zone' : 'Terrain-based zone'); ?></span>
                            <?php else: ?>
                                <span title="Using radius approximation method">Within <?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?>m of summit</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('timer', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_time_duration($gpx_stats['total_time'])); ?></div>
                        <div class="sota-stat-label">Total Time</div>
                        <div class="sota-stat-secondary">start to finish</div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('trend-up', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_elevation($gpx_stats['elevation_gain'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Elevation Gain</div>
                        <div class="sota-stat-secondary">
                            ↓ <?php echo esc_html(sota_magic_format_elevation($gpx_stats['elevation_loss'], $unit_system)); ?> loss
                            <br>Peak <?php echo esc_html(sota_magic_format_elevation($gpx_stats['max_elevation'], $unit_system)); ?>
                        </div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('walk', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_speed($gpx_stats['hiking_speed'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Hiking Speed</div>
                        <div class="sota-stat-secondary">average</div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon"><?php sota_magic_echo_svg('distance', 30); ?></div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_distance($gpx_stats['total_distance'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Total Distance</div>
                        <div class="sota-stat-secondary">entire hike</div>
                    </div>
                </div>

                <!-- Methodology explanation -->
                <div style="margin-top:15px; padding:12px; background:rgba(0,0,0,0.03); border-radius:8px; font-size:13px; color:#666;">
                    <strong>Activation Zone Method:</strong>
                    <?php if ($gpx_stats['using_api']): ?>
                        <?php if ($gpx_stats['zone_source'] === 'sotlas'): ?>
                        Using the high-precision terrain-based activation zone from <a href="https://sotl.as" target="_blank" style="color:#0073aa;">SOTLAS</a>.
                        It is calculated from elevation data and the official SOTA 25m vertical drop rule, at roughly 1 meter detail.
                        <?php else: ?>
                        Using the terrain-based activation zone from <a href="https://activation.zone" target="_blank" style="color:#0073aa;">activation.zone</a> (by N6ARA).
                        It uses Digital Elevation Model (DEM) data and the official SOTA 25m vertical drop rule.
                        <?php endif; ?>
                    <?php else: ?>
                        Using radius approximation method (<?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?>m from highest GPS point).
                        For more accuracy, turn on terrain-based activation zones in Settings → Activator Toolkit, or make sure your log includes the summit reference.
                    <?php endif; ?>
                </div>
                
                <!-- Stats help modal -->
                <div id="sota-stats-modal" class="sota-modal-backdrop" onclick="if(event.target===this)this.classList.remove('sota-modal-open')">
                    <div class="sota-modal" role="dialog" aria-modal="true" aria-labelledby="sota-modal-title">
                        <button class="sota-modal-close" onclick="document.getElementById('sota-stats-modal').classList.remove('sota-modal-open')" aria-label="Close">✕</button>
                        <h2 id="sota-modal-title"><?php sota_magic_echo_svg('chart', 20); ?> How Hike Stats Are Calculated</h2>
                        <p class="sota-modal-subtitle">These figures are derived automatically from your GPX track file.</p>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('hike', 17); ?> Hiking Time &amp; Distance</h3>
                            <p>Time and distance accumulated while <strong>moving outside the activation zone</strong> at a speed above the stationary threshold (default 0.3 km/h). Periods where you were stopped (waiting at a trailhead, taking a break) are excluded and counted separately as Rest Breaks.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('pause', 17); ?> Rest Breaks</h3>
                            <p>Stationary periods <strong>outside the activation zone</strong> lasting longer than the rest threshold (default 3 minutes). Anything shorter is ignored as normal GPS noise or a momentary pause. Rest break time is shown inside the Hiking Time box for reference but is <em>not</em> added to hiking time.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('radio', 17); ?> Activation Time</h3>
                            <p><strong>All time spent inside the activation zone</strong>, regardless of whether you were moving or stationary. This captures the full period from when you first entered the zone to when you left, including any walking around the summit, setting up gear, and operating.</p>
                            <p>The activation zone boundary is determined by one of two methods (see below).</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('timer', 17); ?> Total Time</h3>
                            <p>The elapsed time from the <strong>first to the last GPS trackpoint</strong> in the file. This equals Hiking Time + Activation Time + Rest Breaks + any unclassified transition time at the boundaries.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('trend-up', 17); ?> Elevation Gain, Loss &amp; Peak</h3>
                            <p>Gain and loss are the <strong>cumulative</strong> altitude climbed and descended across all trackpoints. Each uphill step between consecutive points adds to gain; each downhill step adds to loss. Out-and-back routes will show roughly equal gain and loss.</p>
                            <p>Peak is the <strong>highest elevation</strong> recorded in the GPS track. It is also used as the starting reference for the activation zone when a terrain-based zone is used.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('walk', 17); ?> Hiking Speed</h3>
                            <p>Average speed calculated as <strong>Hiking Distance ÷ Hiking Time</strong>. Only moving segments outside the activation zone are included, so rest stops and summit time do not drag the average down.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('distance', 17); ?> Total Distance</h3>
                            <p>The full length of the <strong>entire GPS track</strong>, from the first to the last trackpoint, including time spent in the activation zone.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3><?php sota_magic_echo_svg('pin', 17); ?> Activation Zone Methods</h3>
                            <?php if ($gpx_stats['using_api']): ?>
                            <p><strong>Terrain-based zone (currently active):</strong> The boundary extends to where the terrain drops 25 metres below the summit, following the official SOTA rule.
                            <?php if ($gpx_stats['zone_source'] === 'sotlas'): ?>
                            It comes from <a href="https://sotl.as" target="_blank" style="color:#0073aa;">SOTLAS</a>, which publishes boundaries at roughly 1 meter detail. This is the most accurate method available.
                            <?php else: ?>
                            It comes from <a href="https://activation.zone" target="_blank" style="color:#0073aa;">activation.zone</a>, which uses lower resolution elevation data. SOTLAS is tried first and had no boundary for this summit.
                            <?php endif; ?>
                            </p>
                            <?php else: ?>
                            <p><strong>Radius method (currently active):</strong> The activation zone is approximated as a circle of <strong><?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?> metres</strong> around the highest GPS point. This is less precise than the API method because it ignores terrain shape, but works without an internet lookup or summit reference in the log.</p>
                            <p>For better accuracy, turn on terrain-based activation zones in <em>Settings → Activator Toolkit</em> and make sure your log includes the summit reference.</p>
                            <?php endif; ?>
                        </div>

                        <div class="sota-modal-note">
                            <?php sota_magic_echo_svg('gear', 14); ?> The stationary speed threshold, rest break minimum duration, activation zone radius, and unit system (metric/imperial) can all be tuned in <strong>Settings → Activator Toolkit</strong>. If any values look wrong, the Manual Overrides on the Activator Toolkit block let you correct individual figures without re-uploading files.
                        </div>
                    </div>
                </div>


                <!-- Debug info -->
                <?php if (get_option('sota_debug_mode_public') || (get_option('sota_debug_mode') && current_user_can('manage_options'))): ?>
                <div style="margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:5px; font-size:12px; font-family:monospace;">
                    <strong>Debug Info:</strong><br>
                    API Enabled: <?php echo get_option('sota_use_azapi') ? 'YES' : 'NO'; ?> (zone mode: <?php echo esc_html($zone_mode); ?>, source: <?php echo esc_html($gpx_stats['zone_source']); ?>)<br>
                    CSV URL: <?php echo $csv_url ? 'Present' : 'Missing'; ?><br>
                    Summit Reference: <?php echo esc_html(isset($gpx_stats['summit_ref']) ? $gpx_stats['summit_ref'] : 'Not extracted'); ?><br>
                    Using API: <?php echo $gpx_stats['using_api'] ? 'YES' : 'NO'; ?><br>
                    Summit Lat/Lon: <?php echo esc_html($gpx_stats['summit_lat'] . ', ' . $gpx_stats['summit_lon']); ?><br>
                    Max Elevation: <?php echo esc_html((string) $gpx_stats['max_elevation']); ?> m<br>
                    Polygon Data: <?php
                        if ($gpx_stats['activation_zone_polygon']) {
                            $point_count = is_array($gpx_stats['activation_zone_polygon'][0]) ? count($gpx_stats['activation_zone_polygon'][0]) : count($gpx_stats['activation_zone_polygon']);
                            echo esc_html('Present (' . $point_count . ' points)');
                        } else {
                            echo 'NULL';
                        }
                    ?><br>
                    <strong>API Debug:</strong> <?php echo esc_html(isset($gpx_stats['api_debug']) ? $gpx_stats['api_debug'] : 'N/A'); ?><br>
                    <strong>Polygon Check:</strong> <?php echo esc_html(isset($gpx_stats['polygon_check_debug']) ? $gpx_stats['polygon_check_debug'] : 'N/A'); ?><br>
                    <strong>Points in Zone:</strong> <?php echo esc_html((string)(isset($gpx_stats['points_in_zone']) ? $gpx_stats['points_in_zone'] : 0)); ?> total, <?php echo esc_html((string)(isset($gpx_stats['stationary_in_zone']) ? $gpx_stats['stationary_in_zone'] : 0)); ?> stationary<br>
                    <strong>Speed Range in Zone:</strong> <?php echo esc_html(isset($gpx_stats['speed_range_in_zone']) ? $gpx_stats['speed_range_in_zone'] : 'N/A'); ?><br>
                    <strong>Stationary Threshold:</strong> <?php echo esc_html(get_option('sota_stationary_threshold')); ?> km/h<br>
                    <strong>Activation Time:</strong> <?php echo esc_html(sota_magic_format_time_duration($gpx_stats['stationary_time'])); ?> (<?php echo esc_html((string) $gpx_stats['stationary_time']); ?> seconds)
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($map_iframe_url): ?>
            <h3 style="margin-top:40px;"><?php sota_magic_echo_svg('map', 22); ?> <?php echo esc_html(get_option('sota_headline_map')); ?></h3>
            <iframe id="sota-contact-map-<?php echo absint($sota_map_counter); ?>" src="<?php echo esc_url($map_iframe_url); ?>"
                    style="width:100%; height:500px; border:none; border-radius:8px; background:#f5f5f5;"
                    title="Contact Map">
            </iframe>
        <?php endif; ?>

        <?php if ($csv_url): ?>
            <h3 style="margin-top:40px;"><?php sota_magic_echo_svg('antenna', 22); ?> <?php echo esc_html(get_option('sota_headline_csv')); ?></h3>
            <div class="sota-table-wrapper">
                <?php
                $table_contacts = [];
                if ( $log_format === 'adif' ) {
                    $table_contacts = sota_magic_parse_adif_contacts( $csv_url, $my_summit_ref );
                } else {
                    $csv_table_response = wp_remote_get($csv_url, ['timeout' => 15]);
                    if (!is_wp_error($csv_table_response)) {
                        $csv_table_body = wp_remote_retrieve_body($csv_table_response);
                        foreach (explode("\n", $csv_table_body) as $csv_table_line) {
                            $row = str_getcsv(trim($csv_table_line));
                            if (empty($row[0]) || $row[0] !== 'V2') continue;
                            $table_contacts[] = [
                                'my_summit'    => strtoupper( $row[2] ?? '' ),
                                'date'         => $row[3] ?? '',
                                'time'         => $row[4] ?? '',
                                'frequency'    => $row[5] ?? '',
                                'mode'         => $row[6] ?? '',
                                'callsign'     => $row[7] ?? '',
                                'their_summit' => strtoupper( trim($row[8] ?? '') ),
                                'comments'     => trim($row[9] ?? ''),
                            ];
                        }
                    }
                }
                usort($table_contacts, function($a, $b) {
                    $to_sort_key = function($c) {
                        $parts = explode('/', $c['date']);
                        if (count($parts) === 3) {
                            $y = (int)$parts[2]; $y = $y < 50 ? 2000 + $y : 1900 + $y;
                            $date_key = sprintf('%04d%02d%02d', $y, (int)$parts[1], (int)$parts[0]);
                        } else { $date_key = $c['date']; }
                        return $date_key . str_replace(':', '', $c['time']);
                    };
                    return strcmp($to_sort_key($a), $to_sort_key($b));
                });
                $display_summit = $my_summit_ref ?: ( !empty($table_contacts[0]['my_summit']) ? $table_contacts[0]['my_summit'] : '' );
                if ( $display_summit ) {
                    echo '<p style="margin:0 0 8px 0;font-size:0.9em;color:#666;">My Summit: <strong>' . esc_html($display_summit) . '</strong></p>';
                }
                ?>
                <table class="sota-table" id="sota-contact-table-<?php echo absint($sota_map_counter); ?>">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Callsign</th>
                            <th>Frequency</th>
                            <th>Mode</th>
                            <th>Their Summit</th>
                            <th>Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($table_contacts as $tc) {
                        $s2s = !empty($tc['their_summit']);
                        $csv_date = $tc['date']; // DD/MM/YY
                        $parts = explode('/', $csv_date);
                        if (count($parts) === 3) {
                            $day   = (int) $parts[0];
                            $month = (int) $parts[1];
                            $year  = (int) $parts[2];
                            $year  = $year < 50 ? 2000 + $year : 1900 + $year;
                            $formatted_date = date_i18n(get_option('date_format'), strtotime("$year-$month-$day"));
                        } else {
                            $formatted_date = $csv_date;
                        }
                        echo '<tr class="' . ($s2s ? 's2s-row' : '') . '" data-callsign="' . esc_attr($tc['callsign']) . '">';
                        echo '<td>' . esc_html($formatted_date) . '<br><span class="sota-contact-time">' . esc_html($tc['time']) . '</span></td>';
                        echo '<td><strong>' . esc_html($tc['callsign']) . '</strong>' . ($s2s ? '<span class="s2s-badge">S2S</span>' : '') . '</td>';
                        echo '<td>' . esc_html($tc['frequency']) . '</td>';
                        echo '<td>' . esc_html($tc['mode']) . '</td>';
                        echo '<td>' . esc_html($tc['their_summit']) . '</td>';
                        echo '<td>' . esc_html($tc['comments']) . '</td>';
                        echo '</tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ( $map_iframe_url && $csv_url ):
            if ( ! wp_script_is( 'sota-table-hover', 'registered' ) ) {
                wp_register_script( 'sota-table-hover', '', [], '1.1.5', true );
            }
            wp_enqueue_script( 'sota-table-hover' );
            $hover_js = '(function(){'
                . 'var iframe=document.getElementById("sota-contact-map-' . $sota_map_counter . '");'
                . 'if(!iframe)return;'
                . 'var rows=document.querySelectorAll("#sota-contact-table-' . $sota_map_counter . ' tbody tr[data-callsign]");'
                . 'function send(msg){try{iframe.contentWindow.postMessage(msg,"*");}catch(e){}}'
                . 'rows.forEach(function(row){'
                .     'row.style.cursor="pointer";'
                .     'row.addEventListener("mouseenter",function(){send({action:"show",callsign:row.dataset.callsign});});'
                .     'row.addEventListener("mouseleave",function(){send({action:"hide"});});'
                . '});'
                . '})();';
            wp_add_inline_script( 'sota-table-hover', $hover_js );
        endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
