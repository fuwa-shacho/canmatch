<?php

namespace App\Services\Rag;

use App\Models\QuestionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 質問の受付(Issue 68)
 *
 * ① 1人が1日に質問できる回数の上限を確かめる(上限に達していたら、Cloudflare を呼ばない)
 * ② 質問に答える(QuestionAnswerer)
 * ③ 質問ごとに記録を残す(質問文、取り出した商品ID、渡した根拠、回答、処理時間、成否)
 *
 * 「1日」は日本時間の0時で区切る。
 */
class QuestionDesk
{
    public function __construct(
        private QuestionAnswerer $answerer,
    ) {}

    /**
     * @return array<string, mixed> QuestionAnswerer::answer() の結果に、remaining(今日あと何回質問できるか)と limit を足したもの
     */
    public function ask(User $user, string $question): array
    {
        $question = trim($question);
        $limit = self::dailyLimit();
        $used = self::usedToday($user);

        // ① 上限に達していたら、何も呼ばない(記録も残さない)
        if ($used >= $limit) {
            return [
                'status' => 'limited',
                'answer' => "今日の質問は上限の{$limit}回に達しました。明日(日本時間の0時以降)にもう一度お試しください。",
                'notice' => null,
                'rows' => [],
                'remaining' => 0,
                'limit' => $limit,
            ];
        }

        // ② 答える
        $startedAt = hrtime(true);
        $result = $this->answerer->answer($question);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        // ③ 記録する(記録に失敗しても、回答は返す)
        $this->record($user, $question, $result, $durationMs);

        return $result + [
            'remaining' => max(0, $limit - $used - 1),
            'limit' => $limit,
        ];
    }

    /**
     * 今日あと何回質問できるか(画面の表示用)
     */
    public static function remainingToday(User $user): int
    {
        return max(0, self::dailyLimit() - self::usedToday($user));
    }

    public static function dailyLimit(): int
    {
        return max(1, (int) config('services.cloudflare.rag_daily_limit', 10));
    }

    /**
     * 今日(日本時間)の質問の回数。上限に数えるのは、記録に残った質問(Cloudflare を呼んだもの)
     */
    private static function usedToday(User $user): int
    {
        $startOfTodayUtc = CarbonImmutable::now('Asia/Tokyo')->startOfDay()->utc();

        return QuestionLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $startOfTodayUtc)
            ->count();
    }

    private function record(User $user, string $question, array $result, int $durationMs): void
    {
        try {
            QuestionLog::create([
                'user_id' => $user->id,
                'question' => $question,
                'status' => $result['status'],
                'product_ids' => $result['product_ids'] ?? [],
                'evidence' => $result['evidence'] ?? [],
                'citations' => $result['citations'] ?? [],
                'answer' => $result['answer'],
                'error_reason' => $result['error_reason'] ?? null,
                'model' => $result['model'] ?? null,
                'prompt_version' => $result['prompt_version'] ?? null,
                'top_score' => $result['top_score'] ?? null,
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            Log::warning('質問の記録に失敗', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
