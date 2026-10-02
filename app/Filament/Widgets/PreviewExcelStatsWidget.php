<?php

namespace App\Filament\Widgets;

use App\Models\MonthlyClosure;
use App\Services\MonthlyMovementsExcelService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PreviewExcelStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    public ?int $closureId = null;

    public function mount(int $closureId): void
    {
        $this->closureId = $closureId;
    }

    private function loadData(): array
    {
        if (! $this->closureId) {
            return [];
        }

        $closure = MonthlyClosure::find($this->closureId);
        if (! $closure) {
            return [];
        }

        $service = app(MonthlyMovementsExcelService::class);
        $data = Cache::remember(
            MonthlyMovementsExcelService::previewCacheKey($closure->id),
            now()->addSeconds($closure->is_closed
                ? MonthlyMovementsExcelService::PREVIEW_CACHE_TTL_CLOSED
                : MonthlyMovementsExcelService::PREVIEW_CACHE_TTL_OPEN),
            fn (): array => $service->previewArrays($closure->month, $closure->year),
        );

        $entradas = $data['entradas'];
        $salidas = $data['salidas'];
        $movimientos = $data['movimientos'];

        $extractTotals = function (array &$rows): array {
            if (empty($rows)) {
                return [];
            }
            $last = end($rows);
            $hasTotalLabel = collect($last)->contains(fn ($cell) => str_contains(strtolower((string) $cell), 'total'));
            $allEmptyExceptLabel = collect($last)->filter(fn ($cell) => $cell !== '' && ! is_numeric($cell))->count() <= 1;
            if ($hasTotalLabel && $allEmptyExceptLabel) {
                array_pop($rows);

                return $last;
            }

            return [];
        };

        $movimientosTotals = $extractTotals($movimientos);

        $entradasCount = count(array_filter($entradas, function (array $row) {
            return ! collect($row)->contains(fn ($cell) => str_contains(strtolower((string) $cell), 'total') || str_contains(strtolower((string) $cell), 'subtotal'));
        }));

        $salidasCount = count(array_filter($salidas, function (array $row) {
            return ! collect($row)->contains(fn ($cell) => str_contains(strtolower((string) $cell), 'total') || str_contains(strtolower((string) $cell), 'subtotal'));
        }));

        $entradasTotal = 0.0;
        foreach ($entradas as $row) {
            if (isset($row[4]) && is_numeric($row[4]) && (float) $row[4] > 0) {
                $hasLabel = collect($row)->contains(fn ($cell) => str_contains(strtolower((string) $cell), 'total') || str_contains(strtolower((string) $cell), 'subtotal'));
                if (! $hasLabel) {
                    $entradasTotal += (float) $row[4];
                }
            }
        }

        $salidasTotal = 0.0;
        foreach ($salidas as $row) {
            if (isset($row[4]) && is_numeric($row[4]) && (float) $row[4] > 0) {
                $hasLabel = collect($row)->contains(fn ($cell) => str_contains(strtolower((string) $cell), 'total') || str_contains(strtolower((string) $cell), 'subtotal'));
                if (! $hasLabel) {
                    $salidasTotal += (float) $row[4];
                }
            }
        }

        $prevMonth = Carbon::create($closure->year, $closure->month, 1)->subMonth();
        $prevClosure = MonthlyClosure::query()
            ->where('month', $prevMonth->month)
            ->where('year', $prevMonth->year)
            ->where('is_closed', true)
            ->first();

        $prevTotal = 0.0;
        if ($prevClosure) {
            $prevTotal = (float) DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $prevClosure->id)
                ->sum('total_value');
        }

        return [
            'entradas_count' => $entradasCount,
            'salidas_count' => $salidasCount,
            'movimientos_count' => count($movimientos),
            'entradas_value' => $entradasTotal,
            'salidas_value' => $salidasTotal,
            'mov_ini' => (float) ($movimientosTotals[4] ?? 0),
            'mov_com' => (float) ($movimientosTotals[7] ?? 0),
            'mov_sal' => (float) ($movimientosTotals[10] ?? 0),
            'mov_final' => (float) ($movimientosTotals[13] ?? 0),
            'prev_total' => $prevTotal,
            'prev_label' => $this->monthLabel($prevMonth),
        ];
    }

    private function monthLabel(Carbon $date): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($months[$date->month] ?? '').' '.$date->year;
    }

    protected function getStats(): array
    {
        $t = $this->loadData();
        $fmt = fn ($v) => '$ '.number_format((float) $v, 2, '.', ',');
        $diff = ($t['mov_final'] ?? 0) - ($t['prev_total'] ?? 0);
        $diffPct = ($t['prev_total'] ?? 0) > 0 ? (($diff / ($t['prev_total'] ?? 1)) * 100) : 0;
        $diffLabel = ($diff >= 0 ? '+' : '').number_format($diff, 2, '.', ',').' ('.number_format($diffPct, 1).'%)';

        return [
            Stat::make('Total '.$t['prev_label'], $fmt($t['prev_total'] ?? 0))
                ->description('Mes anterior · '.($diff >= 0 ? 'Sube ' : 'Baja ').$diffLabel.' vs actual')
                ->descriptionIcon($diff >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color($diff >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-calendar'),

            Stat::make('Entradas', $fmt($t['entradas_value'] ?? 0))
                ->description(number_format($t['entradas_count'] ?? 0).' registros')
                ->color('success')
                ->icon('heroicon-o-arrow-up-tray'),

            Stat::make('Salidas', $fmt($t['salidas_value'] ?? 0))
                ->description(number_format($t['salidas_count'] ?? 0).' registros')
                ->color('danger')
                ->icon('heroicon-o-arrow-down-tray'),

            Stat::make('Inventario Final', $fmt($t['mov_final'] ?? 0))
                ->description('Inicial: '.$fmt($t['mov_ini'] ?? 0).' · Compra: '.$fmt($t['mov_com'] ?? 0).' · Salida: '.$fmt($t['mov_sal'] ?? 0))
                ->descriptionIcon('heroicon-o-information-circle')
                ->color('primary')
                ->icon('heroicon-o-banknotes'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
