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
                    ->selectRaw('COUNT(DISTINCT vending_machine_id)')
                    ->whereColumn('machine_products.product_id', 'products.id'),
                'vending_machines_count'
            )
            ->whereIn('products.id', $detector->soldRareProductIds())
            ->with('vendingMachines')
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
