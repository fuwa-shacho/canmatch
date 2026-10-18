<?php

use App\Http\Controllers\Api\VendingMachineController;
use Illuminate\Support\Facades\Route;

Route::get('/vending-machines', VendingMachineController::class);
