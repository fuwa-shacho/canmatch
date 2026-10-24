<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Evals\RagScorer;
use App\Services\Rag\AnswerGenerator;
use Illuminate\Console\Command;
use Throwable;

class EvalRag extends Command
{
    protected $signature = 'eval:rag {--dataset=evals/rag-questions.json} {--live : Cloudflareを呼んで評価する} {--save= : 評価結果をJSONで保存}';

    protected $description = 'RAGの検索・回答を10問の正解セットで別々に評価する';

    public function handle(RagScorer $scorer): int
    {
        try {
            $path = base_path((string) $this->option('dataset'));
            $dataset = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $cases = $dataset['questions'] ?? null;
            if (! is_array($cases) || count($cases) !== 10
                || count(array_filter($cases, fn ($case) => ($case['answerable'] ?? false) === true)) !== 7) {
                throw new \InvalidArgumentException('10問（正解あり7問、なし3問）が必要です');
            }
            foreach ($cases as $case) {
                if (! isset($case['id'], $case['question'], $case['answerable'], $case['expected_product_names'])
                    || ! is_array($case['expected_product_names'])
                    || (bool) $case['answerable'] !== ($case['expected_product_names'] !== [])) {
                    throw new \InvalidArgumentException('質問データの形式が不正です');
                }
            }
        } catch (Throwable $e) {
            $this->error('評価セットを読み込めません: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('live')) {
            $this->info('評価セット: 10問（正解あり7問、なし3問）');
            $this->line('外部AIを使った評価は --live を指定して実行します。');

            return self::SUCCESS;
        }

        $retrieverClass = 'App\\Services\\Rag\\EvidenceRetriever';
        if (! class_exists($retrieverClass)) {
            $this->error('Issue #64 の EvidenceRetriever が未統合です。統合後に再実行してください。');

            return self::FAILURE;
        }

        // 評価の前提: 各「答えあり」質問に正解商品を扱う自販機が少なくとも1台存在する。
        $missing = [];
        foreach ($cases as $case) {
            if (! $case['answerable']) {
                continue;
            }
            if (! Product::query()->whereIn('name', $case['expected_product_names'])->whereHas('vendingMachines')->exists()) {
                $missing[] = $case['id'];
            }
        }
        if ($missing !== []) {
            $this->error('正解商品を販売する自販機が登録されていない質問: '.implode(', ', $missing));
            $this->line('正解データに対応する開発用販売情報を用意してから再実行してください。');

            return self::FAILURE;
        }

        $results = [];
        foreach ($cases as $case) {
            try {
                $retrieved = app($retrieverClass)->retrieve($case['question']);
                $evidence = $retrieved['evidence'];
            } catch (Throwable $e) {
                $results[] = [
                    'id' => $case['id'], 'question' => $case['question'],
                    'retrieval_pass' => false, 'answer_pass' => false,
                    'error' => 'retrieval: '.get_class($e),
                ];

                continue;
            }

            try {
                $citations = $evidence === [] ? [] : app(AnswerGenerator::class)->generate($case['question'], $evidence)['citations'];
                $score = $scorer->score($case, $evidence, $citations);
                $results[] = ['id' => $case['id'], 'question' => $case['question'], ...$score, 'error' => null];
            } catch (Throwable $e) {
                $score = $scorer->score($case, $evidence, null);
                $results[] = [
                    'id' => $case['id'], 'question' => $case['question'],
                    ...$score, 'answer_pass' => false,
                    'error' => 'generation: '.get_class($e),
                ];
            }
        }

        $retrievalCount = count(array_filter($results, fn (array $row): bool => $row['retrieval_pass']));
        $answerCount = count(array_filter($results, fn (array $row): bool => $row['answer_pass']));
        $this->table(['ID', '検索', '回答', 'エラー'], array_map(fn (array $row): array => [
            $row['id'], $row['retrieval_pass'] ? 'OK' : 'NG',
            $row['answer_pass'] ? 'OK' : 'NG', $row['error'] ?? '-',
        ], $results));
        $this->info("検索: {$retrievalCount}/10、回答: {$answerCount}/10");

        if ($this->option('save')) {
            $report = [
                'date' => now()->toIso8601String(),
                'config' => [
                    'threshold' => config('services.cloudflare.rag_score_threshold'),
                    'embedding_model' => config('services.cloudflare.embedding_model'),
                    'generation_model' => config('services.cloudflare.generation_model'),
                    'answer_prompt' => config('services.cloudflare.answer_prompt'),
                ],
                'retrieval_pass' => $retrievalCount,
                'answer_pass' => $answerCount,
                'results' => $results,
            ];
            file_put_contents(base_path((string) $this->option('save')), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
