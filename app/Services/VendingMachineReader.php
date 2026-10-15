<?php

namespace App\Services;

use App\Exceptions\AiReadFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VendingMachineReader
{
    /**
     * 写真を読み取り、商品の一覧などを返す
     *
     * @throws AiReadFailedException AIの呼び出しや応答の読み取りに失敗したとき
     */
    public function read(string $path, string $disk = 'local'): array
    {
        // ① 写真を読み込む(ここの失敗はAIのせいではないので、そのままの例外)
        $image = Storage::disk($disk)->get($path);
        if ($image === null) {
            throw new RuntimeException("写真が見つかりません: {$path}");
        }
        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';

        // ② 呼び出し先のURLを組み立てる
        $config = config('services.gemini');
        $url = rtrim($config['base_url'], '/') . "/models/{$config['model']}:generateContent";

        // ③ Geminiを呼ぶ(429は1回だけ再試行する)
        $response = $this->send($url, $this->buildBody($image, $mimeType));

        // ④ 応答からJSONの文字列を取り出して、配列にする
        $text = $response->json('candidates.0.content.parts.0.text');
        $data = json_decode($text ?? '', true);
        if (! is_array($data)) {
            $this->fail('invalid_response', null, 'AIの応答をJSONとして読めませんでした', $text);
        }

        // ⑤ 結果と、記録に使う情報をまとめて返す
        return [
            'result' => $this->normalize($data),
            'raw' => $text,
            'model' => $config['model'],
            'prompt_version' => $config['prompt'],
            'gateway_log_id' => $response->header('cf-aig-log-id') ?: null,
        ];
    }

    /**
     * Geminiにリクエストを送る。429(上限超過)のときだけ、待ってから1回再試行する
     */
    private function send(string $url, array $body): Response
    {
        $config = config('services.gemini');
        $maxAttempts = 2; // 最初の1回 + 再試行1回

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Http::timeout((int) $config['timeout'])
                    ->withHeaders(['x-goog-api-key' => $config['key']])
                    ->post($url, $body)
                    ->throw();
            } catch (ConnectionException $e) {
                // 接続できない、または時間切れ
                $this->fail('timeout', null, 'AIに接続できませんでした', $e->getMessage(), $e);
            } catch (RequestException $e) {
                $status = $e->response->status();

                // 429で、まだ再試行していなければ、待ってもう一度
                if ($status === 429 && $attempt < $maxAttempts) {
                    Log::info('AI読み取りが上限超過のため再試行します', ['wait' => $config['retry_wait']]);
                    sleep((int) $config['retry_wait']);
                    continue;
                }

                $reason = match (true) {
                    $status === 429 => 'rate_limit',
                    $status === 404 => 'model_not_found',
                    $status >= 500 => 'server_error',
                    default => 'request_rejected', // 400, 401, 403 など(キーやリクエストの問題)
                };

                $this->fail($reason, $status, "AIがエラーを返しました(HTTP {$status})", $e->response->body(), $e);
            }
        }

        // ここには来ない(ループの中で必ず return か例外になる)
        $this->fail('request_rejected', null, 'AIの呼び出しに失敗しました');
    }

    /**
     * 失敗をログに残して、専用の例外を投げる
     *
     * ログに残すのは種類、ステータス、モデル名、応答の冒頭だけ。
     * APIキーはヘッダーで送っているので出ない。写真(base64)も出さない。
     */
    private function fail(
        string $reason,
        ?int $status,
        string $message,
        ?string $detail = null,
        ?Throwable $previous = null,
    ): never {
        Log::warning('AI読み取りに失敗', [
            'reason' => $reason,
            'status' => $status,
            'model' => config('services.gemini.model'),
            'prompt' => config('services.gemini.prompt'),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ]);

        throw new AiReadFailedException($reason, $message, $status, $previous);
    }

    /**
     * Geminiに送る本文を作る
     */
    private function buildBody(string $image, string $mimeType): array
    {
        $generationConfig = [
            'responseMimeType' => 'application/json',
        ];

        // 思考の量は、設定したときだけ送る(モデルによって受け付ける値が違うため)
        $thinkingLevel = config('services.gemini.thinking_level');
        if (! empty($thinkingLevel)) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $thinkingLevel];
        }

        return [
            'contents' => [[
                'parts' => [
                    ['inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($image),
                    ]],
                    ['text' => $this->prompt()],
                ],
            ]],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * 指示文のファイルを読む
     */
    private function prompt(): string
    {
        $name = config('services.gemini.prompt');

        return file_get_contents(resource_path("prompts/{$name}.md"));
    }

    /**
     * AIの結果の形をそろえる(想定外の値を null にする)
     */
    private function normalize(array $data): array
    {
        $isVendingMachine = (bool) ($data['is_vending_machine'] ?? false);

        $products = [];
        foreach ($data['products'] ?? [] as $item) {
            $name = isset($item['name']) && $item['name'] !== '' ? (string) $item['name'] : null;
            $price = is_numeric($item['price'] ?? null) ? (int) $item['price'] : null;
            $temperature = in_array($item['temperature'] ?? null, ['hot', 'cold'], true)
                ? $item['temperature']
                : null;

            if ($name === null && $price === null) {
                continue; // 名前も価格も読めない行は捨てる
            }

            $products[] = compact('name', 'price', 'temperature');
        }

        return [
            'is_vending_machine' => $isVendingMachine,
            'maker' => $data['maker'] ?? null,
            // 自販機でないなら、AIが何か返していても商品は空にする
            'products' => $isVendingMachine ? $products : [],
        ];
    }
}