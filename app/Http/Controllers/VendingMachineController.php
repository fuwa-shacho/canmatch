<?php

namespace App\Http\Controllers;

use App\Models\VendingMachine;
use Illuminate\View\View;

class VendingMachineController extends Controller
{
    public function __invoke(VendingMachine $vendingMachine): View
    {
        $vendingMachine->load([
            'user:id,name',
            'products' => fn ($query) => $query->orderBy('machine_products.id'),
        ]);

        $daysSinceConfirmation = max(0, (int) $vendingMachine->last_confirmed_at
            ->copy()
            ->startOfDay()
            ->diffInDays(now()->startOfDay(), false));

        return view('vending-machines.show', compact('vendingMachine', 'daysSinceConfirmation'));
    }
}
