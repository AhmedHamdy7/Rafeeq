{{--
    Loads Google Maps once, on the first page that has a map, and draws each <x-admin.map>.
    Included by the admin layout only while AdminMaps::enabled(); otherwise nothing reaches Google.
--}}
<script>
    (function () {
        var key = @js(\App\Domains\Admin\Support\AdminMaps::key());
        var loading = null;

        function load() {
            if (window.google && window.google.maps && window.google.maps.geometry) {
                return Promise.resolve();
            }
            if (loading) {
                return loading;
            }
            loading = new Promise(function (resolve, reject) {
                window.__rafeeqMapsReady = resolve;
                var script = document.createElement('script');
                script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key)
                    + '&libraries=geometry&loading=async&callback=__rafeeqMapsReady'
                    + '&language=' + encodeURIComponent(document.documentElement.lang || 'ar') + '&region=EG';
                script.async = true;
                script.onerror = function () { loading = null; reject(new Error('maps')); };
                document.head.appendChild(script);
            });
            return loading;
        }

        function token(name, fallback) {
            var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return value || fallback;
        }

        function colourFor(kind) {
            switch (kind) {
                case 'origin': case 'pickup': return token('--success-600', '#15803d');
                case 'dropoff': case 'destination': return token('--danger-600', '#dc2626');
                case 'car': return token('--info-600', '#2563eb');
                default: return token('--fg-muted', '#6b7280');
            }
        }

        window.rafeeqMap = function (el, data) {
            load().then(function () {
                var maps = window.google.maps;
                var map = new maps.Map(el, {
                    mapTypeControl: false,
                    streetViewControl: false,
                    gestureHandling: 'cooperative',
                    clickableIcons: false,
                    colorScheme: document.documentElement.dataset.theme === 'dark' ? 'DARK' : 'LIGHT',
                });
                var bounds = new maps.LatLngBounds();
                var count = 0;

                (data.lines || []).forEach(function (line) {
                    var path = line.encoded
                        ? maps.geometry.encoding.decodePath(line.encoded)
                        : (line.points || []).map(function (p) { return new maps.LatLng(p[0], p[1]); });
                    if (path.length === 0) {
                        return;
                    }
                    var trail = line.kind === 'trail';
                    new maps.Polyline({
                        map: map,
                        path: path,
                        strokeColor: trail ? token('--warning-600', '#d97706') : token('--brand', '#0f766e'),
                        strokeOpacity: trail ? 0 : 0.85,
                        strokeWeight: trail ? 3 : 5,
                        icons: trail ? [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: 1, scale: 3 }, offset: '0', repeat: '12px' }] : null,
                    });
                    path.forEach(function (point) { bounds.extend(point); count++; });
                });

                (data.markers || []).forEach(function (marker) {
                    var position = new maps.LatLng(marker.lat, marker.lng);
                    new maps.Marker({
                        map: map,
                        position: position,
                        title: marker.title || '',
                        label: marker.label ? { text: marker.label, color: '#fff', fontSize: '11px', fontWeight: '700' } : null,
                        zIndex: marker.kind === 'car' ? 10 : 1,
                        icon: {
                            path: maps.SymbolPath.CIRCLE,
                            scale: marker.kind === 'car' ? 10 : 9,
                            fillColor: colourFor(marker.kind),
                            fillOpacity: 1,
                            strokeColor: '#fff',
                            strokeWeight: 2,
                        },
                    });
                    bounds.extend(position);
                    count++;
                });

                if (count === 1) {
                    map.setCenter(bounds.getCenter());
                    map.setZoom(15);
                } else if (count > 1) {
                    map.fitBounds(bounds, 36);
                } else {
                    map.setCenter({ lat: 30.0444, lng: 31.2357 });
                    map.setZoom(11);
                }
            }).catch(function () {
                el.classList.add('ad-map--failed');
                el.textContent = el.dataset.failed || '';
            });
        };
    })();
</script>
