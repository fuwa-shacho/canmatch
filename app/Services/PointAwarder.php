<?php

namespace App\Services;

use App\Models\PointLog;
use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

class PointAwarder
{
    public function awardForRegistration(
        User $user,
        VendingMachine $machine,
        ?DateTimeInterface $awardedAt = null,
    ): PointLog {
        return DB::transaction(function () use ($user, $machine, $awardedAt): PointLog {
            $lockedMachine = VendingMachine::query()
                ->whereKey($machine->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PointLog::query()
                ->where('vending_machine_id', $lockedMachine->id)
                ->where('type', 'registration')
                ->lockForUpdate()
                ->first();

            return $existing ?? $this->award($user, $lockedMachine, 'registration', $awardedAt);
        });
    }

    public function awardForUpdate(User $user, VendingMachine $machine): ?PointLog
    {
        $cooldownDays = config('points.update_cooldown_days');

        if (! is_int($cooldownDays) || $cooldownDays < 1) {
            throw new LogicException('Update point cooldown must be a positive integer.');
        }

        $hasRecentAward = PointLog::query()
            ->where('vending_machine_id', $machine->id)
            ->whereIn('type', ['registration', 'update'])
            ->where('created_at', '>=', now()->subDays($cooldownDays))
            ->exists();

        if ($hasRecentAward) {
            return null;
        }

        return $this->award($user, $machine, 'update');
    }

    public function awardRareBonus(User $user, VendingMachine $machine): PointLog
    {
        return DB::transaction(function () use ($user, $machine): PointLog {
            $lockedMachine = VendingMachine::query()
                ->whereKey($machine->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PointLog::query()
                ->where('vending_machine_id', $lockedMachine->id)
                ->where('type', 'rare_bonus')
                ->lockForUpdate()
                ->first();

            return $existing ?? $this->award($user, $lockedMachine, 'rare_bonus');
        });
    }

    public function awardRetroactiveRareBonus(
        User $user,
        VendingMachine $machine,
        Product $product,
    ): PointLog {
        return DB::transaction(function () use ($user, $machine, $product): PointLog {
            Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $existing = PointLog::query()
                ->where('type', 'rare_bonus')
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            return $existing ?? $this->award($user, $machine, 'rare_bonus', product: $product);
        });
    }

    private function award(
        User $user,
        VendingMachine $machine,
        string $type,
        ?DateTimeInterface $awardedAt = null,
        ?Product $product = null,
    ): PointLog {
        $points = config("points.{$type}");

        if (! is_int($points) || $points < 1) {
            throw new LogicException("Point value for {$type} must be a positive integer.");
        }

        $pointLog = new PointLog([
            'user_id' => $user->id,
            'type' => $type,
            'points' => $points,
            'vending_machine_id' => $machine->id,
            'product_id' => $product?->id,
        ]);

        if ($awardedAt !== null) {
            $pointLog->created_at = $awardedAt;
        }

        $pointLog->save();

        return $pointLog;
    }
}
