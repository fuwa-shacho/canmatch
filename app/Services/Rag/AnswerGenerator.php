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
    /**
     * 質問と根拠から回答を作る
     *
     * @param  array<int, array{machine_id: int, product_id: int, price: int}>  $evidence  64 で作る根拠の一覧
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

        // ② 応答からJSONを取り出す
        $raw = $response->json();
        $data = $this->extractJson($raw);
        if (! is_array($data) || ! is_string($data['answer'] ?? null) || trim($data['answer']) === '') {
            $this->fail('invalid_response', '生成モデルの応答を読めませんでした', null, json_encode($raw, JSON_UNESCAPED_UNICODE));
        }
        $answer = trim($data['answer']);

        // ③ citations(回答に使った根拠の行)が、根拠の行と完全に一致するか
        $citations = $this->validateCitations($data['citations'] ?? null, $evidence, $answer);

        // ④ 本文の自販機・価格・その組み合わせが、citations と一致するか
        $this->validateAnswerText($answer, $citations);

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
     * citations の各行が、根拠の一覧の行(自販機ID・商品ID・価格の組)と一致するか確かめ、型をそろえて返す
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

        return array_values($result);
    }

    /**
     * 本文に書かれた自販機・価格・その組み合わせが、すべて citations にあるか確かめる
     */
    private function validateAnswerText(string $answer, array $citations): void
    {
        $citedMachines = array_column($citations, 'machine_id');
        $citedPrices = array_column($citations, 'price');
        $citedPairs = [];
        foreach ($citations as $row) {
            $citedPairs[$row['machine_id'] . '-' . $row['price']] = true;
        }

        // 本文の「自販機○」
        preg_match_all('/自販機\s*(\d+)/u', $answer, $m);
        foreach ($m[1] as $id) {
            if (! in_array((int) $id, $citedMachines, true)) {
                $this->fail('unsupported_machine', "根拠にない自販機が回答に含まれていました(自販機{$id})", null, $answer);
            }
        }

        // 本文の「○○円」
        preg_match_all('/(\d[\d,]*)\s*円/u', $answer, $m);
        foreach ($m[1] as $price) {
            if (! in_array($this->toInt($price), $citedPrices, true)) {
                $this->fail('unsupported_price', "根拠にない価格が回答に含まれていました({$price}円)", null, $answer);
            }
        }

        // 本文の「自販機○ … △△円」の組(同じ文の中で、間に別の「自販機」を挟まないもの)
        preg_match_all('/自販機\s*(\d+)(?:(?!自販機)[^。])*?(\d[\d,]*)\s*円/u', $answer, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $pair = (int) $match[1] . '-' . $this->toInt($match[2]);
            if (! isset($citedPairs[$pair])) {
                $this->fail('unsupported_combination', "根拠にない自販機と価格の組み合わせが回答に含まれていました(自販機{$match[1]}・{$match[2]}円)", null, $answer);
            }
        }
    }

    private function rowKey(array $row): string
    {
        return (int) ($row['machine_id'] ?? 0) . '-' . (int) ($row['product_id'] ?? 0) . '-' . (int) ($row['price'] ?? 0);
    }

    private function toInt(string $number): int
    {
        return (int) str_replace(',', '', $number);
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