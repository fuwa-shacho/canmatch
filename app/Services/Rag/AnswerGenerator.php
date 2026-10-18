<?php

namespace App\Services\Rag;

use App\Exceptions\RagGenerationFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnswerGenerator
{
    /** 回答の1行目(サーバーが決める固定の前置き。モデルの文章は使わない) */
    public const LEAD = '条件に合う商品は次のとおりです。';

    /** 条件に合う行が1つもないときの回答 */
    public const NO_MATCH = '登録されたデータからは条件に合う商品を確認できません。';

    /**
     * 質問と根拠から回答を作る
     *
     * モデルには「紹介する根拠の行(citations)」だけを返させる。モデルの文章は使わない。
     * 回答は、固定の前置きと、自販機・価格を含む一覧(サーバーが根拠の行から組み立てる)だけなので、
     * 回答に出てくる自販機と価格(とその組み合わせ)は、必ず根拠の行と一致する。
     *
     * @param  array<int, array{machine_id: int, product_id: int, price: int, product_name?: string, days_ago?: int}>  $evidence  64 で作る根拠の一覧
     * @return array{
     *     answer: string,
     *     citations: array<int, array{machine_id: int, product_id: int, price: int}>,
     *     machine_ids: int[],
     *     model: string,
     *     prompt_version: string,
     *     raw: mixed
     * }
     *
     * @throws RagGenerationFailedException
     */
    public function generate(string $question, array $evidence): array
    {
        if ($evidence === []) {
            $this->fail('no_evidence', '根拠がありません');
        }

        $c = config('services.cloudflare');
        $url = "https://api.cloudflare.com/client/v4/accounts/{$c['account_id']}/ai/run/{$c['generation_model']}";

        // ① 生成モデルを呼ぶ
        try {
            $response = Http::withToken($c['api_token'])
                ->timeout((int) $c['timeout'])
                ->post($url, $this->buildBody($question, $evidence, $c))
                ->throw();
        } catch (ConnectionException $e) {
            $this->fail('timeout', '生成モデルに接続できませんでした', $e);
        } catch (RequestException $e) {
            $status = $e->response->status();
            $this->fail($status === 429 ? 'rate_limit' : 'request_failed', "生成モデルがエラーを返しました(HTTP {$status})", $e, $e->response->body());
        }

        // ② 応答からJSONを取り出す(使うのは citations だけ。モデルの文章は読まない)
        $raw = $response->json();
        $data = $this->extractJson($raw);
        if (! is_array($data)) {
            $this->fail('invalid_response', '生成モデルの応答を読めませんでした', null, json_encode($raw, JSON_UNESCAPED_UNICODE));
        }

        // ③ citations(選んだ根拠の行)が、根拠の行と完全に一致するか
        $citations = $this->validateCitations(
            $data['citations'] ?? null,
            $evidence,
            (string) json_encode($data['citations'] ?? null, JSON_UNESCAPED_UNICODE),
        );

        // ④ 回答は、固定の前置きと、選ばれた根拠の行だけからサーバーで組み立てる
        $answer = $this->buildAnswer($citations, $evidence);

        return [
            'answer' => $answer,
            'citations' => $citations,
            'machine_ids' => array_values(array_unique(array_column($citations, 'machine_id'))),
            'model' => $c['generation_model'],
            'prompt_version' => $c['answer_prompt'],
            'raw' => $raw,
        ];
    }

    /**
     * citations の各行が、根拠の一覧の行(自販機ID・商品ID・価格の組)と一致するか確かめ、
     * 型をそろえて、根拠の一覧の順(64 で価格の安い順にする)で返す
     *
     * @return array<int, array{machine_id: int, product_id: int, price: int}>
     */
    private function validateCitations(mixed $citations, array $evidence, string $answer): array
    {
        if (! is_array($citations) || ! array_is_list($citations)) {
            $this->fail('invalid_response', '回答に使った根拠の行(citations)がありません', null, $answer);
        }

        // 根拠の行を「自販機ID-商品ID-価格」の組で持つ
        $evidenceKeys = [];
        foreach ($evidence as $row) {
            $evidenceKeys[$this->rowKey($row)] = true;
        }

        $result = [];
        foreach ($citations as $item) {
            if (! is_array($item) || ! isset($item['machine_id'], $item['product_id'], $item['price'])
                || ! is_numeric($item['machine_id']) || ! is_numeric($item['product_id']) || ! is_numeric($item['price'])) {
                $this->fail('invalid_response', 'citations の行の形が想定と違います', null, json_encode($citations, JSON_UNESCAPED_UNICODE));
            }

            $row = [
                'machine_id' => (int) $item['machine_id'],
                'product_id' => (int) $item['product_id'],
                'price' => (int) $item['price'],
            ];

            if (! isset($evidenceKeys[$this->rowKey($row)])) {
                $this->fail(
                    'unsupported_citation',
                    "根拠にない組み合わせが citations に含まれていました(自販機{$row['machine_id']}・商品{$row['product_id']}・{$row['price']}円)",
                    null,
                    $answer,
                );
            }

            $result[$this->rowKey($row)] = $row; // 同じ行の重複は1つにまとめる
        }

        // 根拠の一覧の順に並べ直す(AI が返した順ではなく、64 で決めた安い順にする)
        $ordered = [];
        foreach ($evidence as $evidenceRow) {
            $key = $this->rowKey($evidenceRow);
            if (isset($result[$key]) && ! isset($ordered[$key])) {
                $ordered[$key] = $result[$key];
            }
        }

        return array_values($ordered);
    }

    /**
     * 固定の前置きの後ろに、citations の行を「・商品名:自販機○で△△円(情報の新しさ)」の形で並べる
     *
     * 自販機・商品名・価格・日数は、すべて根拠の行から取る(モデルの出力は使わない)。
     */
    private function buildAnswer(array $citations, array $evidence): string
    {
        if ($citations === []) {
            return self::NO_MATCH;
        }

        $evidenceByKey = [];
        foreach ($evidence as $row) {
            $evidenceByKey[$this->rowKey($row)] = $row;
        }

        $lines = [self::LEAD];
        foreach ($citations as $cite) {
            $row = $evidenceByKey[$this->rowKey($cite)];
            $name = $row['product_name'] ?? "商品{$row['product_id']}";
            $line = "・{$name}:自販機{$row['machine_id']}で{$row['price']}円";
            if (isset($row['days_ago']) && is_numeric($row['days_ago'])) {
                $line .= '(' . $this->freshness((int) $row['days_ago']) . ')';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function freshness(int $daysAgo): string
    {
        return match (true) {
            $daysAgo <= 0 => '今日の情報',
            $daysAgo === 1 => '昨日の情報',
            default => "{$daysAgo}日前の情報",
        };
    }

    private function rowKey(array $row): string
    {
        return (int) ($row['machine_id'] ?? 0) . '-' . (int) ($row['product_id'] ?? 0) . '-' . (int) ($row['price'] ?? 0);
    }

    /**
     * 生成モデルに送る本文を作る
     */
    private function buildBody(string $question, array $evidence, array $c): array
    {
        $body = [
            'messages' => [
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => $this->userMessage($question, $evidence)],
            ],
            'max_completion_tokens' => (int) $c['max_tokens'],
            'temperature' => 0.2,
        ];

        // 考える機能をオフにする(オンだと、考える分のトークンを使い、答えまで届かないことがある)
        if (filter_var($c['disable_thinking'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $body['chat_template_kwargs'] = ['enable_thinking' => false];
        }

        // JSONで返させる
        if (filter_var($c['json_mode'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
    }

    private function userMessage(string $question, array $evidence): string
    {
        return "質問:{$question}\n\n根拠の一覧(JSON):\n"
            . json_encode(array_values($evidence), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function prompt(): string
    {
        $name = config('services.cloudflare.answer_prompt');

        return file_get_contents(resource_path("prompts/{$name}.md"));
    }

    /**
     * モデルによって応答の形が違うので、どちらにも対応する
     */
    private function extractJson(?array $body): ?array
    {
        $result = $body['result'] ?? null;
        $content = $result['response'] ?? ($result['choices'][0]['message']['content'] ?? null);

        if (is_array($content)) {
            return $content; // すでにJSONとして解釈されている場合
        }
        if (! is_string($content)) {
            return null;
        }

        // ```json ... ``` で囲まれていても、最初の { から最後の } までを取り出す
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function fail(string $reason, string $message, ?Throwable $previous = null, ?string $detail = null): never
    {
        Log::warning('回答の生成に失敗', [
            'reason' => $reason,
            'model' => config('services.cloudflare.generation_model'),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ]);

        throw new RagGenerationFailedException($reason, $message, $previous);
    }
}