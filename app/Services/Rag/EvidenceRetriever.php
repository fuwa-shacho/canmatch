<?php

namespace App\Services\Rag;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 質問から根拠のデータを集める(Issue 64)
 *
 * ① 質問を数値化し、索引(Vectorize)から似た商品を上位 topK 件取り出す
 * ② 似ている度合い(score)がしきい値未満の商品を捨てる。1位がしきい値未満なら、飲み物と関係のない質問とみなす
 * ③ 残った商品を扱う自販機・価格・記録日をデータベースから取り出す
 * ④ 価格の安い順に並べ、AI に渡す件数を絞る
 *
 * 根拠が0行のときは、生成(65)を呼ばずに「登録されたデータからは確認できません」と返すため、
 * 呼び出し側は found() が false なら生成を呼ばない。
 *
 * 数値化・検索で Cloudflare が失敗したときは、例外をそのまま投げる(呼び出し側で「いまは質問を受け付けられません」にする)。
 */
class EvidenceRetriever
{
    public function __construct(
        private EmbeddingService $embedding,
        private VectorIndex $index,
    ) {}

    /**
     * @return array{
     *     evidence: array<int, array{
     *         machine_id: int,
     *         product_id: int,
     *         product_name: string,
     *         volume_ml: int|null,
     *         description: string|null,
     *         price: int,
     *         temperature: string|null,
     *         recorded_at: string,
     *         days_ago: int
     *     }>,
     *     product_ids: int[],
     *     top_score: float|null
     * }
     *
     *  - evidence: AI に渡す根拠の行(価格の安い順、最大 max_evidence 行)
     *  - product_ids: しきい値を超えた商品ID(score の高い順)。記録用
     *  - top_score: 1位の score。索引が空なら null
     */
    public function retrieve(string $question): array
    {
        $question = trim($question);
        $settings = $this->settings();

        // ① 質問を数値化し、似た商品を取り出す
        $vector = $this->embedding->embedOne($question);
        $matches = $this->index->query($vector, $settings['top_k']);

        $topScore = $this->topScore($matches);

        // ② しきい値未満を捨てる(1位が未満なら、残る商品は0件になる)
        $productIds = $this->productIdsAboveThreshold($matches, $settings['threshold']);

        if ($productIds === []) {
            return ['evidence' => [], 'product_ids' => [], 'top_score' => $topScore];
        }

        // ③④ 自販機・価格・記録日を取り出し、安い順に絞る
        return [
            'evidence' => $this->evidenceRows($productIds, $settings['max_evidence']),
            'product_ids' => $productIds,
            'top_score' => $topScore,
        ];
    }

    /**
     * 根拠が1行以上あるか(なければ生成を呼ばない)
     */
    public static function found(array $result): bool
    {
        return ($result['evidence'] ?? []) !== [];
    }

    /**
     * @param  array<int, mixed>  $matches
     */
    private function topScore(array $matches): ?float
    {
        $scores = [];
        foreach ($matches as $match) {
            if (is_array($match) && isset($match['score']) && is_numeric($match['score'])) {
                $scores[] = (float) $match['score'];
            }
        }

        return $scores === [] ? null : max($scores);
    }

    /**
     * score がしきい値以上の商品IDを、score の高い順に返す(同じIDの重複は1つにまとめる)
     *
     * @param  array<int, mixed>  $matches
     * @return int[]
     */
    private function productIdsAboveThreshold(array $matches, float $threshold): array
    {
        $scored = [];
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['id'], $match['score']) || ! is_numeric($match['score'])) {
                continue;
            }

            $id = filter_var($match['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $score = (float) $match['score'];

            if ($id === false || $score < $threshold) {
                continue;
            }

            if (! isset($scored[$id]) || $scored[$id] < $score) {
                $scored[$id] = $score;
            }
        }

        arsort($scored);

        return array_keys($scored);
    }

    /**
     * 商品を扱う自販機の行を、価格の安い順に最大 $limit 行取り出す
     *
     * 非表示の自販機は除く:非表示の列は Issue 10 で追加される。追加されたら、ここに条件を足す。
     *
     * @param  int[]  $productIds
     */
    private function evidenceRows(array $productIds, int $limit): array
    {
        $rows = DB::table('machine_products')
            ->join('products', 'products.id', '=', 'machine_products.product_id')
            ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
            ->whereNull('vending_machines.hidden_at')
            ->whereIn('machine_products.product_id', $productIds)
            ->orderBy('machine_products.price')
            ->orderByDesc('machine_products.recorded_at')
            ->orderBy('machine_products.vending_machine_id')
            ->orderBy('machine_products.id')
            ->limit($limit)
            ->get([
                'machine_products.vending_machine_id as machine_id',
                'machine_products.product_id',
                'products.name as product_name',
                'products.volume_ml',
                'products.description',
                'machine_products.price',
                'machine_products.temperature',
                'machine_products.recorded_at',
            ]);

        $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();

        return $rows->map(function (object $row) use ($today): array {
            // 記録日時は UTC で保存されている。日付と「何日前」は日本時間で数える
            $recordedAt = CarbonImmutable::parse($row->recorded_at, 'UTC')->setTimezone('Asia/Tokyo');

            return [
                'machine_id' => (int) $row->machine_id,
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'volume_ml' => $row->volume_ml === null ? null : (int) $row->volume_ml,
                'description' => $row->description,
                'price' => (int) $row->price,
                'temperature' => $row->temperature,
                'recorded_at' => $recordedAt->format('Y-m-d'),
                'days_ago' => max(0, (int) $recordedAt->startOfDay()->diffInDays($today)),
            ];
        })->values()->all();
    }

    /**
     * @return array{threshold: float, top_k: int, max_evidence: int}
     */
    private function settings(): array
    {
        $c = config('services.cloudflare');

        return [
            'threshold' => (float) ($c['rag_score_threshold'] ?? 0.45),
            'top_k' => max(1, min(100, (int) ($c['rag_top_k'] ?? 10))),
            'max_evidence' => max(1, (int) ($c['rag_max_evidence'] ?? 20)),
        ];
    }
}
