<?php

use App\Modules\Admin\Http\AuditController;
use App\Modules\Admin\Http\AuthorityController;
use App\Modules\Admin\Http\CalendarController;
use App\Modules\Admin\Http\CommitteeController;
use App\Modules\Admin\Http\LookupController;
use App\Modules\Admin\Http\NotificationAdminController;
use App\Modules\Admin\Http\OrganisationController;
use App\Modules\Admin\Http\RoleController;
use App\Modules\Admin\Http\UserController;
use Illuminate\Support\Facades\Route;

// Every action authorises its own permission; 'can:admin.access' is only the outer gate.
Route::prefix('admin')->name('admin.')->middleware('can:admin.access')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/local', [UserController::class, 'storeLocal'])->name('users.local');
    Route::get('users/{user}', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles');

    Route::get('organisation', [OrganisationController::class, 'index'])->name('organisation.index');
    Route::post('departments', [OrganisationController::class, 'storeDepartment'])->name('departments.store');
    Route::put('departments/{department}', [OrganisationController::class, 'updateDepartment'])->name('departments.update');
    Route::post('designations', [OrganisationController::class, 'storeDesignation'])->name('designations.store');
    Route::put('designations/{designation}', [OrganisationController::class, 'updateDesignation'])->name('designations.update');

    Route::get('authorities', [AuthorityController::class, 'index'])->name('authorities.index');
    Route::post('authorities', [AuthorityController::class, 'store'])->name('authorities.store');
    Route::put('authorities/{authority}', [AuthorityController::class, 'update'])->name('authorities.update');
    Route::post('authorities/{authority}/holders', [AuthorityController::class, 'assign'])->name('authorities.assign');
    Route::put('authority-assignments/{assignment}/end', [AuthorityController::class, 'endAssignment'])->name('authorities.end');

    Route::get('committees', [CommitteeController::class, 'index'])->name('committees.index');
    Route::post('committees', [CommitteeController::class, 'store'])->name('committees.store');
    Route::put('committees/{committee}', [CommitteeController::class, 'update'])->name('committees.update');
    Route::post('committees/{committee}/members', [CommitteeController::class, 'addMember'])->name('committees.members.store');
    Route::put('committee-members/{member}/end', [CommitteeController::class, 'endMember'])->name('committees.members.end');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');

    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    Route::post('audit/verify', [AuditController::class, 'verify'])->middleware('throttle:6,1')->name('audit.verify');

    Route::get('calendars', [CalendarController::class, 'index'])->name('calendars.index');
    Route::post('calendars', [CalendarController::class, 'store'])->name('calendars.store');
    Route::put('calendars/{calendar}', [CalendarController::class, 'update'])->name('calendars.update');
    Route::post('calendars/{calendar}/holidays', [CalendarController::class, 'storeHoliday'])->name('calendars.holidays.store');
    Route::delete('holidays/{holiday}', [CalendarController::class, 'destroyHoliday'])->name('calendars.holidays.destroy');

    Route::get('notifications', [NotificationAdminController::class, 'index'])->name('notifications.index');
    Route::post('notification-templates', [NotificationAdminController::class, 'store'])->name('notifications.templates.store');
    Route::put('notification-templates/{template}', [NotificationAdminController::class, 'update'])->name('notifications.templates.update');
    Route::post('notification-messages/{message}/retry', [NotificationAdminController::class, 'retry'])->name('notifications.retry');
});

// Pickers used across modules (any signed-in user).
Route::get('lookup/users', [LookupController::class, 'users'])->middleware('throttle:60,1')->name('lookup.users');
