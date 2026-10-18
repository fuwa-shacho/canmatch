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

    // gemma などの応答の形(choices)で、モデルが文章(summary)を付けて返してきた場合を再現する。
    // 文章は回答に使われないことを、各テストで確かめる
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

    private function generate(?array $evidence = null): array
    {
        return app(AnswerGenerator::class)->generate('質問', $evidence ?? $this->evidence);
    }

    // 生成を実行し、投げられた例外を受け取る
    private function failure(): RagGenerationFailedException
    {
        try {
            $this->generate();
        } catch (RagGenerationFailedException $e) {
            return $e;
        }

        $this->fail('RagGenerationFailedException が投げられなかった');
    }

    // ---- 回答は根拠の行からサーバーが組み立てる ----

    public function test_回答は固定の前置きと根拠の行から組み立てる(): void
    {
        $this->fakeAnswer(
            '甘くない炭酸なら、無糖のウィルキンソン タンサンがあります。',
            [$this->cite(1, 3, 120), $this->cite(3, 3, 130)],
        );

        $r = $this->generate();

        $this->assertSame(
            AnswerGenerator::LEAD . "\n"
            . "・ウィルキンソン タンサン:自販機1で120円(今日の情報)\n"
            . '・ウィルキンソン タンサン:自販機3で130円(3日前の情報)',
            $r['answer'],
        );
        $this->assertSame([$this->cite(1, 3, 120), $this->cite(3, 3, 130)], $r['citations']);
        $this->assertSame([1, 3], $r['machine_ids']);
        $this->assertSame('@cf/test/model', $r['model']);
        $this->assertSame('rag_answer_v1', $r['prompt_version']);
        $this->assertArrayNotHasKey('summary', $r);
    }

    public function test_回答の順番は根拠の一覧の順で重複はまとめる(): void
    {
        // モデルが高い順・重複ありで返しても、根拠の一覧の順(安い順)に1回ずつ並べる
        $this->fakeAnswer(
            '炭酸の飲み物です。',
            [$this->cite(2, 2, 160), $this->cite(1, 3, 120), $this->cite(2, 2, 160)],
        );

        $r = $this->generate();

        $this->assertSame(
            AnswerGenerator::LEAD . "\n"
            . "・ウィルキンソン タンサン:自販機1で120円(今日の情報)\n"
            . '・コカ・コーラ:自販機2で160円(昨日の情報)',
            $r['answer'],
        );
        $this->assertSame([1, 2], $r['machine_ids']);
    }

    public function test_日数や商品名がない根拠の行でも組み立てられる(): void
    {
        $evidence = [['machine_id' => 5, 'product_id' => 8, 'price' => 100]];
        $this->fakeAnswer('お茶があります。', [$this->cite(5, 8, 100)]);

        $r = $this->generate($evidence);

        $this->assertSame(AnswerGenerator::LEAD . "\n・商品8:自販機5で100円", $r['answer']);
    }

    public function test_該当なしなら決まった文を返し根拠の行は空(): void
    {
        $this->fakeAnswer('条件に合う商品はありませんでした。', []);

        $r = $this->generate();

        $this->assertSame(AnswerGenerator::NO_MATCH, $r['answer']);
        $this->assertSame([], $r['citations']);
        $this->assertSame([], $r['machine_ids']);
    }

    public function test_文章がなくてもcitationsだけで答えられる(): void
    {
        $this->fakeChoices(json_encode(['citations' => [$this->cite(1, 3, 120)]], JSON_UNESCAPED_UNICODE));

        $this->assertSame([1], $this->generate()['machine_ids']);
    }

    public function test_response_の形の応答も読める(): void
    {
        Http::fake(['*' => Http::response(['result' => ['response' => json_encode([
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE)]])]);

        $this->assertSame([1], $this->generate()['machine_ids']);
    }

    public function test_JSONの前後に説明や囲みがあっても読める(): void
    {
        $this->fakeChoices("はい。\n```json\n" . json_encode([
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE) . "\n```");

        $this->assertSame([1], $this->generate()['machine_ids']);
    }

    // ---- モデルの文章は、どんな書き方でも回答に含めない ----

    public function test_モデルが文章を返しても回答に含めない(): void
    {
        // レビューの指摘:根拠は自販機1だけなのに、「9番の自販機がおすすめです。」と書いてきた場合
        $this->fakeChoices(json_encode([
            'summary' => '9番の自販機がおすすめです。',
            'answer' => '自販機9で999円です。',
            'citations' => [$this->cite(1, 3, 120)],
        ], JSON_UNESCAPED_UNICODE));

        $r = $this->generate();

        $this->assertSame(
            AnswerGenerator::LEAD . "\n・ウィルキンソン タンサン:自販機1で120円(今日の情報)",
            $r['answer'],
        );
        $this->assertStringNotContainsString('9番の自販機', $r['answer']);
        $this->assertStringNotContainsString('999', $r['answer']);
    }

    public static function modelTexts(): array
    {
        return [
            '価格→自販機の順で入れ替え(レビューの例)' => ['160円の商品は自販機1で買えます。120円の商品は自販機2で買えます。'],
            '自販機→価格の順で入れ替え' => ['自販機1で160円、自販機2で120円です。'],
            '番号を数字で書く' => ['9番の自販機がおすすめです。'],
            '自販機と番号の間に語が入る' => ['自販機番号9がおすすめです。'],
            '全角数字' => ['自販機１で１２０円です。'],
            '漢数字' => ['九番の店で百二十円です。'],
            '計算した価格' => ['2本なら240円です。'],
            '台数' => ['3台で売られています。'],
        ];
    }

    #[DataProvider('modelTexts')]
    public function test_モデルがどんな文章を返しても回答は根拠の行だけになる(string $text): void
    {
        $this->fakeAnswer($text, [$this->cite(1, 3, 120), $this->cite(2, 2, 160)]);

        $r = $this->generate();

        $this->assertSame(
            AnswerGenerator::LEAD . "\n"
            . "・ウィルキンソン タンサン:自販機1で120円(今日の情報)\n"
            . '・コカ・コーラ:自販機2で160円(昨日の情報)',
            $r['answer'],
        );
    }

    public function test_回答に含まれる自販機と価格はすべてcitationsの行だけ(): void
    {
        $this->fakeAnswer('九番の店がおすすめです。', [$this->cite(1, 3, 120), $this->cite(3, 3, 130)]);

        $r = $this->generate();

        preg_match_all('/自販機(\d+)/u', $r['answer'], $machines);
        preg_match_all('/(\d+)円/u', $r['answer'], $prices);

        $this->assertSame(['1', '3'], $machines[1]);
        $this->assertSame(['120', '130'], $prices[1]);
        $this->assertStringNotContainsString('九番', $r['answer']);
    }

    // ---- citations の検証 ----

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

    public function test_自販機と価格を入れ替えたcitationsは失敗にする(): void
    {
        $this->fakeAnswer('炭酸です。', [$this->cite(1, 3, 160), $this->cite(2, 2, 120)]);

        $this->assertSame('unsupported_citation', $this->failure()->reason);
    }

    public function test_根拠にない自販機のcitationsは失敗にする(): void
    {
        $this->fakeAnswer('炭酸です。', [$this->cite(9, 3, 120)]);

        $this->assertSame('unsupported_citation', $this->failure()->reason);
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
            $this->generate([]);
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

        $this->generate();

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

        $this->generate();

        Http::assertSent(fn ($r) => ! isset($r['chat_template_kwargs'])
            && ! isset($r['response_format']));
    }
}