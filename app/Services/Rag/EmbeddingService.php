<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class EmbeddingService
{
    use ValidatesVectors;

    /**
     * 文章の配列を、数の並び(ベクトル)の配列にする
     *
     * @param  string[]  $texts
     * @return array<int, float[]>  $texts と同じ順番
     *
     * @throws InvalidArgumentException  渡した文章が空のとき(Cloudflare は呼ばない)
     * @throws RuntimeException  応答の形が想定と違うとき
     */
    public function embed(array $texts): array
    {
        // ① 入力を確かめる(無料枠を無駄に使わないよう、送る前に止める)
        $texts = array_values($texts);
        if ($texts === []) {
            throw new InvalidArgumentException('数値化する文章がありません');
        }
        foreach ($texts as $i => $text) {
            if (! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException("{$i}番目の文章が空です");
            }
        }

        // ② Workers AI を呼ぶ
        $c = config('services.cloudflare');
        $url = "https://api.cloudflare.com/client/v4/accounts/{$c['account_id']}/ai/run/{$c['embedding_model']}";

        $response = Http::withToken($c['api_token'])
            ->timeout((int) $c['timeout'])
            ->post($url, ['text' => $texts])
            ->throw();

        // ③ 応答を確かめる
        $vectors = $response->json('result.data');

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new RuntimeException('埋め込みの応答の形が想定と違います');
        }
        foreach ($vectors as $i => $vector) {
            if (! $this->isNumericVector($vector)) {
                throw new RuntimeException("埋め込みの応答の{$i}番目が、空か数値の並びではありません");
            }
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