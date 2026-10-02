<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PreviewExcelStatsWidget;
use App\Models\MonthlyClosure;
use App\Services\MonthlyMovementsExcelService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

class PreviewExcelPage extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static ?string $navigationLabel = 'Vista Previa Excel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'preview-excel/{record}';

    protected string $view = 'filament.pages.preview-excel';

    public ?MonthlyClosure $record = null;

    #[Url(as: 'tab')]
    public string $activeTab = 'entradas';

    public int $entradasCount = 0;

    public int $salidasCount = 0;

    public int $movimientosCount = 0;

    public array $entradasHeaders = [];

    public array $salidasHeaders = [];

    public array $movimientosHeaders = [];

    public string $monthLabel = '';

    public int $month = 0;

    public int $year = 0;

    public bool $isOpenMonth = false;

    public string $lastRefresh = '';

    public float $entradasGrandTotal = 0;

    public float $salidasGrandTotal = 0;

    public array $movGrandTotals = [];

    public function mount(MonthlyClosure $record): void
    {
        $this->record = $record;
        $this->isOpenMonth = ! $record->is_closed;
        $this->refreshData();
    }

    protected ?array $memoData = null;

    protected function previewTtl(): int
    {
        return $this->record->is_closed
            ? MonthlyMovementsExcelService::PREVIEW_CACHE_TTL_CLOSED
            : MonthlyMovementsExcelService::PREVIEW_CACHE_TTL_OPEN;
    }

    protected function cachedData(): array
    {
        if ($this->memoData !== null) {
            return $this->memoData;
        }

        $service = app(MonthlyMovementsExcelService::class);

        return $this->memoData = Cache::remember(
            MonthlyMovementsExcelService::previewCacheKey($this->record->id),
            now()->addSeconds($this->previewTtl()),
            fn (): array => $service->previewArrays($this->record->month, $this->record->year),
        );
    }

    public function refreshData(): void
    {
        Cache::forget(MonthlyMovementsExcelService::previewCacheKey($this->record->id));
        $this->memoData = null;

        $data = $this->cachedData();

        $this->entradasHeaders = $data['entradasHeaders'];
        $this->salidasHeaders = $data['salidasHeaders'];
        $this->movimientosHeaders = $data['movimientosHeaders'];
        $this->entradasCount = count($this->dataRows($data['entradas']));
        $this->salidasCount = count($this->dataRows($data['salidas']));
        $this->movimientosCount = count($this->dataRows($data['movimientos']));
        $this->entradasGrandTotal = $this->sumDataColumn($data['entradas'], 4);
        $this->salidasGrandTotal = $this->sumDataColumn($data['salidas'], 4);
        $this->movGrandTotals = [
            4 => $this->sumDataColumn($data['movimientos'], 4),
            7 => $this->sumDataColumn($data['movimientos'], 7),
            10 => $this->sumDataColumn($data['movimientos'], 10),
            13 => $this->sumDataColumn($data['movimientos'], 13),
        ];
        $this->monthLabel = $data['monthLabel'];
        $this->month = $this->record->month;
        $this->year = $this->record->year;
        $this->lastRefresh = now()->format('H:i:s');
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            'entradas' => $this->entradasTable($table),
            'salidas' => $this->salidasTable($table),
            'movimientos' => $this->movimientosTable($table),
            default => $this->entradasTable($table),
        };
    }

    protected function entradasTable(Table $table): Table
    {
        $data = $this->cachedData();
        $h = $data['entradasHeaders'];
        $rows = $data['entradas'];

        return $table
            ->modelLabel('Total:')
            ->records(fn (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                int $page,
                int|string $recordsPerPage,
            ) => $this->buildPaginator($rows, $search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                Tables\Columns\TextColumn::make('0')
                    ->label($h[0] ?? 'Código')
                    ->searchable()
                    ->sortable()
                    ->limit(12)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('1')
                    ->label($h[1] ?? 'Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                Tables\Columns\TextColumn::make('2')
                    ->label($h[2] ?? 'Cantidad')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2, decimalSeparator: '.', thousandsSeparator: ','),
                Tables\Columns\TextColumn::make('3')
                    ->label($h[3] ?? 'V/R Unit.')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('4')
                    ->label($h[4] ?? 'V/R Total')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('bold')
                    ->summarize($this->moneySum($this->entradasGrandTotal)),
                Tables\Columns\TextColumn::make('5')
                    ->label($h[5] ?? 'Orden Entrada')
                    ->searchable()
                    ->sortable()
                    ->limit(18),
                Tables\Columns\TextColumn::make('6')
                    ->label($h[6] ?? 'Orden Compra')
                    ->searchable()
                    ->sortable()
                    ->limit(18),
                Tables\Columns\TextColumn::make('7')
                    ->label($h[7] ?? 'Factura')
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('8')
                    ->label($h[8] ?? 'Clase')
                    ->searchable()
                    ->sortable(),
            ])
            ->paginated([5, 10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchable()
            ->striped()
            ->summaries(pageCondition: false);
    }

    protected function salidasTable(Table $table): Table
    {
        $data = $this->cachedData();
        $h = $data['salidasHeaders'];
        $rows = $data['salidas'];

        return $table
            ->modelLabel('Total:')
            ->records(fn (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                int $page,
                int|string $recordsPerPage,
            ) => $this->buildPaginator($rows, $search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                Tables\Columns\TextColumn::make('0')
                    ->label($h[0] ?? 'Código')
                    ->searchable()
                    ->sortable()
                    ->limit(12)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('1')
                    ->label($h[1] ?? 'Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                Tables\Columns\TextColumn::make('2')
                    ->label($h[2] ?? 'Cantidad')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2, decimalSeparator: '.', thousandsSeparator: ','),
                Tables\Columns\TextColumn::make('3')
                    ->label($h[3] ?? 'V/R Unit.')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('4')
                    ->label($h[4] ?? 'V/R Total')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('bold')
                    ->summarize($this->moneySum($this->salidasGrandTotal)),
                Tables\Columns\TextColumn::make('5')
                    ->label($h[5] ?? 'Cód. Destino')
                    ->searchable()
                    ->sortable()
                    ->limit(12),
                Tables\Columns\TextColumn::make('6')
                    ->label($h[6] ?? 'Destino')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('7')
                    ->label($h[7] ?? 'Clase')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('8')
                    ->label($h[8] ?? 'Empleado')
                    ->searchable()
                    ->sortable()
                    ->limit(25),
            ])
            ->paginated([5, 10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchable()
            ->striped()
            ->summaries(pageCondition: false);
    }

    protected function movimientosTable(Table $table): Table
    {
        $data = $this->cachedData();
        $h = $data['movimientosHeaders'];
        $rows = $data['movimientos'];

        return $table
            ->modelLabel('TOTALES')
            ->records(fn (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                int $page,
                int|string $recordsPerPage,
            ) => $this->buildPaginator($rows, $search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                Tables\Columns\TextColumn::make('0')
                    ->label($h[0] ?? 'Código')
                    ->searchable()
                    ->sortable()
                    ->limit(12)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('1')
                    ->label($h[1] ?? 'Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(35),
                Tables\Columns\TextColumn::make('2')
                    ->label($h[2] ?? 'Cant. Inicial')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('3')
                    ->label($h[3] ?? 'V/R Unit. Inic.')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ',')),
                Tables\Columns\TextColumn::make('4')
                    ->label($h[4] ?? 'Valor Inicial')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('medium')
                    ->summarize($this->moneySum($this->movGrandTotals[4] ?? 0)),
                Tables\Columns\TextColumn::make('5')
                    ->label($h[5] ?? 'Cant. Entrada')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('6')
                    ->label($h[6] ?? 'V/R Unit. Compra')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ',')),
                Tables\Columns\TextColumn::make('7')
                    ->label($h[7] ?? 'V/R Total Compra')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('bold')
                    ->summarize($this->moneySum($this->movGrandTotals[7] ?? 0)),
                Tables\Columns\TextColumn::make('8')
                    ->label($h[8] ?? 'Cant. Salida')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('9')
                    ->label($h[9] ?? 'V/R Unit. Salida')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ',')),
                Tables\Columns\TextColumn::make('10')
                    ->label($h[10] ?? 'V/R Total Salida')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('bold')
                    ->summarize($this->moneySum($this->movGrandTotals[10] ?? 0)),
                Tables\Columns\TextColumn::make('11')
                    ->label($h[11] ?? 'Cant. Restante')
                    ->searchable()
                    ->sortable()
                    ->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('12')
                    ->label($h[12] ?? 'Precio Unitario')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ',')),
                Tables\Columns\TextColumn::make('13')
                    ->label($h[13] ?? 'Valor Total')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','))
                    ->weight('bold')
                    ->summarize($this->moneySum($this->movGrandTotals[13] ?? 0)),
            ])
            ->paginated([5, 10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchable()
            ->striped()
            ->summaries(pageCondition: false);
    }

    protected function isTotalRow(array $row): bool
    {
        if (trim((string) ($row[0] ?? '')) !== '') {
            return false;
        }

        return collect($row)->contains(
            fn ($cell): bool => str_contains(strtolower(trim((string) $cell)), 'total'),
        );
    }

    protected function dataRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! $this->isTotalRow($row),
        ));
    }

    protected function sumDataColumn(array $rows, int $index): float
    {
        return collect($this->dataRows($rows))->sum(
            fn (array $row): float => is_numeric($row[$index] ?? null) ? (float) $row[$index] : 0.0,
        );
    }

    protected function moneySum(float $total): Sum
    {
        return Sum::make()
            ->label('Suma')
            ->hiddenLabel()
            ->using(fn (): float => $total)
            ->formatStateUsing(fn ($state): string => '$ '.number_format((float) $state, 2, '.', ','));
    }

    protected function buildPaginator(
        array $rows,
        ?string $search,
        ?string $sortColumn,
        ?string $sortDirection,
        int $page,
        int|string $recordsPerPage,
    ): LengthAwarePaginator {
        $collection = collect($rows);

        if (filled($search)) {
            $searchLower = strtolower($search);
            $collection = $collection->filter(
                fn (array $row): bool => collect($row)->contains(
                    fn ($cell): bool => stripos((string) $cell, $searchLower) !== false,
                ),
            );
        }

        if (filled($sortColumn) && isset($rows[0][$sortColumn])) {
            $desc = $sortDirection === 'desc';
            $collection = $collection->sortBy($sortColumn, SORT_REGULAR, $desc)->values();
        }

        $total = $collection->count();
        $perPage = ($recordsPerPage === 'all') ? max($total, 1) : (int) $recordsPerPage;
        $items = $collection->forPage($page, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    public function getTitle(): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return 'Vista Previa — '.($months[$this->month] ?? '').' '.$this->year;
    }

    public function getHeading(): ?string
    {
        return $this->getTitle();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('preview')
                ->livewireProperty('activeTab')
                ->contained(false)
                ->tabs([
                    'entradas' => Tab::make('Entradas')
                        ->icon(Heroicon::ArrowUpTray)
                        ->badge(fn () => number_format($this->entradasCount))
                        ->badgeColor('primary')
                        ->extraAttributes(['data-tab' => 'entradas'])
                        ->schema([]),
                    'salidas' => Tab::make('Salida')
                        ->icon(Heroicon::ArrowDownTray)
                        ->badge(fn () => number_format($this->salidasCount))
                        ->badgeColor('danger')
                        ->extraAttributes(['data-tab' => 'salidas'])
                        ->schema([]),
                    'movimientos' => Tab::make('Movimientos')
                        ->icon(Heroicon::ArrowsRightLeft)
                        ->badge(fn () => number_format($this->movimientosCount))
                        ->badgeColor('success')
                        ->extraAttributes(['data-tab' => 'movimientos'])
                        ->schema([]),
                ]),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PreviewExcelStatsWidget::make([
                'closureId' => $this->record?->id,
            ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Descargar Excel')
                ->icon(Heroicon::ArrowDownTray)
                ->color('success')
                ->url(fn () => route('inventario.download', $this->record->id)),

            Action::make('backToTable')
                ->label('Volver a la Tabla')
                ->icon(Heroicon::TableCells)
                ->color('gray')
                ->url(fn () => route('filament.admin.resources.monthly-closures.index')),

            Action::make('back')
                ->label('Volver')
                ->icon(Heroicon::ArrowLeft)
                ->color('gray')
                ->url(fn () => route('filament.admin.resources.monthly-closures.view', $this->record->id)),
        ];
    }
}
