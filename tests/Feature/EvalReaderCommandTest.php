<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class EvalReaderCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.model' => 'test-model',
            'services.gemini.base_url' => 'https://gemini.test/v1beta',
            'services.gemini.prompt' => 'vending_read_v1',
            'services.gemini.retry_wait' => 0,
        ]);

        // 開発用の写真と正解を、テスト用のフォルダに作る
        $this->dir = 'storage/framework/testing/evals';
        File::deleteDirectory(base_path($this->dir));
        File::ensureDirectoryExists(base_path("{$this->dir}/photos"));
        File::put(base_path("{$this->dir}/photos/a.jpg"), 'fake-image-a');
        File::put(base_path("{$this->dir}/photos/b.jpg"), 'fake-image-b');
        File::put(base_path("{$this->dir}/answers.json"), json_encode([
            'machines' => [
                [
                    'id' => 'dev_machine',
                    'slots' => 2,
                    'photos' => [['file' => 'a.jpg'], ['file' => 'b.jpg']],
                    'products' => [
                        ['name' => '開発用コーラ', 'price' => 160, 'temperature' => 'cold'],
                        ['name' => '開発用緑茶', 'price' => 150, 'temperature' => 'cold'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    private function geminiReturns(array $products): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'is_vending_machine' => true,
                'maker' => '開発用メーカー',
                'products' => $products,
            ], JSON_UNESCAPED_UNICODE)]]]]],
        ];
    }

    private function evalReader(array $options = []): PendingCommand
    {
        return $this->artisan('eval:reader', [
            '--answers' => "{$this->dir}/answers.json",
            '--dir' => "{$this->dir}/photos",
            '--sleep' => 0,
            '--save' => "{$this->dir}/result.json",
            ...$options,
        ]);
    }

    public function test_全部の写真を読み取り正解率を出して保存する(): void
    {
        Http::fake(['gemini.test/*' => Http::sequence()
            ->push($this->geminiReturns([
                ['name' => '開発用コーラ', 'price' => 160, 'temperature' => 'cold'],
                ['name' => '開発用緑茶', 'price' => 150, 'temperature' => 'cold'],
            ]))
            ->push($this->geminiReturns([
                ['name' => '開発用コーラ', 'price' => 130, 'temperature' => 'cold'],
            ])),
        ]);

        $this->evalReader()
            ->expectsOutputToContain('写真: 2 枚')
            ->assertSuccessful();

        Http::assertSentCount(2);

        $saved = json_decode(File::get(base_path("{$this->dir}/result.json")), true);
        $this->assertSame('test-model', $saved['model']);
        $this->assertSame(4, $saved['total']['strict']['rows']);
        $this->assertSame(3, $saved['total']['strict']['name']);
        $this->assertSame(2, $saved['total']['strict']['price']);
        $this->assertSame(0, $saved['total']['failed']);
    }

    public function test_photoで指定した写真だけを読む(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->geminiReturns([]))]);

        $this->evalReader(['--photo' => ['b.jpg']])
            ->expectsOutputToContain('写真: 1 枚')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_modelを指定するとそのモデルで呼ぶ(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->geminiReturns([]))]);

        $this->evalReader(['--model' => 'other-model', '--photo' => ['a.jpg']])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/other-model:generateContent'));
    }

    public function test_失敗した写真があっても残りを続け失敗の理由を記録する(): void
    {
        Http::fake(['gemini.test/*' => Http::sequence()
            ->push(['error' => 'bad request'], 400)
            ->push($this->geminiReturns([['name' => '開発用緑茶', 'price' => 150, 'temperature' => 'cold']])),
        ]);

        $this->evalReader()->assertSuccessful();

        $saved = json_decode(File::get(base_path("{$this->dir}/result.json")), true);
        $this->assertSame('request_rejected', $saved['results'][0]['error']);
        $this->assertSame(1, $saved['total']['failed']);
        $this->assertSame(1, $saved['total']['strict']['name']);
    }

    public function test_全部失敗したら失敗で終わる(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->evalReader()->assertFailed();
    }

    public function test_キーがなければ読み取りを呼ばずに止まる(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->evalReader()
            ->expectsOutputToContain('GEMINI_API_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_正解ファイルがなければ止まる(): void
    {
        Http::fake();

        $this->evalReader(['--answers' => "{$this->dir}/none.json"])
            ->expectsOutputToContain('正解ファイルがありません')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_写真が見つからなければその写真を飛ばす(): void
    {
        File::delete(base_path("{$this->dir}/photos/a.jpg"));
        Http::fake(['gemini.test/*' => Http::response($this->geminiReturns([]))]);

        $this->evalReader()->assertSuccessful();

        Http::assertSentCount(1);
        $saved = json_decode(File::get(base_path("{$this->dir}/result.json")), true);
        $this->assertSame('photo_not_found', $saved['results'][0]['error']);
    }
}
