<?php
/**
 * SOTA Contact Map - Standalone iframe page
 * Version: 1.3
 */

// Load WordPress
require_once('../../../wp-load.php');

// Get parameters
$csv_url = $_GET['csv'] ?? '';
if (!$csv_url) {
    echo '<div style="padding:20px;">No CSV file specified</div>';
    exit;
}

// Get QRZ credentials
$qrz_user = get_option('sota_qrz_username');
$qrz_pass = get_option('sota_qrz_password');

// Parse CSV
$contacts = [];
if ($handle = @fopen($csv_url, 'r')) {
    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
        if ($data[0] == 'V2') {
            $contacts[] = [
                'my_summit'   => $data[2],
                'date'        => $data[3],
                'time'        => $data[4],
                'frequency'   => $data[5],
                'mode'        => $data[6],
                'callsign'    => $data[7],
                'their_summit'=> trim($data[8] ?? ''),
                'comments'    => trim($data[9] ?? '')
            ];
        }
    }
    fclose($handle);
}

/**
 * Extract a Maidenhead grid square from free text.
 * Prefers 6-char precision; falls back to 4-char.
 * Returns the grid in uppercase, or null if none found.
 */
function extract_grid_square($text) {
    // 6-char grid: two field letters (A-R), two digits, two subsquare letters (A-X)
    if (preg_match('/(?<![A-Z0-9])([A-R]{2}[0-9]{2}[A-X]{2})(?![A-Z0-9])/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    // 4-char grid: two field letters (A-R), two digits
    if (preg_match('/(?<![A-Z0-9])([A-R]{2}[0-9]{2})(?![A-Z0-9])/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

/**
 * Convert a 4- or 6-character Maidenhead locator to lat/lon center point.
 */
function maidenhead_to_latlon($grid) {
    $g = strtoupper($grid);
    $lon = (ord($g[0]) - ord('A')) * 20 - 180;
    $lat = (ord($g[1]) - ord('A')) * 10 - 90;
    $lon += (ord($g[2]) - ord('0')) * 2;
    $lat += (ord($g[3]) - ord('0'));
    if (strlen($g) >= 6) {
        $lon += (ord($g[4]) - ord('A')) * (5.0 / 60) + (5.0 / 60 / 2);
        $lat += (ord($g[5]) - ord('A')) * (2.5 / 60) + (2.5 / 60 / 2);
    } else {
        $lon += 1.0;   // center of 2° cell
        $lat += 0.5;   // center of 1° cell
    }
    return ['lat' => round($lat, 6), 'lon' => round($lon, 6)];
}

// Get summit location
$summit = null;
if (!empty($contacts[0]['my_summit'])) {
    $summit_ref = $contacts[0]['my_summit'];
    
    $api_url = 'https://api2.sota.org.uk/api/summits/' . $summit_ref;
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'SOTA-Magic-Plugin/1.0'
        ]
    ]);
    
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response !== false) {
        $summit_data = json_decode($response, true);
        if ($summit_data && isset($summit_data['latitude']) && isset($summit_data['longitude'])) {
            $summit = [
                'lat' => floatval($summit_data['latitude']),
                'lon' => floatval($summit_data['longitude']),
                'name' => $summit_data['name'] ?? $summit_ref,
                'ref' => $summit_ref
            ];
        }
    }
}

// Get QRZ session if credentials available
$qrz_session = null;
if ($qrz_user && $qrz_pass) {
    $login_url = "https://xmldata.qrz.com/xml/current/?username=" . urlencode($qrz_user) . "&password=" . urlencode($qrz_pass);
    $login_response = @file_get_contents($login_url);
    if ($login_response) {
        preg_match('/<Key>([^<]+)<\/Key>/', $login_response, $matches);
        if (!empty($matches[1])) {
            $qrz_session = $matches[1];
        }
    }
}

// Function to get band color from frequency
function get_band_color($frequency) {
    $freq = floatval($frequency);
    
    if ($freq >= 1.8 && $freq < 2.0) return '#8B4513';      // 160m - Brown
    if ($freq >= 3.5 && $freq < 4.0) return '#FFA500';      // 80m - Orange
    if ($freq >= 7.0 && $freq < 7.3) return '#FFD700';      // 40m - Gold
    if ($freq >= 10.1 && $freq < 10.15) return '#FFFF00';   // 30m - Yellow
    if ($freq >= 14.0 && $freq < 14.35) return '#00FF00';   // 20m - Green
    if ($freq >= 18.068 && $freq < 18.168) return '#00CED1'; // 17m - Turquoise
    if ($freq >= 21.0 && $freq < 21.45) return '#0000FF';   // 15m - Blue
    if ($freq >= 24.89 && $freq < 24.99) return '#4B0082';  // 12m - Indigo
    if ($freq >= 28.0 && $freq < 29.7) return '#8B00FF';    // 10m - Violet
    if ($freq >= 50.0 && $freq < 54.0) return '#FF1493';    // 6m - Deep Pink
    if ($freq >= 144.0 && $freq < 148.0) return '#FF69B4';  // 2m - Hot Pink
    if ($freq >= 222.0 && $freq < 225.0) return '#FFB6C1';  // 1.25m - Light Pink
    if ($freq >= 420.0 && $freq < 450.0) return '#FFC0CB';  // 70cm - Pink
    
    return '#999999'; // Default gray for unknown
}

// Get locations for all contacts
$contact_locations = [];
foreach ($contacts as $contact) {
    $callsign      = $contact['callsign'];
    $comments      = $contact['comments'];
    $is_s2s        = !empty($contact['their_summit']);
    $band_color    = get_band_color($contact['frequency']);

    // --- Priority 1: grid square in comments (portable/field operation) ---
    $grid = extract_grid_square($comments);
    if ($grid) {
        $coords = maidenhead_to_latlon($grid);
        $contact_locations[] = [
            'callsign'        => $callsign,
            'lat'             => $coords['lat'],
            'lon'             => $coords['lon'],
            'summit'          => $contact['their_summit'],
            'mode'            => $contact['mode'],
            'frequency'       => $contact['frequency'],
            'is_s2s'          => $is_s2s,
            'color'           => $band_color,
            'location_source' => 'grid',
            'grid'            => $grid
        ];
        continue;
    }

    // --- Priority 2: S2S — use SOTA API summit coordinates ---
    if ($is_s2s) {
        $their_api_url  = 'https://api2.sota.org.uk/api/summits/' . $contact['their_summit'];
        $their_response = @file_get_contents($their_api_url, false, $context);
        if ($their_response !== false) {
            $their_data = json_decode($their_response, true);
            if ($their_data && isset($their_data['latitude']) && isset($their_data['longitude'])) {
                $contact_locations[] = [
                    'callsign'        => $callsign,
                    'lat'             => floatval($their_data['latitude']),
                    'lon'             => floatval($their_data['longitude']),
                    'summit'          => $contact['their_summit'],
                    'mode'            => $contact['mode'],
                    'frequency'       => $contact['frequency'],
                    'is_s2s'          => true,
                    'color'           => $band_color,
                    'location_source' => 'sota'
                ];
            }
        }
        continue;
    }

    // --- Priority 3: QRZ.com home address lookup ---
    if ($qrz_session) {
        $qrz_url      = "https://xmldata.qrz.com/xml/current/?s=" . urlencode($qrz_session) . "&callsign=" . urlencode($callsign);
        $qrz_response = @file_get_contents($qrz_url);
        if ($qrz_response) {
            preg_match('/<lat>([^<]+)<\/lat>/', $qrz_response, $lat_match);
            preg_match('/<lon>([^<]+)<\/lon>/', $qrz_response, $lon_match);
            if (!empty($lat_match[1]) && !empty($lon_match[1])) {
                $contact_locations[] = [
                    'callsign'        => $callsign,
                    'lat'             => floatval($lat_match[1]),
                    'lon'             => floatval($lon_match[1]),
                    'summit'          => '',
                    'mode'            => $contact['mode'],
                    'frequency'       => $contact['frequency'],
                    'is_s2s'          => false,
                    'color'           => $band_color,
                    'location_source' => 'qrz'
                ];
            }
        }
        usleep(500000); // Rate limit: 0.5 s between QRZ calls
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SOTA Contact Map v1.3</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
        
        /* Loading overlay */
        #loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #f5f5f5;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        
        #loading-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #e0e0e0;
            border-top: 5px solid #0073aa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            margin-top: 20px;
            font-size: 16px;
            color: #666;
        }
        
        .loading-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
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
        // Initialize map with minimal CartoDB Positron style
        <?php if ($summit): ?>
        var map = L.map('map').setView([<?php echo $summit['lat']; ?>, <?php echo $summit['lon']; ?>], 6);
        <?php else: ?>
        var map = L.map('map').setView([37.0, -95.0], 4);
        <?php endif; ?>
        
        // Use CartoDB Positron - a clean, minimal basemap
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap contributors © CARTO',
            maxZoom: 19
        }).addTo(map);
        
        // Mountain icon for summit (using Unicode mountain emoji as SVG text)
        var summitIcon = L.divIcon({
            html: '<div style="font-size: 32px; text-align: center; line-height: 32px;">🏔️</div>',
            className: 'summit-icon',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });
        
        // Add summit marker
        <?php if ($summit): ?>
        var summitMarker = L.marker([<?php echo $summit['lat']; ?>, <?php echo $summit['lon']; ?>], {icon: summitIcon}).addTo(map);
        summitMarker.bindPopup('<div class="popup-content"><strong>🏔️ <?php echo esc_js($summit['name']); ?></strong><?php echo esc_js($summit['ref']); ?><br><em>Your Activation</em></div>');
        <?php endif; ?>
        
        // Add contact markers and lines
        <?php foreach ($contact_locations as $loc): ?>
        
        // Create colored circle marker for contact
        var contactCircle = L.circleMarker([<?php echo $loc['lat']; ?>, <?php echo $loc['lon']; ?>], {
            radius: <?php echo $loc['is_s2s'] ? '8' : '6'; ?>,
            fillColor: '<?php echo $loc['color']; ?>',
            color: '<?php echo $loc['is_s2s'] ? '#000' : '#fff'; ?>',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.9
        }).addTo(map);
        
        contactCircle.bindPopup('<div class="popup-content"><strong><?php echo esc_js($loc['callsign']); ?></strong><?php if ($loc['summit']): ?><br>📡 <?php echo esc_js($loc['summit']); ?> <span style="background:#ff9800;color:white;padding:2px 6px;border-radius:3px;font-size:11px;">S2S</span><?php endif; ?><br><span class="band-indicator" style="background-color:<?php echo $loc['color']; ?>;"></span><?php echo esc_js($loc['frequency']); ?> MHz - <?php echo esc_js($loc['mode']); ?><?php if ($loc['location_source'] === 'grid'): ?><br><span style="font-size:11px;color:#0073aa;">📍 Grid: <?php echo esc_js($loc['grid']); ?></span><?php elseif ($loc['location_source'] === 'qrz'): ?><br><span style="font-size:11px;color:#888;">🏠 QRZ home address</span><?php endif; ?></div>');
        
        // Draw line from summit to contact
        <?php if ($summit): ?>
        L.polyline([
            [<?php echo $summit['lat']; ?>, <?php echo $summit['lon']; ?>],
            [<?php echo $loc['lat']; ?>, <?php echo $loc['lon']; ?>]
        ], {
            color: '<?php echo $loc['color']; ?>',
            weight: 3,
            opacity: 0.7
        }).addTo(map);
        <?php endif; ?>
        <?php endforeach; ?>
        
        // Fit bounds to show all markers
        <?php if ($summit && count($contact_locations) > 0): ?>
        var bounds = L.latLngBounds([
            [<?php echo $summit['lat']; ?>, <?php echo $summit['lon']; ?>]
            <?php foreach ($contact_locations as $loc): ?>
            ,[<?php echo $loc['lat']; ?>, <?php echo $loc['lon']; ?>]
            <?php endforeach; ?>
        ]);
        map.fitBounds(bounds, {padding: [50, 50]});
        <?php endif; ?>
        
        // Hide loading overlay when map is ready
        map.whenReady(function() {
            setTimeout(function() {
                document.getElementById('loading-overlay').classList.add('hidden');
            }, 500);
        });
    </script>
</body>
</html>