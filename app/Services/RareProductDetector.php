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
            ->where('product_id', $productId)
            ->distinct()
            ->count('vending_machine_id');
    }

    /**
     * @return array{machine_id: int, user_id: int|null}|null
     */
    public function firstDiscovery(int $productId): ?array
    {
        $row = DB::table('machine_products')
            ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
            ->where('machine_products.product_id', $productId)
            ->orderBy('machine_products.recorded_at')
            ->orderBy('machine_products.id')
            ->first(['vending_machines.id as machine_id', 'vending_machines.user_id']);

        return $row === null ? null : [
            'machine_id' => (int) $row->machine_id,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
        ];
    }

    public function soldRareProductIds(): Builder
    {
        return DB::table('machine_products')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT vending_machine_id) <= ?', [
                $this->maximumMachineCount(),
            ]);
    }
}
