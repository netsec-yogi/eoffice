<?php

use App\Modules\Finance\Http\AdvanceController;
use App\Modules\Finance\Http\BudgetController;
use App\Modules\Finance\Http\ProcurementController;
use App\Modules\Finance\Http\RuleSetController;
use App\Modules\Finance\Http\VendorController;
use App\Modules\Finance\Http\VendorPortalController;
use Illuminate\Support\Facades\Route;

// Each action authorises itself.
Route::get('purchases', [ProcurementController::class, 'index'])->name('procurement.index');
Route::get('purchases/rules', [RuleSetController::class, 'index'])->name('procurement.rules');
Route::post('purchases/rules', [RuleSetController::class, 'store'])->name('procurement.rules.store');
Route::post('purchases/rules/{set}/publish', [RuleSetController::class, 'publish'])->name('procurement.rules.publish');
Route::get('purchases/{case}', [ProcurementController::class, 'show'])->name('procurement.show');
Route::post('purchases/{case}/sourcing', [ProcurementController::class, 'sourcing'])->name('procurement.sourcing');
Route::post('purchases/{case}/quotations', [ProcurementController::class, 'quotation'])->name('procurement.quotations.store');
Route::post('purchases/{case}/select', [ProcurementController::class, 'select'])->name('procurement.select');

Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
Route::post('vendors/{vendor}/account', [VendorController::class, 'account'])->name('vendors.account');

Route::get('budget', [BudgetController::class, 'index'])->name('budget.index');
Route::post('budget', [BudgetController::class, 'store'])->name('budget.store');
Route::get('budget/{head}', [BudgetController::class, 'show'])->name('budget.show');
Route::post('budget/{head}/entries', [BudgetController::class, 'post'])->name('budget.post');

Route::get('advances', [AdvanceController::class, 'index'])->name('advances.index');

Route::get('vendor', VendorPortalController::class)->name('vendor.portal');
