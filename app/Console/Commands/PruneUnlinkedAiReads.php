<?php

namespace App\Console\Commands;

use App\Models\AiReadLog;
use Illuminate\Console\Command;

class PruneUnlinkedAiReads extends Command
{
    protected $signature = 'ai:prune-unlinked {--days=30 : 未登録ログの保存日数} {--apply : 削除を実行（省略時は件数だけ表示）}';

    protected $description = '自販機に紐付かなかった古い読み取りログを消去する';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            $this->error('--daysには1以上の整数を指定してください');

            return self::FAILURE;
        }

        $query = AiReadLog::query()
            ->whereNull('vending_machine_id')
            ->where('created_at', '<', now()->subDays($days));
        $count = (clone $query)->count();

        if (! $this->option('apply')) {
            $this->info("削除候補: {$count} 件。削除するには --apply を指定してください。");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("未登録ログ {$count} 件の削除を実行しました。");

        return self::SUCCESS;
    }
}
