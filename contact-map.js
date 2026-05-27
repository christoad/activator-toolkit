/* contact-map.js — SOTA Contact Map renderer
 * Fetches map data from sotaContactMapParams.dataUrl, then renders the Leaflet map.
 * The HTML shell (loading animation) is already visible before this script runs.
 */
(function () {
    'use strict';

    var params      = window.sotaContactMapParams;
    var loadingText = document.getElementById( 'loading-text' );

    fetch( params.dataUrl )
        .then( function ( r ) {
            if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
            return r.json();
        } )
        .then( function ( data ) {
            if ( data.error ) throw new Error( data.error );
            renderMap( data );
        } )
        .catch( function () {
            if ( loadingText ) loadingText.textContent = 'Could not load map data. Please try refreshing.';
            var spinner = document.querySelector( '.loading-spinner' );
            if ( spinner ) spinner.style.display = 'none';
        } );

    function renderMap( data ) {
        var mapCenter = data.summit ? [ data.summit.lat, data.summit.lon ] : [ 37.0, -95.0 ];
        var mapZoom   = data.summit ? 6 : 4;
        var map = L.map( 'map' ).setView( mapCenter, mapZoom );

        L.tileLayer( 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap contributors © CARTO',
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
                '<div class="popup-content"><strong>🏔️ ' +
                data.summit.name + '</strong>' + data.summit.ref +
                '<br><em>Your Activation</em></div>'
            );
        }

        var allPoints         = [];
        var markersByCallsign = {};
        if ( data.summit ) allPoints.push( [ data.summit.lat, data.summit.lon ] );

        data.contacts.forEach( function ( c ) {
            var contactCircle = L.circleMarker( [ c.lat, c.lon ], {
                radius:      c.is_s2s ? 8 : 6,
                fillColor:   c.color,
                color:       c.is_s2s ? '#000' : '#fff',
                weight:      2,
                opacity:     1,
                fillOpacity: 0.9
            } ).addTo( map );

            var popupHtml = '<div class="popup-content"><strong>' + c.callsign + '</strong>';
            if ( c.s2s_summit ) {
                popupHtml += '<br>📡 ' + c.s2s_summit +
                    ' <span style="background:#ff9800;color:white;padding:2px 6px;border-radius:3px;font-size:11px;">S2S</span>';
            }
            popupHtml += '<br><span class="band-indicator" style="background-color:' + c.color + ';"></span>' +
                c.frequency + ' MHz - ' + c.mode;
            if ( c.dist_miles !== null ) {
                popupHtml += '<br><span style="font-size:11px;color:#555;">&#128207; ' +
                    c.dist_miles + ' mi  /  ' + c.dist_km + ' km</span>';
            }
            if ( c.location_source === 'grid' ) {
                popupHtml += '<br><span style="font-size:11px;color:#0073aa;">📍 Grid: ' + c.grid + '</span>';
            } else if ( c.location_source === 'qrz' ) {
                popupHtml += '<br><span style="font-size:11px;color:#888;">🏠 QRZ home address</span>';
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
            if ( ! markersByCallsign[ c.callsign ] ) markersByCallsign[ c.callsign ] = [];
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
            map.invalidateSize();
            fitAllPoints();
            setTimeout( function () {
                map.invalidateSize();
                fitAllPoints();
                setTimeout( function () {
                    document.getElementById( 'loading-overlay' ).classList.add( 'hidden' );
                }, 300 );
            }, 300 );
        } );

        window.addEventListener( 'message', function ( e ) {
            if ( ! e.data || typeof e.data !== 'object' ) return;
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

        if ( data.debug && data.debug_meta ) {
            renderDebugPanel( data.debug_meta, data.contacts );
        }
    }

    function renderDebugPanel( meta, contacts ) {
        var div = document.createElement( 'div' );
        div.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#fff3cd;border-top:2px solid #ffc107;' +
            'padding:8px 12px;font-size:11px;font-family:monospace;z-index:99999;max-height:35vh;overflow-y:auto;';

        var html = '<strong>🔍 Contact Map Debug (admin only)</strong><br>';
        html += 'Summit found: <strong>' + ( meta.summit_found
            ? 'YES — ' + meta.summit_ref + ' (' + meta.summit_lat + ', ' + meta.summit_lon + ')'
            : 'NO (API returned nothing)' ) + '</strong><br>';
        html += 'Raw contacts parsed: <strong>' + meta.raw_contacts_count + '</strong> &nbsp;|&nbsp; ';
        html += 'First my_summit: <strong>' + meta.first_my_summit + '</strong><br>';
        html += 'Resolved: <strong>' + meta.resolved_count + '</strong> &nbsp;|&nbsp; ';
        html += 'Lines drawn: <strong>' + ( meta.lines_drawn > 0 ? meta.lines_drawn : 'NONE — summit was null' ) + '</strong><br>';
        html += 'Cache hits: <strong>' + meta.cached_count + '</strong> &nbsp;|&nbsp; ';
        html += 'Fresh lookups: <strong>' + meta.fresh_count + '</strong> &nbsp;|&nbsp; ';
        html += 'Unresolved: <strong>' + meta.unresolved_count + '</strong><br>';
        html += '<hr style="margin:4px 0;">';
        html += 'Locations table: <strong>' + meta.locations_table + '</strong> — <strong>' + meta.locations_table_rows + ' row(s) stored</strong><br>';
        html += 'DB error: <strong>' + ( meta.db_error || 'none' ) + '</strong><br>';
        html += '<hr style="margin:4px 0;">';

        contacts.forEach( function ( c, i ) {
            var cacheLabel = c.cached
                ? ' <span style="color:#28a745;">✓ cached</span>'
                : ' <span style="color:#fd7e14;">⬇ fresh fetch</span>';
            html += 'Contact ' + ( i + 1 ) + ': ' + c.callsign + ' → (' + c.lat + ', ' + c.lon + ') via ' + c.location_source + cacheLabel + '<br>';
        } );

        if ( meta.unresolved && meta.unresolved.length > 0 ) {
            html += '<hr style="margin:4px 0;">';
            html += '<strong style="color:#c0392b;">⚠ Unresolved contacts (not shown on map):</strong><br>';
            meta.unresolved.forEach( function ( ur ) {
                html += '&nbsp;• <strong>' + ur.callsign + '</strong> — ' + ur.reason + '<br>';
            } );
        }

        if ( meta.lookup_fail_debug ) {
            var keys = Object.keys( meta.lookup_fail_debug );
            if ( keys.length > 0 ) {
                html += '<hr style="margin:4px 0;">';
                html += '<strong>Raw lookup responses (failed):</strong><br>';
                keys.forEach( function ( cs ) {
                    html += '<em>' + cs + ':</em><br><pre style="font-size:10px;white-space:pre-wrap;max-height:120px;overflow-y:auto;background:#f8f8f8;padding:4px;">' + meta.lookup_fail_debug[ cs ] + '</pre>';
                } );
            }
        }

        div.innerHTML = html;
        document.body.appendChild( div );
    }
}() );
