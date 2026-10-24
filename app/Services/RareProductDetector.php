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
        $machineCount = DB::table('machine_products')
            ->where('product_id', $productId)
            ->distinct()
            ->count('vending_machine_id');

        return $machineCount <= $this->maximumMachineCount();
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
