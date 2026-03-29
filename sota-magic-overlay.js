// SOTA Magic - Activation Zone Overlay
jQuery(document).ready(function($) {
    setTimeout(function() {
        try {
            console.log('SOTA Magic: Starting overlay...');

            if (typeof sotaMagicData === 'undefined') {
                console.error('SOTA Magic: No data available');
                return;
            }

            var data = sotaMagicData;
            console.log('SOTA Magic: Data loaded:', data);

            // Find the map div
            var mapDiv = document.querySelector('[id^="map_"][class*="leaflet-container"]');
            if (!mapDiv) {
                console.error('SOTA Magic: No Leaflet map div found');
                return;
            }

            console.log('SOTA Magic: Found map div:', mapDiv.id);

            // --- Capture map instance via event interception ---
            var map = null;
            var originalFire = window.L.Evented.prototype.fire;
            var capturedMap = null;

            window.L.Evented.prototype.fire = function(type, d, propagate) {
                if (this instanceof window.L.Map && !capturedMap) {
                    console.log('SOTA Magic: Captured map instance via event!');
                    capturedMap = this;
                    window.L.Evented.prototype.fire = originalFire;
                }
                return originalFire.call(this, type, d, propagate);
            };

            mapDiv.dispatchEvent(new MouseEvent('mousemove', {
                view: window,
                bubbles: true,
                cancelable: true
            }));

            // Safety restore if mousemove didn't trigger a map event
            window.L.Evented.prototype.fire = originalFire;

            map = capturedMap;

            if (!map) {
                // Fallback banner if we couldn't capture the map object
                console.log('SOTA Magic: Event interception failed, showing banner instead');
                var notice = document.createElement('div');
                notice.style.cssText = 'position:absolute;bottom:10px;right:10px;background:rgba(255,107,107,0.9);color:white;padding:8px 12px;border-radius:4px;font-size:12px;z-index:2000;box-shadow:0 2px 5px rgba(0,0,0,0.3);';
                notice.textContent = '🏔️ Activation Zone: ' + data.popup_text;
                mapDiv.appendChild(notice);
                return;
            }

            console.log('SOTA Magic: Found map object!', map);

            // --- Overlay draw / redraw ---
            // Keep references so we can remove layers before redrawing
            var activeOverlayLayer = null;
            var activeSummitMarker = null;

            function drawOverlay() {
                // Remove previous layers if they exist
                if (activeOverlayLayer) {
                    map.removeLayer(activeOverlayLayer);
                    activeOverlayLayer = null;
                }
                if (activeSummitMarker) {
                    map.removeLayer(activeSummitMarker);
                    activeSummitMarker = null;
                }

                if (data.mode === 'polygon') {
                    activeOverlayLayer = L.polygon(data.coordinates, {
                        color: 'rgb(255, 107, 107)',
                        fillColor: 'rgb(255, 107, 107)',
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '5, 5'
                    }).addTo(map);
                    console.log('SOTA Magic: Polygon drawn');
                } else {
                    activeOverlayLayer = L.circle([data.summit_lat, data.summit_lon], {
                        color: 'rgb(255, 165, 0)',
                        fillColor: 'rgb(255, 165, 0)',
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '10, 5',
                        radius: data.radius
                    }).addTo(map);
                    console.log('SOTA Magic: Circle drawn');
                }

                activeSummitMarker = L.marker([data.summit_lat, data.summit_lon], {
                    icon: L.divIcon({
                        html: '<div style="font-size:24px;">🏔️</div>',
                        className: 'sota-summit-marker',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).addTo(map).bindPopup(data.popup_text);
                console.log('SOTA Magic: Summit marker drawn');
            }

            // Initial draw
            drawOverlay();

            // Redraw whenever the user switches base layers
            map.on('baselayerchange', function() {
                console.log('SOTA Magic: Base layer changed, redrawing overlay...');
                // Small delay to let WP GPX Maps finish its own layer-change handling
                setTimeout(drawOverlay, 150);
            });

        } catch (error) {
            console.error('SOTA Magic overlay error:', error);
        }
    }, 3000); // Wait 3 seconds for WP GPX Maps to fully initialize
});
