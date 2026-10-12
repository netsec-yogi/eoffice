<?php

use App\Modules\ComputerCentre\Http\AccountController;
use App\Modules\ComputerCentre\Http\AssetController;
use App\Modules\ComputerCentre\Http\LicenceController;
use Illuminate\Support\Facades\Route;

// Every action authorises computer_centre.manage itself.
Route::prefix('computer-centre')->name('cc.')->group(function () {
    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::put('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::post('assets/{asset}/move', [AssetController::class, 'move'])->name('assets.move');

    Route::get('licences', [LicenceController::class, 'index'])->name('licences.index');
    Route::post('licences', [LicenceController::class, 'store'])->name('licences.store');
    Route::put('licences/{title}', [LicenceController::class, 'update'])->name('licences.update');
    Route::post('licences/{title}/allocate', [LicenceController::class, 'allocate'])->name('licences.allocate');
    Route::post('licence-allocations/{allocation}/revoke', [LicenceController::class, 'revoke'])->name('licences.revoke');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
});
