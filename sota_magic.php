<?php
/**
 * Plugin Name: SOTA Magic
 * Plugin URI: https://www.ki6cr.com/sota-magic-plugin-for-wordpress/
 * Description: Display your SOTA activation data beautifully — GPX track maps with elevation chart, hiking statistics, contact tables, and an interactive contact map. No other plugins required.
 * Version: 1.0.3
 * Author: KI6CR
 * Author URI: https://ki6cr.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sota-magic
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

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
    array_unshift($links, '<a href="options-general.php?page=sota-magic-settings">Settings</a>');
    return $links;
});

// Allow GPX uploads
add_filter('upload_mimes', function($mimes) {
    $mimes['gpx'] = 'application/gpx+xml';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function($data, $file, $filename) {
    if (pathinfo($filename, PATHINFO_EXTENSION) === 'gpx') {
        $data['ext'] = 'gpx';
        $data['type'] = 'application/gpx+xml';
    }
    return $data;
}, 10, 3);

// SETTINGS
add_action('admin_menu', function() {
    add_options_page('SOTA Magic Settings', 'SOTA Magic', 'manage_options', 'sota-magic-settings', 'sota_magic_settings_page');
});

add_action('admin_init', function() {
    $options = [
        'sota_headline_gpx'   => 'Activation GPS Track',
        'sota_headline_csv'   => 'Activation Contacts',
        'sota_headline_map'   => 'Contact Map',
        'sota_bg_color'       => '#ffffff',
        'sota_text_color'     => '#333333',
        'sota_is_transparent' => 0,
        'sota_use_theme_font' => 0,
        'sota_s2s_highlight'  => '#ffebee',
        'sota_s2s_text_color' => '#d32f2f',
        'sota_show_contact_map' => 1,
        'sota_qrz_username'   => '',
        'sota_qrz_password'   => '',
        'sota_show_gpx_stats' => 1,
        'sota_stationary_threshold' => '0.3',
        'sota_unit_system'    => 'metric',
        'sota_activation_zone_radius' => '50',
        'sota_rest_threshold_minutes' => '3',
        'sota_use_azapi' => 1,
        'sota_debug_mode'        => 0,
        'sota_debug_mode_public' => 0,
        'sota_default_map_layer' => 'topo'
    ];
    $color_keys   = ['sota_bg_color', 'sota_text_color', 'sota_s2s_highlight', 'sota_s2s_text_color'];
    $boolean_keys = ['sota_is_transparent', 'sota_use_theme_font', 'sota_show_contact_map', 'sota_show_gpx_stats', 'sota_use_azapi', 'sota_debug_mode', 'sota_debug_mode_public'];
    foreach ($options as $key => $default) {
        if (in_array($key, $color_keys, true)) {
            $sanitize = 'sanitize_hex_color';
        } elseif (in_array($key, $boolean_keys, true)) {
            $sanitize = 'absint';
        } else {
            $sanitize = 'sanitize_text_field';
        }
        register_setting('sota_magic_group', $key, ['sanitize_callback' => $sanitize]);
        if (get_option($key) === false) update_option($key, $default);
    }
});

function sota_magic_settings_page() {
    if (!current_user_can('manage_options')) return;
    
    if (isset($_POST['sota_magic_save'])) {
        check_admin_referer('sota_magic_settings');
        update_option('sota_headline_gpx', sanitize_text_field(wp_unslash($_POST['sota_headline_gpx'] ?? '')));
        update_option('sota_headline_csv', sanitize_text_field(wp_unslash($_POST['sota_headline_csv'] ?? '')));
        update_option('sota_headline_map', sanitize_text_field(wp_unslash($_POST['sota_headline_map'] ?? '')));
        update_option('sota_bg_color', sanitize_hex_color(wp_unslash($_POST['sota_bg_color'] ?? '')));
        update_option('sota_text_color', sanitize_hex_color(wp_unslash($_POST['sota_text_color'] ?? '')));
        update_option('sota_is_transparent', isset($_POST['sota_is_transparent']) ? 1 : 0);
        update_option('sota_use_theme_font', isset($_POST['sota_use_theme_font']) ? 1 : 0);
        update_option('sota_s2s_highlight', sanitize_hex_color(wp_unslash($_POST['sota_s2s_highlight'] ?? '')));
        update_option('sota_s2s_text_color', sanitize_hex_color(wp_unslash($_POST['sota_s2s_text_color'] ?? '')));
        update_option('sota_show_contact_map', isset($_POST['sota_show_contact_map']) ? 1 : 0);
        update_option('sota_qrz_username', sanitize_text_field(wp_unslash($_POST['sota_qrz_username'] ?? '')));
        $sota_magic_new_pass = sanitize_text_field(wp_unslash($_POST['sota_qrz_password'] ?? ''));
        if (!empty($sota_magic_new_pass)) {
            update_option('sota_qrz_password', sota_magic_encrypt_credential($sota_magic_new_pass));
        }
        update_option('sota_show_gpx_stats', isset($_POST['sota_show_gpx_stats']) ? 1 : 0);
        update_option('sota_stationary_threshold', sanitize_text_field(wp_unslash($_POST['sota_stationary_threshold'] ?? '')));
        update_option('sota_unit_system', sanitize_text_field(wp_unslash($_POST['sota_unit_system'] ?? '')));
        update_option('sota_activation_zone_radius', sanitize_text_field(wp_unslash($_POST['sota_activation_zone_radius'] ?? '')));
        update_option('sota_rest_threshold_minutes', sanitize_text_field(wp_unslash($_POST['sota_rest_threshold_minutes'] ?? '')));
        update_option('sota_use_azapi', isset($_POST['sota_use_azapi']) ? 1 : 0);
        update_option('sota_debug_mode', isset($_POST['sota_debug_mode']) ? 1 : 0);
        update_option('sota_default_map_layer', sanitize_text_field(wp_unslash($_POST['sota_default_map_layer'] ?? 'topo')));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><img src="<?php echo esc_url(plugins_url('lib/sota-magic-logo-40.png', __FILE__)); ?>" alt="SOTA Magic" style="height:40px;vertical-align:middle;margin-right:10px;">SOTA Magic Settings</h1>
        <?php
        $sota_magic_data = get_plugin_data( __FILE__ );
        ?>
        <p style="font-size:12px;color:#666;"><em>Created by KI6CR &mdash; Version <?php echo esc_html( $sota_magic_data['Version'] ); ?></em></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('sota_magic_settings'); ?>
            <table class="form-table">
                <tr><th colspan="2"><h2>Headlines</h2></th></tr>
                <tr><th>GPX Headline</th><td><input type="text" name="sota_headline_gpx" value="<?php echo esc_attr(get_option('sota_headline_gpx')); ?>" class="regular-text" /></td></tr>
                <tr><th>CSV Headline</th><td><input type="text" name="sota_headline_csv" value="<?php echo esc_attr(get_option('sota_headline_csv')); ?>" class="regular-text" /></td></tr>
                <tr><th>Map Headline</th><td><input type="text" name="sota_headline_map" value="<?php echo esc_attr(get_option('sota_headline_map')); ?>" class="regular-text" /></td></tr>
                
                <tr><th colspan="2"><h2>Colors</h2></th></tr>
                <tr><th>Background</th><td><input type="color" name="sota_bg_color" value="<?php echo esc_attr(get_option('sota_bg_color')); ?>" /></td></tr>
                <tr><th>Text</th><td><input type="color" name="sota_text_color" value="<?php echo esc_attr(get_option('sota_text_color')); ?>" /></td></tr>
                <tr><th>Transparent BG</th><td><input type="checkbox" name="sota_is_transparent" value="1" <?php checked(1, get_option('sota_is_transparent')); ?> /></td></tr>
                <tr><th>Use Theme Font</th><td><input type="checkbox" name="sota_use_theme_font" value="1" <?php checked(1, get_option('sota_use_theme_font')); ?> /></td></tr>
                
                <tr><th colspan="2"><h2>S2S Highlighting</h2></th></tr>
                <tr><th>S2S Background</th><td><input type="color" name="sota_s2s_highlight" value="<?php echo esc_attr(get_option('sota_s2s_highlight')); ?>" /></td></tr>
                <tr><th>S2S Text</th><td><input type="color" name="sota_s2s_text_color" value="<?php echo esc_attr(get_option('sota_s2s_text_color')); ?>" /></td></tr>
                
                <tr><th colspan="2"><h2>GPX Track Analysis</h2></th></tr>
                <tr><th colspan="2"><p style="background:#f0f0f0;padding:10px;border-left:4px solid #0073aa;margin:10px 0;">
                    <strong>How Hiking vs. Activation Time is Calculated:</strong><br>
                    The plugin can use two methods to determine the activation zone:<br>
                    <strong>1. Activation.Zone API (Recommended):</strong> Queries api.activation.zone for the precise activation zone based on terrain elevation data (25m vertical drop per SOTA rules). Most accurate!<br>
                    <strong>2. Fallback Method:</strong> Uses a simple radius around the highest GPS point. Used automatically if the API is unavailable or disabled.<br><br>
                    <strong>All time spent within the activation zone counts as activation time</strong> (regardless of movement - you're often adjusting antenna, repositioning, taking photos, etc.). All other time (including rest breaks during the hike) counts as hiking time, with rest breaks shown as a sub-note for reference.
                </p></th></tr>
                <tr><th>Use Activation.Zone API</th><td>
                    <input type="checkbox" name="sota_use_azapi" value="1" <?php checked(1, get_option('sota_use_azapi')); ?> />
                    <br><small>Query <a href="https://activation.zone" target="_blank">activation.zone</a> (by N6ARA) for precise activation zone geometry based on terrain data. If disabled or API fails, falls back to radius method.</small>
                </td></tr>
                <tr><th>Show GPX Statistics</th><td><input type="checkbox" name="sota_show_gpx_stats" value="1" <?php checked(1, get_option('sota_show_gpx_stats')); ?> /><br><small>Display hiking time, activation time, and other statistics</small></td></tr>
                <tr><th>Unit System</th><td>
                    <select name="sota_unit_system">
                        <option value="metric" <?php selected('metric', get_option('sota_unit_system')); ?>>Metric (km, m, km/h)</option>
                        <option value="imperial" <?php selected('imperial', get_option('sota_unit_system')); ?>>Imperial (mi, ft, mph)</option>
                    </select>
                    <br><small>Choose how distances and speeds are displayed</small>
                </td></tr>
                <tr><th>Default Map Layer</th><td>
                    <select name="sota_default_map_layer">
                        <option value="topo" <?php selected('topo', get_option('sota_default_map_layer')); ?>>Topographic (OpenTopoMap)</option>
                        <option value="osm" <?php selected('osm', get_option('sota_default_map_layer')); ?>>OpenStreetMap</option>
                        <option value="carto" <?php selected('carto', get_option('sota_default_map_layer')); ?>>Minimal (CartoDB)</option>
                    </select>
                    <br><small>Which base map layer loads by default on the GPX track map</small>
                </td></tr>
                <tr><th>Activation Zone Radius</th><td><input type="number" name="sota_activation_zone_radius" value="<?php echo esc_attr(get_option('sota_activation_zone_radius')); ?>" step="10" min="20" max="200" style="width:80px;" /> meters<br><small>Used as fallback if Activation.Zone API is disabled or unavailable (default: 50m)</small></td></tr>
                <tr><th>Rest Break Threshold</th><td><input type="number" name="sota_rest_threshold_minutes" value="<?php echo esc_attr(get_option('sota_rest_threshold_minutes')); ?>" step="1" min="1" max="30" style="width:80px;" /> minutes<br><small>Minimum duration to count as a rest break. Short stops (photos, water) won't count as breaks. (default: 3 min)</small></td></tr>
                <tr><th>Stationary Speed Threshold</th><td><input type="number" name="sota_stationary_threshold" value="<?php echo esc_attr(get_option('sota_stationary_threshold')); ?>" step="0.1" min="0.1" max="2.0" style="width:80px;" /> km/h<br><small>Speed below this is considered stationary (default: 0.3 km/h)</small></td></tr>
                
                <tr><th colspan="2"><h2>Contact Map</h2></th></tr>
                <tr><th colspan="2"><p style="background:#f0f0f0;padding:10px;border-left:4px solid #0073aa;margin:10px 0;">
                    <strong>How Contact Locations are Determined:</strong><br>
                    • <strong>Summit-to-Summit (S2S) contacts:</strong> Exact summit coordinates from SOTA API<br>
                    • <strong>Regular contacts:</strong> Station location from QRZ.com database (requires QRZ credentials below)<br>
                    • Locations are looked up once when the map loads and cached for performance
                </p></th></tr>
                <tr><th>Show Contact Map</th><td><input type="checkbox" name="sota_show_contact_map" value="1" <?php checked(1, get_option('sota_show_contact_map')); ?> /></td></tr>
                <tr><th>QRZ Username</th><td><input type="text" name="sota_qrz_username" value="<?php echo esc_attr(get_option('sota_qrz_username')); ?>" class="regular-text" /><br><small>Your QRZ.com callsign</small></td></tr>
                <tr><th>QRZ Password</th><td><input type="password" name="sota_qrz_password" value="" placeholder="<?php echo get_option('sota_qrz_password') ? esc_attr('(saved — leave blank to keep current password)') : ''; ?>" class="regular-text" /><br><small>Your QRZ.com password. Leave blank to keep the current saved password.</small></td></tr>

                <tr><th colspan="2"><h2>Developer Tools</h2></th></tr>
                <tr><th>Debug Mode (admin only)</th><td>
                    <input type="checkbox" name="sota_debug_mode" value="1" <?php checked(1, get_option('sota_debug_mode')); ?> />
                    <br><small>Shows technical debug panels <strong>visible only to logged-in admins</strong>. Safe to leave on without affecting public visitors. Enables two panels:
                    <br>• <strong>GPX stats page</strong> — API response details, polygon vertex count, points-in-zone count, and speed ranges
                    <br>• <strong>Contact map</strong> — summit lookup result, contacts resolved, per-contact location source, and lines-drawn count</small>
                </td></tr>
                <tr><th>Debug Mode (public)</th><td>
                    <input type="checkbox" name="sota_debug_mode_public" value="1" <?php checked(1, get_option('sota_debug_mode_public')); ?> />
                    <br><small>Same debug panels as above but <strong>visible to all visitors</strong> — use temporarily when testing while logged out. Disable when done.</small>
                </td></tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'sota_magic_save'); ?>
        </form>
    </div>
    <?php
}

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
    
    $options = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json' . "\r\n" .
                         'Accept: application/json' . "\r\n",
            'content' => $json_data,
            'timeout' => 30,
            'user_agent' => 'SOTA-Magic-Plugin/0.517',
            'ignore_errors' => true
        )
    );
    
    $context = stream_context_create($options);
    $result = @file_get_contents($api_url, false, $context);
    
    if ($result === false) {
        $error = error_get_last();
        $debug .= "API call failed: " . ($error ? $error['message'] : 'Unknown error');
        return ['polygon' => null, 'debug' => $debug];
    }
    
    $debug .= "Response received (" . strlen($result) . " bytes) | ";
    
    // Check HTTP response code
    if (isset($http_response_header)) {
        $debug .= "Status: " . $http_response_header[0] . " | ";
    }
    
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
function sota_magic_analyze_gpx_track($gpx_url, $csv_url = null, $force_radius = false) {
    $stationary_threshold = floatval(get_option('sota_stationary_threshold', 0.3)); // km/h
    $activation_zone_radius = floatval(get_option('sota_activation_zone_radius', 50)); // meters
    $rest_threshold_minutes = floatval(get_option('sota_rest_threshold_minutes', 10)); // minutes
    $rest_threshold_seconds = $rest_threshold_minutes * 60;
    $use_azapi = get_option('sota_use_azapi', 1);
    
    // Download and parse GPX
    $gpx_content = @file_get_contents($gpx_url);
    if (!$gpx_content) {
        return null;
    }
    
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
    
    // --- Step 1: Extract summit reference from CSV (always, regardless of zone method) ---
    $summit_ref = null;
    if ($csv_url) {
        $csv_response = wp_remote_get($csv_url, ['timeout' => 15]);
        if (!is_wp_error($csv_response)) {
            $csv_body = wp_remote_retrieve_body($csv_response);
            foreach (explode("\n", $csv_body) as $csv_line) {
                $row = str_getcsv(trim($csv_line));
                if (!empty($row[0]) && $row[0] === 'V2' && !empty($row[2])) {
                    $summit_ref = $row[2];
                    break;
                }
            }
        }
    }

    // --- Step 2: Fetch official summit coordinates from SOTA API (always when ref available) ---
    // This ensures the activation zone center is the official summit, not the GPX high point,
    // regardless of whether the activation.zone polygon API or radius fallback is used.
    if ($summit_ref) {
        $sota_api_url = 'https://api2.sota.org.uk/api/summits/' . $summit_ref;
        $sota_context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'SOTA-Magic-Plugin/1.0']]);
        $sota_response = @file_get_contents($sota_api_url, false, $sota_context);
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

    if ($use_azapi && $summit_ref && !$force_radius) {
        $sota_magic_az_cache_key = 'sota_magic_az_' . sanitize_key($summit_ref);
        // Read directly from DB to bypass object cache
        global $wpdb;
        $sota_magic_az_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $sota_magic_az_cache_key
        ));
        $sota_magic_az_cached = false;
        if ($sota_magic_az_raw !== null) {
            $sota_magic_az_entry = maybe_unserialize($sota_magic_az_raw);
            if (is_array($sota_magic_az_entry) && isset($sota_magic_az_entry['expires']) && time() < $sota_magic_az_entry['expires']) {
                $sota_magic_az_cached = $sota_magic_az_entry['data'];
            }
        }
        if ($sota_magic_az_cached !== false) {
            $activation_zone_polygon = $sota_magic_az_cached;
            $api_debug_message       = 'Loaded from cache (terrain polygon — cached 365 days)';
            if ($activation_zone_polygon) $using_api = true;
        } else {
            $api_result = sota_magic_get_activation_zone_from_api($summit_ref, $summit_lat, $summit_lon, $max_elevation);
            if (is_array($api_result)) {
                $activation_zone_polygon = $api_result['polygon'];
                $api_debug_message       = $api_result['debug'];
                if ($activation_zone_polygon) {
                    $using_api = true;
                    $wpdb->replace($wpdb->options, [
                        'option_name'  => $sota_magic_az_cache_key,
                        'option_value' => maybe_serialize(['data' => $activation_zone_polygon, 'expires' => time() + 365 * DAY_IN_SECONDS]),
                        'autoload'     => 'no',
                    ]);
                    $api_debug_message .= ' | Cached for 365 days';
                }
            }
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
    $gpx_content = @file_get_contents($gpx_url);
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

// BLOCK REGISTRATION
add_action('init', function() {
    register_block_type('ki6cr/sota-data', [
        'editor_script' => 'sota-editor-js',
        'render_callback' => 'sota_magic_render_sota_data'
    ]);
});

// AJAX: Clear QRZ location cache
add_action('wp_ajax_sota_magic_clear_qrz_cache', function() {
    check_ajax_referer('sota_magic_clear_qrz_cache');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');
    global $wpdb;
    $table   = $wpdb->prefix . 'sota_magic_locations';
    $count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $wpdb->query("TRUNCATE TABLE $table");
    wp_send_json_success($count . ' cached location(s) cleared. Fresh lookups will run on next map load.');
});

add_action('enqueue_block_editor_assets', function() {
    wp_register_script('sota-editor-js', '', ['wp-blocks','wp-element','wp-editor','wp-components'], '1.0.0', true);
    wp_localize_script('sota-editor-js', 'sotaMagicAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sota_magic_clear_qrz_cache'),
    ]);
    wp_add_inline_script('sota-editor-js', "
        wp.blocks.registerBlockType('ki6cr/sota-data', {
            title: 'SOTAMAGIC',
            icon: 'location-alt',
            category: 'common',
            attributes: {
                gpxUrl: {type:'string'},
                csvUrl: {type:'string'},
                forceRadiusZone: {type:'boolean', default:false},
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
                hideGpxStats: {type:'boolean', default:false}
            },
            edit: function(props) {
                var _ms = wp.element.useState(false);
                var showModal = _ms[0];
                var setShowModal = _ms[1];
                return wp.element.createElement('div', {
                    style:{padding:'25px', background:'\\x23f5f5f5', border:'2px dashed \\x230073aa', borderRadius:'8px', textAlign:'center'}
                },
                    wp.element.createElement('h3', {style:{margin:'0 0 10px 0', color:'\\x230073aa'}}, '🏔️ SOTAMAGIC'),
                    wp.element.createElement('p', {style:{color:'\\x23d32f2f', fontWeight:'bold', margin:'0 0 10px 0'}}, '⚠️ Map and table visible in Preview only'),
                    wp.element.createElement('p', {style:{color:'\\x23666', fontSize:'13px', marginBottom:'16px'}}, 'Settings → SOTA Magic to customize colors, units, and more.'),
                    wp.element.createElement('div', {style:{textAlign:'left', marginBottom:'16px'}},
                        wp.element.createElement('div', {style:{background:'\\x23ffffff', border:'1px solid \\x23dddddd', borderRadius:'6px', padding:'12px 14px', marginBottom:'8px', display:'flex', alignItems:'center', gap:'12px'}},
                            wp.element.createElement('div', {style:{flex:'1', minWidth:'0'}},
                                wp.element.createElement('div', {style:{fontWeight:'700', fontSize:'13px', color:'\\x231e1e1e', marginBottom:'3px'}}, '📍 GPS Track (.gpx)'),
                                wp.element.createElement('div', {style:{fontSize:'12px', color:'\\x23666666', lineHeight:'1.5'}}, 'The track file exported from your GPS device or app — Garmin, Gaia GPS, CalTopo, etc.')
                            ),
                            wp.element.createElement(wp.editor.MediaUpload, {
                                onSelect: function(media) { props.setAttributes({gpxUrl: media.url}); },
                                allowedTypes: ['application/gpx+xml', 'text/xml'],
                                render: function(obj) {
                                    return wp.element.createElement(wp.components.Button, {
                                        isPrimary: !!props.attributes.gpxUrl,
                                        isSecondary: !props.attributes.gpxUrl,
                                        onClick: obj.open,
                                        style:{flexShrink:'0', whiteSpace:'nowrap'}
                                    }, props.attributes.gpxUrl ? '✓ GPX Uploaded' : 'Upload GPX');
                                }
                            })
                        ),
                        wp.element.createElement('div', {style:{background:'\\x23ffffff', border:'1px solid \\x23dddddd', borderRadius:'6px', padding:'12px 14px', display:'flex', alignItems:'center', gap:'12px'}},
                            wp.element.createElement('div', {style:{flex:'1', minWidth:'0'}},
                                wp.element.createElement('div', {style:{fontWeight:'700', fontSize:'13px', color:'\\x231e1e1e', marginBottom:'3px'}}, '📋 Contacts Log (.csv)'),
                                wp.element.createElement('div', {style:{fontSize:'12px', color:'\\x23666666', lineHeight:'1.5'}}, 'The same CSV file you upload to the official SOTA website (sotadata.org.uk) — SOTA CSV v2 format.')
                            ),
                            wp.element.createElement(wp.editor.MediaUpload, {
                                onSelect: function(media) { props.setAttributes({csvUrl: media.url}); },
                                allowedTypes: ['text/csv'],
                                render: function(obj) {
                                    return wp.element.createElement(wp.components.Button, {
                                        isPrimary: !!props.attributes.csvUrl,
                                        isSecondary: !props.attributes.csvUrl,
                                        onClick: obj.open,
                                        style:{flexShrink:'0', whiteSpace:'nowrap'}
                                    }, props.attributes.csvUrl ? '✓ CSV Uploaded' : 'Upload CSV');
                                }
                            })
                        )
                    ),
                    wp.element.createElement('div', {style:{textAlign:'center', marginBottom:'10px'}},
                        wp.element.createElement('button', {
                            onClick: function(e) { e.preventDefault(); setShowModal(true); },
                            style:{background:'none', border:'1px solid \\x230073aa', borderRadius:'20px', color:'\\x230073aa', fontSize:'12px', cursor:'pointer', padding:'3px 14px', lineHeight:'1.6'}
                        }, 'ℹ️ How are statistics calculated?')
                    ),
                    showModal && wp.element.createElement(wp.components.Modal, {
                        title: '📊 How Statistics Are Calculated',
                        onRequestClose: function() { setShowModal(false); }
                    },
                        wp.element.createElement('div', {style:{fontSize:'13px', lineHeight:'1.65', color:'\\x23333333', maxWidth:'500px'}},

                            wp.element.createElement('h3', {style:{marginTop:'0', marginBottom:'6px', color:'\\x230073aa', fontSize:'14px'}}, '📍 Activation Zone'),
                            wp.element.createElement('p', {style:{marginTop:'0'}}, 'The activation zone boundary is the foundation — all time stats depend on it. Here is how it is determined, in order:'),
                            wp.element.createElement('ol', {style:{paddingLeft:'18px', margin:'6px 0 0 0'}},
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Summit reference'), ' is read from your CSV file (e.g. W6/CT-001).'),
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Official coordinates'), ' are fetched from the SOTA API (api2.sota.org.uk).'),
                                wp.element.createElement('li', {style:{marginBottom:'5px'}}, wp.element.createElement('strong', null, 'Activation.Zone API'), ' (by N6ARA) returns a precise terrain-based polygon using the official 25m vertical drop rule.'),
                                wp.element.createElement('li', null, wp.element.createElement('strong', null, 'Fallback:'), ' if no summit reference or the API is unavailable, a radius circle is drawn around the highest GPS point in your track.')
                            ),

                            wp.element.createElement('hr', {style:{border:'none', borderTop:'1px solid \\x23eeeeee', margin:'14px 0'}}),

                            wp.element.createElement('h3', {style:{margin:'0 0 8px 0', color:'\\x230073aa', fontSize:'14px'}}, '⏱️ Time Statistics'),
                            wp.element.createElement('p', {style:{margin:'0 0 6px 0'}}, wp.element.createElement('strong', null, 'Activation Time: '), 'All time spent inside the activation zone — whether you are moving around the summit, setting up, or operating.'),
                            wp.element.createElement('p', {style:{margin:'0 0 6px 0'}}, wp.element.createElement('strong', null, 'Hiking Time: '), 'All time spent outside the zone. Rest breaks are included and shown as a sub-note under hiking time.'),
                            wp.element.createElement('p', {style:{margin:'0'}}, wp.element.createElement('strong', null, 'Total Time: '), 'Elapsed time from the first to the last GPS trackpoint.'),

                            wp.element.createElement('hr', {style:{border:'none', borderTop:'1px solid \\x23eeeeee', margin:'14px 0'}}),

                            wp.element.createElement('h3', {style:{margin:'0 0 8px 0', color:'\\x230073aa', fontSize:'14px'}}, '🔧 Why Stats Might Look Wrong'),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Activation time is 0 or missing'), ' — The plugin could not locate the activation zone. Check that your CSV file includes a valid summit reference. Turn on Debug Mode in Settings → SOTA Magic for details.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Zone looks wrong on the map'), ' — Look for a red polygon (API-based) or orange circle (radius fallback) on the map. If you see a circle, the API did not return a zone. Try increasing the radius in Settings, or use the Statistics Override below to force a specific value.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0 0 8px 0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'Hiking time seems too high'), ' — Rest breaks are included in hiking time. Adjust the rest break threshold in Settings → SOTA Magic.'
                            ),
                            wp.element.createElement('p', {style:{margin:'0', padding:'8px 10px', background:'\\x23fff8e1', borderRadius:'4px', borderLeft:'3px solid \\x23f59e0b'}},
                                wp.element.createElement('strong', null, 'GPS track does not reach the summit'), ' — The zone is centred on the highest point in your track. If you stopped before the peak, use the Activation Zone radius override below or manually enter the activation time.'
                            )
                        )
                    ),
                    wp.element.createElement('div', {style:{display:'flex', alignItems:'center', gap:'8px', marginBottom:'6px', padding:'8px 10px',
                        background: props.attributes.hideGpxStats ? '\\x23fff3cd' : '\\x23f0f0f0', borderRadius:'4px',
                        border: props.attributes.hideGpxStats ? '1px solid \\x23f0ad4e' : '1px solid \\x23dddddd'}},
                        wp.element.createElement('input', {type:'checkbox', id:'hideGpxStats', checked:!!props.attributes.hideGpxStats,
                            onChange:function(e){props.setAttributes({hideGpxStats:e.target.checked});},
                            style:{width:'16px',height:'16px',cursor:'pointer',flexShrink:'0'}}),
                        wp.element.createElement('label', {htmlFor:'hideGpxStats', style:{fontSize:'13px', fontWeight:'700', cursor:'pointer',
                            color: props.attributes.hideGpxStats ? '\\x23856404' : '\\x23333333', fontFamily:'sans-serif', lineHeight:'1.4'}},
                            props.attributes.hideGpxStats ? '⛔ GPX hike statistics hidden from post' : 'Hide GPX hike statistics from post')
                    ),
                    wp.element.createElement(wp.components.PanelBody, {title:'⚙️ Statistics Overrides', initialOpen:false},
                        wp.element.createElement('p', {style:{fontSize:'12px', color:'\\x23555555', marginTop:'0', marginBottom:'12px', lineHeight:'1.5'}},
                            'Check the box next to a field to enable that override. Leave unchecked to use the GPX-calculated value.'
                        ),
                        // --- Activation Zone (no text input, just a toggle) ---
                        wp.element.createElement('div', {style:{display:'table', width:'100%', borderCollapse:'collapse', marginBottom:'6px'}},
                            wp.element.createElement('div', {style:{display:'table-row'}},
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'24px', paddingRight:'8px'}},
                                    wp.element.createElement('input', {type:'checkbox', checked:!!props.attributes.forceRadiusZone,
                                        onChange:function(e){props.setAttributes({forceRadiusZone:e.target.checked});},
                                        style:{width:'16px',height:'16px',cursor:'pointer',display:'block'}})
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle', width:'175px', paddingRight:'8px',
                                    fontWeight:'700', fontSize:'13px', color:'\\x23000000', fontFamily:'sans-serif'}},
                                    'Activation Zone:'
                                ),
                                wp.element.createElement('div', {style:{display:'table-cell', verticalAlign:'middle',
                                    fontSize:'12px', color: props.attributes.forceRadiusZone ? '\\x23c05000' : '\\x23555555',
                                    fontStyle: props.attributes.forceRadiusZone ? 'normal' : 'italic', fontFamily:'sans-serif'}},
                                    props.attributes.forceRadiusZone ? '📍 Radius-based (API skipped)' : 'Using API / plugin default'
                                )
                            )
                        ),
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
                        // --- QRZ Cache Refresh ---
                        wp.element.createElement('div', {style:{marginTop:'16px', paddingTop:'12px', borderTop:'1px solid \\x23e0e0e0'}},
                            wp.element.createElement('p', {style:{fontSize:'11px', color:'\\x23666666', fontFamily:'sans-serif', margin:'0 0 8px'}},
                                '🗺️ QRZ Location Cache'
                            ),
                            wp.element.createElement('button', {
                                style:{
                                    width:'100%', padding:'7px 12px', fontSize:'12px', fontFamily:'sans-serif',
                                    background:'\\x23f0f0f0', border:'1px solid \\x23cccccc', borderRadius:'3px',
                                    cursor:'pointer', color:'\\x23333333'
                                },
                                onClick: function(e) {
                                    e.preventDefault();
                                    var btn = e.target;
                                    btn.disabled = true;
                                    btn.textContent = 'Clearing...';
                                    fetch(sotaMagicAdmin.ajaxUrl, {
                                        method: 'POST',
                                        headers: {'Content-Type':'application/x-www-form-urlencoded'},
                                        body: 'action=sota_magic_clear_qrz_cache&_ajax_nonce=' + sotaMagicAdmin.nonce
                                    })
                                    .then(function(r){ return r.json(); })
                                    .then(function(data){
                                        btn.textContent = data.success ? '✓ ' + data.data : '✗ Error';
                                        btn.style.background = data.success ? '\\x23d4edda' : '\\x23f8d7da';
                                        setTimeout(function(){
                                            btn.textContent = 'Force QRZ Refresh (clear cache)';
                                            btn.style.background = '\\x23f0f0f0';
                                            btn.disabled = false;
                                        }, 4000);
                                    });
                                }
                            }, 'Force QRZ Refresh (clear cache)'),
                            wp.element.createElement('p', {style:{fontSize:'10px', color:'\\x23999999', fontFamily:'sans-serif', margin:'6px 0 0'}},
                                'Clears cached QRZ locations for all contacts site-wide. Fresh lookups run on next map load.'
                            )
                        )
                    )
                );
            },
            save: function() { return null; }
        });
    ");
});

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

function sota_magic_render_sota_data($atts) {
    $gpx_url = $atts['gpxUrl'] ?? '';
    $csv_url = $atts['csvUrl'] ?? '';
    if (!$gpx_url && !$csv_url) return '';

    // Manual override attributes (only applied when their Enabled flag is checked)
    $force_radius_zone      = !empty($atts['forceRadiusZone']);
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
    $hide_stats_display = !empty($atts['hideGpxStats']);
    $unit_system = get_option('sota_unit_system', 'metric');

    // Analyze GPX if available and stats are enabled
    $gpx_stats = null;
    $track_points = [];
    if ($gpx_url && $show_gpx_stats) {
        $gpx_stats = sota_magic_analyze_gpx_track($gpx_url, $csv_url, $force_radius_zone);
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
        $map_iframe_url = plugins_url('contact-map.php', __FILE__) . '?csv=' . urlencode($csv_url) . '&_nonce=' . wp_create_nonce('sota_magic_contact_map') . $sota_magic_debug_param;
    }

    // Unique map ID for this block (static counter survives multiple blocks on one page)
    static $sota_map_counter = 0;
    $sota_map_counter++;
    $map_id = 'sota-gpx-map-' . $sota_map_counter;

    // Enqueue map assets and register per-block init call
    if ($gpx_url && !empty($track_points)) {
        wp_enqueue_style('sota-leaflet', plugins_url('lib/leaflet.css', __FILE__), [], '1.9.4');
        wp_enqueue_script('sota-leaflet-js', plugins_url('lib/leaflet.js', __FILE__), [], '1.9.4', true);
        wp_enqueue_script('sota-chartjs', plugins_url('lib/chart.umd.min.js', __FILE__), [], '4.4.0', true);
        wp_enqueue_script('sota-magic-map', plugins_url('sota-magic-map.js', __FILE__), ['sota-leaflet-js', 'sota-chartjs'], '1.1.0', true);

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
            'defaultLayer'   => get_option('sota_default_map_layer', 'topo'),
        ];

        wp_add_inline_script('sota-magic-map',
            'sotaMagicInitMap(' . wp_json_encode($map_id) . ', ' . wp_json_encode($map_data) . ');'
        );
    }

    ob_start();
    ?>
    <style>
        .sota-main-container {
            background: <?php echo esc_attr($bg); ?>;
            color: <?php echo esc_attr($text); ?>;
            font-family: <?php echo esc_attr($font); ?>;
            padding: 30px;
            border-radius: 12px;
            margin: 40px 0;
            <?php if (!get_option('sota_is_transparent')): ?>
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            <?php endif; ?>
        }
        .sota-main-container h3 {
            color: <?php echo esc_attr($text); ?>;
            border-bottom: 1px solid <?php echo esc_attr($text); ?>44;
            padding-bottom: 10px;
        }

        /* GPX Statistics Grid */
        .sota-gpx-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 0 0 20px 0;
            padding: 20px;
            background: <?php echo $bg === 'transparent' ? 'rgba(255,255,255,0.05)' : (esc_attr($bg) . '22'); ?>;
            border-radius: 8px;
            border: 1px solid <?php echo esc_attr($text); ?>22;
        }

        .sota-stat-box {
            text-align: center;
            padding: 15px;
            background: <?php echo $bg === 'transparent' ? 'rgba(255,255,255,0.1)' : '#fff'; ?>;
            border-radius: 6px;
            <?php if ($bg !== 'transparent'): ?>
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            <?php endif; ?>
        }

        .sota-stat-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .sota-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: <?php echo esc_attr($text); ?>;
            margin-bottom: 5px;
        }

        .sota-stat-label {
            font-size: 13px;
            color: <?php echo esc_attr($text); ?>99;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sota-stat-secondary {
            font-size: 12px;
            color: <?php echo esc_attr($text); ?>77;
            margin-top: 5px;
        }

        /* Responsive table wrapper */
        .sota-table-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            margin: 20px 0;
        }
        .sota-table {
            width: 100%;
            border-collapse: collapse;
            color: <?php echo esc_attr($text); ?>;
            min-width: 800px; /* Ensures table doesn't get too cramped */
        }
        .sota-table th {
            border-bottom: 2px solid <?php echo esc_attr($text); ?>66;
            padding: 12px;
            text-align: left;
            white-space: nowrap;
        }
        .sota-table td {
            padding: 10px;
            border-bottom: 1px solid <?php echo esc_attr($text); ?>22;
            vertical-align: top;
        }
        /* Allow comments column to wrap text */
        .sota-table td:last-child {
            white-space: normal;
            word-wrap: break-word;
            max-width: 300px;
        }
        .s2s-row td {
            background: <?php echo esc_attr($s2s_bg); ?> !important;
            color: <?php echo esc_attr($s2s_text); ?> !important;
            font-weight: bold;
        }
        .s2s-badge {
            background: <?php echo esc_attr($s2s_text); ?>;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75em;
            margin-left: 5px;
        }
        /* GPX map + elevation chart */
        .sota-gpx-map {
            width: 100%;
            height: 400px;
            border-radius: 8px 8px 0 0;
            background: #e8e8e8;
        }
        .sota-gpx-chart-wrap {
            width: 100%;
            height: 140px;
            margin-bottom: 16px;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            position: relative;
            padding: 4px 4px 8px 4px;
            box-sizing: border-box;
        }
        /* Stats help modal */
        .sota-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 99999;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 16px;
        }
        .sota-modal-backdrop.sota-modal-open {
            display: flex;
        }
        .sota-modal {
            background: #ffffff;
            color: #1e1e1e;
            border-radius: 12px;
            padding: 30px 32px;
            max-width: 680px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
            position: relative;
            font-family: sans-serif;
        }
        .sota-modal h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            color: #0073aa;
            border: none;
            padding: 0;
        }
        .sota-modal .sota-modal-subtitle {
            font-size: 13px;
            color: #666;
            margin: 0 0 22px 0;
        }
        .sota-modal-close {
            position: absolute;
            top: 16px;
            right: 20px;
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #888;
            line-height: 1;
            padding: 0;
        }
        .sota-modal-close:hover { color: #333; }
        .sota-modal-section {
            border-top: 1px solid #eeeeee;
            padding: 14px 0;
        }
        .sota-modal-section:first-of-type { border-top: none; padding-top: 0; }
        .sota-modal-section h3 {
            margin: 0 0 6px 0;
            font-size: 15px;
            color: #1e1e1e;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            padding: 0;
        }
        .sota-modal-section p {
            margin: 0;
            font-size: 13px;
            color: #444;
            line-height: 1.65;
        }
        .sota-modal-section p + p { margin-top: 6px; }
        .sota-modal-note {
            margin-top: 20px;
            padding: 12px 14px;
            background: #f0f7ff;
            border-left: 3px solid #0073aa;
            border-radius: 4px;
            font-size: 12px;
            color: #444;
            line-height: 1.6;
        }
        .sota-help-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: none;
            border: 1px solid currentColor;
            border-radius: 20px;
            padding: 3px 11px;
            font-size: 12px;
            cursor: pointer;
            opacity: 0.65;
            color: inherit;
            font-family: sans-serif;
            vertical-align: middle;
        }
        .sota-help-btn:hover { opacity: 1; }
        .sota-stats-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 12px 0 8px 0;
        }
        .sota-stats-header-label {
            font-size: 13px;
            font-weight: 600;
            opacity: 0.7;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        /* Mobile responsiveness */
        @media screen and (max-width: 768px) {
            .sota-main-container {
                padding: 15px;
            }
            .sota-gpx-stats {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                padding: 15px;
            }
            .sota-stat-icon {
                font-size: 24px;
            }
            .sota-stat-value {
                font-size: 20px;
            }
            .sota-table {
                font-size: 14px;
            }
            .sota-table th,
            .sota-table td {
                padding: 8px;
            }
        }
    </style>

    <div class="sota-main-container">
        <?php if ($gpx_url): ?>
            <h3>🏔️ <?php echo esc_html(get_option('sota_headline_gpx')); ?></h3>
            <?php if (!empty($track_points)): ?>
            <div id="<?php echo esc_attr($map_id); ?>" class="sota-gpx-map"></div>
            <div class="sota-gpx-chart-wrap">
                <canvas id="<?php echo esc_attr($map_id); ?>-chart"></canvas>
            </div>
            <?php else: ?>
            <p style="color:#888;font-style:italic;margin:10px 0 16px;">Map unavailable — GPX file could not be loaded.</p>
            <?php endif; ?>

            <?php if ($gpx_stats && !$hide_stats_display): ?>
                <div class="sota-stats-header">
                    <span class="sota-stats-header-label">Hike Statistics</span>
                    <button class="sota-help-btn" onclick="document.getElementById('sota-stats-modal').classList.add('sota-modal-open')">
                        ℹ️ How is this calculated?
                    </button>
                </div>
                <div class="sota-gpx-stats">
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">🥾</div>
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
                        <div class="sota-stat-icon">📻</div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_time_duration($gpx_stats['stationary_time'])); ?></div>
                        <div class="sota-stat-label">Activation Time</div>
                        <div class="sota-stat-secondary">
                            <?php if ($gpx_stats['using_api']): ?>
                                <span title="Using precise activation zone from activation.zone API">✓ API-based zone</span>
                            <?php else: ?>
                                <span title="Using radius approximation method">Within <?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?>m of summit</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">⏱️</div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_time_duration($gpx_stats['total_time'])); ?></div>
                        <div class="sota-stat-label">Total Time</div>
                        <div class="sota-stat-secondary"><?php echo esc_html(sota_magic_format_distance($gpx_stats['total_distance'], $unit_system)); ?></div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">📈</div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_elevation($gpx_stats['elevation_gain'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Elevation Gain</div>
                        <div class="sota-stat-secondary">↓ <?php echo esc_html(sota_magic_format_elevation($gpx_stats['elevation_loss'], $unit_system)); ?> loss</div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">🚶</div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_speed($gpx_stats['hiking_speed'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Hiking Speed</div>
                        <div class="sota-stat-secondary">average</div>
                    </div>

                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">⛰️</div>
                        <div class="sota-stat-value"><?php echo esc_html(sota_magic_format_elevation($gpx_stats['max_elevation'], $unit_system)); ?></div>
                        <div class="sota-stat-label">Peak Elevation</div>
                        <div class="sota-stat-secondary"><?php echo esc_html(sota_magic_format_elevation($gpx_stats['min_elevation'], $unit_system)); ?> base</div>
                    </div>
                </div>

                <!-- Methodology explanation -->
                <div style="margin-top:15px; padding:12px; background:rgba(0,0,0,0.03); border-radius:8px; font-size:13px; color:#666;">
                    <strong>Activation Zone Method:</strong>
                    <?php if ($gpx_stats['using_api']): ?>
                        Using precise terrain-based activation zone from <a href="https://activation.zone" target="_blank" style="color:#0073aa;">activation.zone</a> (by N6ARA).
                        This uses Digital Elevation Model (DEM) data and the official SOTA 25m vertical drop rule for maximum accuracy.
                    <?php else: ?>
                        Using radius approximation method (<?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?>m from highest GPS point).
                        For more accuracy, enable the activation.zone API in Settings → SOTA Magic, or ensure your CSV file includes the summit reference.
                    <?php endif; ?>
                </div>
                
                <!-- Stats help modal -->
                <div id="sota-stats-modal" class="sota-modal-backdrop" onclick="if(event.target===this)this.classList.remove('sota-modal-open')">
                    <div class="sota-modal" role="dialog" aria-modal="true" aria-labelledby="sota-modal-title">
                        <button class="sota-modal-close" onclick="document.getElementById('sota-stats-modal').classList.remove('sota-modal-open')" aria-label="Close">✕</button>
                        <h2 id="sota-modal-title">📊 How Hike Stats Are Calculated</h2>
                        <p class="sota-modal-subtitle">These figures are derived automatically from your GPX track file.</p>

                        <div class="sota-modal-section">
                            <h3>🥾 Hiking Time &amp; Distance</h3>
                            <p>Time and distance accumulated while <strong>moving outside the activation zone</strong> at a speed above the stationary threshold (default 0.3 km/h). Periods where you were stopped — waiting at a trailhead, taking a break — are excluded and counted separately as Rest Breaks.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>⏸️ Rest Breaks</h3>
                            <p>Stationary periods <strong>outside the activation zone</strong> lasting longer than the rest threshold (default 3 minutes). Anything shorter is ignored as normal GPS noise or a momentary pause. Rest break time is shown inside the Hiking Time box for reference but is <em>not</em> added to hiking time.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>📻 Activation Time</h3>
                            <p><strong>All time spent inside the activation zone</strong>, regardless of whether you were moving or stationary. This captures the full period from when you first entered the zone to when you left — including any walking around the summit, setting up gear, and operating.</p>
                            <p>The activation zone boundary is determined by one of two methods (see below).</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>⏱️ Total Time</h3>
                            <p>The elapsed time from the <strong>first to the last GPS trackpoint</strong> in the file. This equals Hiking Time + Activation Time + Rest Breaks + any unclassified transition time at the boundaries.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>📈 Elevation Gain &amp; Loss</h3>
                            <p>The <strong>cumulative</strong> altitude gained and lost across all trackpoints. Each uphill step between consecutive points adds to gain; each downhill step adds to loss. Out-and-back routes will show roughly equal gain and loss.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>🚶 Hiking Speed</h3>
                            <p>Average speed calculated as <strong>Hiking Distance ÷ Hiking Time</strong>. Only moving segments outside the activation zone are included, so rest stops and summit time do not drag the average down.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>⛰️ Peak &amp; Base Elevation</h3>
                            <p>The <strong>highest and lowest elevation values</strong> recorded in the GPS track. The highest point is also used as the starting reference for the activation zone when the API method is used.</p>
                        </div>

                        <div class="sota-modal-section">
                            <h3>📍 Activation Zone Methods</h3>
                            <?php if ($gpx_stats['using_api']): ?>
                            <p><strong>API-based zone (currently active):</strong> The boundary is retrieved from <a href="https://activation.zone" target="_blank" style="color:#0073aa;">activation.zone</a> using Digital Elevation Model (DEM) terrain data and the official SOTA rule — the zone extends to where the terrain drops 25 metres below the summit. This is the most accurate method and matches what SOTA adjudicators use.</p>
                            <?php else: ?>
                            <p><strong>Radius method (currently active):</strong> The activation zone is approximated as a circle of <strong><?php echo esc_html((string) $gpx_stats['activation_zone_radius']); ?> metres</strong> around the highest GPS point. This is less precise than the API method because it ignores terrain shape, but works without an internet lookup or summit reference in the log.</p>
                            <p>For better accuracy, enable the activation.zone API in <em>Settings → SOTA Magic</em> and ensure your CSV log includes the summit reference.</p>
                            <?php endif; ?>
                        </div>

                        <div class="sota-modal-note">
                            ⚙️ The stationary speed threshold, rest break minimum duration, activation zone radius, and unit system (metric/imperial) can all be tuned in <strong>Settings → SOTA Magic</strong>. If any values look wrong, the Manual Overrides on the SOTAMAGIC block let you correct individual figures without re-uploading files.
                        </div>
                    </div>
                </div>
                <script>
                (function() {
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            var m = document.getElementById('sota-stats-modal');
                            if (m) m.classList.remove('sota-modal-open');
                        }
                    });
                })();
                </script>

                <!-- Debug info -->
                <?php if (get_option('sota_debug_mode_public') || (get_option('sota_debug_mode') && current_user_can('manage_options'))): ?>
                <div style="margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:5px; font-size:12px; font-family:monospace;">
                    <strong>🔍 Debug Info:</strong><br>
                    API Enabled: <?php echo get_option('sota_use_azapi') ? 'YES' : 'NO'; ?><br>
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
            <h3 style="margin-top:40px;">🗺️ <?php echo esc_html(get_option('sota_headline_map')); ?></h3>
            <iframe src="<?php echo esc_url($map_iframe_url); ?>" 
                    style="width:100%; height:500px; border:none; border-radius:8px; background:#f5f5f5;" 
                    title="Contact Map">
            </iframe>
        <?php endif; ?>

        <?php if ($csv_url): ?>
            <h3 style="margin-top:40px;">📡 <?php echo esc_html(get_option('sota_headline_csv')); ?></h3>
            <div class="sota-table-wrapper">
                <table class="sota-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Callsign</th>
                            <th>Frequency</th>
                            <th>Mode</th>
                            <th>My Summit</th>
                            <th>Their Summit</th>
                            <th>Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $csv_table_response = wp_remote_get($csv_url, ['timeout' => 15]);
                    if (!is_wp_error($csv_table_response)) {
                        $csv_table_body = wp_remote_retrieve_body($csv_table_response);
                        foreach (explode("\n", $csv_table_body) as $csv_table_line) {
                            $data = str_getcsv(trim($csv_table_line));
                            if (empty($data[0]) || $data[0] !== 'V2') continue;
                            $s2s = !empty(trim($data[8] ?? ''));

                            // Format date according to WordPress settings
                            $csv_date = $data[3]; // Format: DD/MM/YY (e.g., 16/01/26)
                            $parts = explode('/', $csv_date);
                            if (count($parts) === 3) {
                                $day   = (int) $parts[0];
                                $month = (int) $parts[1];
                                $year  = (int) $parts[2];
                                // Handle 2-digit year (26 = 2026, not 1926)
                                $year = $year < 50 ? 2000 + $year : 1900 + $year;
                                $formatted_date = date_i18n(get_option('date_format'), strtotime("$year-$month-$day"));
                            } else {
                                $formatted_date = $csv_date; // Fallback to original
                            }

                            echo '<tr class="' . ($s2s ? 's2s-row' : '') . '">';
                            echo '<td>' . esc_html($formatted_date) . '</td>';
                            echo '<td>' . esc_html($data[4] ?? '') . '</td>';
                            echo '<td><strong>' . esc_html($data[7] ?? '') . '</strong>' . ($s2s ? '<span class="s2s-badge">S2S</span>' : '') . '</td>';
                            echo '<td>' . esc_html($data[5] ?? '') . '</td>';
                            echo '<td>' . esc_html($data[6] ?? '') . '</td>';
                            echo '<td>' . esc_html($data[2] ?? '') . '</td>';
                            echo '<td>' . esc_html($data[8] ?? '') . '</td>';
                            echo '<td>' . esc_html($data[9] ?? '') . '</td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
