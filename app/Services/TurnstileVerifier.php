<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile のトークンを確認する(Issue #48)
 *
 * 無効の設定なら常に通す。有効なのに鍵がない・トークンがない・確認に失敗した場合は通さない。
 */
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled', false);
    }

    public function verify(?string $token): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $secret = config('services.turnstile.secret');

        if (! is_string($secret) || $secret === '' || ! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::timeout(5)->asForm()->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
            ]);

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
