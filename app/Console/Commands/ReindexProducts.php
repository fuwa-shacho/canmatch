<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Rag\ProductIndexer;
use App\Services\Rag\VectorIndex;
use Illuminate\Console\Command;
use Throwable;

class ReindexProducts extends Command
{
    protected $signature = 'rag:reindex';

    protected $description = '全商品の検索用の文章を数値化して、索引(Vectorize)に登録し直す';

    public function handle(ProductIndexer $indexer, VectorIndex $index): int
    {
        $total = Product::count();
        if ($total === 0) {
            $this->warn('商品が1件もありません。先に db:seed --class=ProductSeeder を実行してください。');

            return self::SUCCESS;
        }

        $this->info("{$total} 件の商品を登録します…");

        try {
            $done = $indexer->reindexAll(fn (int $done) => $this->line("  {$done} / {$total}"));
        } catch (Throwable $e) {
            $this->error('登録に失敗しました:'.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$done} 件を登録しました。");

        // 反映には数秒〜1分かかるので、件数はあくまで目安
        try {
            $this->line('索引の件数(反映待ちの分は含まれないことがあります):'.$index->count());
        } catch (Throwable) {
            $this->line('索引の件数は取得できませんでした。');
        }

        return self::SUCCESS;
    }
}