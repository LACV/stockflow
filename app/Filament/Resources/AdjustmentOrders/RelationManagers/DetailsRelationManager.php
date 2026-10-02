<?php

namespace App\Filament\Resources\AdjustmentOrders\RelationManagers;

use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Support\ArticleSearch;
use App\Models\Article;
use App\Models\InventoryTransactionDetail;
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
use Filament\Tables\Table;

class DetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'details';

    protected static ?string $title = 'Artículos Ajustados';

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

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('article_id')
                ->label('Artículo')
                ->relationship('article', modifyQueryUsing: fn ($query) => $query->where('is_active', true)->with('brand')->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn (Article $record) => $record->name.($record->reference ? ' · '.$record->reference : '').($record->brand ? ' · ('.$record->brand->name.')' : ''))
                ->searchable(['name', 'reference'])
                ->getSearchResultsUsing(ArticleSearch::forSelect())
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, $set, $get) {
                    if ($state) {
                        $article = Article::find($state);
                        if ($article) {
                            $lastEntryPrice = $article->prices()
                                ->whereIn('source', ['ENTRY', 'MANUAL'])
                                ->latest('date')
                                ->first();
                            if ($lastEntryPrice) {
                                $set('unit_cost', $lastEntryPrice->price);
                            } elseif ($article->current_average_cost > 0) {
                                $set('unit_cost', $article->current_average_cost);
                            }
                            $set('_current_stock', $article->stock_quantity);
                            $set('_current_avg_cost', $article->current_average_cost);
                        }
                    } else {
                        $set('_current_stock', null);
                        $set('_current_avg_cost', null);
                    }
                })
                ->createOptionForm(ArticleForm::getCreateFields()),

            TextInput::make('quantity')
                ->label('Cantidad Ajustada')
                ->helperText(function ($get) {
                    $stock = $get('_current_stock');
                    if ($stock !== null) {
                        $qty = (float) ($get('quantity') ?? 0);
                        $resultado = (float) $stock + $qty;
                        $fmtStock = rtrim(rtrim(number_format((float) $stock, 4, '.', ''), '0'), '.');
                        $fmtResult = rtrim(rtrim(number_format($resultado, 4, '.', ''), '0'), '.');

                        return "Stock actual: {$fmtStock} und → Resultante: {$fmtResult} und";
                    }

                    return 'Positivo para aumentar stock, negativo para reducirlo';
                })
                ->numeric()
                ->minValue(fn ($get) => $get('_current_stock') !== null ? -(float) max(0, (float) $get('_current_stock')) : null)
                ->validationMessages([
                    'min' => 'El ajuste no puede reducir el stock por debajo de cero.',
                ])
                ->required()
                ->live()
                ->suffix('und'),

            TextInput::make('unit_cost')
                ->label('Costo Unitario')
                ->helperText(function ($get) {
                    $avgCost = $get('_current_avg_cost');
                    if ($avgCost !== null) {
                        return 'Costo prom. actual: $'.number_format((float) $avgCost, 2, '.', ',');
                    }

                    return null;
                })
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->required(),

            Select::make('adjusts_detail_id')
                ->label('Corrige detalle de')
                ->helperText('Selecciona el detalle de entrada o salida que estás corrigiendo con este ajuste. Si el ajuste es general (ej. conteo físico), déjalo vacío.')
                ->placeholder('Ajuste general (sin corrección puntual)')
                ->visible(fn ($get) => filled($get('article_id')))
                ->options(function ($get) {
                    $articleId = $get('article_id');
                    if (! $articleId) {
                        return [];
                    }

                    return InventoryTransactionDetail::query()
                        ->where('article_id', $articleId)
                        ->whereHas('transaction', fn ($q) => $q->whereIn('type', ['ENTRY', 'EXIT']))
                        ->with(['transaction', 'article'])
                        ->latest()
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(function ($d) {
                            $t = $d->transaction;
                            $type = $t->type === 'ENTRY' ? 'ENTRADA' : 'SALIDA';
                            $date = $t->transaction_date
                                ? Carbon::parse($t->transaction_date)->format('d/m/Y')
                                : '—';
                            $ref = $t->invoice_number
                                ?? $t->reference_document
                                ?? $t->notes
                                ?? '—';
                            $qty = rtrim(rtrim(number_format($d->quantity, 4, '.', ''), '0'), '.');
                            $unit = $d->article?->unit_of_measure ?? 'und';

                            return [$d->id => "{$type} #{$t->id} · {$date} · {$ref} · {$qty} {$unit}"];
                        })
                        ->toArray();
                })
                ->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('article.name')
                    ->label('Artículo')
                    ->icon('heroicon-o-cube')
                    ->iconColor('warning')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.'))
                    ->suffix(' und')
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'warning')),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Costo Unitario')
                    ->money('COP', true)
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total')
                    ->money('COP', true)
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('article.stock_quantity')
                    ->label('Stock Actual')
                    ->formatStateUsing(fn ($state, $record) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' '.($record->article?->unit_of_measure ?? 'und'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= (float) config('inventory.stock_minimo', 2) ? 'warning' : 'success'))
                    ->tooltip('Stock actual del artículo en inventario'),

                Tables\Columns\TextColumn::make('resulting_stock')
                    ->label('Stock Resultante')
                    ->getStateUsing(fn ($record) => ($record->article?->stock_quantity ?? 0) + ($record->quantity ?? 0))
                    ->formatStateUsing(fn ($state, $record) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' '.($record->article?->unit_of_measure ?? 'und'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= (float) config('inventory.stock_minimo', 2) ? 'warning' : 'success'))
                    ->tooltip('Stock después de aplicar el ajuste'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar artículo')
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
                    ->after(fn ($livewire) => $livewire->dispatch('adjustment-detail-changed')),
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
                    ->modalHeading('¿Eliminar ajuste?')
                    ->modalDescription('Esta acción eliminará el artículo del ajuste. No se puede deshacer.')
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
                    ->after(fn ($livewire) => $livewire->dispatch('adjustment-detail-changed')),
            ])
            ->defaultSort('id', 'desc')
            ->striped()
            ->emptyStateIcon('heroicon-o-adjustments-horizontal')
            ->emptyStateHeading('Sin ajustes')
            ->emptyStateDescription('Agrega artículos a este ajuste usando el botón de arriba.');
    }
}
