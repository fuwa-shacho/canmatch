<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\VendingMachine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('dashboard', [
            'machineCount' => VendingMachine::query()->count(),
            // 商品マスタの件数ではなく、実際にどこかの自販機で売られている商品の種類
            'productCount' => Product::query()->whereHas('vendingMachines')->count(),
            'myMachineCount' => VendingMachine::query()->where('user_id', $request->user()->id)->count(),
        ]);
    }
}
