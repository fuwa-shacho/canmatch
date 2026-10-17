<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class VectorIndex
{
    use ValidatesVectors;

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
     * @param  array<int, array{id: string|int, values: float[], metadata?: array}>  $vectors
     * @return string|null  受付ID(反映は数秒〜1分後)
     *
     * @throws InvalidArgumentException  空、IDがない、ベクトルが数値の並びでないとき(Cloudflare は呼ばない)
     */
    public function upsert(array $vectors): ?string
    {
        if ($vectors === []) {
            throw new InvalidArgumentException('登録するベクトルがありません');
        }

        $lines = [];
        foreach (array_values($vectors) as $i => $v) {
            $id = $v['id'] ?? null;
            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                throw new InvalidArgumentException("{$i}番目のIDが空です");
            }
            if (! $this->isNumericVector($v['values'] ?? null)) {
                throw new InvalidArgumentException("{$i}番目のベクトルが、空か数値の並びではありません");
            }

            $v['id'] = (string) $id; // Vectorize のIDは文字列
            $lines[] = json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        // Vectorize は1行に1件の JSON(NDJSON)で受け付ける
        return $this->http()
            ->withBody(implode("\n", $lines), 'application/x-ndjson')
            ->post($this->url('upsert'))
            ->throw()
            ->json('result.mutationId');
    }

    /**
     * 似ているものを上から topK 件探す
     *
     * @return array<int, array{id: string, score: float}>
     *
     * @throws InvalidArgumentException  ベクトルが数値の並びでない、topK が範囲外のとき
     */
    public function query(array $vector, int $topK = 10): array
    {
        if (! $this->isNumericVector($vector)) {
            throw new InvalidArgumentException('検索に使うベクトルが、空か数値の並びではありません');
        }
        if ($topK < 1 || $topK > 100) {
            throw new InvalidArgumentException('topK は 1〜100 で指定してください');
        }

        $matches = $this->http()
            ->post($this->url('query'), [
                'vector' => $vector,
                'topK' => $topK,
                'returnValues' => false,
                'returnMetadata' => 'none',
            ])
            ->throw()
            ->json('result.matches');

        return is_array($matches) ? $matches : [];
    }

    /**
     * IDを指定して消す
     *
     * @param  array<int, string|int>  $ids
     *
     * @throws InvalidArgumentException  IDが空のとき
     */
    public function delete(array $ids): ?string
    {
        $ids = array_values(array_filter(
            array_map(fn ($id) => trim((string) $id), $ids),
            fn (string $id) => $id !== '',
        ));
        if ($ids === []) {
            throw new InvalidArgumentException('削除するIDがありません');
        }

        return $this->http()
            ->post($this->url('delete_by_ids'), ['ids' => $ids])
            ->throw()
            ->json('result.mutationId');
    }
}