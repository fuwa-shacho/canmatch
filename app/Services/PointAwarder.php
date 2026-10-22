<?php

namespace App\Services;

use App\Models\PointLog;
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
