<?php

use App\Http\Controllers\RankingController;
use App\Http\Controllers\RareProductController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('map', 'map')
    ->middleware(['auth'])
    ->name('map');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');
    Volt::route('posts/create', 'posts.create')->name('posts.create');

    Route::get('rare-products', RareProductController::class)->name('rare-products.index');
    Route::get('ranking', RankingController::class)->name('ranking.index');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
