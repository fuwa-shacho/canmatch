<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VendingMachineReader
{
    /**
     * 写真を読み取り、商品の一覧などを返す
     */
    public function read(string $path, string $disk = 'local'): array
    {
        // ① 写真を読み込む
        $image = Storage::disk($disk)->get($path);
        if ($image === null) {
            throw new RuntimeException("写真が見つかりません: {$path}");
        }
        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';

        // ② 呼び出し先のURLを組み立てる
        $config = config('services.gemini');
        $url = rtrim($config['base_url'], '/') . "/models/{$config['model']}:generateContent";

        // ③ Geminiを呼ぶ
        $response = Http::timeout((int) $config['timeout'])
            ->withHeaders(['x-goog-api-key' => $config['key']])
            ->post($url, $this->buildBody($image, $mimeType))
            ->throw();

        // ④ 応答からJSONの文字列を取り出して、配列にする
        $text = $response->json('candidates.0.content.parts.0.text');
        $data = json_decode($text ?? '', true);
        if (! is_array($data)) {
            throw new RuntimeException('AIの応答をJSONとして読めませんでした');
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
     * Geminiに送る本文を作る
     */
    private function buildBody(string $image, string $mimeType): array
    {
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
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => [
                    'thinkingLevel' => config('services.gemini.thinking_level'),
                ],
            ],
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
            'is_vending_machine' => (bool) ($data['is_vending_machine'] ?? false),
            'maker' => $data['maker'] ?? null,
            'products' => $products,
        ];
    }
}