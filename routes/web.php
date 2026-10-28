<?php

use App\Http\Controllers\AhpController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BatchAssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\NetworkAssetController;
use App\Http\Controllers\PrioritizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleManagementController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified', 'can:view-dashboard'])->name('dashboard');

Route::view('/administration', 'administration.index')
    ->middleware(['auth', 'verified', 'can:access-administration'])
    ->name('administration.index');

Route::middleware('auth')->group(function () {
    Route::resource('users', UserManagementController::class)->except(['show', 'destroy']);
    Route::resource('roles', RoleManagementController::class)->except('show');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{type}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/ahp', [AhpController::class, 'index'])->name('ahp.index');
    Route::get('/criteria-weights', [AhpController::class, 'index'])->name('ahp.weights');
    Route::post('/ahp', [AhpController::class, 'store'])->name('ahp.store');
    Route::resource('assets', NetworkAssetController::class);
    Route::get('/assessments/batch', [BatchAssessmentController::class, 'index'])->block(10, 10)->name('assessments.batch');
    Route::post('/assessments/batch', [BatchAssessmentController::class, 'store'])->block(10, 10)->name('assessments.batch.store');
    Route::resource('assessments', AssessmentController::class);
    Route::resource('runs', PrioritizationController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('/runs/{run}/compare', [PrioritizationController::class, 'compare'])->name('runs.compare');
    Route::get('/runs/{run}/results/{result}', [PrioritizationController::class, 'result'])->name('runs.result');
    foreach (['locations', 'asset-types', 'criteria'] as $kind) {
        foreach ([['get', '', 'index'], ['get', '/create', 'create'], ['post', '', 'store'], ['get', '/{record}/edit', 'edit'], ['put', '/{record}', 'update'], ['delete', '/{record}', 'destroy']] as [$verb, $path, $action]) {
            Route::$verb('/'.$kind.$path, [MasterDataController::class, $action])
                ->defaults('kind', $kind)->name($kind.'.'.$action);
        }
    }
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
