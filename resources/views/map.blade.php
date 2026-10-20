
<x-layouts.app>
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-bold">自販機マップ</h1>
        <form id="product-search-form" class="flex flex-col gap-2 sm:flex-row" role="search">
            <label for="product-search" class="sr-only">商品名</label>
            <input
                id="product-search"
                name="product_name"
                type="search"
                maxlength="100"
                placeholder="商品名の一部で検索"
                class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-neutral-900 dark:border-neutral-600 dark:bg-neutral-800 dark:text-white"
            >
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">
                検索
            </button>
            <button
                id="product-search-reset"
                type="button"
                class="rounded-lg border border-neutral-300 px-4 py-2 font-semibold dark:border-neutral-600"
            >
                リセット
            </button>
        </form>
        <p id="search-summary" role="status" aria-live="polite" class="text-sm text-neutral-600 dark:text-neutral-300"></p>
        <ul id="search-results" class="grid gap-2 sm:grid-cols-2"></ul>
        <p
            id="location-message"
            role="status"
            aria-live="polite"
            class="hidden rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        ></p>
        <p
            id="map-message"
            role="status"
            aria-live="polite"
            class="hidden rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900"
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
            let markerLayer = null;
            let markerRequest = null;
            const academy = [33.589, 130.395];

            const destroyMap = () => {
                markerRequest?.abort();
                markerRequest = null;

                if (!map) {
                    return;
                }

                map.remove();
                map = null;
                markerLayer = null;
            };

            const createPopupContent = (machine) => {
                const content = document.createElement('div');
                const maker = document.createElement('strong');
                const productCount = document.createElement('p');
                const detailLink = document.createElement('a');

                maker.textContent = machine.maker || 'メーカー不明';
                productCount.textContent = `商品数: ${machine.product_count}件`;
                detailLink.href = `/vending-machines/${encodeURIComponent(machine.id)}`;
                detailLink.textContent = '詳細を見る';

                content.append(maker, productCount, detailLink);

                return content;
            };

            const renderSearchResults = (machines, productName) => {
                const summary = document.getElementById('search-summary');
                const results = document.getElementById('search-results');

                if (!summary || !results) {
                    return;
                }

                results.replaceChildren();

                if (!productName) {
                    summary.textContent = '';
                    return;
                }

                const rows = machines
                    .flatMap((machine) => (machine.matched_products ?? []).map((product) => ({ machine, product })))
                    .sort((left, right) =>
                        left.product.price - right.product.price ||
                        left.machine.id - right.machine.id ||
                        left.product.machine_product_id - right.product.machine_product_id
                    );
                const lowestPrice = rows[0]?.product.price;

                summary.textContent = rows.length > 0
                    ? `「${productName}」の販売情報: ${rows.length}件（自販機${machines.length}台）`
                    : `「${productName}」を扱う自販機は見つかりませんでした。`;

                for (const { machine, product } of rows) {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    const heading = document.createElement('div');
                    const price = document.createElement('p');
                    const details = document.createElement('p');
                    const isLowest = product.price === lowestPrice;
                    const temperatureLabels = {
                        hot: '温',
                        cold: '冷'
                    };

                    item.className = isLowest
                        ? 'rounded-lg border-2 border-amber-400 bg-amber-50 p-3 dark:bg-amber-950/30'
                        : 'rounded-lg border border-neutral-200 p-3 dark:border-neutral-700';
                    link.href = `/vending-machines/${encodeURIComponent(machine.id)}`;
                    link.textContent = machine.maker || 'メーカー不明';
                    link.className = 'font-semibold text-blue-700 hover:underline dark:text-blue-300';
                    heading.append(link);

                    if (isLowest) {
                        const badge = document.createElement('span');
                        badge.textContent = '最安';
                        badge.className = 'ml-2 rounded bg-amber-400 px-2 py-1 text-xs font-bold text-amber-950';
                        heading.append(badge);
                    }

                    price.textContent = `${product.product_name}: ${product.price}円`;
                    price.className = 'mt-1 font-bold';
                    details.textContent = `${temperatureLabels[product.temperature] ?? '温冷不明'} / ${product.days_ago}日前の情報`;
                    details.className = product.days_ago >= 30
                        ? 'mt-1 text-sm font-semibold text-red-700 dark:text-red-300'
                        : 'mt-1 text-sm text-neutral-600 dark:text-neutral-300';
                    item.append(heading, price, details);
                    results.append(item);
                }
            };

            const loadVendingMachineMarkers = async (requestedMap, productName = '') => {
                const message = document.getElementById('map-message');
                const request = new AbortController();
                const parameters = new URLSearchParams();

                if (productName) {
                    parameters.set('product_name', productName);
                }

                const endpoint = parameters.size > 0
                    ? `/api/vending-machines?${parameters.toString()}`
                    : '/api/vending-machines';

                markerRequest?.abort();
                markerRequest = request;

                try {
                    const response = await fetch(endpoint, {
                        headers: { Accept: 'application/json' },
                        signal: request.signal
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const machines = await response.json();

                    if (!Array.isArray(machines)) {
                        throw new Error('Unexpected response');
                    }

                    if (
                        map !== requestedMap ||
                        !requestedMap.getContainer().isConnected
                    ) {
                        return;
                    }

                    markerLayer?.clearLayers();

                    for (const machine of machines) {
                        const latitude = Number(machine.latitude);
                        const longitude = Number(machine.longitude);

                        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                            continue;
                        }

                        L.marker([latitude, longitude])
                            .bindPopup(createPopupContent(machine))
                            .addTo(markerLayer);
                    }

                    renderSearchResults(machines, productName);
                    message?.classList.add('hidden');
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    if (
                        map === requestedMap &&
                        requestedMap.getContainer().isConnected &&
                        message
                    ) {
                        message.textContent = '自販機情報を読み込めませんでした。時間をおいて再度お試しください。';
                        message.classList.remove('hidden');
                    }
                } finally {
                    if (markerRequest === request) {
                        markerRequest = null;
                    }
                }
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
                markerLayer = L.layerGroup().addTo(map);

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
                loadVendingMachineMarkers(map);

                const searchForm = document.getElementById('product-search-form');
                const searchInput = document.getElementById('product-search');
                const resetButton = document.getElementById('product-search-reset');

                searchForm?.addEventListener('submit', (event) => {
                    event.preventDefault();
                    loadVendingMachineMarkers(map, searchInput?.value.trim() ?? '');
                });

                resetButton?.addEventListener('click', () => {
                    if (searchInput) {
                        searchInput.value = '';
                    }

                    loadVendingMachineMarkers(map);
                });
            };

            document.addEventListener('livewire:navigating', destroyMap);
            document.addEventListener('livewire:navigated', initializeMap);
            initializeMap();
        })();
    </script>
</x-layouts.app>
