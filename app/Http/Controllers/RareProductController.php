<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class RareProductController extends Controller
{
    public function __invoke(): View
    {
        $maximumMachineCount = (int) config('canmatch.rare_product_max_machines');

        $rareProductIds = DB::table('machine_products')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT vending_machine_id) <= ?', [$maximumMachineCount]);

        $products = Product::query()
            ->select('products.*')
            ->selectSub(
                DB::table('machine_products')
                    ->selectRaw('COUNT(DISTINCT vending_machine_id)')
                    ->whereColumn('machine_products.product_id', 'products.id'),
                'vending_machines_count'
            )
            ->whereIn('products.id', $rareProductIds)
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
