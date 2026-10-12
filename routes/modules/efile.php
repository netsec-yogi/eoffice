<?php

use App\Modules\EFile\Http\CorrespondenceController;
use App\Modules\EFile\Http\EFileController;
use App\Modules\EFile\Http\RecordsController;
use Illuminate\Support\Facades\Route;

// Every action authorises itself (custody, FileAccess, permissions).
Route::get('files', [EFileController::class, 'index'])->name('efiles.index');
Route::get('files/new', [EFileController::class, 'create'])->name('efiles.create');
Route::post('files', [EFileController::class, 'store'])->name('efiles.store');
Route::get('files/{efile}', [EFileController::class, 'show'])->name('efiles.show');
Route::post('files/{efile}/notes', [EFileController::class, 'storeNote'])->name('efiles.notes.store');
Route::put('file-notes/{note}', [EFileController::class, 'updateNote'])->name('efiles.notes.update');
Route::delete('file-notes/{note}', [EFileController::class, 'destroyNote'])->name('efiles.notes.destroy');
Route::post('files/{efile}/send', [EFileController::class, 'send'])->name('efiles.send');
Route::post('file-movements/{movement}/receive', [EFileController::class, 'receive'])->name('efiles.receive');
Route::post('file-movements/{movement}/recall', [EFileController::class, 'recall'])->name('efiles.recall');
Route::post('files/{efile}/documents', [EFileController::class, 'attach'])->middleware('throttle:uploads')->name('efiles.attach');
Route::post('files/{efile}/close', [EFileController::class, 'close'])->name('efiles.close');
Route::post('files/{efile}/reopen', [EFileController::class, 'reopen'])->name('efiles.reopen');
Route::post('files/{efile}/grants', [EFileController::class, 'grant'])->name('efiles.grant');
Route::delete('file-grants/{grant}', [EFileController::class, 'revokeGrant'])->name('efiles.grants.revoke');
Route::post('files/{efile}/links', [EFileController::class, 'link'])->name('efiles.link');

Route::get('correspondence', [CorrespondenceController::class, 'index'])->name('correspondence.index');
Route::post('correspondence', [CorrespondenceController::class, 'store'])->middleware('throttle:uploads')->name('correspondence.store');
Route::post('correspondence/{entry}/attach', [CorrespondenceController::class, 'attach'])->name('correspondence.attach');

Route::get('records', [RecordsController::class, 'index'])->name('records.index');
Route::post('records/categories', [RecordsController::class, 'storeCategory'])->name('records.categories.store');
Route::put('records/categories/{category}', [RecordsController::class, 'updateCategory'])->name('records.categories.update');
Route::post('records/schedules', [RecordsController::class, 'storeSchedule'])->name('records.schedules.store');
Route::put('records/schedules/{schedule}', [RecordsController::class, 'updateSchedule'])->name('records.schedules.update');
Route::post('records/files/{efile}/dispose', [RecordsController::class, 'dispose'])->name('records.dispose');
Route::post('records/files/{efile}/legal-hold', [RecordsController::class, 'legalHold'])->name('records.legal_hold');
