<?php

namespace App\Console\Commands;

use App\Services\Rag\EvidenceRetriever;
use Illuminate\Console\Command;
use Throwable;

class ShowEvidence extends Command
{
    protected $signature = 'rag:evidence {question : 質問の文章}';

    protected $description = '質問から集めた根拠(AI に渡す行)を表示する。生成は呼ばない(しきい値の確認用)';

    public function handle(EvidenceRetriever $retriever): int
    {
        $question = trim((string) $this->argument('question'));
        if ($question === '') {
            $this->error('質問を入力してください。');

            return self::FAILURE;
        }

        try {
            $result = $retriever->retrieve($question);
        } catch (Throwable $e) {
            $this->error('根拠を集められませんでした:'.$e->getMessage());

            return self::FAILURE;
        }

        $topScore = $result['top_score'] === null ? 'なし(索引が空)' : number_format($result['top_score'], 3);
        $this->line("1位の score:{$topScore}");
        $this->line('しきい値を超えた商品ID:'.($result['product_ids'] === [] ? 'なし' : implode(', ', $result['product_ids'])));

        if (! EvidenceRetriever::found($result)) {
            $this->warn('根拠は0行です(登録されたデータからは確認できません)。');

            return self::SUCCESS;
        }

        $this->table(
            ['自販機', '商品', '価格', '温冷', '記録日', '何日前', '説明'],
            array_map(fn (array $row) => [
                $row['machine_id'],
                $row['product_name'],
                "{$row['price']}円",
                $row['temperature'] ?? '不明',
                $row['recorded_at'],
                $row['days_ago'],
                mb_strimwidth((string) $row['description'], 0, 40, '…'),
            ], $result['evidence']),
        );

        return self::SUCCESS;
    }
}
