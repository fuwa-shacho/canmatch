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
     * @param  array<int, array{machine_id: int, price: int}>  $evidence  64 で作る根拠の一覧
     * @return array{answer: string, machine_ids: int[], model: string, prompt_version: string, raw: mixed}
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

        // ③ 自販機のIDは、根拠にあるものだけ残す
        $allowedIds = array_map('intval', array_column($evidence, 'machine_id'));
        $machineIds = array_values(array_unique(array_intersect(
            array_map('intval', (array) ($data['machine_ids'] ?? [])),
            $allowedIds,
        )));

        // ④ 回答の中の「○○円」が、すべて根拠の価格にあるか
        $allowedPrices = array_map('intval', array_column($evidence, 'price'));
        preg_match_all('/(\d[\d,]*)\s*円/u', $answer, $m);
        foreach ($m[1] as $p) {
            if (! in_array((int) str_replace(',', '', $p), $allowedPrices, true)) {
                $this->fail('unsupported_price', "根拠にない価格が回答に含まれていました({$p}円)", null, $answer);
            }
        }

        return [
            'answer' => $answer,
            'machine_ids' => $machineIds,
            'model' => $c['generation_model'],
            'prompt_version' => $c['answer_prompt'],
            'raw' => $raw,
        ];
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