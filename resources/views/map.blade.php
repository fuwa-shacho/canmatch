
<x-layouts.app>
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-bold">自販機マップ</h1>
        <p
            id="location-message"
            role="status"
            aria-live="polite"
            class="hidden rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        ></p>
        <div
            id="vending-map"
            wire:ignore
            class="w-full overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700"
            style="height: 70vh; min-height: 350px; position: relative; z-index: 0;"
        ></div>
    </div>

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    />

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" data-navigate-once></script>

    <script data-navigate-once>
        (() => {
            let map = null;
            const academy = [33.589, 130.395];

            const destroyMap = () => {
                if (!map) {
                    return;
                }

                map.remove();
                map = null;
            };

            const initializeMap = () => {
                if (!window.L) {
                    document
                        .querySelector('script[src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"]')
                        ?.addEventListener('load', initializeMap, { once: true });

                    return;
                }

                const container = document.getElementById('vending-map');

                if (!container) {
                    destroyMap();

                    return;
                }

                if (map?.getContainer() === container) {
                    map.invalidateSize();

                    return;
                }

                destroyMap();
                map = L.map(container).setView(academy, 16);

                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                const locationControl = L.control({ position: 'topright' });
                locationControl.onAdd = () => {
                    const button = L.DomUtil.create('button');

                    button.type = 'button';
                    button.textContent = '現在地';
                    button.title = '現在地へ移動';
                    button.setAttribute('aria-label', '現在地へ移動');

                    button.style.cssText = [
                        'background: white',
                        'color: #222',
                        'border: 2px solid #aaa',
                        'border-radius: 4px',
                        'padding: 8px 12px',
                        'font-size: 14px',
                        'font-weight: bold',
                        'cursor: pointer'
                    ].join(';');

                    L.DomEvent.disableClickPropagation(button);
                    L.DomEvent.disableScrollPropagation(button);

                    L.DomEvent.on(button, 'click', () => {
                        const message = document.getElementById('location-message');

                        const showMessage = (text) => {
                            if (!message) return;

                            message.textContent = text;
                            message.classList.remove('hidden');
                        };

                        if (!navigator.geolocation) {
                            showMessage('このブラウザでは位置情報を取得できません。');
                            return;
                        }

                        if (!window.isSecureContext) {
                            showMessage('位置情報の取得にはHTTPS接続が必要です。');
                            return;
                        }

                        if (message) {
                            message.textContent = '';
                            message.classList.add('hidden');
                        }

                        const requestedMap = map;

                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                if (
                                    map === requestedMap &&
                                    requestedMap.getContainer().isConnected
                                ) {
                                    requestedMap.setView(
                                        [
                                            position.coords.latitude,
                                            position.coords.longitude
                                        ],
                                        17
                                    );
                                }
                            },
                            (error) => {
                                if (
                                    map !== requestedMap ||
                                    !requestedMap.getContainer().isConnected
                                ) {
                                    return;
                                }

                                const messages = {
                                    1: '位置情報の使用が許可されていません。ブラウザの設定を確認してください。',
                                    2: '現在地を特定できませんでした。',
                                    3: '位置情報の取得がタイムアウトしました。'
                                };

                                showMessage(messages[error.code] ?? '位置情報を取得できませんでした。');
                            },
                            {
                                enableHighAccuracy: true,
                                timeout: 10000,
                                maximumAge: 60000
                            }
                        );
                    });

                    return button;
                };

                locationControl.addTo(map);
            };

            document.addEventListener('livewire:navigating', destroyMap);
            document.addEventListener('livewire:navigated', initializeMap);
            initializeMap();
        })();
    </script>
</x-layouts.app>
