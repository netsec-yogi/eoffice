<?php

use App\Modules\OfficialDocuments\Http\MonitorController;
use App\Modules\OfficialDocuments\Http\OfficialDocumentController;
use App\Modules\OfficialDocuments\Http\TemplateController;
use Illuminate\Support\Facades\Route;

// Every action authorises itself; downloads check release and access per document.
Route::get('official-documents', [OfficialDocumentController::class, 'index'])->name('official-documents.index');
Route::get('official-documents/{document}/download', [OfficialDocumentController::class, 'download'])->whereUuid('document')->middleware('throttle:60,1')->name('official-documents.download');

Route::prefix('admin')->name('admin.')->middleware('can:admin.access')->group(function () {
    Route::get('pdf-templates', [TemplateController::class, 'index'])->name('pdf-templates.index');
    Route::post('pdf-templates', [TemplateController::class, 'store'])->middleware('throttle:uploads')->name('pdf-templates.store');
    Route::get('pdf-templates/{template}', [TemplateController::class, 'show'])->name('pdf-templates.show');
    Route::put('pdf-templates/{template}', [TemplateController::class, 'update'])->name('pdf-templates.update');
    Route::post('pdf-templates/{template}/versions', [TemplateController::class, 'storeVersion'])->middleware('throttle:uploads')->name('pdf-templates.versions.store');
    Route::post('pdf-templates/{template}/outputs', [TemplateController::class, 'saveOutput'])->name('pdf-templates.outputs.save');
    Route::post('pdf-outputs/{output}/disable', [TemplateController::class, 'disableOutput'])->name('pdf-templates.outputs.disable');
    Route::get('pdf-template-versions/{version}/mapper', [TemplateController::class, 'mapper'])->name('pdf-templates.mapper');
    Route::get('pdf-template-versions/{version}/pages/{page}', [TemplateController::class, 'page'])->whereNumber('page')->name('pdf-templates.page');
    Route::put('pdf-template-versions/{version}/mapping', [TemplateController::class, 'saveMapping'])->name('pdf-templates.mapping');
    Route::match(['get', 'post'], 'pdf-template-versions/{version}/preview', [TemplateController::class, 'preview'])->middleware('throttle:30,1')->name('pdf-templates.preview');
    Route::post('pdf-template-versions/{version}/publish', [TemplateController::class, 'publish'])->name('pdf-templates.publish');
    Route::post('pdf-template-versions/{version}/discard', [TemplateController::class, 'discard'])->name('pdf-templates.discard');

    Route::get('official-documents', [MonitorController::class, 'index'])->name('official-documents.index');
    Route::post('official-documents/{document}/retry', [MonitorController::class, 'retry'])->whereUuid('document')->name('official-documents.retry');
    Route::post('official-documents/{document}/regenerate', [MonitorController::class, 'regenerate'])->whereUuid('document')->name('official-documents.regenerate');
});
