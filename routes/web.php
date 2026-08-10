<?php

use Illuminate\Support\Facades\Route;
use OTGH\AccessControl\OpcAdapter\Http\Controllers\Admin\Health\OpcDiagnosticsController;

Route::middleware(['web', 'auth'])
    ->prefix('admin/health')
    ->group(function (): void {
        Route::get('/opc-diagnostics', [OpcDiagnosticsController::class, '__invoke'])
            ->name('admin.opc-diagnostics');
    });
