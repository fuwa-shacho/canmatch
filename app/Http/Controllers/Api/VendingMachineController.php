<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendingMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendingMachineController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_name' => ['nullable', 'string', 'max:100'],
        ]);
        $productName = trim($validated['product_name'] ?? '');

        $machines = VendingMachine::query()
            ->select(['id', 'latitude', 'longitude', 'maker', 'last_confirmed_at'])
            ->selectSub(
                DB::table('machine_products')
                    ->selectRaw('COUNT(DISTINCT product_id)')
                    ->whereColumn('machine_products.vending_machine_id', 'vending_machines.id'),
                'product_count'
            )
            ->when(
                $productName !== '',
                fn ($query) => $query
                    ->whereHas(
                        'products',
                        fn ($products) => $products->whereLike('products.name', "%{$productName}%")
                    )
                    ->with([
                        'products' => fn ($products) => $products
                            ->select(['products.id', 'products.name', 'products.volume_ml'])
                            ->whereLike('products.name', "%{$productName}%")
                            ->orderBy('machine_products.price')
                            ->orderBy('machine_products.id'),
                    ])
            )
            ->orderBy('id')
            ->get();

        $payload = $machines->map(function (VendingMachine $machine) use ($productName): array {
            $item = [
                'id' => (int) $machine->id,
                'latitude' => (float) $machine->latitude,
                'longitude' => (float) $machine->longitude,
                'maker' => $machine->maker,
                'product_count' => (int) $machine->product_count,
                'last_confirmed_at' => $machine->last_confirmed_at->utc()->toISOString(),
            ];

            if ($productName !== '') {
                $item['matched_products'] = $machine->products->map(function ($product): array {
                    $recordedAt = $product->pivot->recorded_at;
                    $daysAgo = max(
                        0,
                        (int) $recordedAt->copy()->startOfDay()->diffInDays(now()->startOfDay(), false)
                    );

                    return [
                        'machine_product_id' => (int) $product->pivot->id,
                        'product_id' => (int) $product->id,
                        'product_name' => $product->name,
                        'volume_ml' => $product->volume_ml,
                        'price' => (int) $product->pivot->price,
                        'temperature' => $product->pivot->temperature,
                        'recorded_at' => $recordedAt->utc()->toISOString(),
                        'days_ago' => $daysAgo,
                    ];
                })->all();
            }

            return $item;
        });

        return response()->json($payload->all());
    }
}
