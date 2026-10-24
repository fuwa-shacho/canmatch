{{-- Cloudflare Turnstile のチェック欄(#48)。設定が無効なら何も出さない。親の Livewire コンポーネントに turnstileToken が必要 --}}
@if (config('services.turnstile.enabled'))
    <div
        x-data="{
            widget: null,
            async initWidget() {
                const siteKey = @js(config('services.turnstile.site_key'));
                if (!siteKey) return;
                if (!window.turnstile) {
                    await new Promise((resolve, reject) => {
                        const existing = document.querySelector('script[data-canmatch-turnstile]');
                        if (existing) {
                            existing.addEventListener('load', resolve, { once: true });
                            existing.addEventListener('error', reject, { once: true });
                            return;
                        }
                        const script = document.createElement('script');
                        script.dataset.canmatchTurnstile = '1';
                        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.appendChild(script);
                    }).catch(() => null);
                }
                if (!window.turnstile || !this.$refs.widget) return;
                this.widget = window.turnstile.render(this.$refs.widget, {
                    sitekey: siteKey,
                    callback: (token) => $wire.set('turnstileToken', token),
                    'expired-callback': () => $wire.set('turnstileToken', null),
                    'error-callback': () => $wire.set('turnstileToken', null),
                });
            },
        }"
        x-init="initWidget()"
        x-on:turnstile-reset.window="if (widget !== null && window.turnstile) window.turnstile.reset(widget)"
        class="space-y-2"
    >
        <div x-ref="widget" wire:ignore></div>
        @error('turnstileToken') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
    </div>
@endif
