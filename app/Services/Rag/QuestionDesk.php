<?php

namespace App\Services\Rag;

use App\Models\QuestionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 質問の受付(Issue 68)
 *
 * ① 1日の枠を1回分確保する(利用者の行をロックして数え、空きがあれば記録の行を先に作る)
 *    確保できなければ、Cloudflare を呼ばない。確保の処理そのものが失敗したときも呼ばない(安全側)
 * ② 質問に答える(QuestionAnswerer)
 * ③ 確保した記録の行に結果を書き込む(質問文、取り出した商品ID、渡した根拠、回答、処理時間、成否)
 *
 * 枠は Cloudflare を呼ぶ前に数えるので、同じ人が同時に何回送っても上限を超えて呼ばない。
 * ③の書き込みに失敗しても、①で枠は数えてあるので上限は守られる。
 * 「1日」は日本時間の0時で区切る。
 */
class QuestionDesk
{
    /** 枠を確保した直後、まだ答えが出ていない状態 */
    public const PENDING = 'pending';

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

        // ① 枠を確保する
        try {
            [$log, $used] = $this->reserve($user, $question, $limit);
        } catch (Throwable $e) {
            Log::warning('質問の枠を確保できなかった', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->refused('unavailable', QuestionAnswerer::UNAVAILABLE, null, $limit);
        }

        if ($log === null) {
            return $this->refused(
                'limited',
                "今日の質問は上限の{$limit}回に達しました。明日(日本時間の0時以降)にもう一度お試しください。",
                0,
                $limit,
            );
        }

        // ② 答える(途中で例外が出ても、③で記録を残してから投げ直す)
        $startedAt = hrtime(true);
        $result = null;

        try {
            $result = $this->answerer->answer($question);
        } finally {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->complete($log, $result, $durationMs);
        }

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
     * 今日(日本時間)の質問の回数。確保した枠(Cloudflare を呼ぶ前に作った記録)をすべて数える
     */
    private static function usedToday(User $user): int
    {
        $startOfTodayUtc = CarbonImmutable::now('Asia/Tokyo')->startOfDay()->utc();

        return QuestionLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $startOfTodayUtc)
            ->count();
    }

    /**
     * 利用者の行をロックして今日の回数を数え、空きがあれば記録の行を作る
     *
     * 同じ利用者の確保は、このロックで1つずつ順番に行われる。
     *
     * @return array{0: QuestionLog|null, 1: int} 作った記録(枠がなければ null)と、確保する前の回数
     */
    private function reserve(User $user, string $question, int $limit): array
    {
        return DB::transaction(function () use ($user, $question, $limit): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $used = self::usedToday($user);

            if ($used >= $limit) {
                return [null, $used];
            }

            $log = QuestionLog::create([
                'user_id' => $user->id,
                'question' => $question,
                'status' => self::PENDING,
                'product_ids' => [],
                'evidence' => [],
                'citations' => [],
                'answer' => '',
                'duration_ms' => 0,
            ]);

            return [$log, $used];
        });
    }

    /**
     * 確保した記録の行に結果を書き込む。失敗しても回答は返す(枠はすでに数えてある)
     */
    private function complete(QuestionLog $log, ?array $result, int $durationMs): void
    {
        try {
            $log->update($result === null
                ? [
                    'status' => 'unavailable',
                    'answer' => QuestionAnswerer::UNAVAILABLE,
                    'error_reason' => 'exception',
                    'duration_ms' => $durationMs,
                ]
                : [
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
            Log::warning('質問の記録に失敗', ['question_log_id' => $log->id, 'error' => $e->getMessage()]);
        }
    }

    private function refused(string $status, string $answer, ?int $remaining, int $limit): array
    {
        return [
            'status' => $status,
            'answer' => $answer,
            'notice' => null,
            'rows' => [],
            'remaining' => $remaining,
            'limit' => $limit,
        ];
    }
}
