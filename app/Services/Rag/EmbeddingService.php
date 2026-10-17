<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmbeddingService
{
    /**
     * 文章の配列を、数の並び(ベクトル)の配列にする
     *
     * @param  string[]  $texts
     * @return array<int, float[]>  $texts と同じ順番
     */
    public function embed(array $texts): array
    {
        $c = config('services.cloudflare');
        $url = "https://api.cloudflare.com/client/v4/accounts/{$c['account_id']}/ai/run/{$c['embedding_model']}";

        $response = Http::withToken($c['api_token'])
            ->timeout((int) $c['timeout'])
            ->post($url, ['text' => array_values($texts)])
            ->throw();

        $vectors = $response->json('result.data');

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new RuntimeException('埋め込みの応答の形が想定と違います');
        }

        return $vectors;
    }

    /**
     * 1つの文章をベクトルにする(質問用)
     */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0];
    }
}