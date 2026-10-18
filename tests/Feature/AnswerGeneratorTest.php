<?php

namespace Tests\Feature;

use App\Exceptions\RagGenerationFailedException;
use App\Services\Rag\AnswerGenerator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnswerGeneratorTest extends TestCase
{
    // 自販機1=ウィルキンソン120円、自販機2=コカ・コーラ160円、自販機3=ウィルキンソン130円
    private array $evidence = [
        ['machine_id' => 1, 'product_id' => 3, 'product_name' => 'ウィルキンソン タンサン', 'price' => 120],
        ['machine_id' => 2, 'product_id' => 2, 'product_name' => 'コカ・コーラ', 'price' => 160],
        ['machine_id' => 3, 'product_id' => 3, 'product_name' => 'ウィルキンソン タンサン', 'price' => 130],
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

    // gemma などの応答の形(choices)で、answer と citations を返させる
    private function fakeAnswer(string $answer, array $citations, int $status = 200): void
    {
        $this->fakeChoices(json_encode(['answer' => $answer, 'citations' => $citations], JSON_UNESCAPED_UNICODE), $status);
    }

    private function fakeChoices(string $content, int $status = 200): void
    {
        Http::fake(['*' => Http::response(
            ['result' => ['choices' => [['message' => ['content' => $content]]]]],
            $status,
        )]);
    }

    private function cite(int $machineId, int $productId, int $price): array
    {
        return ['machine_id' => $machineId, 'product_id' => $productId, 'price' => $price];
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

    public function test_回答と根拠の行と自販機IDを返す(): void
    {
        $this->fakeAnswer(
            '無糖の炭酸なら、ウィルキンソン タンサンが自販機1で120円です。自販機3でも130円で売られています。',
            [$this->cite(1, 3, 120), $this->cite(3, 3, 130)],
        );

        $r = app(AnswerGenerator::class)->generate('甘くない炭酸', $this->evidence);

        $this->assertSame([$this->cite(1, 3, 120), $this->cite(3, 3, 130)], $r['citations']);
        $this->assertSame([1, 3], $r['machine_ids']);
        $this->assertSame('@cf/test/model', $r['model']);
        $this->assertSame('rag_answer_v1', $r['prompt_version']);
    }

    public function test_該当なしなら根拠の行は空で通る(): void
    {
        $this->fakeAnswer('登録されたデータからは条件に合う商品を確認できません。', []);

        $r = app(AnswerGenerator::class)->generate('温かいお茶', $this->evidence);

        $this->assertSame([], $r['citations']);
        $this->assertSame([], $r['machine_ids']);
    }

    public function test_response_の形の応答も読める(): void
    {
        Http::fake(['*' => Http::response(['result' => ['response' => json_encode([
            'answer' => '自販機1で120円です。',
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE)]])]);

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame([1], $r['machine_ids']);
    }

    public function test_JSONの前後に説明や囲みがあっても読める(): void
    {
        $this->fakeChoices("はい。\n```json\n" . json_encode([
            'answer' => '自販機1で120円です。',
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE) . "\n```");

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame('自販機1で120円です。', $r['answer']);
    }

    // ---- citations の検証 ----

    public function test_citationsがなければ失敗にする(): void
    {
        $this->fakeChoices('{"answer": "自販機1で120円です。"}');

        $this->assertSame('invalid_response', $this->failure()->reason);
    }

    public function test_根拠にない組み合わせのcitationsは失敗にする(): void
    {
        // 自販機1は120円なのに、160円として返してきた
        $this->fakeAnswer('自販機1で160円です。', [$this->cite(1, 3, 160)]);

        $this->assertSame('unsupported_citation', $this->failure()->reason);
    }

    // ---- 本文の検証 ----

    public function test_本文で自販機と価格を入れ替えたら失敗にする(): void
    {
        // citations は正しいが、本文で自販機1の価格を自販機2の160円と入れ替えた
        $this->fakeAnswer(
            '自販機1で160円です。自販機2では120円です。',
            [$this->cite(1, 3, 120), $this->cite(2, 2, 160)],
        );

        $this->assertSame('unsupported_combination', $this->failure()->reason);
    }

    public function test_本文だけに根拠外の自販機があれば失敗にする(): void
    {
        $this->fakeAnswer(
            '自販機1で120円です。自販機9にもあります。',
            [$this->cite(1, 3, 120)],
        );

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_本文だけに根拠にある別の自販機があっても失敗にする(): void
    {
        // 自販機2は根拠にはあるが、citations に入れていない
        $this->fakeAnswer(
            '自販機1で120円です。自販機2にもあります。',
            [$this->cite(1, 3, 120)],
        );

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_本文にcitationsにない価格があれば失敗にする(): void
    {
        $this->fakeAnswer('自販機1で120円です。2本なら240円です。', [$this->cite(1, 3, 120)]);

        $this->assertSame('unsupported_price', $this->failure()->reason);
    }

    // ---- その他の失敗 ----

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
        $this->fakeAnswer('自販機1で120円です。', [$this->cite(1, 3, 120)]);

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
        $this->fakeAnswer('自販機1で120円です。', [$this->cite(1, 3, 120)]);

        app(AnswerGenerator::class)->generate('質問', $this->evidence);

        Http::assertSent(fn ($r) => ! isset($r['chat_template_kwargs'])
            && ! isset($r['response_format']));
    }
}