// SOTA Magic - Activation Zone Overlay
// This script adds the activation zone polygon to WP GPX Maps
jQuery(document).ready(function($) {
    // Wait for WP GPX Maps to fully initialize
    setTimeout(function() {
        try {
            console.log('SOTA Magic: Starting overlay...');
            
            // Get data passed from PHP
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
            
            // Try to get the actual Leaflet map using event interception
            var map = null;
            
            console.log('SOTA Magic: Attempting to capture map via event interception...');
            
            // Hook into Leaflet's fire method to capture the map instance
            var originalFire = window.L.Evented.prototype.fire;
            var capturedMap = null;
            
            window.L.Evented.prototype.fire = function(type, data, propagate) {
                if (this instanceof window.L.Map && !capturedMap) {
                    console.log('SOTA Magic: Captured map instance via event!');
                    capturedMap = this;
                    // Restore original fire method
                    window.L.Evented.prototype.fire = originalFire;
                }
                return originalFire.call(this, type, data, propagate);
            };
            
            // Trigger a mousemove event to cause the map to fire an event
            mapDiv.dispatchEvent(new MouseEvent('mousemove', {
                view: window,
                bubbles: true,
                cancelable: true
            }));
            
            // Restore original even if we didn't capture (safety)
            window.L.Evented.prototype.fire = originalFire;
            
            map = capturedMap;
            
            if (!map) {
                console.log('SOTA Magic: Event interception failed, showing banner instead');
                
                // Fallback: show banner
                var notice = document.createElement('div');
                notice.style.position = 'absolute';
                notice.style.bottom = '10px';
                notice.style.right = '10px';
                notice.style.background = 'rgba(255, 107, 107, 0.9)';
                notice.style.color = 'white';
                notice.style.padding = '8px 12px';
                notice.style.borderRadius = '4px';
                notice.style.fontSize = '12px';
                notice.style.zIndex = '2000';
                notice.style.boxShadow = '0 2px 5px rgba(0,0,0,0.3)';
                notice.textContent = '🏔️ Activation Zone: ' + data.popup_text;
                
                mapDiv.appendChild(notice);
                
                console.log('SOTA Magic: Added text notice at bottom-right (could not access map object for polygon)');
            } else {
                console.log('SOTA Magic: Found map object!', map);
                
                // Draw the polygon or circle
                if (data.mode === 'polygon') {
                    var polygon = L.polygon(data.coordinates, {
                        color: 'rgb(255, 107, 107)',
                        fillColor: 'rgb(255, 107, 107)',
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '5, 5'
                    }).addTo(map);
                    
                    console.log('SOTA Magic: Polygon added!', polygon);
                } else {
                    var circle = L.circle([data.summit_lat, data.summit_lon], {
                        color: 'rgb(255, 165, 0)',
                        fillColor: 'rgb(255, 165, 0)',
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '10, 5',
                        radius: data.radius
                    }).addTo(map);
                    
                    console.log('SOTA Magic: Circle added!', circle);
                }
                
                // Add summit marker
                var marker = L.marker([data.summit_lat, data.summit_lon], {
                    icon: L.divIcon({
                        html: '<div style="font-size:24px;">🏔️</div>',
                        className: 'sota-summit-marker',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).addTo(map).bindPopup(data.popup_text);
                
                console.log('SOTA Magic: Marker added!', marker);
            }
            
        } catch (error) {
            console.error('SOTA Magic overlay error:', error);
        }
    }, 3000); // Wait 3 seconds for map to fully load
});
