<?php

namespace Tests\Unit;

use App\Services\Evals\RagScorer;
use PHPUnit\Framework\TestCase;

class RagScorerTest extends TestCase
{
    private function case(bool $answerable = true): array
    {
        return [
            'answerable' => $answerable,
            'expected_product_names' => $answerable ? ['無糖炭酸'] : [],
        ];
    }

    private function row(string $name, int $id): array
    {
        return ['machine_id' => 1, 'product_id' => $id, 'price' => 120, 'product_name' => $name];
    }

    public function test_search_and_answer_pass_when_expected_product_is_cited(): void
    {
        $evidence = [$this->row('無糖炭酸', 5), $this->row('甘いコーラ', 6)];
        $score = (new RagScorer)->score($this->case(), $evidence, [
            ['machine_id' => 1, 'product_id' => 5, 'price' => 120],
        ]);

        $this->assertTrue($score['retrieval_pass']);
        $this->assertTrue($score['answer_pass']);
    }

    public function test_search_can_pass_even_when_generator_selects_wrong_product(): void
    {
        $evidence = [$this->row('無糖炭酸', 5), $this->row('甘いコーラ', 6)];
        $score = (new RagScorer)->score($this->case(), $evidence, [
            ['machine_id' => 1, 'product_id' => 6, 'price' => 120],
        ]);

        $this->assertTrue($score['retrieval_pass']);
        $this->assertFalse($score['answer_pass']);
    }

    public function test_unanswerable_question_passes_when_no_evidence_or_citations_exist(): void
    {
        $score = (new RagScorer)->score($this->case(false), [], []);

        $this->assertTrue($score['retrieval_pass']);
        $this->assertTrue($score['answer_pass']);
    }

    public function test_unanswerable_question_with_irrelevant_evidence_fails_retrieval(): void
    {
        $score = (new RagScorer)->score($this->case(false), [$this->row('コーラ', 9)], []);

        $this->assertFalse($score['retrieval_pass']);
        $this->assertTrue($score['answer_pass']);
    }

    public function test_answer_with_unsupported_citation_fails(): void
    {
        $score = (new RagScorer)->score($this->case(), [$this->row('無糖炭酸', 5)], [
            ['machine_id' => 1, 'product_id' => 5, 'price' => 999],
        ]);

        $this->assertFalse($score['answer_pass']);
    }
}
