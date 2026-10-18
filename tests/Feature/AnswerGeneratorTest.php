<?php

namespace Tests\Feature;

use App\Exceptions\RagGenerationFailedException;
use App\Services\Rag\AnswerGenerator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerGeneratorTest extends TestCase
{
    // 自販機1=ウィルキンソン120円、自販機2=コカ・コーラ160円、自販機3=ウィルキンソン130円
    private array $evidence = [
        ['machine_id' => 1, 'product_id' => 3, 'product_name' => 'ウィルキンソン タンサン', 'price' => 120, 'days_ago' => 0],
        ['machine_id' => 2, 'product_id' => 2, 'product_name' => 'コカ・コーラ', 'price' => 160, 'days_ago' => 1],
        ['machine_id' => 3, 'product_id' => 3, 'product_name' => 'ウィルキンソン タンサン', 'price' => 130, 'days_ago' => 3],
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

    // gemma などの応答の形(choices)で、summary と citations を返させる
    private function fakeAnswer(string $summary, array $citations, int $status = 200): void
    {
        $this->fakeChoices(json_encode(['summary' => $summary, 'citations' => $citations], JSON_UNESCAPED_UNICODE), $status);
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

    public function test_自販機と価格の一覧は根拠の行から組み立てる(): void
    {
        $this->fakeAnswer(
            '甘くない炭酸なら、無糖のウィルキンソン タンサンがあります。',
            [$this->cite(1, 3, 120), $this->cite(3, 3, 130)],
        );

        $r = app(AnswerGenerator::class)->generate('甘くない炭酸', $this->evidence);

        $this->assertSame(
            "甘くない炭酸なら、無糖のウィルキンソン タンサンがあります。\n"
            . "・ウィルキンソン タンサン:自販機1で120円(今日の情報)\n"
            . '・ウィルキンソン タンサン:自販機3で130円(3日前の情報)',
            $r['answer'],
        );
        $this->assertSame('甘くない炭酸なら、無糖のウィルキンソン タンサンがあります。', $r['summary']);
        $this->assertSame([$this->cite(1, 3, 120), $this->cite(3, 3, 130)], $r['citations']);
        $this->assertSame([1, 3], $r['machine_ids']);
        $this->assertSame('@cf/test/model', $r['model']);
        $this->assertSame('rag_answer_v1', $r['prompt_version']);
    }

    public function test_一覧はcitationsの順に並べ重複はまとめる(): void
    {
        $this->fakeAnswer(
            '炭酸の飲み物です。',
            [$this->cite(2, 2, 160), $this->cite(1, 3, 120), $this->cite(2, 2, 160)],
        );

        $r = app(AnswerGenerator::class)->generate('炭酸', $this->evidence);

        $this->assertSame(
            "炭酸の飲み物です。\n"
            . "・コカ・コーラ:自販機2で160円(昨日の情報)\n"
            . '・ウィルキンソン タンサン:自販機1で120円(今日の情報)',
            $r['answer'],
        );
        $this->assertSame([2, 1], $r['machine_ids']);
    }

    public function test_日数や商品名がない根拠の行でも組み立てられる(): void
    {
        $this->evidence = [['machine_id' => 5, 'product_id' => 8, 'price' => 100]];
        $this->fakeAnswer('お茶があります。', [$this->cite(5, 8, 100)]);

        $r = app(AnswerGenerator::class)->generate('お茶', $this->evidence);

        $this->assertSame("お茶があります。\n・商品8:自販機5で100円", $r['answer']);
    }

    public function test_番号のない自販機という言葉は書いてよい(): void
    {
        $this->fakeAnswer('近くの自販機で買える無糖の炭酸です。', [$this->cite(1, 3, 120)]);

        $r = app(AnswerGenerator::class)->generate('甘くない炭酸', $this->evidence);

        $this->assertStringStartsWith('近くの自販機で買える無糖の炭酸です。', $r['answer']);
    }

    public function test_該当なしなら決まった文を返し根拠の行は空(): void
    {
        $this->fakeAnswer('登録されたデータからは条件に合う商品を確認できません', []);

        $r = app(AnswerGenerator::class)->generate('温かいお茶', $this->evidence);

        $this->assertSame(AnswerGenerator::NO_MATCH, $r['answer']);
        $this->assertSame([], $r['citations']);
        $this->assertSame([], $r['machine_ids']);
    }

    public function test_response_の形の応答も読める(): void
    {
        Http::fake(['*' => Http::response(['result' => ['response' => json_encode([
            'summary' => '炭酸です。',
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE)]])]);

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame([1], $r['machine_ids']);
    }

    public function test_JSONの前後に説明や囲みがあっても読める(): void
    {
        $this->fakeChoices("はい。\n```json\n" . json_encode([
            'summary' => '炭酸です。',
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE) . "\n```");

        $r = app(AnswerGenerator::class)->generate('質問', $this->evidence);

        $this->assertSame('炭酸です。', $r['summary']);
    }

    // ---- citations の検証 ----

    public function test_summaryがなければ失敗にする(): void
    {
        // 以前の形(answer)で返してきた場合も、読めない応答として扱う
        $this->fakeChoices('{"answer": "炭酸です。", "citations": []}');

        $this->assertSame('invalid_response', $this->failure()->reason);
    }

    public function test_citationsがなければ失敗にする(): void
    {
        $this->fakeChoices('{"summary": "炭酸です。"}');

        $this->assertSame('invalid_response', $this->failure()->reason);
    }

    public function test_根拠にない組み合わせのcitationsは失敗にする(): void
    {
        // 自販機1は120円なのに、自販機2の160円と組み合わせて返してきた
        $this->fakeAnswer('炭酸です。', [$this->cite(1, 3, 160)]);

        $this->assertSame('unsupported_citation', $this->failure()->reason);
    }

    // ---- モデルの文章(summary)の検証 ----

    public function test_自販機から価格の順で入れ替えた文章は失敗にする(): void
    {
        $this->fakeAnswer(
            '自販機1で160円です。自販機2では120円です。',
            [$this->cite(1, 3, 120), $this->cite(2, 2, 160)],
        );

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_価格から自販機の順で入れ替えた文章は失敗にする(): void
    {
        // 2回目のレビューで指摘された、価格が先に来る書き方
        $this->fakeAnswer(
            '160円の商品は自販機1で買えます。120円の商品は自販機2で買えます。',
            [$this->cite(1, 3, 120), $this->cite(2, 2, 160)],
        );

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_文章に自販機の番号があれば失敗にする(): void
    {
        $this->fakeAnswer('自販機9にもあります。', [$this->cite(1, 3, 120)]);

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_文章に全角の自販機番号があっても失敗にする(): void
    {
        $this->fakeAnswer('自販機１にあります。', [$this->cite(1, 3, 120)]);

        $this->assertSame('unsupported_machine', $this->failure()->reason);
    }

    public function test_文章に価格があれば失敗にする(): void
    {
        // citations にある価格でも、文章には書かせない
        $this->fakeAnswer('120円で買える無糖の炭酸です。', [$this->cite(1, 3, 120)]);

        $this->assertSame('unsupported_price', $this->failure()->reason);
    }

    public static function summariesWithPrice(): array
    {
        return [
            '計算した価格' => ['2本なら240円です。'],
            '全角の数字' => ['１２０円です。'],
            '漢数字' => ['百二十円です。'],
            '数字と円の間に空白' => ['120 円です。'],
        ];
    }

    #[DataProvider('summariesWithPrice')]
    public function test_文章に書き方を変えた価格があっても失敗にする(string $summary): void
    {
        $this->fakeAnswer($summary, [$this->cite(1, 3, 120)]);

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
        $this->fakeAnswer('炭酸です。', [$this->cite(1, 3, 120)]);

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
        $this->fakeAnswer('炭酸です。', [$this->cite(1, 3, 120)]);

        app(AnswerGenerator::class)->generate('質問', $this->evidence);

        Http::assertSent(fn ($r) => ! isset($r['chat_template_kwargs'])
            && ! isset($r['response_format']));
    }
}