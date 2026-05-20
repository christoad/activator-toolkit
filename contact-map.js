/* contact-map.js — SOTA Contact Map renderer
 * Reads data from window.sotaContactMapData, which is output as a JSON
 * variable by contact-map.php (the standalone AJAX page that wraps this map).
 */
(function () {
    'use strict';

    var data = window.sotaContactMapData;

    // Initialize map centered on summit if available, otherwise continental US.
    var mapCenter = data.summit ? [ data.summit.lat, data.summit.lon ] : [ 37.0, -95.0 ];
    var mapZoom   = data.summit ? 6 : 4;
    var map = L.map( 'map' ).setView( mapCenter, mapZoom );

    L.tileLayer( 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '\u00a9 OpenStreetMap contributors \u00a9 CARTO',
        maxZoom: 19
    } ).addTo( map );

    var summitIcon = L.divIcon( {
        html:       '<div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;filter:drop-shadow(0 1px 2px rgba(0,0,0,0.4));"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#CC2200" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20L12 4L21 20H3Z"/><path d="M9 20L12 13L15 17"/></svg></div>',
        className:  'summit-icon',
        iconSize:   [ 32, 32 ],
        iconAnchor: [ 16, 16 ]
    } );

    if ( data.summit ) {
        var summitMarker = L.marker( [ data.summit.lat, data.summit.lon ], { icon: summitIcon } ).addTo( map );
        summitMarker.bindPopup(
            '<div class="popup-content"><strong>\ud83c\udfd4\ufe0f ' +
            data.summit.name + '</strong>' + data.summit.ref +
            '<br><em>Your Activation</em></div>'
        );
    }

    var allPoints = [];
    var markersByCallsign = {};
    if ( data.summit ) {
        allPoints.push( [ data.summit.lat, data.summit.lon ] );
    }

    data.contacts.forEach( function ( c ) {
        var contactCircle = L.circleMarker( [ c.lat, c.lon ], {
            radius:      c.is_s2s ? 8 : 6,
            fillColor:   c.color,
            color:       c.is_s2s ? '#000' : '#fff',
            weight:      2,
            opacity:     1,
            fillOpacity: 0.9
        } ).addTo( map );

        // Build popup HTML — same content as was previously PHP-generated inline.
        var popupHtml = '<div class="popup-content"><strong>' + c.callsign + '</strong>';
        if ( c.s2s_summit ) {
            popupHtml += '<br>\ud83d\udce1 ' + c.s2s_summit +
                ' <span style="background:#ff9800;color:white;padding:2px 6px;border-radius:3px;font-size:11px;">S2S</span>';
        }
        popupHtml += '<br><span class="band-indicator" style="background-color:' + c.color + ';"></span>' +
            c.frequency + ' MHz - ' + c.mode;
        if ( c.dist_miles !== null ) {
            popupHtml += '<br><span style="font-size:11px;color:#555;">&#128207; ' +
                c.dist_miles + ' mi \u00a0/\u00a0 ' + c.dist_km + ' km</span>';
        }
        if ( c.location_source === 'grid' ) {
            popupHtml += '<br><span style="font-size:11px;color:#0073aa;">\ud83d\udccd Grid: ' + c.grid + '</span>';
        } else if ( c.location_source === 'qrz' ) {
            popupHtml += '<br><span style="font-size:11px;color:#888;">\ud83c\udfe0 QRZ home address</span>';
        }
        popupHtml += '</div>';
        contactCircle.bindPopup( popupHtml );

        if ( data.summit ) {
            L.polyline(
                [ [ data.summit.lat, data.summit.lon ], [ c.lat, c.lon ] ],
                { color: c.color, weight: 3, opacity: 0.7, interactive: false }
            ).addTo( map );
        }

        allPoints.push( [ c.lat, c.lon ] );
        if ( !markersByCallsign[ c.callsign ] ) markersByCallsign[ c.callsign ] = [];
        markersByCallsign[ c.callsign ].push( contactCircle );
    } );

    function fitAllPoints() {
        if ( allPoints.length > 1 ) {
            map.fitBounds( L.latLngBounds( allPoints ), { padding: [ 40, 40 ], animate: false } );
        } else if ( allPoints.length === 1 ) {
            map.setView( allPoints[ 0 ], 10 );
        }
    }

    map.whenReady( function () {
        // First pass — immediate fit
        map.invalidateSize();
        fitAllPoints();

        // Second pass after a short delay — catches iframe resize settling
        setTimeout( function () {
            map.invalidateSize();
            fitAllPoints();
            setTimeout( function () {
                document.getElementById( 'loading-overlay' ).classList.add( 'hidden' );
            }, 300 );
        }, 300 );
    } );

    window.addEventListener( 'message', function ( e ) {
        if ( !e.data || typeof e.data !== 'object' ) return;
        if ( e.data.action === 'show' ) {
            var markers = markersByCallsign[ e.data.callsign ];
            if ( markers && markers.length > 0 ) markers[ 0 ].openPopup();
        } else if ( e.data.action === 'hide' ) {
            map.closePopup();
        }
    } );

    if ( data.unresolved && data.unresolved.length > 0 ) {
        var UnresolvedControl = L.Control.extend( {
            options: { position: 'bottomleft' },
            onAdd: function () {
                var div = L.DomUtil.create( 'div', '' );
                div.style.cssText = 'background:white;border-radius:4px;padding:8px 12px;font-size:12px;' +
                    'font-family:sans-serif;max-width:240px;box-shadow:0 1px 5px rgba(0,0,0,0.3);line-height:1.6;';
                var html = '<strong style="color:#555;">&#9888; No location found</strong><br>';
                data.unresolved.forEach( function ( c ) {
                    html += '<span style="color:#888;">&bull; ' + c.callsign + '</span><br>';
                } );
                div.innerHTML = html;
                return div;
            }
        } );
        new UnresolvedControl().addTo( map );
    }

    if ( data.debug ) {
        console.log( '[SOTA Map Debug] summit:', data.summit );
        console.log( '[SOTA Map Debug] contact_locations:', data.contacts );
        console.log( '[SOTA Map Debug] contacts count:', data.contacts.length );
    }
}() );
