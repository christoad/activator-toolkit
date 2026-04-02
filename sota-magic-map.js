/* sota-magic-map.js — Self-contained GPX map + elevation chart for SOTA Magic
 * No dependency on WP-GPX Maps plugin.
 *
 * Called once per block (from wp_add_inline_script in footer):
 *   sotaMagicInitMap(mapId, data)
 *
 * data = {
 *   trackPoints:    [[lat, lon, ele], ...],
 *   summitLat:      <float|null>,
 *   summitLon:      <float|null>,
 *   activationZone: { mode: 'polygon', coordinates: [[lat,lon],...] }
 *                 | { mode: 'circle',  radius: <meters> }
 *                 | null,
 *   units:          'metric' | 'imperial',
 *   popupText:      <string>
 * }
 */

window.sotaMagicInitMap = function (mapId, data) {

    // ── Guards ────────────────────────────────────────────────────────────────
    var mapEl = document.getElementById(mapId);
    if (!mapEl) {
        console.error('SOTA Magic: map container not found:', mapId);
        return;
    }
    if (typeof L === 'undefined' || typeof Chart === 'undefined') {
        console.error('SOTA Magic: Leaflet or Chart.js not loaded for', mapId);
        return;
    }
    if (!data.trackPoints || data.trackPoints.length < 2) {
        mapEl.innerHTML = '<p style="padding:20px;color:#888;">No track data available.</p>';
        return;
    }

    // ── Haversine helper (returns metres) ─────────────────────────────────────
    function haversineMeters(lat1, lon1, lat2, lon2) {
        var R    = 6371000;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLon = (lon2 - lon1) * Math.PI / 180;
        var a    = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
                 * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ── Unit helpers ──────────────────────────────────────────────────────────
    var isImperial = (data.units === 'imperial');
    var distUnit   = isImperial ? 'mi'  : 'km';
    var eleUnit    = isImperial ? 'ft'  : 'm';
    var distFactor = isImperial ? 0.000621371 : 0.001; // metres → display unit
    var eleFactor  = isImperial ? 3.28084      : 1;    // metres → display unit

    // ── Cumulative distance array (display units) ─────────────────────────────
    var pts     = data.trackPoints;
    var cumDist = [0];
    for (var i = 1; i < pts.length; i++) {
        cumDist.push(
            cumDist[i - 1] + haversineMeters(pts[i-1][0], pts[i-1][1], pts[i][0], pts[i][1]) * distFactor
        );
    }

    var chartX = cumDist.map(function (v) { return Math.round(v * 100) / 100; });
    var chartY = pts.map(function (pt) {
        return Math.round(pt[2] * eleFactor * 10) / 10;
    });

    // ── Leaflet map ───────────────────────────────────────────────────────────
    var map = L.map(mapId, { zoomControl: true });

    var osmLayer = L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors', maxZoom: 19 }
    );
    var topoLayer = L.tileLayer(
        'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
        { attribution: 'Map data: &copy; OpenStreetMap contributors | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a>', maxZoom: 17 }
    );
    var cartoLayer = L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        { attribution: '&copy; OpenStreetMap contributors &copy; <a href="https://carto.com">CARTO</a>', maxZoom: 19 }
    );

    topoLayer.addTo(map); // Topo is most useful for hiking routes

    L.control.layers(
        { 'Topographic': topoLayer, 'OpenStreetMap': osmLayer, 'Minimal': cartoLayer },
        null,
        { position: 'topright', collapsed: true }
    ).addTo(map);

    // ── GPX track polyline ────────────────────────────────────────────────────
    var latLngs  = pts.map(function (pt) { return [pt[0], pt[1]]; });
    var polyline = L.polyline(latLngs, { color: '#e67e00', weight: 3, opacity: 0.85 }).addTo(map);
    map.fitBounds(polyline.getBounds(), { padding: [24, 24] });

    // ── Activation zone + zoom button ────────────────────────────────────────
    var az = data.activationZone;
    var azLayer = null;

    if (az && data.summitLat !== null && data.summitLon !== null) {
        if (az.mode === 'polygon' && az.coordinates && az.coordinates.length > 0) {
            azLayer = L.polygon(az.coordinates, {
                color: '#CC2200', fillColor: '#CC2200',
                fillOpacity: 0.18, weight: 2, dashArray: '5,4'
            }).addTo(map).bindPopup('<strong>SOTA Activation Zone</strong><br><small>Source: Activation.Zone by N6ARA</small>');
        } else if (az.mode === 'circle') {
            azLayer = L.circle([data.summitLat, data.summitLon], {
                color: 'rgb(255,165,0)', fillColor: 'rgb(255,165,0)',
                fillOpacity: 0.15, weight: 2, dashArray: '10,5',
                radius: az.radius
            }).addTo(map).bindPopup('Activation Zone (radius approx.)');
        }
    }

    // ── Zoom to Activation Zone button (above the map) ───────────────────────
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
    azBtn.addEventListener('click', function () {
        if (azLayer) map.fitBounds(azLayer.getBounds(), { padding: [40, 40] });
    });
    mapEl.parentNode.insertBefore(azBtn, mapEl);

    // ── Summit marker ─────────────────────────────────────────────────────────
    if (data.summitLat !== null && data.summitLon !== null) {
        L.marker([data.summitLat, data.summitLon], {
            icon: L.divIcon({
                html: '<div style="font-size:24px;line-height:1;text-align:center;">🏔️</div>',
                className: 'sota-summit-marker',
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            }),
            zIndexOffset: 1000
        }).addTo(map).bindPopup(data.popupText || 'Summit');
    }

    // ── Hover dot (moves on map when chart is moused over) ────────────────────
    var hoverDot = L.circleMarker([pts[0][0], pts[0][1]], {
        radius: 7, color: '#fff', fillColor: '#e67e00',
        fillOpacity: 1, weight: 2, interactive: false
    });
    // Added/removed dynamically — not on map yet

    // ── Chart.js elevation chart ──────────────────────────────────────────────
    var chartCanvas = document.getElementById(mapId + '-chart');
    if (!chartCanvas) return;

    new Chart(chartCanvas, {
        type: 'line',
        data: {
            labels: chartX,
            datasets: [{
                data: chartY,
                borderColor: '#e67e00',
                backgroundColor: 'rgba(230,126,0,0.10)',
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function (items) {
                            return chartX[items[0].dataIndex].toFixed(2) + ' ' + distUnit;
                        },
                        label: function (item) {
                            return Math.round(chartY[item.dataIndex]) + ' ' + eleUnit;
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    title: { display: true, text: 'Distance (' + distUnit + ')', font: { size: 11 } },
                    ticks: {
                        maxTicksLimit: 8,
                        callback: function (v) { return (+v).toFixed(1); }
                    },
                    grid: { display: false }
                },
                y: {
                    title: { display: true, text: 'Elevation (' + eleUnit + ')', font: { size: 11 } },
                    ticks: {
                        maxTicksLimit: 5,
                        callback: function (v) { return Math.round(v); }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                }
            },
            onHover: function (event, elements) {
                if (!elements || elements.length === 0) {
                    if (map.hasLayer(hoverDot)) map.removeLayer(hoverDot);
                    return;
                }
                var idx = elements[0].index;
                if (idx >= 0 && idx < pts.length) {
                    hoverDot.setLatLng([pts[idx][0], pts[idx][1]]);
                    if (!map.hasLayer(hoverDot)) hoverDot.addTo(map);
                }
            }
        }
    });
};
