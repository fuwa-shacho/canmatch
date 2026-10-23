<?php

namespace App\Services\Rag;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 商品の検索用の文章を数値化して、索引(Vectorize)に登録する(Issue 63)
 *
 * ベクトルのIDは商品IDにする。同じ商品を登録し直すと上書きされ、件数は増えない。
 */
class ProductIndexer
{
    /** 1回の数値化で送る商品の数(多すぎると Workers AI に断られるので小分けにする) */
    public const BATCH_SIZE = 20;

    public function __construct(
        private EmbeddingService $embedding,
        private VectorIndex $index,
    ) {}

    /**
     * 全商品を登録し直す(説明やモデルを変えたとき、登録に失敗した商品があるときに使う)
     *
     * @param  (callable(int $done): void)|null  $onProgress  進み具合を知らせる(コマンドの表示用)
     * @return int  登録した商品の数
     */
    public function reindexAll(?callable $onProgress = null): int
    {
        $done = 0;

        Product::query()->orderBy('id')->chunkById(self::BATCH_SIZE, function ($products) use (&$done, $onProgress) {
            $this->upsert($products->all());
            $done += $products->count();

            if ($onProgress) {
                $onProgress($done);
            }
        });

        return $done;
    }

    /**
     * 1つの商品を登録する(投稿で商品を追加・更新したときに使う。45 からつなぐ)
     *
     * 失敗しても例外は投げずに記録だけ残し、false を返す。投稿そのものは成功させるため。
     * 登録できなかった商品は、あとで rag:reindex を実行すれば登録される。
     */
    public function indexQuietly(Product $product): bool
    {
        try {
            $this->upsert([$product]);

            return true;
        } catch (Throwable $e) {
            Log::warning('商品の索引への登録に失敗', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  Product[]  $products
     */
    private function upsert(array $products): void
    {
        if ($products === []) {
            return;
        }

        // ① 検索用の文章を作る(62 の searchText)
        $texts = array_map(fn (Product $p) => $p->searchText(), $products);

        // ② まとめて数値化する(文章と同じ順番でベクトルが返る)
        $vectors = $this->embedding->embed($texts);

        // ③ 商品IDをベクトルのIDにして登録する
        $rows = [];
        foreach ($products as $i => $product) {
            $rows[] = ['id' => (string) $product->id, 'values' => $vectors[$i]];
        }

        $this->index->upsert($rows);
    }
}