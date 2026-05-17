<?php
/**
 * debug-map.php — GPX track + Activation Zone render debugger.
 * Admin-only. Deploy to test site plugin folder.
 * Access: /wp-content/plugins/activator-toolkit-for-sota/debug-map.php?gpx=URL&csv=URL
 */

$wp_root = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
require( $wp_root . '/wp-load.php' );

if ( ( $_GET['pw'] ?? '' ) !== 'sota' ) {
    wp_die( 'Add ?pw=sota to the URL.' );
}

$gpx_url = isset( $_GET['gpx'] ) ? esc_url_raw( wp_unslash( $_GET['gpx'] ) ) : 'https://www.christopherreddick.com/test/wp-content/uploads/2026/05/2026-04-14-094134.gpx';
$csv_url = isset( $_GET['csv'] ) ? esc_url_raw( wp_unslash( $_GET['csv'] ) ) : 'https://www.christopherreddick.com/test/wp-content/uploads/2026/05/KI6CR_09_11_2025_2366754.csv';

$debug        = [];
$track_points = [];
$gpx_stats    = null;
$az_data      = null;
$summit_lat   = null;
$summit_lon   = null;

if ( $gpx_url ) {

    // ── Track points (the lightweight path) ──────────────────────────────────
    $track_points                  = sota_get_gpx_track_points( $gpx_url );
    $debug['1_track_points_count'] = count( $track_points );
    $debug['1_track_first_point']  = $track_points[0] ?? null;
    $debug['1_track_last_point']   = ! empty( $track_points ) ? $track_points[ count( $track_points ) - 1 ] : null;

    // ── Full analysis (needed for activation zone) ────────────────────────────
    $gpx_stats = sota_magic_analyze_gpx_track( $gpx_url, $csv_url ?: null );

    if ( $gpx_stats ) {
        $debug['2_using_api']         = $gpx_stats['using_api'];
        $debug['2_summit_lat']        = $gpx_stats['summit_lat'];
        $debug['2_summit_lon']        = $gpx_stats['summit_lon'];
        $debug['2_az_radius_setting'] = $gpx_stats['activation_zone_radius'];

        $poly                          = $gpx_stats['activation_zone_polygon'] ?? null;
        $debug['3_az_polygon_type']    = gettype( $poly );
        $debug['3_az_polygon_is_null'] = is_null( $poly );

        if ( is_array( $poly ) ) {
            $debug['3_az_polygon_L0_count'] = count( $poly );
            $el0                            = $poly[0] ?? null;
            $debug['3_az_polygon_L0_type']  = gettype( $el0 );

            if ( is_array( $el0 ) ) {
                $debug['3_az_polygon_L1_count'] = count( $el0 );
                $el1                            = $el0[0] ?? null;
                $debug['3_az_polygon_L1_type']  = gettype( $el1 );
                $is_double                      = is_array( $el1 );
                $debug['3_az_polygon_double_nested'] = $is_double;

                if ( $is_double ) {
                    // Structure: [[[lon,lat], [lon,lat], ...]] — [0] gives coordinate ring
                    $sample                             = array_slice( $el0, 0, 3 );
                    $debug['3_az_sample_raw_coords']    = $sample; // should be [[lon,lat], ...]
                    $debug['3_az_sample_leaflet_coords'] = array_map( fn( $c ) => [ $c[1], $c[0] ], $sample );
                } else {
                    // Structure: [[lon,lat], [lon,lat], ...] — single level
                    // Render code does [0] which gives [lon, lat], then foreach iterates two floats — BUG
                    $debug['3_az_polygon_L0_value'] = $el0;
                    $debug['WARNING_AZ_SINGLE_LEVEL'] = 'activation_zone_polygon is single-level. '
                        . 'Render code does [0] expecting a ring but gets [lon,lat] — foreach will iterate floats, producing empty coords!';
                }
            }
        }

        // ── Build az_data exactly as the render function does ──────────────────
        if ( $gpx_stats['using_api'] && ! empty( $poly ) ) {
            $leaflet_coords = [];
            foreach ( $poly[0] as $coord ) {
                $leaflet_coords[] = [ $coord[1], $coord[0] ]; // [lon,lat] → [lat,lon]
            }
            $az_data = [ 'mode' => 'polygon', 'coordinates' => $leaflet_coords ];

            $debug['4_az_data_mode']        = 'polygon';
            $debug['4_az_data_coord_count'] = count( $leaflet_coords );
            $debug['4_az_data_sample']      = array_slice( $leaflet_coords, 0, 3 );

            // Sanity-check first coordinate
            if ( ! empty( $leaflet_coords ) ) {
                $c0                              = $leaflet_coords[0];
                $lat_ok                          = ( isset( $c0[0] ) && is_numeric( $c0[0] ) && $c0[0] >= -90 && $c0[0] <= 90 );
                $lon_ok                          = ( isset( $c0[1] ) && is_numeric( $c0[1] ) && $c0[1] >= -180 && $c0[1] <= 180 );
                $debug['4_az_first_lat_valid']   = $lat_ok ? 'YES (' . $c0[0] . ')' : 'NO — value=' . ( $c0[0] ?? 'missing' );
                $debug['4_az_first_lon_valid']   = $lon_ok ? 'YES (' . $c0[1] . ')' : 'NO — value=' . ( $c0[1] ?? 'missing' );
                if ( ! $lat_ok || ! $lon_ok ) {
                    $debug['WARNING_AZ_COORDS'] = 'First coordinate fails lat/lon range check — polygon likely has swapped or corrupt coords.';
                }
            }
        } else {
            $az_data                  = [ 'mode' => 'circle', 'radius' => (float) ( $gpx_stats['activation_zone_radius'] ) ];
            $debug['4_az_data_mode']   = 'circle';
            $debug['4_az_data_radius'] = $az_data['radius'];
            if ( ! $gpx_stats['using_api'] ) {
                $debug['4_az_circle_reason'] = 'using_api is false — API disabled or no CSV summit ref supplied';
            } elseif ( empty( $poly ) ) {
                $debug['4_az_circle_reason'] = 'using_api is true but activation_zone_polygon is empty — API call returned nothing';
            }
        }

        $summit_lat = (float) $gpx_stats['summit_lat'];
        $summit_lon = (float) $gpx_stats['summit_lon'];

    } else {
        $debug['2_gpx_stats'] = 'NULL — analyze_gpx_track returned nothing. Possible: no CSV URL, API timeout, invalid GPX.';

        // Fallback: derive summit from highest track point
        if ( ! empty( $track_points ) ) {
            $highest = null;
            foreach ( $track_points as $tp ) {
                if ( $highest === null || $tp[2] > $highest[2] ) {
                    $highest = $tp;
                }
            }
            if ( $highest ) {
                $summit_lat = $highest[0];
                $summit_lon = $highest[1];
            }
        }
    }

    $debug['5_summit_lat_final'] = $summit_lat;
    $debug['5_summit_lon_final'] = $summit_lon;
}

$map_data = [
    'trackPoints'    => $track_points,
    'summitLat'      => $summit_lat,
    'summitLon'      => $summit_lon,
    'activationZone' => $az_data,
    'units'          => get_option( 'sota_unit_system', 'imperial' ),
    'popupText'      => 'Summit',
    'defaultLayer'   => get_option( 'sota_default_map_layer', 'topo' ),
];

$plugin_url = plugins_url( '', __FILE__ );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SOTA Debug Map</title>
<link rel="stylesheet" href="<?php echo esc_url( $plugin_url . '/lib/leaflet.css' ); ?>">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font: 13px/1.5 monospace; margin: 0; padding: 16px; background: #1a1a1a; color: #d0d0d0; }
h1 { font-size: 15px; color: #f90; margin: 0 0 14px; }
form div { margin-bottom: 8px; }
form label { color: #888; width: 130px; display: inline-block; }
form input[type=text] { width: 500px; padding: 4px 8px; font: 12px monospace; background: #252525; color: #e0e0e0; border: 1px solid #555; border-radius: 3px; }
form button { margin-top: 6px; padding: 5px 18px; background: #e67e00; color: #fff; border: none; cursor: pointer; font: 13px monospace; border-radius: 3px; }

.section { margin-bottom: 20px; }
.stitle { font-size: 12px; font-weight: bold; color: #f90; border-bottom: 1px solid #333; padding-bottom: 4px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: .05em; }

table.dbg { border-collapse: collapse; width: 100%; font-size: 12px; }
table.dbg td { padding: 3px 8px; border: 1px solid #2a2a2a; vertical-align: top; }
table.dbg tr:nth-child(even) td { background: #1f1f1f; }
table.dbg td.k { color: #7baacc; white-space: nowrap; width: 260px; }
.ok   { color: #5c5; }
.warn { color: #fa0; font-weight: bold; }
.err  { color: #f55; font-weight: bold; }
pre { margin: 0; white-space: pre-wrap; font: 11px monospace; color: #adc; }

#map-wrap { position: relative; height: 440px; background: #111; border: 1px solid #333; }
#sota-debug-map { height: 100%; }

#dbg-overlay {
    position: absolute; bottom: 8px; left: 8px; z-index: 1000;
    background: rgba(10,10,10,.88); color: #d0d0d0;
    font: 11px/1.7 monospace; padding: 8px 12px;
    border-radius: 4px; max-width: 360px; max-height: 300px; overflow-y: auto;
    pointer-events: none; border: 1px solid #444;
}
#dbg-overlay .dok   { color: #5c5; }
#dbg-overlay .derr  { color: #f55; font-weight: bold; }
#dbg-overlay .dwarn { color: #fa0; }
#dbg-overlay .dlog  { color: #aaa; }

#chart-wrap { height: 110px; background: #111; border: 1px solid #333; border-top: none; }
#chart-wrap canvas { width: 100%; height: 100%; }

#js-log { background: #111; border: 1px solid #333; padding: 8px; font: 11px/1.6 monospace;
    max-height: 200px; overflow-y: auto; }
#js-log .cl { color: #999; }
#js-log .cw { color: #fa0; }
#js-log .ce { color: #f55; }
#js-log .co { color: #5c5; }
</style>
</head>
<body>

<h1>Activator Toolkit — GPX / Activation Zone Debug</h1>

<div class="section">
<form method="get">
    <input type="hidden" name="pw" value="sota">
    <div><label>GPX URL:</label> <input type="text" name="gpx" value="<?php echo esc_attr( $gpx_url ); ?>" placeholder="https://your-site.com/…/track.gpx"></div>
    <div><label>CSV URL (optional):</label> <input type="text" name="csv" value="<?php echo esc_attr( $csv_url ); ?>" placeholder="https://your-site.com/…/contacts.csv"></div>
    <button type="submit">Run Debug</button>
    <?php if ( $gpx_url ): ?>
        &nbsp; <a href="?" style="color:#888;font-size:11px;">Clear</a>
    <?php endif; ?>
</form>
</div>

<?php if ( $gpx_url ): ?>

<div class="section">
    <div class="stitle">PHP Analysis</div>
    <table class="dbg">
    <?php foreach ( $debug as $key => $val ): ?>
        <?php
        $is_warn = ( false !== strpos( $key, 'WARNING' ) );
        if ( is_bool( $val ) ) {
            $display = $val ? '<span class="ok">true</span>' : '<span class="warn">false</span>';
        } elseif ( is_null( $val ) ) {
            $display = '<span class="warn">null</span>';
        } elseif ( is_array( $val ) ) {
            $display = '<pre>' . esc_html( json_encode( $val, JSON_PRETTY_PRINT ) ) . '</pre>';
        } else {
            $str     = (string) $val;
            $display = $is_warn ? '<span class="warn">' . esc_html( $str ) . '</span>' : esc_html( $str );
        }
        ?>
        <tr>
            <td class="k"><?php echo esc_html( $key ); ?></td>
            <td><?php echo $display; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped above ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="section">
    <div class="stitle">map_data JSON (trackPoints truncated)</div>
    <pre style="background:#111;padding:8px;border:1px solid #333;max-height:160px;overflow:auto;"><?php
    $json = wp_json_encode( $map_data, JSON_PRETTY_PRINT );
    // Truncate trackPoints for readability
    $json = preg_replace(
        '/("trackPoints"\s*:\s*\[)[\s\S]*?(\])/m',
        '$1 /* ' . count( $track_points ) . ' points — omitted */ $2',
        $json,
        1
    );
    echo esc_html( $json );
    ?></pre>
</div>

<div class="section">
    <div class="stitle">Live Map</div>
    <div id="map-wrap">
        <div id="sota-debug-map"></div>
        <div id="dbg-overlay"><strong style="color:#f90">JS Debug</strong><br></div>
    </div>
    <div id="chart-wrap"><canvas id="sota-debug-map-chart"></canvas></div>
</div>

<div class="section">
    <div class="stitle">JS Console</div>
    <div id="js-log"></div>
</div>

<div class="section">
    <div class="stitle">Debug Report — copy and paste to Claude</div>
    <button id="copy-btn" onclick="buildReport()" style="padding:5px 18px;background:#e67e00;color:#fff;border:none;cursor:pointer;font:13px monospace;border-radius:3px;margin-bottom:8px;">Build &amp; Copy Report</button>
    <textarea id="report-box" readonly style="width:100%;height:200px;background:#111;color:#d0d0d0;border:1px solid #444;font:11px/1.5 monospace;padding:8px;resize:vertical;" placeholder="Click 'Build &amp; Copy Report' after the map loads…"></textarea>
</div>

<?php endif; // $gpx_url ?>

<script src="<?php echo esc_url( $plugin_url . '/lib/leaflet.js' ); ?>"></script>
<script src="<?php echo esc_url( $plugin_url . '/lib/chart.umd.min.js' ); ?>"></script>
<script>
// ── Console intercept ─────────────────────────────────────────────────────────
(function() {
    var logEl = document.getElementById('js-log');
    function appendLog(type, args) {
        if (!logEl) return;
        var cls = { error: 'ce', warn: 'cw', ok: 'co' }[type] || 'cl';
        var line = document.createElement('div');
        line.className = cls;
        line.textContent = '[' + type.toUpperCase() + '] ' +
            Array.prototype.slice.call(args).map(function(a) {
                return (typeof a === 'object') ? JSON.stringify(a) : String(a);
            }).join(' ');
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }
    var orig = { log: console.log, warn: console.warn, error: console.error };
    console.log   = function() { orig.log.apply(console, arguments);   appendLog('log',   arguments); };
    console.warn  = function() { orig.warn.apply(console, arguments);  appendLog('warn',  arguments); };
    console.error = function() { orig.error.apply(console, arguments); appendLog('error', arguments); };

    window.dlog = function(msg, cls) {
        var el = document.getElementById('dbg-overlay');
        if (!el) return;
        var d = document.createElement('div');
        d.className = cls || 'dlog';
        d.textContent = msg;
        el.appendChild(d);
        el.scrollTop = el.scrollHeight;
    };
})();
</script>

<?php if ( $gpx_url ): ?>
<script>
(function() {
    var mapId = 'sota-debug-map';
    var data  = <?php echo wp_json_encode( $map_data ); ?>;

    // ── Pre-flight checks ─────────────────────────────────────────────────────
    dlog('=== PRE-FLIGHT ===', 'dlog');

    var pts = data.trackPoints;
    dlog('trackPoints: ' + (pts ? pts.length : 'null'),
         (pts && pts.length >= 2) ? 'dok' : 'derr');

    dlog('summitLat: ' + data.summitLat + '  summitLon: ' + data.summitLon,
         (data.summitLat !== null && data.summitLon !== null) ? 'dok' : 'dwarn');

    if (data.activationZone) {
        dlog('az.mode: ' + data.activationZone.mode, 'dok');
        if (data.activationZone.mode === 'polygon') {
            var n = data.activationZone.coordinates ? data.activationZone.coordinates.length : 0;
            dlog('az polygon coords: ' + n, n > 2 ? 'dok' : 'derr');
            if (n > 0) {
                var c0 = data.activationZone.coordinates[0];
                var latOk = (typeof c0[0] === 'number' && c0[0] >= -90  && c0[0] <= 90);
                var lonOk = (typeof c0[1] === 'number' && c0[1] >= -180 && c0[1] <= 180);
                dlog('az first coord [lat,lon]: [' + (c0[0]+'').slice(0,9) + ', ' + (c0[1]+'').slice(0,9) + ']',
                     (latOk && lonOk) ? 'dok' : 'derr');
                if (!latOk || !lonOk) dlog('BAD COORDS — lat/lon out of range or swapped!', 'derr');
            }
        } else if (data.activationZone.mode === 'circle') {
            dlog('az circle radius: ' + data.activationZone.radius + 'm',
                 data.activationZone.radius > 0 ? 'dok' : 'derr');
        }
    } else {
        dlog('activationZone: null — no AZ will render', 'derr');
    }

    // ── Map container size ────────────────────────────────────────────────────
    var mapEl = document.getElementById(mapId);
    var rect  = mapEl ? mapEl.getBoundingClientRect() : null;
    dlog('container: ' + (rect ? Math.round(rect.width) + 'x' + Math.round(rect.height) + 'px' : 'NOT FOUND'),
         (rect && rect.height > 0) ? 'dok' : 'derr');
    if (rect && rect.height === 0) {
        dlog('ZERO HEIGHT — Leaflet will not render track tiles correctly!', 'derr');
    }

    if (typeof L === 'undefined') { dlog('Leaflet NOT LOADED!', 'derr'); return; }
    if (typeof Chart === 'undefined') { dlog('Chart.js NOT LOADED!', 'derr'); return; }
    dlog('Leaflet ' + L.version + ' OK', 'dok');

    if (!pts || pts.length < 2) {
        dlog('Aborting — need ≥2 track points', 'derr');
        return;
    }

    // ── Leaflet map ───────────────────────────────────────────────────────────
    dlog('=== MAP INIT ===', 'dlog');
    var map = L.map(mapId, { zoomControl: true });

    var layers = {
        topo:  L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',               { maxZoom: 17 }),
        osm:   L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',             { maxZoom: 19 }),
        carto: L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 })
    };
    (layers[data.defaultLayer] || layers.topo).addTo(map);
    L.control.layers({ Topo: layers.topo, OSM: layers.osm, Carto: layers.carto }, null).addTo(map);

    // GPX polyline
    var latLngs  = pts.map(function(p) { return [p[0], p[1]]; });
    var polyline = L.polyline(latLngs, { color: '#e67e00', weight: 3, opacity: 0.85 }).addTo(map);
    dlog('polyline added (' + latLngs.length + ' pts)', 'dok');

    // Activation zone
    var az      = data.activationZone;
    var azLayer = null;

    if (az && data.summitLat !== null && data.summitLon !== null) {
        if (az.mode === 'polygon' && az.coordinates && az.coordinates.length > 2) {
            try {
                azLayer = L.polygon(az.coordinates, {
                    color: '#CC2200', fillColor: '#CC2200',
                    fillOpacity: 0.18, weight: 2, dashArray: '5,4'
                }).addTo(map).bindPopup('SOTA Activation Zone');
                dlog('AZ polygon added to map ✓', 'dok');
            } catch (e) {
                dlog('AZ polygon L.polygon() threw: ' + e.message, 'derr');
            }
        } else if (az.mode === 'circle') {
            azLayer = L.circle([data.summitLat, data.summitLon], {
                color: 'rgb(255,165,0)', fillColor: 'rgb(255,165,0)',
                fillOpacity: 0.15, weight: 2, dashArray: '10,5',
                radius: az.radius
            }).addTo(map).bindPopup('Activation Zone (radius)');
            dlog('AZ circle added (' + az.radius + 'm) ✓', 'dok');
        } else {
            dlog('AZ skipped — polygon has ' + (az.coordinates ? az.coordinates.length : 0) + ' coords', 'derr');
        }
    } else {
        if (!az)                       dlog('AZ skipped — az is null', 'derr');
        else if (data.summitLat===null) dlog('AZ skipped — summitLat is null', 'derr');
        else if (data.summitLon===null) dlog('AZ skipped — summitLon is null', 'derr');
    }

    // ── Zoom to Activation Zone button (matches plugin behavior) ─────────────
    var azBtn = document.createElement('button');
    azBtn.textContent = '🏔️ Zoom to Activation Zone';
    azBtn.disabled    = !azLayer;
    azBtn.style.cssText = [
        'display:inline-block',
        'margin-bottom:6px',
        'padding:0.3rem 0.9rem',
        'border-radius:20px',
        'border:2px solid #CC2200',
        'background:' + (azLayer ? '#CC2200' : '#fff'),
        'color:' + (azLayer ? '#fff' : '#CC2200'),
        'font-weight:700',
        'font-size:0.78rem',
        'cursor:' + (azLayer ? 'pointer' : 'default'),
        'opacity:' + (azLayer ? '1' : '0.45'),
        'transition:all 0.2s'
    ].join(';');
    azBtn.addEventListener('click', function() {
        if (azLayer) map.fitBounds(azLayer.getBounds(), { padding: [40, 40], maxZoom: 15 });
    });
    // Insert before #map-wrap (the outer container), same pattern as plugin
    var mapWrap = document.getElementById('map-wrap');
    mapWrap.parentNode.insertBefore(azBtn, mapWrap);
    dlog('AZ button added (enabled: ' + !!azLayer + ')', azLayer ? 'dok' : 'dwarn');

    // Summit marker
    if (data.summitLat !== null && data.summitLon !== null) {
        L.marker([data.summitLat, data.summitLon], {
            icon: L.divIcon({ html: '<div style="font-size:24px;line-height:1;">🏔️</div>',
                              className: 'sota-summit-marker', iconSize: [30,30], iconAnchor: [15,15] }),
            zIndexOffset: 1000
        }).addTo(map).bindPopup('Summit');
        dlog('Summit marker at [' + (+data.summitLat).toFixed(5) + ', ' + (+data.summitLon).toFixed(5) + ']', 'dok');
    }

    // Initial fitBounds
    var trackBounds = polyline.getBounds();
    map.fitBounds(trackBounds, { padding: [24, 24] });
    dlog('fitBounds called', 'dlog');

    // ── Size-change detection (main cause of "disappearing GPX track") ────────
    dlog('=== SIZE WATCH ===', 'dlog');
    var sizeAtInit = map.getSize();
    dlog('map size at init: ' + sizeAtInit.x + 'x' + sizeAtInit.y,
         sizeAtInit.y > 0 ? 'dok' : 'derr');

    setTimeout(function() {
        var sizeBefore = map.getSize();
        map.invalidateSize({ animate: false });
        var sizeAfter  = map.getSize();
        var changed    = (sizeBefore.x !== sizeAfter.x || sizeBefore.y !== sizeAfter.y);
        dlog('invalidateSize (200ms): ' + sizeBefore.x + 'x' + sizeBefore.y +
             ' → ' + sizeAfter.x + 'x' + sizeAfter.y,
             changed ? 'dwarn' : 'dlog');
        if (changed) {
            dlog('SIZE CHANGED after invalidateSize — this is the disappearing-track bug!', 'dwarn');
            map.fitBounds(trackBounds, { padding: [24, 24] });
            dlog('Re-called fitBounds to compensate', 'dok');
        }
    }, 200);

    // Also watch for container resizes
    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(function(entries) {
            for (var e of entries) {
                var h = Math.round(e.contentRect.height);
                var w = Math.round(e.contentRect.width);
                dlog('ResizeObserver: container now ' + w + 'x' + h, h > 0 ? 'dlog' : 'derr');
                if (h === 0) dlog('Container collapsed to 0 height!', 'derr');
            }
        }).observe(mapEl);
        dlog('ResizeObserver watching container', 'dlog');
    }

    // ── Chart.js elevation chart ──────────────────────────────────────────────
    dlog('=== CHART ===', 'dlog');
    var isImperial = (data.units === 'imperial');
    var distUnit   = isImperial ? 'mi' : 'km';
    var eleUnit    = isImperial ? 'ft' : 'm';
    var distFactor = isImperial ? 0.000621371 : 0.001;
    var eleFactor  = isImperial ? 3.28084 : 1;

    var R = 6371000;
    var cumDist = [0];
    for (var i = 1; i < pts.length; i++) {
        var dLat = (pts[i][0]-pts[i-1][0]) * Math.PI/180;
        var dLon = (pts[i][1]-pts[i-1][1]) * Math.PI/180;
        var a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                Math.cos(pts[i-1][0]*Math.PI/180)*Math.cos(pts[i][0]*Math.PI/180)*
                Math.sin(dLon/2)*Math.sin(dLon/2);
        cumDist.push(cumDist[i-1] + R*2*Math.atan2(Math.sqrt(a), Math.sqrt(1-a))*distFactor);
    }

    var chartX = cumDist.map(function(v) { return Math.round(v*100)/100; });
    var chartY = pts.map(function(p) { return Math.round(p[2]*eleFactor*10)/10; });

    var canvas = document.getElementById(mapId + '-chart');
    if (canvas) {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: chartX,
                datasets: [{ data: chartY, borderColor: '#e67e00',
                    backgroundColor: 'rgba(230,126,0,0.10)', borderWidth: 1.5,
                    pointRadius: 0, fill: true, tension: 0.3 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { type: 'linear', min: 0, max: chartX[chartX.length-1],
                         title: { display: true, text: 'Distance (' + distUnit + ')' },
                         ticks: { maxTicksLimit: 8, callback: function(v) { return (+v).toFixed(1); } },
                         grid: { display: false } },
                    y: { title: { display: true, text: 'Elevation (' + eleUnit + ')' },
                         ticks: { maxTicksLimit: 5, callback: function(v) { return Math.round(v); } } }
                }
            }
        });
        dlog('Chart rendered ✓', 'dok');
    } else {
        dlog('Chart canvas not found!', 'derr');
    }

})();
</script>
<?php endif; ?>

<script>
function buildReport() {
    var lines = [];

    lines.push('=== PHP ANALYSIS ===');
    var rows = document.querySelectorAll('table.dbg tr');
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length >= 2) {
            var key = cells[0].textContent.trim();
            var val = cells[1].textContent.trim().replace(/\s+/g, ' ');
            lines.push(key + ': ' + val);
        }
    });

    lines.push('');
    lines.push('=== MAP DATA JSON ===');
    var pre = document.querySelector('pre');
    if (pre) lines.push(pre.textContent.trim());

    lines.push('');
    lines.push('=== JS DEBUG LOG ===');
    var overlay = document.getElementById('dbg-overlay');
    if (overlay) {
        overlay.querySelectorAll('div').forEach(function(d) {
            lines.push(d.textContent.trim());
        });
    }

    lines.push('');
    lines.push('=== JS CONSOLE ===');
    var jslog = document.getElementById('js-log');
    if (jslog) {
        jslog.querySelectorAll('div').forEach(function(d) {
            lines.push(d.textContent.trim());
        });
    }

    var report = lines.join('\n');
    var box = document.getElementById('report-box');
    box.value = report;
    box.select();

    if (navigator.clipboard) {
        navigator.clipboard.writeText(report).then(function() {
            document.getElementById('copy-btn').textContent = '✓ Copied!';
            setTimeout(function() { document.getElementById('copy-btn').textContent = 'Build & Copy Report'; }, 2000);
        });
    }
}
</script>

</body>
</html>
