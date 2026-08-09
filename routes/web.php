<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\OpeningBalanceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Chart of accounts. There is deliberately no destroy route: accounts are
    // archived, never deleted, once anything could reference them.
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
    Route::post('accounts/{account}/restore', [AccountController::class, 'restore'])->name('accounts.restore');

    // Exchange rates. Unlike accounts these are deletable: nothing points at a
    // rate row — a posted line carries its own snapshot — so removing one can
    // orphan no history.
    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
    Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
    Route::patch('exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'update'])
        ->name('exchange-rates.update');
    Route::delete('exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'destroy'])
        ->name('exchange-rates.destroy');

    // Opening balances. There is no index, edit or destroy: a run is a posted
    // journal entry, so the way to change one is to reverse it and post again.
    Route::get('opening-balances', [OpeningBalanceController::class, 'create'])
        ->name('opening-balances.create');
    Route::post('opening-balances', [OpeningBalanceController::class, 'store'])
        ->name('opening-balances.store');
});

require __DIR__.'/settings.php';
