<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\RareProductDetector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class RareProductController extends Controller
{
    public function __invoke(RareProductDetector $detector): View
    {
        $maximumMachineCount = $detector->maximumMachineCount();

        $products = Product::query()
            ->select('products.*')
            ->selectSub(
                DB::table('machine_products')
                    ->join('vending_machines', 'vending_machines.id', '=', 'machine_products.vending_machine_id')
                    ->selectRaw('COUNT(DISTINCT vending_machine_id)')
                    ->whereNull('vending_machines.hidden_at')
                    ->whereColumn('machine_products.product_id', 'products.id'),
                'vending_machines_count'
            )
            ->whereIn('products.id', $detector->soldRareProductIds())
            ->with(['vendingMachines' => fn ($query) => $query->whereNull('vending_machines.hidden_at')])
            ->orderBy('products.name')
            ->get()
            ->each(function (Product $product): void {
                $product->setRelation(
                    'vendingMachines',
                    $product->vendingMachines
                        ->unique('id')
                        ->sortBy('id')
                        ->values()
                );
            });

        return view('rare-products', [
            'maximumMachineCount' => $maximumMachineCount,
            'products' => $products,
        ]);
    }
}
