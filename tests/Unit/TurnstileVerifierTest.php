<?php

namespace Tests\Unit;

use App\Services\TurnstileVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerifierTest extends TestCase
{
    public function test_無効の設定では確認せずに通す(): void
    {
        config(['services.turnstile.enabled' => false]);
        Http::fake();

        $this->assertTrue(app(TurnstileVerifier::class)->verify(null));
        Http::assertNothingSent();
    }

    public function test_有効なのに鍵やトークンがなければ通さない(): void
    {
        config(['services.turnstile.enabled' => true, 'services.turnstile.secret' => null]);
        Http::fake();

        $this->assertFalse(app(TurnstileVerifier::class)->verify('token'));

        config(['services.turnstile.secret' => 'test-secret']);
        $this->assertFalse(app(TurnstileVerifier::class)->verify(null));
        $this->assertFalse(app(TurnstileVerifier::class)->verify(''));
        Http::assertNothingSent();
    }

    public function test_確認が成功すれば通す(): void
    {
        config(['services.turnstile.enabled' => true, 'services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->assertTrue(app(TurnstileVerifier::class)->verify('valid-token'));
        Http::assertSent(fn ($request) => $request['secret'] === 'test-secret' && $request['response'] === 'valid-token');
    }

    public function test_確認が失敗すれば通さない(): void
    {
        config(['services.turnstile.enabled' => true, 'services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->assertFalse(app(TurnstileVerifier::class)->verify('invalid-token'));
    }

    public function test_確認先が落ちていれば通さない(): void
    {
        config(['services.turnstile.enabled' => true, 'services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response([], 503)]);

        $this->assertFalse(app(TurnstileVerifier::class)->verify('token'));
    }
}
