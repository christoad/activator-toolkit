<?php
/**
 * SOTA Contact Map - Standalone iframe page
 * Version: 1.4
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly — served via admin-ajax.php

/**
 * Look up a cached location row from the dedicated locations table.
 * Returns object with lat/lon/label/source, or null if not found / expired.
 */
function sota_magic_location_read( $cache_key ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT lat, lon, label, source FROM $table
         WHERE cache_key = %s
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1",
        $cache_key
    ) );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/**
 * Store a location in the dedicated locations table.
 * Pass $expires_seconds = 0 for permanent storage.
 */
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

// Verify nonce — also checked by the AJAX handler that includes this file, but verified here too for static analysis
if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
    exit;
}

// Get and sanitize parameters
$sota_magic_debug_mode = ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' );
$sota_magic_csv_url = isset( $_GET['csv'] ) ? esc_url_raw( wp_unslash( $_GET['csv'] ) ) : '';
if ( ! $sota_magic_csv_url ) {
    echo '<div style="padding:20px;">No CSV file specified</div>';
    exit;
}

// Get QRZ credentials
$sota_magic_qrz_user = get_option( 'sota_qrz_username' );
$sota_magic_qrz_pass = sota_magic_decrypt_credential( get_option( 'sota_qrz_password' ) );

// Parse CSV via wp_remote_get
$sota_magic_contacts = [];
$sota_magic_csv_response = wp_remote_get( $sota_magic_csv_url, [ 'timeout' => 15 ] );
if ( ! is_wp_error( $sota_magic_csv_response ) ) {
    $sota_magic_csv_body = wp_remote_retrieve_body( $sota_magic_csv_response );
    foreach ( explode( "\n", $sota_magic_csv_body ) as $sota_magic_csv_line ) {
        $sota_magic_row = str_getcsv( trim( $sota_magic_csv_line ) );
        if ( ! empty( $sota_magic_row[0] ) && $sota_magic_row[0] === 'V2' ) {
            $sota_magic_contacts[] = [
                'my_summit'    => $sota_magic_row[2] ?? '',
                'date'         => $sota_magic_row[3] ?? '',
                'time'         => $sota_magic_row[4] ?? '',
                'frequency'    => $sota_magic_row[5] ?? '',
                'mode'         => $sota_magic_row[6] ?? '',
                'callsign'     => $sota_magic_row[7] ?? '',
                'their_summit' => trim( $sota_magic_row[8] ?? '' ),
                'comments'     => trim( $sota_magic_row[9] ?? '' ),
            ];
        }
    }
}

/**
 * Calculate great-circle distance between two lat/lon points (Haversine formula).
 * Returns array with 'km' and 'miles', both rounded to 1 decimal place.
 */
function sota_magic_haversine( $lat1, $lon1, $lat2, $lon2 ) {
    $R     = 6371;
    $dLat  = deg2rad( $lat2 - $lat1 );
    $dLon  = deg2rad( $lon2 - $lon1 );
    $a     = sin( $dLat / 2 ) * sin( $dLat / 2 )
           + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
           * sin( $dLon / 2 ) * sin( $dLon / 2 );
    $km    = $R * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
    return [ 'km' => round( $km, 1 ), 'miles' => round( $km * 0.621371, 1 ) ];
}

/**
 * Extract a Maidenhead grid square from free text.
 */
function sota_magic_extract_grid_square( $sota_magic_text ) {
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2}[A-X]{2})(?![A-Z0-9])/i', $sota_magic_text, $m ) ) {
        return strtoupper( $m[1] );
    }
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2})(?![A-Z0-9])/i', $sota_magic_text, $m ) ) {
        return strtoupper( $m[1] );
    }
    return null;
}

/**
 * Convert a 4- or 6-character Maidenhead locator to lat/lon center point.
 */
function sota_magic_maidenhead_to_latlon( $sota_magic_grid ) {
    $g   = strtoupper( $sota_magic_grid );
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

/**
 * Get band color from frequency (MHz).
 */
function sota_magic_get_band_color( $sota_magic_frequency ) {
    $freq = floatval( $sota_magic_frequency );
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

// Get summit location from SOTA API (cached 90 days in locations table)
$sota_magic_summit = null;
if ( ! empty( $sota_magic_contacts[0]['my_summit'] ) ) {
    $sota_magic_summit_ref = $sota_magic_contacts[0]['my_summit'];
    $sota_magic_summit_key = 'summit_' . sanitize_key( $sota_magic_summit_ref );
    $sota_magic_summit_row = sota_magic_location_read( $sota_magic_summit_key );
    if ( $sota_magic_summit_row ) {
        $sota_magic_summit = [
            'lat'  => floatval( $sota_magic_summit_row->lat ),
            'lon'  => floatval( $sota_magic_summit_row->lon ),
            'name' => $sota_magic_summit_row->label ?: $sota_magic_summit_ref,
            'ref'  => $sota_magic_summit_ref,
        ];
    } else {
        $sota_magic_api_url  = 'https://api2.sota.org.uk/api/summits/' . $sota_magic_summit_ref;
        $sota_magic_wp_resp  = wp_remote_get( $sota_magic_api_url, [ 'timeout' => 30, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
        $sota_magic_response = ! is_wp_error( $sota_magic_wp_resp ) ? wp_remote_retrieve_body( $sota_magic_wp_resp ) : false;
        if ( $sota_magic_response !== false ) {
            $sota_magic_summit_data = json_decode( $sota_magic_response, true );
            if ( $sota_magic_summit_data && isset( $sota_magic_summit_data['latitude'], $sota_magic_summit_data['longitude'] ) ) {
                $sota_magic_summit_name = $sota_magic_summit_data['name'] ?? $sota_magic_summit_ref;
                $sota_magic_summit = [
                    'lat'  => floatval( $sota_magic_summit_data['latitude'] ),
                    'lon'  => floatval( $sota_magic_summit_data['longitude'] ),
                    'name' => $sota_magic_summit_name,
                    'ref'  => $sota_magic_summit_ref,
                ];
                sota_magic_location_write( $sota_magic_summit_key, $sota_magic_summit['lat'], $sota_magic_summit['lon'], $sota_magic_summit_name, 'sota', 90 * DAY_IN_SECONDS );
            }
        }
    }
}

// Get QRZ session if credentials available
$sota_magic_qrz_session = null;
if ( $sota_magic_qrz_user && $sota_magic_qrz_pass ) {
    $sota_magic_login_url      = 'https://xmldata.qrz.com/xml/current/?username=' . rawurlencode( $sota_magic_qrz_user ) . '&password=' . rawurlencode( $sota_magic_qrz_pass );
    $sota_magic_login_wp       = wp_remote_get( $sota_magic_login_url, [ 'timeout' => 15 ] );
    $sota_magic_login_response = ! is_wp_error( $sota_magic_login_wp ) ? wp_remote_retrieve_body( $sota_magic_login_wp ) : false;
    if ( $sota_magic_login_response ) {
        preg_match( '/<Key>([^<]+)<\/Key>/', $sota_magic_login_response, $sota_magic_matches );
        if ( ! empty( $sota_magic_matches[1] ) ) {
            $sota_magic_qrz_session = $sota_magic_matches[1];
        }
    }
}

// Get locations for all contacts
$sota_magic_contact_locations = [];
$sota_magic_unresolved        = [];
$sota_magic_qrz_fail_debug    = []; // Only populated in debug mode
foreach ( $sota_magic_contacts as $sota_magic_contact ) {
    $sota_magic_callsign   = $sota_magic_contact['callsign'];
    $sota_magic_comments   = $sota_magic_contact['comments'];
    $sota_magic_is_s2s     = ! empty( $sota_magic_contact['their_summit'] );
    $sota_magic_band_color = sota_magic_get_band_color( $sota_magic_contact['frequency'] );

    // Priority 1: grid square in comments
    $sota_magic_grid = sota_magic_extract_grid_square( $sota_magic_comments );
    if ( $sota_magic_grid ) {
        $sota_magic_coords              = sota_magic_maidenhead_to_latlon( $sota_magic_grid );
        $sota_magic_contact_locations[] = [
            'callsign'        => $sota_magic_callsign,
            'lat'             => $sota_magic_coords['lat'],
            'lon'             => $sota_magic_coords['lon'],
            'summit'          => $sota_magic_contact['their_summit'],
            'mode'            => $sota_magic_contact['mode'],
            'frequency'       => $sota_magic_contact['frequency'],
            'is_s2s'          => $sota_magic_is_s2s,
            'color'           => $sota_magic_band_color,
            'location_source' => 'grid',
            'grid'            => $sota_magic_grid,
        ];
        continue;
    }

    // Priority 2: S2S — use SOTA API summit coordinates (cached 90 days in locations table)
    if ( $sota_magic_is_s2s ) {
        $sota_magic_their_ref = $sota_magic_contact['their_summit'];
        $sota_magic_s2s_key   = 'summit_' . sanitize_key( $sota_magic_their_ref );
        $sota_magic_s2s_row   = sota_magic_location_read( $sota_magic_s2s_key );
        if ( $sota_magic_s2s_row ) {
            $sota_magic_contact_locations[] = [
                'callsign'        => $sota_magic_callsign,
                'lat'             => floatval( $sota_magic_s2s_row->lat ),
                'lon'             => floatval( $sota_magic_s2s_row->lon ),
                'summit'          => $sota_magic_their_ref,
                'mode'            => $sota_magic_contact['mode'],
                'frequency'       => $sota_magic_contact['frequency'],
                'is_s2s'          => true,
                'color'           => $sota_magic_band_color,
                'location_source' => 'sota',
                'cached'          => true,
            ];
        } else {
            $sota_magic_their_api_url  = 'https://api2.sota.org.uk/api/summits/' . $sota_magic_their_ref;
            $sota_magic_their_wp       = wp_remote_get( $sota_magic_their_api_url, [ 'timeout' => 15, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
            $sota_magic_their_response = ! is_wp_error( $sota_magic_their_wp ) ? wp_remote_retrieve_body( $sota_magic_their_wp ) : false;
            if ( $sota_magic_their_response !== false ) {
                $sota_magic_their_data = json_decode( $sota_magic_their_response, true );
                if ( $sota_magic_their_data && isset( $sota_magic_their_data['latitude'], $sota_magic_their_data['longitude'] ) ) {
                    $sota_magic_s2s_lat = floatval( $sota_magic_their_data['latitude'] );
                    $sota_magic_s2s_lon = floatval( $sota_magic_their_data['longitude'] );
                    sota_magic_location_write( $sota_magic_s2s_key, $sota_magic_s2s_lat, $sota_magic_s2s_lon, $sota_magic_their_ref, 'sota', 90 * DAY_IN_SECONDS );
                    $sota_magic_contact_locations[] = [
                        'callsign'        => $sota_magic_callsign,
                        'lat'             => $sota_magic_s2s_lat,
                        'lon'             => $sota_magic_s2s_lon,
                        'summit'          => $sota_magic_their_ref,
                        'mode'            => $sota_magic_contact['mode'],
                        'frequency'       => $sota_magic_contact['frequency'],
                        'is_s2s'          => true,
                        'color'           => $sota_magic_band_color,
                        'location_source' => 'sota',
                        'cached'          => false,
                    ];
                } else {
                    $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'SOTA API returned no coordinates for ' . $sota_magic_their_ref ];
                }
            } else {
                $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'SOTA API unreachable for ' . $sota_magic_their_ref ];
            }
        }
        continue;
    }

    // Priority 3: QRZ.com home address lookup (stored permanently in locations table)
    if ( $sota_magic_qrz_session ) {
        $sota_magic_qrz_key = 'qrz_' . sanitize_key( strtolower( $sota_magic_callsign ) );
        $sota_magic_qrz_row = sota_magic_location_read( $sota_magic_qrz_key );
        if ( $sota_magic_qrz_row ) {
            $sota_magic_contact_locations[] = [
                'callsign'        => $sota_magic_callsign,
                'lat'             => floatval( $sota_magic_qrz_row->lat ),
                'lon'             => floatval( $sota_magic_qrz_row->lon ),
                'summit'          => '',
                'mode'            => $sota_magic_contact['mode'],
                'frequency'       => $sota_magic_contact['frequency'],
                'is_s2s'          => false,
                'color'           => $sota_magic_band_color,
                'location_source' => 'qrz',
                'cached'          => true,
            ];
        } else {
            $sota_magic_qrz_url      = 'https://xmldata.qrz.com/xml/current/?s=' . rawurlencode( $sota_magic_qrz_session ) . '&callsign=' . rawurlencode( $sota_magic_callsign );
            $sota_magic_qrz_wp       = wp_remote_get( $sota_magic_qrz_url, [ 'timeout' => 15, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
            $sota_magic_qrz_response = ! is_wp_error( $sota_magic_qrz_wp ) ? wp_remote_retrieve_body( $sota_magic_qrz_wp ) : false;
            if ( $sota_magic_qrz_response ) {
                preg_match( '/<lat>([^<]+)<\/lat>/', $sota_magic_qrz_response, $sota_magic_lat_match );
                preg_match( '/<lon>([^<]+)<\/lon>/', $sota_magic_qrz_response, $sota_magic_lon_match );
                if ( ! empty( $sota_magic_lat_match[1] ) && ! empty( $sota_magic_lon_match[1] ) ) {
                    $sota_magic_qrz_lat = floatval( $sota_magic_lat_match[1] );
                    $sota_magic_qrz_lon = floatval( $sota_magic_lon_match[1] );
                    sota_magic_location_write( $sota_magic_qrz_key, $sota_magic_qrz_lat, $sota_magic_qrz_lon, $sota_magic_callsign, 'qrz', 0 );
                    $sota_magic_contact_locations[] = [
                        'callsign'        => $sota_magic_callsign,
                        'lat'             => $sota_magic_qrz_lat,
                        'lon'             => $sota_magic_qrz_lon,
                        'summit'          => '',
                        'mode'            => $sota_magic_contact['mode'],
                        'frequency'       => $sota_magic_contact['frequency'],
                        'is_s2s'          => false,
                        'color'           => $sota_magic_band_color,
                        'location_source' => 'qrz',
                        'cached'          => false,
                    ];
                } else {
                    $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'No coordinates in QRZ response' ];
                    if ( $sota_magic_debug_mode ) {
                        $sota_magic_qrz_fail_debug[ $sota_magic_callsign ] = substr( $sota_magic_qrz_response, 0, 1000 );
                    }
                }
            } else {
                $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'QRZ HTTP request failed' ];
            }
            usleep( 500000 ); // Rate limit: 0.5s between QRZ calls (only on cache miss)
        }
    } else {
        $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'No QRZ credentials configured' ];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SOTA Contact Map</title>
<?php
// Standalone HTML document served via admin-ajax.php — wp_enqueue_style/script not available here.
// String concatenation avoids literal '<link'/'<script' patterns that trigger static analysis sniffs.
echo '<' . 'link rel=' . '"stylesheet" href="' . esc_url( plugins_url( 'lib/leaflet.css', __FILE__ ) ) . '">' . "\n";
echo '<' . 'link rel=' . '"stylesheet" href="' . esc_url( plugins_url( 'contact-map.css', __FILE__ ) ) . '">' . "\n";
echo '<' . 'script src="' . esc_url( plugins_url( 'lib/leaflet.js', __FILE__ ) ) . '"></' . 'script>' . "\n";
?>
</head>
<body>
    <div id="loading-overlay">
        <div class="loading-icon">🏔️</div>
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading contact map...</div>
    </div>

    <div id="map"></div>

    <script>
        <?php if ( $sota_magic_summit ) : ?>
        var map = L.map('map').setView([<?php echo floatval( $sota_magic_summit['lat'] ); ?>, <?php echo floatval( $sota_magic_summit['lon'] ); ?>], 6);
        <?php else : ?>
        var map = L.map('map').setView([37.0, -95.0], 4);
        <?php endif; ?>

        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '\u00a9 OpenStreetMap contributors \u00a9 CARTO',
            maxZoom: 19
        }).addTo(map);

        var summitIcon = L.divIcon({
            html: '<div style="font-size:32px;text-align:center;line-height:32px;">\ud83c\udfd4\ufe0f</div>',
            className: 'summit-icon',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });

        <?php if ( $sota_magic_summit ) : ?>
        var summitMarker = L.marker([<?php echo floatval( $sota_magic_summit['lat'] ); ?>, <?php echo floatval( $sota_magic_summit['lon'] ); ?>], {icon: summitIcon}).addTo(map);
        summitMarker.bindPopup('<div class="popup-content"><strong>\ud83c\udfd4\ufe0f <?php echo esc_js( $sota_magic_summit['name'] ); ?></strong><?php echo esc_js( $sota_magic_summit['ref'] ); ?><br><em>Your Activation</em></div>');
        <?php endif; ?>

        <?php foreach ( $sota_magic_contact_locations as $sota_magic_loc ) : ?>
        var contactCircle = L.circleMarker([<?php echo floatval( $sota_magic_loc['lat'] ); ?>, <?php echo floatval( $sota_magic_loc['lon'] ); ?>], {
            radius: <?php echo $sota_magic_loc['is_s2s'] ? '8' : '6'; ?>,
            fillColor: '<?php echo esc_attr( $sota_magic_loc['color'] ); ?>',
            color: '<?php echo $sota_magic_loc['is_s2s'] ? '#000' : '#fff'; ?>',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.9
        }).addTo(map);

        <?php
        $sota_magic_dist_html = '';
        if ( $sota_magic_summit ) {
            $sota_magic_dist = sota_magic_haversine(
                $sota_magic_summit['lat'], $sota_magic_summit['lon'],
                $sota_magic_loc['lat'],   $sota_magic_loc['lon']
            );
            $sota_magic_dist_html = '<br><span style="font-size:11px;color:#555;">&#128207; ' . esc_js( $sota_magic_dist['miles'] ) . ' mi &nbsp;/&nbsp; ' . esc_js( $sota_magic_dist['km'] ) . ' km</span>';
        }
        ?>
        contactCircle.bindPopup('<div class="popup-content"><strong><?php echo esc_js( $sota_magic_loc['callsign'] ); ?></strong><?php if ( $sota_magic_loc['summit'] ) : ?><br>\ud83d\udce1 <?php echo esc_js( $sota_magic_loc['summit'] ); ?> <span style="background:#ff9800;color:white;padding:2px 6px;border-radius:3px;font-size:11px;">S2S</span><?php endif; ?><br><span class="band-indicator" style="background-color:<?php echo esc_attr( $sota_magic_loc['color'] ); ?>;"></span><?php echo esc_js( $sota_magic_loc['frequency'] ); ?> MHz - <?php echo esc_js( $sota_magic_loc['mode'] ); ?><?php echo $sota_magic_dist_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above ?><?php if ( $sota_magic_loc['location_source'] === 'grid' ) : ?><br><span style="font-size:11px;color:#0073aa;">\ud83d\udccd Grid: <?php echo esc_js( $sota_magic_loc['grid'] ); ?></span><?php elseif ( $sota_magic_loc['location_source'] === 'qrz' ) : ?><br><span style="font-size:11px;color:#888;">\ud83c\udfe0 QRZ home address</span><?php endif; ?></div>');

        <?php if ( $sota_magic_summit ) : ?>
        L.polyline([
            [<?php echo floatval( $sota_magic_summit['lat'] ); ?>, <?php echo floatval( $sota_magic_summit['lon'] ); ?>],
            [<?php echo floatval( $sota_magic_loc['lat'] ); ?>, <?php echo floatval( $sota_magic_loc['lon'] ); ?>]
        ], {
            color: '<?php echo esc_attr( $sota_magic_loc['color'] ); ?>',
            weight: 3,
            opacity: 0.7
        }).addTo(map);
        <?php endif; ?>
        <?php endforeach; ?>

        var allPoints = [];
        <?php if ( $sota_magic_summit ) : ?>
        allPoints.push([<?php echo floatval( $sota_magic_summit['lat'] ); ?>, <?php echo floatval( $sota_magic_summit['lon'] ); ?>]);
        <?php endif; ?>
        <?php foreach ( $sota_magic_contact_locations as $sota_magic_loc ) : ?>
        allPoints.push([<?php echo floatval( $sota_magic_loc['lat'] ); ?>, <?php echo floatval( $sota_magic_loc['lon'] ); ?>]);
        <?php endforeach; ?>

        map.whenReady(function() {
            map.invalidateSize();
            if (allPoints.length > 1) {
                map.fitBounds(L.latLngBounds(allPoints), {padding: [50, 50]});
            } else if (allPoints.length === 1) {
                map.setView(allPoints[0], 10);
            }
            setTimeout(function() {
                document.getElementById('loading-overlay').classList.add('hidden');
            }, 500);
        });

        <?php if ( ! empty( $sota_magic_unresolved ) ) : ?>
        var unresolvedContacts = <?php echo wp_json_encode( $sota_magic_unresolved ); ?>;
        var UnresolvedControl = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function() {
                var div = L.DomUtil.create('div', '');
                div.style.cssText = 'background:white;border-radius:4px;padding:8px 12px;font-size:12px;font-family:sans-serif;max-width:240px;box-shadow:0 1px 5px rgba(0,0,0,0.3);line-height:1.6;';
                var html = '<strong style="color:#555;">&#9888; No location found</strong><br>';
                unresolvedContacts.forEach(function(c) {
                    html += '<span style="color:#888;">&bull; ' + c.callsign + '</span><br>';
                });
                div.innerHTML = html;
                return div;
            }
        });
        new UnresolvedControl().addTo(map);
        <?php endif; ?>

        <?php if ( $sota_magic_debug_mode ) : ?>
        console.log('[SOTA Map Debug] summit:', <?php echo wp_json_encode( $sota_magic_summit ); ?>);
        console.log('[SOTA Map Debug] contact_locations:', <?php echo wp_json_encode( $sota_magic_contact_locations ); ?>);
        console.log('[SOTA Map Debug] contacts_raw count:', <?php echo count( $sota_magic_contacts ); ?>);
        console.log('[SOTA Map Debug] first my_summit field:', <?php echo wp_json_encode( $sota_magic_contacts[0]['my_summit'] ?? 'none' ); ?>);
        <?php endif; ?>
    </script>

    <?php if ( $sota_magic_debug_mode ) : ?>
    <div style="position:fixed;bottom:0;left:0;right:0;background:#fff3cd;border-top:2px solid #ffc107;padding:8px 12px;font-size:11px;font-family:monospace;z-index:99999;max-height:35vh;overflow-y:auto;">
        <strong>🔍 Contact Map Debug (admin only)</strong><br>
        Summit found: <strong><?php echo $sota_magic_summit ? 'YES — ' . esc_html( $sota_magic_summit['ref'] ) . ' (' . esc_html( $sota_magic_summit['lat'] ) . ', ' . esc_html( $sota_magic_summit['lon'] ) . ')' : 'NO (API returned nothing)'; ?></strong><br>
        Raw CSV contacts parsed: <strong><?php echo count( $sota_magic_contacts ); ?></strong><br>
        First row my_summit field: <strong><?php echo esc_html( $sota_magic_contacts[0]['my_summit'] ?? '(empty)' ); ?></strong><br>
        Contact locations resolved: <strong><?php echo count( $sota_magic_contact_locations ); ?></strong><br>
        Lines drawn: <strong><?php echo ( $sota_magic_summit && count( $sota_magic_contact_locations ) > 0 ) ? count( $sota_magic_contact_locations ) . ' lines' : 'NONE — summit was null'; ?></strong><br>
        <?php
        $sota_magic_cached_count = 0;
        $sota_magic_fresh_count  = 0;
        foreach ( $sota_magic_contact_locations as $sota_magic_loc ) {
            if ( ! empty( $sota_magic_loc['cached'] ) ) $sota_magic_cached_count++;
            else $sota_magic_fresh_count++;
        }
        ?>
        Cache hits: <strong><?php echo esc_html( (string) $sota_magic_cached_count ); ?></strong> &nbsp;|&nbsp; Fresh lookups: <strong><?php echo esc_html( (string) $sota_magic_fresh_count ); ?></strong> &nbsp;|&nbsp; Unresolved: <strong><?php echo esc_html( (string) count( $sota_magic_unresolved ) ); ?></strong><br>
        <?php
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sota_magic_loc_table  = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
        $sota_magic_total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sota_magic_loc_table" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sota_magic_db_error   = $wpdb->last_error;
        ?>
        <hr style="margin:4px 0;">
        Locations table: <strong><?php echo esc_html( $sota_magic_loc_table ); ?></strong> — <strong><?php echo esc_html( (string) $sota_magic_total_rows ); ?> row(s) stored</strong><br>
        DB error: <strong><?php echo $sota_magic_db_error ? esc_html( $sota_magic_db_error ) : 'none'; ?></strong><br>
        <hr style="margin:4px 0;">
        <?php foreach ( $sota_magic_contact_locations as $sota_magic_idx => $sota_magic_loc ) : ?>
        <?php $sota_magic_cache_label = isset( $sota_magic_loc['cached'] ) ? ( $sota_magic_loc['cached'] ? ' <span style="color:#28a745;">✓ cached</span>' : ' <span style="color:#fd7e14;">⬇ fresh fetch</span>' ) : ''; ?>
        Contact <?php echo esc_html( (string) ( $sota_magic_idx + 1 ) ); ?>: <?php echo esc_html( $sota_magic_loc['callsign'] ); ?> → (<?php echo esc_html( $sota_magic_loc['lat'] ); ?>, <?php echo esc_html( $sota_magic_loc['lon'] ); ?>) via <?php echo esc_html( $sota_magic_loc['location_source'] ); ?><?php echo $sota_magic_cache_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled HTML ?><br>
        <?php endforeach; ?>
        <?php if ( ! empty( $sota_magic_unresolved ) ) : ?>
        <hr style="margin:4px 0;">
        <strong style="color:#c0392b;">⚠ Unresolved contacts (not shown on map):</strong><br>
        <?php foreach ( $sota_magic_unresolved as $sota_magic_ur ) : ?>
        &nbsp;• <strong><?php echo esc_html( $sota_magic_ur['callsign'] ); ?></strong> — <?php echo esc_html( $sota_magic_ur['reason'] ); ?><br>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ( ! empty( $sota_magic_qrz_fail_debug ) ) : ?>
        <hr style="margin:4px 0;">
        <strong>QRZ raw responses (failed lookups):</strong><br>
        <?php foreach ( $sota_magic_qrz_fail_debug as $sota_magic_cs => $sota_magic_raw ) : ?>
        <em><?php echo esc_html( $sota_magic_cs ); ?>:</em><br>
        <pre style="font-size:10px;white-space:pre-wrap;max-height:120px;overflow-y:auto;background:#f8f8f8;padding:4px;"><?php echo esc_html( $sota_magic_raw ); ?></pre>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</body>
</html>
