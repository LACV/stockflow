<?php

namespace App\Filament\Resources\DispatchOrders\RelationManagers;

use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Support\ArticleSearch;
use App\Models\Article;
use App\Models\Destination;
use App\Models\Employee;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class DetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'details';

    protected static ?string $title = 'Artículos Retirados';

    protected function isTransactionActive(): bool
    {
        return $this->getOwnerRecord()?->status === 'active';
    }

    protected function isMonthClosed(): bool
    {
        $date = Carbon::parse($this->getOwnerRecord()->transaction_date);
        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        if (! $closure || ! $closure->is_closed) {
            return false;
        }
        if ($closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure')) {
            return false;
        }

        return true;
    }

    protected function canEditInReopenedMonth(): bool
    {
        $date = Carbon::parse($this->getOwnerRecord()->transaction_date);
        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        return $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
    }

    protected function getClosedMonthMessage(): string
    {
        $date = Carbon::parse($this->getOwnerRecord()->transaction_date);
        $monthName = $date->locale('es')->isoFormat('MMMM');

        return "Imposible agregar en {$monthName} {$date->year}, este mes está cerrado.";
    }

    protected function sendClosedMonthNotification(): void
    {
        Notification::make()
            ->title('Mes cerrado')
            ->body($this->getClosedMonthMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    protected function pendingEffectiveQtyByArticle(bool $excludeMountedDetail = false): array
    {
        $transaction = $this->getOwnerRecord();

        if (! $transaction || $transaction->status !== 'inactive') {
            return [];
        }

        $excludeId = $excludeMountedDetail
            ? $this->getMountedAction()?->getRecord()?->getKey()
            : null;

        return $transaction->details()
            ->with('adjustments')
            ->get()
            ->reject(fn ($detail) => $excludeId !== null && (int) $detail->getKey() === (int) $excludeId)
            ->reject(fn ($detail) => filled($detail->adjusts_detail_id))
            ->groupBy('article_id')
            ->map(fn ($rows) => $rows->sum(fn ($detail) => (float) $detail->quantity + (float) $detail->adjustments->sum('quantity')))
            ->toArray();
    }

    protected function projectedAvailable(int $articleId): float
    {
        $live = (float) (Article::find($articleId)?->stock_quantity ?? 0);
        $pending = $this->pendingEffectiveQtyByArticle(excludeMountedDetail: true);

        return max(0.0, $live - (float) ($pending[$articleId] ?? 0));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('article_id')
                ->label('Artículo')
                ->relationship('article', modifyQueryUsing: fn ($query) => $query->where('is_active', true)->with('brand')->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn (Article $record) => ArticleSearch::label(
                    $record,
                    true,
                    $this->pendingEffectiveQtyByArticle(),
                ))
                ->searchable(['name', 'reference'])
                ->getSearchResultsUsing(ArticleSearch::forSelect(
                    withStock: true,
                    pendingByArticle: $this->pendingEffectiveQtyByArticle(),
                ))
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, $set) {
                    if ($state) {
                        $article = Article::find($state);
                        if ($article) {
                            $set('unit_cost', $article->current_average_cost);
                        }
                    }
                })
                ->createOptionForm(ArticleForm::getCreateFields()),

            TextInput::make('quantity')
                ->label('Cantidad')
                ->helperText(function ($get) {
                    $articleId = $get('article_id');
                    if (! $articleId) {
                        return 'Selecciona un artículo para ver el stock disponible';
                    }

                    $stock = $this->projectedAvailable((int) $articleId);
                    $qty = (float) ($get('quantity') ?? 0);
                    $result = $stock - $qty;

                    $fmtStock = rtrim(rtrim(number_format($stock, 4, '.', ''), '0'), '.');
                    $fmtResult = rtrim(rtrim(number_format($result, 4, '.', ''), '0'), '.');

                    if ($stock <= 0) {
                        return "Sin stock disponible ({$fmtStock} und) — no se puede retirar este artículo.";
                    }
                    if ($stock <= (float) config('inventory.stock_minimo', 2)) {
                        return "Stock bajo: {$fmtStock} und disponibles → Resultante: {$fmtResult} und.";
                    }

                    return "Stock disponible: {$fmtStock} und → Resultante: {$fmtResult} und.";
                })
                ->numeric()
                ->minValue(0.0001)
                ->maxValue(fn ($get) => $get('article_id') ? $this->projectedAvailable((int) $get('article_id')) : null)
                ->validationMessages([
                    'max' => 'La cantidad no puede superar el stock disponible del artículo.',
                ])
                ->live()
                ->required(),

            TextInput::make('unit_cost')
                ->label('Costo Unitario')
                ->helperText('Se usará el PPP actual del artículo al guardar')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->disabled()
                ->dehydrated()
                ->required(),

            Select::make('employee_id')
                ->label('Solicitante')
                ->relationship('employee', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required(),
                ])
                ->createOptionUsing(fn (array $data) => Employee::create(['name' => $data['name']])),

            Select::make('destination_id')
                ->label('Destino')
                ->relationship('destination', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required(),
                ])
                ->createOptionUsing(fn (array $data) => Destination::create(['name' => $data['name']])),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('article.name')
                    ->label('Artículo')
                    ->searchable()
                    ->icon('heroicon-o-cube')
                    ->iconColor('primary')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.'))
                    ->suffix(' und')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('adjustments_count')
                    ->label('Ajustes')
                    ->counts('adjustments')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} ajuste".($state > 1 ? 's' : '') : '—'),

                Tables\Columns\TextColumn::make('effective_quantity')
                    ->label('Cant. Efectiva')
                    ->getStateUsing(function ($record) {
                        $adjustments = $record->adjustments()->sum('quantity');
                        $effective = (float) $record->quantity + (float) $adjustments;
                        if ((float) $adjustments == 0) {
                            return rtrim(rtrim(number_format($record->quantity, 4, '.', ''), '0'), '.');
                        }
                        $original = rtrim(rtrim(number_format($record->quantity, 4, '.', ''), '0'), '.');
                        $final = rtrim(rtrim(number_format($effective, 4, '.', ''), '0'), '.');

                        return "{$original} → {$final}";
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Costo Unitario')
                    ->money('COP', true)
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total')
                    ->money('COP', true)
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Solicitante')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('destination.name')
                    ->label('Destino')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('article.stock_quantity')
                    ->label('Stock Disponible')
                    ->getStateUsing(function ($record) {
                        $realStock = (float) ($record->article?->stock_quantity ?? 0);
                        $isDraft = $this->getOwnerRecord()?->status === 'inactive';

                        $available = $realStock;
                        if ($isDraft) {
                            $articleId = $record->article_id;
                            $pending = $record->transaction?->details ?? collect();

                            $available = $realStock - $pending
                                ->filter(fn ($d) => (int) $d->article_id === (int) $articleId)
                                ->reject(fn ($d) => filled($d->adjusts_detail_id))
                                ->sum(function ($d) {
                                    return (float) $d->quantity + (float) $d->adjustments()->sum('quantity');
                                });
                        }

                        return max(0.0, $available);
                    })
                    ->formatStateUsing(fn ($state, $record) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' '.($record->article?->unit_of_measure ?? 'und'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= (float) config('inventory.stock_minimo', 2) ? 'warning' : 'success'))
                    ->tooltip(fn ($record) => $this->getOwnerRecord()?->status === 'inactive'
                        ? 'Stock real menos las salidas pendientes de esta factura (borrador). Al guardar se aplicará al inventario.'
                        : 'Stock actual del artículo en inventario'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar retiro')
                    ->icon('heroicon-o-plus')
                    ->disabled(fn () => $this->isMonthClosed() || ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()))
                    ->beforeFormFilled(function (CreateAction $action) {
                        if ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()) {
                            Notification::make()
                                ->title('Factura ya guardada')
                                ->body('Esta factura ya fue guardada. No se pueden agregar artículos.')
                                ->warning()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                        if ($this->isMonthClosed()) {
                            $this->sendClosedMonthNotification();
                            $action->halt();
                        }
                    })
                    ->mutateDataUsing(function (array $data): array {
                        $data['total_cost'] = ($data['quantity'] ?? 0) * ($data['unit_cost'] ?? 0);

                        return $data;
                    })
                    ->after(fn ($livewire) => $livewire->dispatch('dispatch-detail-changed')),
            ])
            ->actions([
                EditAction::make()
                    ->disabled(fn () => $this->isMonthClosed() || ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()))
                    ->beforeFormFilled(function (EditAction $action) {
                        if ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()) {
                            Notification::make()
                                ->title('Factura ya guardada')
                                ->body('Esta factura ya fue guardada. No se pueden editar artículos.')
                                ->warning()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                        if ($this->isMonthClosed()) {
                            $this->sendClosedMonthNotification();
                            $action->halt();
                        }
                    })
                    ->mutateDataUsing(function (array $data): array {
                        $data['total_cost'] = ($data['quantity'] ?? 0) * ($data['unit_cost'] ?? 0);

                        return $data;
                    }),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('¿Eliminar retiro?')
                    ->modalDescription('Esta acción eliminará el artículo del detalle. No se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->modalCancelActionLabel('Cancelar')
                    ->disabled(fn () => $this->isMonthClosed() || ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()))
                    ->before(function (DeleteAction $action) {
                        if ($this->isTransactionActive() && ! $this->canEditInReopenedMonth()) {
                            Notification::make()
                                ->title('Factura ya guardada')
                                ->body('Esta factura ya fue guardada. No se pueden eliminar artículos.')
                                ->warning()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                        if ($this->isMonthClosed()) {
                            $this->sendClosedMonthNotification();
                            $action->halt();
                        }
                    })
                    ->after(fn ($livewire) => $livewire->dispatch('dispatch-detail-changed')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('id', 'desc')
            ->striped()
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->emptyStateHeading('Sin retiros')
            ->emptyStateDescription('Agrega artículos a este despacho usando el botón de arriba.');
    }
}
