<?php

namespace App\Services\Evals;

class RagScorer
{
    /**
     * Evaluate retrieval and cited-answer selection separately.
     * An answerable case passes if at least one expected product is present;
     * answer citations must all be relevant and cite at least one expected product.
     */
    public function score(array $case, array $evidence, ?array $citations): array
    {
        $expected = $case['expected_product_names'];
        $answerable = (bool) $case['answerable'];
        $relevantEvidence = array_values(array_filter($evidence, fn (array $row): bool => in_array($row['product_name'] ?? null, $expected, true)
        ));

        $retrievalPass = $answerable ? $relevantEvidence !== [] : $evidence === [];
        $citationKeys = [];
        foreach ($citations ?? [] as $citation) {
            $citationKeys[$this->key($citation)] = true;
        }
        $citedRows = array_values(array_filter($evidence, fn (array $row): bool => isset($citationKeys[$this->key($row)])
        ));
        $citationValid = count($citationKeys) === count($citedRows);

        $answerPass = $answerable
            ? $citationValid && $citedRows !== [] && count(array_filter($citedRows, fn (array $row): bool => ! in_array($row['product_name'] ?? null, $expected, true)
            )) === 0
            : $citationValid && $citedRows === [];

        return [
            'retrieval_pass' => $retrievalPass,
            'answer_pass' => $answerPass,
            'evidence_count' => count($evidence),
            'relevant_evidence_count' => count($relevantEvidence),
            'citation_count' => count($citations ?? []),
        ];
    }

    private function key(array $row): string
    {
        return (int) ($row['machine_id'] ?? 0).':'.(int) ($row['product_id'] ?? 0).':'.(int) ($row['price'] ?? -1);
    }
}
