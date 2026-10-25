<?php

namespace Tests\Feature;

use Tests\TestCase;

class EvalRagCommandTest extends TestCase
{
    public function test_default_does_not_call_cloudflare(): void
    {
        $this->artisan('eval:rag')
            ->expectsOutputToContain('評価セット: 10問')
            ->assertSuccessful();
    }

    public function test_dataset_contains_seven_answerable_and_three_unanswerable_cases(): void
    {
        $dataset = json_decode(file_get_contents(base_path('evals/rag-questions.json')), true, 512, JSON_THROW_ON_ERROR);
        $questions = $dataset['questions'];

        $this->assertCount(10, $questions);
        $this->assertCount(7, array_filter($questions, fn (array $row): bool => $row['answerable']));
        $this->assertCount(3, array_filter($questions, fn (array $row): bool => ! $row['answerable']));
        $this->assertCount(10, array_unique(array_column($questions, 'id')));
    }
}
