<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendingMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VendingMachineController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $machines = VendingMachine::query()
            ->select(['id', 'latitude', 'longitude', 'maker', 'last_confirmed_at'])
            ->selectSub(
                DB::table('machine_products')
                    ->selectRaw('COUNT(DISTINCT product_id)')
                    ->whereColumn('machine_products.vending_machine_id', 'vending_machines.id'),
                'product_count'
            )
            ->orderBy('id')
            ->get();

        $payload = $machines->map(fn (VendingMachine $machine): array => [
            'id' => (int) $machine->id,
            'latitude' => (float) $machine->latitude,
            'longitude' => (float) $machine->longitude,
            'maker' => $machine->maker,
            'product_count' => (int) $machine->product_count,
            'last_confirmed_at' => $machine->last_confirmed_at->utc()->toISOString(),
        ]);

        return response()->json($payload->all());
    }
}
