<?php

namespace Tests\Feature;

use App\Services\VendingMachineReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class VendingMachineReaderTest extends TestCase
{
    // 各テストの前に毎回実行される(Pestの beforeEach にあたる)
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'test-model');

        Storage::fake('local');
        Storage::disk('local')->put('photos/test.jpg', 'dummy');
    }

    // Geminiの応答の形を作る
    private function geminiResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_写真から商品の配列を返す(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(json_encode([
            'is_vending_machine' => true,
            'maker' => 'コカ・コーラ',
            'products' => [
                ['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold'],
                ['name' => 'ジョージア 微糖', 'price' => null, 'temperature' => 'hot'],
            ],
        ])))]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertCount(2, $r['result']['products']);
        $this->assertSame(160, $r['result']['products'][0]['price']);
        $this->assertNull($r['result']['products'][1]['price']);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function test_想定外の値はnullにそろえる(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(json_encode([
            'is_vending_machine' => true,
            'products' => [
                ['name' => '綾鷹', 'price' => '160円', 'temperature' => '冷'],
            ],
        ])))]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertNull($r['result']['products'][0]['price']);
        $this->assertNull($r['result']['products'][0]['temperature']);
    }

    public function test_JSONでない応答なら例外を投げる(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse('読めませんでした'))]);

        $this->expectException(RuntimeException::class);

        app(VendingMachineReader::class)->read('photos/test.jpg');
    }
}