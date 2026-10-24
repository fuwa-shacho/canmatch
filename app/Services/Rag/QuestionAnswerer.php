<?php

namespace App\Services\Rag;

use App\Exceptions\RagGenerationFailedException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 質問に答える(Issue 66)
 *
 * 根拠を集め(64)、根拠があれば回答を作る(65)。失敗したときの表示は docs/rag-design.md の
 * 「答えない場合」「失敗したとき」に従う。
 *
 * 状態(status):
 *  - answered    回答できた(rows は回答に使った根拠の行)
 *  - no_match    根拠はあったが、条件に合う行を AI が選ばなかった(rows は空)
 *  - no_evidence 根拠が0行(しきい値を超える商品がない、扱う自販機がない)。生成は呼ばない
 *  - fallback    生成に失敗した。根拠の一覧(安い順)をそのまま rows にする
 *  - unavailable 数値化・検索に失敗した。何も表示できない
 */
class QuestionAnswerer
{
    public const NO_EVIDENCE = '登録されたデータからは確認できません。';

    public const UNAVAILABLE = 'いまは質問を受け付けられません。時間をおいて、もう一度お試しください。';

    public const FALLBACK_NOTICE = '条件での絞り込みができませんでした。質問に近い商品を、価格の安い順に表示します。';

    public function __construct(
        private EvidenceRetriever $retriever,
        private AnswerGenerator $generator,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     answer: string,
     *     notice: string|null,
     *     rows: array<int, array<string, mixed>>,
     *     product_ids: int[],
     *     evidence: array<int, array<string, mixed>>,
     *     citations: array<int, array{machine_id: int, product_id: int, price: int}>,
     *     top_score: float|null,
     *     model: string|null,
     *     prompt_version: string|null,
     *     error_reason: string|null
     * }
     */
    public function answer(string $question): array
    {
        $question = trim($question);

        // ① 根拠を集める(失敗したら、何も表示できない)
        try {
            $retrieval = $this->retriever->retrieve($question);
        } catch (Throwable $e) {
            Log::warning('質問の根拠を集められなかった', ['error' => $e->getMessage()]);

            return $this->result([
                'status' => 'unavailable',
                'answer' => self::UNAVAILABLE,
                'error_reason' => 'retrieval_failed',
            ]);
        }

        $evidence = $retrieval['evidence'];
        $base = [
            'product_ids' => $retrieval['product_ids'],
            'evidence' => $evidence,
            'top_score' => $retrieval['top_score'],
        ];

        // ② 根拠が0行なら、生成を呼ばない
        if (! EvidenceRetriever::found($retrieval)) {
            return $this->result($base + [
                'status' => 'no_evidence',
                'answer' => self::NO_EVIDENCE,
            ]);
        }

        // ③ 回答を作る。失敗したら、根拠の一覧をそのまま見せる
        try {
            $generated = $this->generator->generate($question, $evidence);
        } catch (RagGenerationFailedException $e) {
            return $this->result($base + [
                'status' => 'fallback',
                'answer' => self::FALLBACK_NOTICE,
                'notice' => self::FALLBACK_NOTICE,
                'rows' => $evidence,
                'error_reason' => $e->reason,
            ]);
        }

        $rows = $this->citedRows($generated['citations'], $evidence);

        return $this->result($base + [
            'status' => $rows === [] ? 'no_match' : 'answered',
            'answer' => $generated['answer'],
            'rows' => $rows,
            'citations' => $generated['citations'],
            'model' => $generated['model'],
            'prompt_version' => $generated['prompt_version'],
        ]);
    }

    /**
     * citations に選ばれた行を、根拠の一覧の順(安い順)で返す
     *
     * 同じ自販機・商品・価格で温冷だけ違う行は、どちらも表示する(citations は温冷を持たないため)。
     */
    private function citedRows(array $citations, array $evidence): array
    {
        $keys = [];
        foreach ($citations as $cite) {
            $keys["{$cite['machine_id']}-{$cite['product_id']}-{$cite['price']}"] = true;
        }

        return array_values(array_filter(
            $evidence,
            fn (array $row) => isset($keys["{$row['machine_id']}-{$row['product_id']}-{$row['price']}"]),
        ));
    }

    private function result(array $fields): array
    {
        return $fields + [
            'status' => 'unavailable',
            'answer' => self::UNAVAILABLE,
            'notice' => null,
            'rows' => [],
            'product_ids' => [],
            'evidence' => [],
            'citations' => [],
            'top_score' => null,
            'model' => null,
            'prompt_version' => null,
            'error_reason' => null,
        ];
    }
}
