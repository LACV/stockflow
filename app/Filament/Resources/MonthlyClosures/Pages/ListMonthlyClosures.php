<?php

namespace App\Filament\Resources\MonthlyClosures\Pages;

use App\Filament\Resources\MonthlyClosures\MonthlyClosureResource;
use App\Models\MonthlyClosure;
use App\Services\MonthlyMovementsExcelService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

class ListMonthlyClosures extends ListRecords
{
    protected static string $resource = MonthlyClosureResource::class;

    protected function getHeaderActions(): array
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $closureYears = MonthlyClosure::query()->selectRaw('DISTINCT year')->pluck('year');
        $transactionYears = DB::table('inventory_transactions')
            ->selectRaw('DISTINCT YEAR(transaction_date) as y')
            ->pluck('y');

        $years = $closureYears
            ->concat($transactionYears)
            ->filter(fn ($year) => (int) $year >= 2000)
            ->push(now()->year)
            ->unique()
            ->sort()
            ->mapWithKeys(fn (int $year): array => [$year => $year])
            ->all();

        $defaultYear = now()->year;
        $defaultMonth = $this->fallbackMonth($defaultYear, now()->month);

        return [
            Action::make('downloadExcel')
                ->label('Descargar Excel del mes')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->form([
                    Select::make('month')
                        ->label('Mes')
                        ->options(fn (Get $get): array => $this->closedMonthsOptions((int) $get('year'), $months))
                        ->default($defaultMonth)
                        ->required(),
                    Select::make('year')
                        ->label('Año')
                        ->options($years)
                        ->default($defaultYear)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => $set('month', $this->fallbackMonth((int) $get('year'), (int) $get('month'))))
                        ->required(),
                ])
                ->modalSubmitActionLabel('Descargar')
                ->modalHeading('Exportar movimientos del mes')
                ->action(function (array $data) {
                    return app(MonthlyMovementsExcelService::class)
                        ->stream((int) $data['month'], (int) $data['year']);
                }),
        ];
    }

    private function closedMonthsFor(int $year): array
    {
        return MonthlyClosure::query()
            ->where('year', $year)
            ->where('is_closed', true)
            ->pluck('month')
            ->map(fn ($month) => (int) $month)
            ->sort()
            ->values()
            ->toArray();
    }

    private function closedMonthsOptions(int $year, array $months): array
    {
        return collect($this->closedMonthsFor($year))
            ->mapWithKeys(fn (int $month): array => [$month => $months[$month]])
            ->all();
    }

    private function fallbackMonth(int $year, int $currentMonth): int
    {
        $available = $this->closedMonthsFor($year);

        if (in_array($currentMonth, $available, true)) {
            return $currentMonth;
        }

        return $available === [] ? $currentMonth : end($available);
    }
}
