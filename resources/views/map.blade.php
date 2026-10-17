
<x-layouts.app>
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-bold">自販機マップ</h1>

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
            };

            document.addEventListener('livewire:navigating', destroyMap);
            document.addEventListener('livewire:navigated', initializeMap);
            initializeMap();
        })();
    </script>
</x-layouts.app>
