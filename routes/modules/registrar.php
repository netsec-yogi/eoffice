<?php

use App\Modules\Registrar\Http\CertificateController;
use App\Modules\Registrar\Http\CircularController;
use App\Modules\Registrar\Http\ClaimsController;
use App\Modules\Registrar\Http\LeaveController;
use App\Modules\Registrar\Http\RtiController;
use Illuminate\Support\Facades\Route;

// Each action authorises itself.
Route::get('leave', [LeaveController::class, 'mine'])->name('leave.mine');
Route::get('leave/admin', [LeaveController::class, 'admin'])->name('leave.admin');
Route::post('leave/types', [LeaveController::class, 'storeType'])->name('leave.types.store');
Route::put('leave/types/{type}', [LeaveController::class, 'updateType'])->name('leave.types.update');
Route::post('leave/ledger', [LeaveController::class, 'post'])->name('leave.ledger.post');

Route::get('claims', [ClaimsController::class, 'index'])->name('claims.index');

Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
Route::post('certificate-templates', [CertificateController::class, 'storeTemplate'])->name('certificates.templates.store');
Route::put('certificate-templates/{template}', [CertificateController::class, 'updateTemplate'])->name('certificates.templates.update');
Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->name('certificates.revoke');

Route::get('circulars', [CircularController::class, 'index'])->name('circulars.index');
Route::get('circulars/new', [CircularController::class, 'create'])->name('circulars.create');
Route::post('circulars', [CircularController::class, 'store'])->name('circulars.store');
Route::get('circulars/{circular}', [CircularController::class, 'show'])->name('circulars.show');
Route::get('circulars/{circular}/edit', [CircularController::class, 'edit'])->name('circulars.edit');
Route::put('circulars/{circular}', [CircularController::class, 'update'])->name('circulars.update');
Route::post('circulars/{circular}/submit', [CircularController::class, 'submit'])->name('circulars.submit');
Route::post('circulars/{circular}/acknowledge', [CircularController::class, 'acknowledge'])->name('circulars.acknowledge');
Route::post('circulars/{circular}/revise', [CircularController::class, 'revise'])->name('circulars.revise');
Route::post('circulars/{circular}/withdraw', [CircularController::class, 'withdraw'])->name('circulars.withdraw');
Route::post('circulars/{circular}/archive', [CircularController::class, 'archive'])->name('circulars.archive');

Route::get('rti', [RtiController::class, 'index'])->name('rti.index');
