<?php

namespace App\Console\Commands;

use App\Models\VendingMachine;
use App\Services\PointAwarder;
use Illuminate\Console\Command;

class BackfillRegistrationPoints extends Command
{
    protected $signature = 'points:backfill-registrations {--apply : 実際にポイント履歴を作成する}';

    protected $description = '登録ポイントがない既存自販機を確認し、必要なら登録時刻で補完する';

    public function handle(PointAwarder $awarder): int
    {
        $query = VendingMachine::query()
            ->whereNotNull('user_id')
            ->whereDoesntHave(
                'pointLogs',
                fn ($pointLogs) => $pointLogs->where('type', 'registration'),
            );

        $count = (clone $query)->count();
        $this->info("登録ポイントの補完対象: {$count}件");

        if (! $this->option('apply')) {
            $this->warn('プレビューのみです。変更はしていません。適用する場合は --apply を付けて再実行してください。');

            return self::SUCCESS;
        }

        $created = 0;

        $query
            ->with('user')
            ->orderBy('id')
            ->eachById(function (VendingMachine $machine) use ($awarder, &$created): void {
                if ($machine->user === null) {
                    return;
                }

                $before = $machine->pointLogs()
                    ->where('type', 'registration')
                    ->exists();

                $awarder->awardForRegistration(
                    $machine->user,
                    $machine,
                    $machine->created_at,
                );

                if (! $before) {
                    $created++;
                }
            });

        $this->info("登録ポイントを {$created}件補完しました。");

        return self::SUCCESS;
    }
}
