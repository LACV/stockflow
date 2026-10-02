<?php

use App\Models\MonthlyClosure;
use App\Services\MonthlyMovementsExcelService;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/admin/keep-alive', fn () => response()->noContent());

Route::get('/inventario/download/{record}', function (int $record) {
    $closure = MonthlyClosure::findOrFail($record);

    return app(MonthlyMovementsExcelService::class)
        ->stream($closure->month, $closure->year);
})->name('inventario.download');
