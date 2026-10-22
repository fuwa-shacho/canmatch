<?php

namespace Tests\Feature;

use App\Models\QuestionLog;
use App\Models\User;
use App\Services\Rag\QuestionAnswerer;
use App\Services\Rag\QuestionDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class QuestionDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare.rag_daily_limit', 3);

        // 2026-10-22 12:00 JST(今日の始まりは 2026-10-21 15:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-10-22 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function answered(): array
    {
        return [
            'status' => 'answered',
            'answer' => "条件に合う商品は次のとおりです。\n・開発用無糖炭酸:自販機1で120円(今日の情報)",
            'notice' => null,
            'rows' => [['machine_id' => 1, 'product_id' => 2, 'price' => 120]],
            'product_ids' => [2, 5],
            'evidence' => [
                ['machine_id' => 1, 'product_id' => 2, 'price' => 120],
                ['machine_id' => 3, 'product_id' => 5, 'price' => 160],
            ],
            'citations' => [['machine_id' => 1, 'product_id' => 2, 'price' => 120]],
            'top_score' => 0.62,
            'model' => '@cf/test/generation',
            'prompt_version' => 'rag_answer_v1',
            'error_reason' => null,
        ];
    }

    private function fakeAnswerer(?array $result = null, int $times = 1): void
    {
        $mock = Mockery::mock(QuestionAnswerer::class);
        if ($times === 0) {
            $mock->shouldNotReceive('answer');
        } else {
            $mock->shouldReceive('answer')->times($times)->andReturn($result ?? $this->answered());
        }

        $this->app->instance(QuestionAnswerer::class, $mock);
    }

    private function logAt(User $user, string $createdAtUtc): void
    {
        DB::table('question_logs')->insert([
            'user_id' => $user->id,
            'question' => '開発用の過去の質問',
            'status' => 'answered',
            'product_ids' => '[]',
            'evidence' => '[]',
            'citations' => '[]',
            'answer' => '開発用',
            'duration_ms' => 10,
            'created_at' => $createdAtUtc,
        ]);
    }

    public function test_question_and_its_evidence_are_recorded(): void
    {
        $user = User::factory()->create();
        $this->fakeAnswerer();

        $result = app(QuestionDesk::class)->ask($user, '  甘くない炭酸  ');

        $this->assertSame('answered', $result['status']);
        $this->assertSame(2, $result['remaining']);
        $this->assertSame(3, $result['limit']);

        $log = QuestionLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('甘くない炭酸', $log->question);
        $this->assertSame('answered', $log->status);
        $this->assertSame([2, 5], $log->product_ids);
        $this->assertSame($this->answered()['evidence'], $log->evidence);
        $this->assertSame([['machine_id' => 1, 'product_id' => 2, 'price' => 120]], $log->citations);
        $this->assertSame($this->answered()['answer'], $log->answer);
        $this->assertSame('@cf/test/generation', $log->model);
        $this->assertSame('rag_answer_v1', $log->prompt_version);
        $this->assertSame(0.62, $log->top_score);
        $this->assertNull($log->error_reason);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        $this->assertSame('2026-10-22 03:00:00', $log->created_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_failures_are_recorded_with_the_reason(): void
    {
        $user = User::factory()->create();
        $this->fakeAnswerer([
            'status' => 'unavailable',
            'answer' => QuestionAnswerer::UNAVAILABLE,
            'notice' => null,
            'rows' => [],
            'product_ids' => [],
            'evidence' => [],
            'citations' => [],
            'top_score' => null,
            'model' => null,
            'prompt_version' => null,
            'error_reason' => 'retrieval_failed',
        ]);

        app(QuestionDesk::class)->ask($user, '甘くない炭酸');

        $log = QuestionLog::sole();
        $this->assertSame('unavailable', $log->status);
        $this->assertSame('retrieval_failed', $log->error_reason);
        $this->assertSame([], $log->evidence);
    }

    public function test_questions_over_the_daily_limit_are_refused_without_calling_cloudflare(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->logAt($user, '2026-10-22 01:00:00');
        }
        $this->fakeAnswerer(times: 0);

        $result = app(QuestionDesk::class)->ask($user, '甘くない炭酸');

        $this->assertSame('limited', $result['status']);
        $this->assertSame(0, $result['remaining']);
        $this->assertStringContainsString('上限の3回', $result['answer']);
        $this->assertSame(3, QuestionLog::count());
    }

    public function test_the_day_is_counted_in_japan_time(): void
    {
        $user = User::factory()->create();
        $this->logAt($user, '2026-10-21 14:59:59'); // 日本時間 10/21 23:59:59(昨日)
        $this->logAt($user, '2026-10-21 15:00:00'); // 日本時間 10/22 0:00(今日)

        $this->assertSame(2, QuestionDesk::remainingToday($user));
    }

    public function test_other_users_questions_do_not_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->logAt($other, '2026-10-22 01:00:00');
        }

        $this->assertSame(3, QuestionDesk::remainingToday($user));
    }

    public function test_logs_are_deleted_with_the_user(): void
    {
        $user = User::factory()->create();
        $this->logAt($user, '2026-10-22 01:00:00');

        $user->delete();

        $this->assertSame(0, QuestionLog::count());
    }

    public function test_page_shows_remaining_questions_and_the_limit_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (range(1, 2) as $i) {
            $this->logAt($user, '2026-10-22 01:00:00');
        }

        $this->fakeAnswerer(['rows' => []] + $this->answered());

        Volt::test('questions.ask')
            ->assertSee('今日はあと 1 回質問できます(1日 3 回まで)。')
            ->set('question', '甘くない炭酸')
            ->call('ask')
            ->assertSee('今日はあと 0 回質問できます')
            ->call('ask')
            ->assertSee('今日の質問は上限の3回に達しました。');

        $this->assertSame(3, QuestionLog::count());
    }
}
