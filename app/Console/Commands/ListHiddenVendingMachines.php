<?php

namespace App\Console\Commands;

use App\Models\VendingMachine;
use Illuminate\Console\Command;

class ListHiddenVendingMachines extends Command
{
    protected $signature = 'machines:hidden';

    protected $description = '非表示になっている自販機を一覧する';

    public function handle(): int
    {
        $machines = VendingMachine::query()
            ->whereNotNull('hidden_at')
            ->withCount('removalReports')
            ->orderBy('id')
            ->get();

        if ($machines->isEmpty()) {
            $this->info('非表示の自販機はありません。');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'メーカー', '非表示日時(UTC)', '報告数'],
            $machines->map(fn (VendingMachine $machine): array => [
                $machine->id,
                $machine->maker ?? '不明',
                $machine->hidden_at->utc()->format('Y-m-d H:i:s'),
                $machine->removal_reports_count,
            ])->all()
        );

        return self::SUCCESS;
    }
}
