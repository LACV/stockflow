<?php

namespace App\Filament\Widgets;

use App\Models\InventoryTransaction;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class InventoryStatsWidget extends BaseWidget
{
    protected function getOpenMonth(): ?array
    {
        $open = MonthlyClosure::where('is_closed', false)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        if (! $open) {
            return null;
        }

        return [
            'month' => (int) $open->month,
            'year' => (int) $open->year,
            'start' => Carbon::create($open->year, $open->month, 1)->startOfMonth()->toDateTimeString(),
            'end' => Carbon::create($open->year, $open->month, 1)->endOfMonth()->toDateTimeString(),
        ];
    }

    protected function getMonthTotals(array $open): array
    {
        $range = [$open['start'], $open['end']];

        $entries = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', $range)
            ->where(function ($query) {
                $query->where('t.type', 'ENTRY')
                    ->orWhere(fn ($inner) => $inner
                        ->where('t.type', 'ADJUSTMENT')
                        ->where('d.quantity', '>', 0));
            })
            ->selectRaw('COALESCE(SUM(d.quantity), 0) AS qty, COALESCE(SUM(d.quantity * d.unit_cost), 0) AS value')
            ->first();

        $exits = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', $range)
            ->where(function ($query) {
                $query->where('t.type', 'EXIT')
                    ->orWhere(fn ($inner) => $inner
                        ->where('t.type', 'ADJUSTMENT')
                        ->where('d.quantity', '<', 0));
            })
            ->selectRaw('COALESCE(SUM(ABS(d.quantity)), 0) AS qty, COALESCE(SUM(ABS(d.quantity) * d.unit_cost), 0) AS value')
            ->first();

        return [
            'entradas' => ['qty' => (float) $entries->qty, 'value' => (float) $entries->value],
            'salidas' => ['qty' => (float) $exits->qty, 'value' => (float) $exits->value],
        ];
    }

    protected function getStats(): array
    {
        // Consulta SQL directa — eficiente sin importar el tamaño del inventario
        $totalValuation = DB::table('articles')
            ->where('is_active', true)
            ->selectRaw('SUM(stock_quantity * current_average_cost) as total')
            ->value('total') ?? 0;

        $totalItems = DB::table('articles')
            ->where('is_active', true)
            ->sum('stock_quantity') ?? 0;

        $lastTransaction = InventoryTransaction::where('status', 'active')
            ->latest('transaction_date')
            ->value('transaction_date');

        $lastTransactionFormatted = $lastTransaction
            ? Carbon::parse($lastTransaction)->format('d/m/Y')
            : 'Sin registros';

        $stats = [
            Stat::make('Valoración Total del Inventario', '$'.number_format($totalValuation, 2, '.', ','))
                ->description('Calculado por Promedio Ponderado')
                ->color('success')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Total de Artículos Físicos', number_format($totalItems, 2, '.', ','))
                ->description('Suma de cantidades en almacén')
                ->color('info')
                ->icon('heroicon-o-cube'),

            Stat::make('Última Transacción', $lastTransactionFormatted)
                ->description('Fecha del último movimiento activo')
                ->color('warning')
                ->icon('heroicon-o-calendar-days'),
        ];

        $open = $this->getOpenMonth();

        if (! $open) {
            $stats[] = Stat::make('Mes Abierto', '—')
                ->description('No hay un mes abierto en este momento')
                ->color('gray')
                ->icon('heroicon-o-clock');

            return $stats;
        }

        $totals = $this->getMonthTotals($open);
        $monthLabel = Carbon::create($open['year'], $open['month'], 1)->locale('es')->isoFormat('MMMM');

        $stats[] = Stat::make('Entradas del Mes', number_format($totals['entradas']['qty'], 2, ',', '.'))
            ->description('Valor: $'.number_format($totals['entradas']['value'], 2, '.', ',').' · '.$monthLabel)
            ->color('success')
            ->icon('heroicon-o-arrow-down-tray');

        $stats[] = Stat::make('Salidas del Mes', number_format($totals['salidas']['qty'], 2, ',', '.'))
            ->description('Valor: $'.number_format($totals['salidas']['value'], 2, '.', ',').' · '.$monthLabel)
            ->color('danger')
            ->icon('heroicon-o-arrow-up-tray');

        return $stats;
    }
}
