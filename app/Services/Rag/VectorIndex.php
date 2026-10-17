<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

class VectorIndex
{
    private function url(string $action): string
    {
        $c = config('services.cloudflare');

        return "https://api.cloudflare.com/client/v4/accounts/{$c['account_id']}/vectorize/v2/indexes/{$c['vectorize_index']}/{$action}";
    }

    private function http()
    {
        $c = config('services.cloudflare');

        return Http::withToken($c['api_token'])->timeout((int) $c['timeout']);
    }

    /**
     * 登録する(同じIDなら上書き)
     *
     * @param  array<int, array{id: string, values: float[], metadata?: array}>  $vectors
     * @return string|null  受付ID(反映は数秒後)
     */
    public function upsert(array $vectors): ?string
    {
        // Vectorize は1行に1件の JSON(NDJSON)で受け付ける
        $ndjson = collect($vectors)
            ->map(fn (array $v) => json_encode($v, JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        return $this->http()
            ->withBody($ndjson, 'application/x-ndjson')
            ->post($this->url('upsert'))
            ->throw()
            ->json('result.mutationId');
    }

    /**
     * 似ているものを上から topK 件探す
     *
     * @return array<int, array{id: string, score: float}>
     */
    public function query(array $vector, int $topK = 10): array
    {
        return $this->http()
            ->post($this->url('query'), [
                'vector' => $vector,
                'topK' => $topK,
                'returnValues' => false,
                'returnMetadata' => 'none',
            ])
            ->throw()
            ->json('result.matches') ?? [];
    }

    /**
     * IDを指定して消す
     *
     * @param  array<int, string|int>  $ids
     */
    public function delete(array $ids): ?string
    {
        return $this->http()
            ->post($this->url('delete_by_ids'), ['ids' => array_map('strval', $ids)])
            ->throw()
            ->json('result.mutationId');
    }
}