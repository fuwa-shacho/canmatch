<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RareProductDetector
{
    public function maximumMachineCount(): int
    {
        return (int) config('canmatch.rare_product_max_machines');
    }

    public function isRare(int $productId): bool
    {
        return $this->machineCount($productId) <= $this->maximumMachineCount();
    }

    public function machineCount(int $productId): int
    {
        return DB::table('machine_products')
            ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
            ->where('product_id', $productId)
            ->whereNull('vending_machines.hidden_at')
            ->distinct()
            ->count('machine_products.vending_machine_id');
    }

    /**
     * @return array{machine_id: int, user_id: int|null}|null
     */
    public function firstDiscovery(int $productId): ?array
    {
        $row = DB::table('machine_products')
            ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
            ->where('machine_products.product_id', $productId)
            ->whereNull('vending_machines.hidden_at')
            ->orderBy('machine_products.recorded_at')
            ->orderBy('machine_products.id')
            ->first(['vending_machines.id as machine_id', 'vending_machines.user_id']);

        return $row === null ? null : [
            'machine_id' => (int) $row->machine_id,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
        ];
    }

    public function hasConfirmedDiscovery(int $productId): bool
    {
        return DB::table('point_logs')
            ->where('type', 'rare_bonus')
            ->where('product_id', $productId)
            ->exists();
    }

    public function soldRareProductIds(): Builder
    {
        return DB::table('machine_products')
            ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
            ->select('machine_products.product_id')
            ->whereNull('vending_machines.hidden_at')
            ->groupBy('machine_products.product_id')
            ->havingRaw('COUNT(DISTINCT machine_products.vending_machine_id) <= ?', [
                $this->maximumMachineCount(),
            ]);
    }
}
