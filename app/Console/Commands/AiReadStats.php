<?php

namespace App\Console\Commands;

use App\Models\AiReadLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AiReadStats extends Command
{
    protected $signature = 'ai:read-stats {--date= : 集計日（日本時間 YYYY-MM-DD）}';

    protected $description = 'AI読み取り回数と確認画面で修正した割合を集計する';

    public function handle(): int
    {
        $date = $this->option('date') ?: now('Asia/Tokyo')->toDateString();
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Tokyo');
            if ($start === false || $start->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('invalid date');
            }
        } catch (\Throwable) {
            $this->error('--dateにはYYYY-MM-DD形式の正しい日付を指定してください');

            return self::FAILURE;
        }

        $logs = AiReadLog::query()
            ->where('created_at', '>=', $start->utc())
            ->where('created_at', '<', $start->addDay()->utc());

        $total = (clone $logs)->count();
        $success = (clone $logs)->where('status', 'success')->count();
        $failed = (clone $logs)->where('status', 'failed')->count();
        $registered = (clone $logs)->whereNotNull('vending_machine_id')->whereNotNull('correction_count')->count();
        $corrected = (clone $logs)->whereNotNull('vending_machine_id')->where('correction_count', '>', 0)->count();
        $rate = $registered === 0 ? '対象なし' : number_format(100 * $corrected / $registered, 1).'%';

        $this->table(['日付', '呼び出し回数', '成功', '失敗', '登録に使用', '修正あり', '修正率'], [
            [$date, $total, $success, $failed, $registered, $corrected, $rate],
        ]);

        return self::SUCCESS;
    }
}
