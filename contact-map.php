<?php
/**
 * SOTA Contact Map - Standalone iframe page
 * Version: 1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    require_once dirname( __FILE__ ) . '/../../../wp-load.php';
}

// Verify nonce
if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
    wp_die( 'Invalid request.' );
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
$sota_magic_qrz_pass = get_option( 'sota_qrz_password' );

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

// Get summit location from SOTA API
$sota_magic_summit = null;
if ( ! empty( $sota_magic_contacts[0]['my_summit'] ) ) {
    $sota_magic_summit_ref = $sota_magic_contacts[0]['my_summit'];
    $sota_magic_api_url    = 'https://api2.sota.org.uk/api/summits/' . $sota_magic_summit_ref;
    $sota_magic_context    = stream_context_create( [ 'http' => [ 'timeout' => 30, 'user_agent' => 'SOTA-Magic-Plugin/1.0' ] ] );
    $sota_magic_response   = @file_get_contents( $sota_magic_api_url, false, $sota_magic_context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( $sota_magic_response !== false ) {
        $sota_magic_summit_data = json_decode( $sota_magic_response, true );
        if ( $sota_magic_summit_data && isset( $sota_magic_summit_data['latitude'], $sota_magic_summit_data['longitude'] ) ) {
            $sota_magic_summit = [
                'lat'  => floatval( $sota_magic_summit_data['latitude'] ),
                'lon'  => floatval( $sota_magic_summit_data['longitude'] ),
                'name' => $sota_magic_summit_data['name'] ?? $sota_magic_summit_ref,
                'ref'  => $sota_magic_summit_ref,
            ];
        }
    }
}

// Get QRZ session if credentials available
$sota_magic_qrz_session = null;
if ( $sota_magic_qrz_user && $sota_magic_qrz_pass ) {
    $sota_magic_login_url      = 'https://xmldata.qrz.com/xml/current/?username=' . rawurlencode( $sota_magic_qrz_user ) . '&password=' . rawurlencode( $sota_magic_qrz_pass );
    $sota_magic_login_response = @file_get_contents( $sota_magic_login_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( $sota_magic_login_response ) {
        preg_match( '/<Key>([^<]+)<\/Key>/', $sota_magic_login_response, $sota_magic_matches );
        if ( ! empty( $sota_magic_matches[1] ) ) {
            $sota_magic_qrz_session = $sota_magic_matches[1];
        }
    }
}

// Get locations for all contacts
$sota_magic_contact_locations = [];
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

    // Priority 2: S2S — use SOTA API summit coordinates
    if ( $sota_magic_is_s2s ) {
        $sota_magic_their_api_url  = 'https://api2.sota.org.uk/api/summits/' . rawurlencode( $sota_magic_contact['their_summit'] );
        $sota_magic_their_response = @file_get_contents( $sota_magic_their_api_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( $sota_magic_their_response !== false ) {
            $sota_magic_their_data = json_decode( $sota_magic_their_response, true );
            if ( $sota_magic_their_data && isset( $sota_magic_their_data['latitude'], $sota_magic_their_data['longitude'] ) ) {
                $sota_magic_contact_locations[] = [
                    'callsign'        => $sota_magic_callsign,
                    'lat'             => floatval( $sota_magic_their_data['latitude'] ),
                    'lon'             => floatval( $sota_magic_their_data['longitude'] ),
                    'summit'          => $sota_magic_contact['their_summit'],
                    'mode'            => $sota_magic_contact['mode'],
                    'frequency'       => $sota_magic_contact['frequency'],
                    'is_s2s'          => true,
                    'color'           => $sota_magic_band_color,
                    'location_source' => 'sota',
                ];
            }
        }
        continue;
    }

    // Priority 3: QRZ.com home address lookup
    if ( $sota_magic_qrz_session ) {
        $sota_magic_qrz_url      = 'https://xmldata.qrz.com/xml/current/?s=' . rawurlencode( $sota_magic_qrz_session ) . '&callsign=' . rawurlencode( $sota_magic_callsign );
        $sota_magic_qrz_response = @file_get_contents( $sota_magic_qrz_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( $sota_magic_qrz_response ) {
            preg_match( '/<lat>([^<]+)<\/lat>/', $sota_magic_qrz_response, $sota_magic_lat_match );
            preg_match( '/<lon>([^<]+)<\/lon>/', $sota_magic_qrz_response, $sota_magic_lon_match );
            if ( ! empty( $sota_magic_lat_match[1] ) && ! empty( $sota_magic_lon_match[1] ) ) {
                $sota_magic_contact_locations[] = [
                    'callsign'        => $sota_magic_callsign,
                    'lat'             => floatval( $sota_magic_lat_match[1] ),
                    'lon'             => floatval( $sota_magic_lon_match[1] ),
                    'summit'          => '',
                    'mode'            => $sota_magic_contact['mode'],
                    'frequency'       => $sota_magic_contact['frequency'],
                    'is_s2s'          => false,
                    'color'           => $sota_magic_band_color,
                    'location_source' => 'qrz',
                ];
            }
        }
        usleep( 500000 ); // Rate limit: 0.5s between QRZ calls
    }
}

// Local Leaflet assets (bundled with plugin — inlined to satisfy WP enqueue rules in standalone page)
$sota_magic_leaflet_css = file_get_contents( plugin_dir_path( __FILE__ ) . 'lib/leaflet.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$sota_magic_leaflet_js  = file_get_contents( plugin_dir_path( __FILE__ ) . 'lib/leaflet.js' );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SOTA Contact Map</title>
    <style><?php echo $sota_magic_leaflet_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local bundled CSS file ?></style>
    <script><?php echo $sota_magic_leaflet_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local bundled JS file ?></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: sans-serif; }
        #map { width: 100%; height: 100vh; }
        .popup-content { font-size: 13px; line-height: 1.6; }
        .popup-content strong { display: block; margin-bottom: 5px; font-size: 15px; color: #333; }
        .band-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }
        #loading-overlay {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: #f5f5f5;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        #loading-overlay.hidden { opacity: 0; pointer-events: none; }
        .loading-spinner {
            width: 50px; height: 50px;
            border: 5px solid #e0e0e0;
            border-top: 5px solid #0073aa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { margin-top: 20px; font-size: 16px; color: #666; }
        .loading-icon { font-size: 48px; margin-bottom: 10px; }
    </style>
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

        contactCircle.bindPopup('<div class="popup-content"><strong><?php echo esc_js( $sota_magic_loc['callsign'] ); ?></strong><?php if ( $sota_magic_loc['summit'] ) : ?><br>\ud83d\udce1 <?php echo esc_js( $sota_magic_loc['summit'] ); ?> <span style="background:#ff9800;color:white;padding:2px 6px;border-radius:3px;font-size:11px;">S2S</span><?php endif; ?><br><span class="band-indicator" style="background-color:<?php echo esc_attr( $sota_magic_loc['color'] ); ?>;"></span><?php echo esc_js( $sota_magic_loc['frequency'] ); ?> MHz - <?php echo esc_js( $sota_magic_loc['mode'] ); ?><?php if ( $sota_magic_loc['location_source'] === 'grid' ) : ?><br><span style="font-size:11px;color:#0073aa;">\ud83d\udccd Grid: <?php echo esc_js( $sota_magic_loc['grid'] ); ?></span><?php elseif ( $sota_magic_loc['location_source'] === 'qrz' ) : ?><br><span style="font-size:11px;color:#888;">\ud83c\udfe0 QRZ home address</span><?php endif; ?></div>');

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

        <?php if ( $sota_magic_summit && count( $sota_magic_contact_locations ) > 0 ) : ?>
        var bounds = L.latLngBounds([
            [<?php echo floatval( $sota_magic_summit['lat'] ); ?>, <?php echo floatval( $sota_magic_summit['lon'] ); ?>]
            <?php foreach ( $sota_magic_contact_locations as $sota_magic_loc ) : ?>
            ,[<?php echo floatval( $sota_magic_loc['lat'] ); ?>, <?php echo floatval( $sota_magic_loc['lon'] ); ?>]
            <?php endforeach; ?>
        ]);
        map.fitBounds(bounds, {padding: [50, 50]});
        <?php endif; ?>

        map.whenReady(function() {
            setTimeout(function() {
                document.getElementById('loading-overlay').classList.add('hidden');
            }, 500);
        });

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
        <?php foreach ( $sota_magic_contact_locations as $sota_magic_idx => $sota_magic_loc ) : ?>
        Contact <?php echo esc_html( (string) ( $sota_magic_idx + 1 ) ); ?>: <?php echo esc_html( $sota_magic_loc['callsign'] ); ?> → (<?php echo esc_html( $sota_magic_loc['lat'] ); ?>, <?php echo esc_html( $sota_magic_loc['lon'] ); ?>) via <?php echo esc_html( $sota_magic_loc['location_source'] ); ?><br>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</body>
</html>
