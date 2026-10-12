<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Documents\Http\DocumentController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Registrar\Http\CertificateController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

// Third parties check a certificate's authenticity without signing in.
Route::get('/verify/{code}', [CertificateController::class, 'verify'])
    ->where('code', '[A-Za-z0-9]{6,32}')->middleware('throttle:verify')->name('certificates.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:uploads')->name('documents.store');
});
