<?php

namespace App\Filament\Support;

use App\Models\InventoryTransaction;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class MonthFilter
{
    public static function make(): SelectFilter
    {
        $months = InventoryTransaction::query()
            ->selectRaw('DISTINCT DATE(transaction_date) AS d')
            ->orderBy('d')
            ->pluck('d')
            ->map(fn ($d) => ['y' => (int) substr((string) $d, 0, 4), 'm' => (int) substr((string) $d, 5, 2)]);

        $open = MonthlyClosure::where('is_closed', false)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $items = collect();

        if ($open) {
            $items->push(['y' => (int) $open->year, 'm' => (int) $open->month]);
        }

        $months->each(function ($row) use ($items) {
            if ($row['y'] < 2000 || $row['m'] < 1 || $row['m'] > 12) {
                return;
            }

            $items->push(['y' => $row['y'], 'm' => $row['m']]);
        });

        $options = $items
            ->unique(fn ($item) => "{$item['y']}-{$item['m']}")
            ->sortByDesc(fn ($item) => [$item['y'], $item['m']])
            ->mapWithKeys(function ($item) {
                $date = Carbon::create($item['y'], $item['m'], 1);

                return ["{$item['y']}-{$item['m']}" => $date->locale('es')->isoFormat('MMMM YYYY')];
            });

        $filter = SelectFilter::make('month_year')
            ->label('Mes/Año')
            ->placeholder('Todos los meses')
            ->options($options)
            ->query(function (Builder $query, array $data) {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                [$year, $month] = explode('-', $value);

                return $query
                    ->whereYear('transaction_date', (int) $year)
                    ->whereMonth('transaction_date', (int) $month);
            });

        if ($open) {
            $filter->default("{$open->year}-{$open->month}");
        }

        return $filter;
    }
}
