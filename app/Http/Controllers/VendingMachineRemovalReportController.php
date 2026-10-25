<?php

namespace App\Http\Controllers;

use App\Models\VendingMachine;
use App\Models\VendingMachineRemovalReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendingMachineRemovalReportController extends Controller
{
    public function store(Request $request, VendingMachine $vendingMachine): RedirectResponse
    {
        DB::transaction(function () use ($request, $vendingMachine): void {
            $machine = VendingMachine::query()->lockForUpdate()->findOrFail($vendingMachine->id);

            VendingMachineRemovalReport::query()->firstOrCreate([
                'vending_machine_id' => $machine->id,
                'user_id' => $request->user()->id,
            ]);

            if ($machine->hidden_at === null) {
                $machine->update(['hidden_at' => now()]);
            }
        });

        return to_route('vending-machines.show', $vendingMachine)
            ->with('status', '撤去を報告しました。この自販機は地図や検索から非表示になりました。');
    }
}
