<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class MonthlyMovementsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    public function getHeading(): string|Htmlable|null
    {
        return 'Movimientos por mes';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return [
            '6' => 'Últimos 6 meses',
            '12' => 'Últimos 12 meses',
        ];
    }

    protected function getData(): array
    {
        $months = in_array($this->filter, ['6', '12'], true)
            ? (int) $this->filter
            : 12;

        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->where('t.transaction_date', '>=', $start->startOfDay()->toDateTimeString())
            ->selectRaw("
                YEAR(t.transaction_date) AS y,
                MONTH(t.transaction_date) AS m,
                COALESCE(SUM(CASE WHEN t.type = 'ENTRY' OR (t.type = 'ADJUSTMENT' AND d.quantity > 0) THEN d.quantity ELSE 0 END), 0) AS entradas,
                COALESCE(SUM(CASE WHEN t.type = 'EXIT' OR (t.type = 'ADJUSTMENT' AND d.quantity < 0) THEN ABS(d.quantity) ELSE 0 END), 0) AS salidas
            ")
            ->groupBy('y', 'm')
            ->get()
            ->keyBy(fn ($row) => $row->y.'-'.str_pad((string) $row->m, 2, '0', STR_PAD_LEFT));

        $labels = [];
        $entradas = [];
        $salidas = [];

        for ($i = 0; $i < $months; $i++) {
            $date = Carbon::now()->startOfMonth()->subMonths($months - 1 - $i);
            $row = $rows->get($date->format('Y-m'));

            $labels[] = $date->locale('es')->isoFormat('MMM YY');
            $entradas[] = $row ? round((float) $row->entradas, 2) : 0;
            $salidas[] = $row ? round((float) $row->salidas, 2) : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Entradas',
                    'data' => $entradas,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.25)',
                    'borderColor' => 'rgb(16, 185, 129)',
                ],
                [
                    'label' => 'Salidas',
                    'data' => $salidas,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.25)',
                    'borderColor' => 'rgb(239, 68, 68)',
                ],
            ],
        ];
    }
}
