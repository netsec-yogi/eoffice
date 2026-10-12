<?php

use App\Modules\Workflow\Http\DelegationController;
use App\Modules\Workflow\Http\FormAdminController;
use App\Modules\Workflow\Http\MonitorController;
use App\Modules\Workflow\Http\NotificationController;
use App\Modules\Workflow\Http\RequestController;
use App\Modules\Workflow\Http\TaskController;
use App\Modules\Workflow\Http\WorkflowAdminController;
use Illuminate\Support\Facades\Route;

// Requests (initiator side)
Route::get('requests/new', [RequestController::class, 'catalogue'])->name('requests.catalogue');
Route::get('requests/new/{definition:key}', [RequestController::class, 'create'])->name('requests.create');
Route::post('requests/new/{definition:key}', [RequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.store');
Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
Route::get('requests/{instance}', [RequestController::class, 'show'])->name('requests.show');
Route::get('requests/{instance}/edit', [RequestController::class, 'edit'])->name('requests.edit');
Route::put('requests/{instance}', [RequestController::class, 'update'])->name('requests.update');
Route::post('requests/{instance}/cancel', [RequestController::class, 'cancel'])->name('requests.cancel');
Route::post('requests/{instance}/hold', [RequestController::class, 'hold'])->name('requests.hold');
Route::post('requests/{instance}/resume', [RequestController::class, 'resume'])->name('requests.resume');

// Tasks (approver side)
Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
Route::post('tasks/{task}/decide', [TaskController::class, 'decide'])->middleware('throttle:60,1')->name('tasks.decide');
Route::post('tasks/{task}/forward', [TaskController::class, 'forward'])->name('tasks.forward');
Route::post('tasks/{task}/reassign', [TaskController::class, 'reassign'])->name('tasks.reassign');

Route::get('delegations', [DelegationController::class, 'index'])->name('delegations.index');
Route::post('delegations', [DelegationController::class, 'store'])->name('delegations.store');
Route::delete('delegations/{delegation}', [DelegationController::class, 'destroy'])->name('delegations.destroy');

Route::get('monitor', [MonitorController::class, 'index'])->name('monitor.index');

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

// Design (administrators)
Route::prefix('admin')->name('admin.')->middleware('can:admin.access')->group(function () {
    Route::get('workflows', [WorkflowAdminController::class, 'index'])->name('workflows.index');
    Route::post('workflows', [WorkflowAdminController::class, 'store'])->name('workflows.store');
    Route::get('workflows/{definition}', [WorkflowAdminController::class, 'edit'])->name('workflows.edit');
    Route::put('workflows/{definition}', [WorkflowAdminController::class, 'update'])->name('workflows.update');
    Route::post('workflows/{definition}/draft', [WorkflowAdminController::class, 'newDraft'])->name('workflows.draft');
    Route::put('workflow-versions/{version}', [WorkflowAdminController::class, 'saveDraft'])->name('workflows.versions.save');
    Route::get('workflow-versions/{version}/check', [WorkflowAdminController::class, 'check'])->name('workflows.versions.check');
    Route::post('workflow-versions/{version}/publish', [WorkflowAdminController::class, 'publish'])->name('workflows.versions.publish');

    Route::get('forms', [FormAdminController::class, 'index'])->name('forms.index');
    Route::post('forms', [FormAdminController::class, 'store'])->name('forms.store');
    Route::get('forms/{template}', [FormAdminController::class, 'edit'])->name('forms.edit');
    Route::post('forms/{template}/draft', [FormAdminController::class, 'newDraft'])->name('forms.draft');
    Route::put('form-versions/{version}', [FormAdminController::class, 'saveDraft'])->name('forms.versions.save');
    Route::post('form-versions/{version}/publish', [FormAdminController::class, 'publish'])->name('forms.versions.publish');
});
