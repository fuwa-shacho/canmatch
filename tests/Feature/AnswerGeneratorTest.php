<?php

namespace Tests\Feature;

use App\Exceptions\RagGenerationFailedException;
use App\Services\Rag\AnswerGenerator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnswerGeneratorTest extends TestCase
{
    private array $evidence = [
        ['machine_id' => 1, 'product_name' => 'ウィルキンソン タンサン', 'price' => 120],
        ['machine_id' => 2, 'product_name' => 'コカ・コーラ', 'price' => 160],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare.account_id', 'acc');
        config()->set('services.cloudflare.api_token', 'token');
        config()->set('services.cloudflare.timeout', 30);
        config()->set('services.cloudflare.generation_model', '@cf/test/model');
        config()->set('services.cloudflare.max_tokens', 400);
        config()->set('services.cloudflare.answer_prompt', 'rag_answer_v1');
        config()->set('services.cloudflare.disable_thinking', true);
        config()->set('services.cloudflare.json_mode', true);
    }

    // gemma などの応答の形(OpenAI と同じ choices の形)
    private function fakeChoices(string $content, int $status = 200): void
    {
        Http::fake(['*' => Http::response(
            ['result' => ['choices' => [['message' => ['content' => $content]]]]],
            $status,
        )]);
    }

    // 生成を実行し、投げられた例外を受け取る
    private function failure(): RagGenerationFailedException
    {
        try {
            app(AnswerGenerator::class)->generate('質問', $this->evidence);
        } catch (RagGenerationFailedException $e) {
            return $e;
        }

        $this->fail('RagGenerationFailedException が投げられなかった');
    }

    // ---- 正しく答えられる場合 ----

    public function test_回答と自販機IDを返す(): void
    {
        $this->fakeChoices('{"answer": "自販機1で120円です。", "machine_ids": [1]}');

        $r = app(AnswerGenerator::class)->generate('甘くない炭酸', $this->evidence);

        $this->assertSame('自販機1で120円です。', $r['answer']);
        $this->assertSame([1], $r['machine_ids']);
        $this->assertSame('@cf/test/model', $r['model']);
        $this->assertSame('rag_answer_v1', $r['prompt_version']);
    }

    public function test_response_の形の応答も読める(): void
    {
        Http::fake(['*' => Http::response(['result' => ['response' => '{"answer": "120円です。", "machine_ids": [1]}']])]);

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame('120円です。', $r['answer']);
    }

    public function test_JSONの前後に説明や囲みがあっても読める(): void
    {
        $this->fakeChoices("はい。\n```json\n{\"answer\": \"120円です。\", \"machine_ids\": [1]}\n```");

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame('120円です。', $r['answer']);
    }

    // ---- 根拠にないものを捨てる ----

    public function test_根拠にない自販機IDは捨てる(): void
    {
        $this->fakeChoices('{"answer": "120円です。", "machine_ids": [1, 99]}');

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame([1], $r['machine_ids']);
    }

    public function test_根拠にない価格が出たら失敗にする(): void
    {
        $this->fakeChoices('{"answer": "2本で240円です。", "machine_ids": [1]}');

        $this->assertSame('unsupported_price', $this->failure()->reason);
    }

    // ---- 失敗する場合 ----

    public function test_答えが空なら失敗にする(): void
    {
        // gemma で考える機能がオンのままだと、content が空で返ってきた
        $this->fakeChoices('');

        $this->assertSame('invalid_response', $this->failure()->reason);
    }

    public function test_無料枠切れなどの429は_rate_limit(): void
    {
        $this->fakeChoices('', 429);

        $this->assertSame('rate_limit', $this->failure()->reason);
    }

    public function test_根拠が空なら生成を呼ばない(): void
    {
        Http::fake();

        try {
            app(AnswerGenerator::class)->generate('質問', []);
            $this->fail('例外が投げられなかった');
        } catch (RagGenerationFailedException $e) {
            $this->assertSame('no_evidence', $e->reason);
        }

        Http::assertNothingSent();
    }

    // ---- 送る中身 ----

    public function test_考える機能をオフにしてJSONで返させる(): void
    {
        $this->fakeChoices('{"answer": "120円です。", "machine_ids": [1]}');

        app(AnswerGenerator::class)->generate('質問', $this->evidence);

        Http::assertSent(fn ($r) => $r['chat_template_kwargs'] === ['enable_thinking' => false]
            && $r['response_format'] === ['type' => 'json_object']
            && $r['max_completion_tokens'] === 400
            && str_contains($r->url(), '/ai/run/@cf/test/model'));
    }

    public function test_切り替えをオフにすると指定を送らない(): void
    {
        // mistral は chat_template_kwargs を送ると 400 になった
        config()->set('services.cloudflare.disable_thinking', 'false');
        config()->set('services.cloudflare.json_mode', 'false');
        $this->fakeChoices('{"answer": "120円です。", "machine_ids": [1]}');

        app(AnswerGenerator::class)->generate('質問', $this->evidence);

        Http::assertSent(fn ($r) => ! isset($r['chat_template_kwargs'])
            && ! isset($r['response_format']));
    }
}