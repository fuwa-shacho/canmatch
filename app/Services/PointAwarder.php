<?php

namespace App\Services;

use App\Models\PointLog;
use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use LogicException;

class PointAwarder
{
    public function awardForRegistration(User $user, VendingMachine $machine): PointLog
    {
        return $this->award($user, $machine, 'registration');
    }

    public function awardForUpdate(User $user, VendingMachine $machine): PointLog
    {
        return $this->award($user, $machine, 'update');
    }

    /**
     * @param  iterable<Product>  $products
     */
    public function awardRareProductBonus(User $user, VendingMachine $machine, iterable $products): ?PointLog
    {
        $productIds = collect($products)
            ->map(fn (Product $product) => $product->getKey())
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return null;
        }

        $maxMachines = config('canmatch.rare_product_max_machines');

        if (! is_int($maxMachines) || $maxMachines < 0) {
            throw new LogicException('Rare product machine threshold must be a non-negative integer.');
        }

        $containsRareProduct = Product::query()
            ->whereKey($productIds)
            ->has('vendingMachines', '<=', $maxMachines)
            ->exists();

        if (! $containsRareProduct) {
            return null;
        }

        return $this->award($user, $machine, 'rare_bonus');
    }

    private function award(User $user, VendingMachine $machine, string $type): PointLog
    {
        $points = config("points.{$type}");

        if (! is_int($points) || $points < 1) {
            throw new LogicException("Point value for {$type} must be a positive integer.");
        }

        return PointLog::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'points' => $points,
            'vending_machine_id' => $machine->id,
        ]);
    }
}
