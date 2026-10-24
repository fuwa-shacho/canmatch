<?php

namespace App\Services;

use App\Models\PointLog;
use App\Models\User;
use App\Models\VendingMachine;
use DateTimeInterface;
use LogicException;

class PointAwarder
{
    public function awardForRegistration(
        User $user,
        VendingMachine $machine,
        ?DateTimeInterface $awardedAt = null,
    ): PointLog {
        $existing = PointLog::query()
            ->where('vending_machine_id', $machine->id)
            ->where('type', 'registration')
            ->first();

        return $existing ?? $this->award($user, $machine, 'registration', $awardedAt);
    }

    public function awardForUpdate(User $user, VendingMachine $machine): PointLog
    {
        return $this->award($user, $machine, 'update');
    }

    private function award(
        User $user,
        VendingMachine $machine,
        string $type,
        ?DateTimeInterface $awardedAt = null,
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
        ]);

        if ($awardedAt !== null) {
            $pointLog->created_at = $awardedAt;
        }

        $pointLog->save();

        return $pointLog;
    }
}
