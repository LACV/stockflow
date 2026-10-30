<?php

namespace App\Filament\Resources\EntryOrders\RelationManagers;

use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Support\ArticleSearch;
use App\Models\Article;
use App\Models\MonthlyClosure;
use App\Services\EntryDeletionService;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class DetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'details';

    protected static ?string $title = 'Artículos de esta Entrada';

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
                ->getOptionLabelFromRecordUsing(fn (Article $record) => ArticleSearch::label($record, true))
                ->searchable(['name', 'reference'])
                ->getSearchResultsUsing(ArticleSearch::forSelect(withStock: true))
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
                            $set('_current_ppp', $article->current_average_cost);
                            $set('_last_prices', $article->prices()->latest('date')->limit(5)->get()->toArray());
                        }
                    } else {
                        $set('_current_ppp', null);
                        $set('_last_prices', null);
                    }
                })
                ->createOptionForm(ArticleForm::getCreateFields()),

            TextInput::make('quantity')
                ->label('Cantidad')
                ->helperText(function ($get) {
                    $articleId = $get('article_id');
                    if (! $articleId) {
                        return 'Selecciona un artículo para ver su stock';
                    }

                    $stock = (float) (Article::find($articleId)?->stock_quantity ?? 0);
                    $qty = (float) ($get('quantity') ?? 0);
                    $result = $stock + $qty;

                    $fmtStock = rtrim(rtrim(number_format($stock, 4, '.', ''), '0'), '.');
                    $fmtResult = rtrim(rtrim(number_format($result, 4, '.', ''), '0'), '.');

                    if ($stock <= (float) config('inventory.stock_minimo', 2)) {
                        return "Stock bajo: {$fmtStock} und en inventario → Resultante tras entrada: {$fmtResult} und.";
                    }

                    return "Stock actual: {$fmtStock} und → Resultante tras entrada: {$fmtResult} und.";
                })
                ->numeric()
                ->minValue(0.0001)
                ->live()
                ->required(),

            TextInput::make('unit_cost')
                ->label('Valor Unitario')
                ->helperText(function ($get) {
                    $prices = $get('_last_prices');
                    $ppp = $get('_current_ppp');
                    $lines = [];

                    if ($ppp !== null) {
                        $lines[] = 'PPP: $'.number_format((float) $ppp, 2, '.', ',');
                    }

                    if ($prices && is_array($prices) && count($prices) > 0) {
                        $latest = $prices[0];
                        $date = Carbon::parse($latest['date'])->format('d/m/Y');
                        $price = '$'.number_format((float) $latest['price'], 2, '.', ',');
                        $lines[] = 'Último precio: '.$price.' ('.$date.')';
                        $lines[] = count($prices).' registros';
                    }

                    return $lines ? implode(' · ', $lines) : 'Selecciona un artículo';
                })
                ->numeric()
                ->prefix('$')
                ->required()
                ->live(),

            Section::make('Últimos precios')
                ->icon('heroicon-o-clock')
                ->collapsible()
                ->collapsed()
                ->compact()
                ->columnSpanFull()
                ->visible(fn ($get) => ! empty($get('_last_prices')))
                ->schema([
                    Html::make(fn ($get) => view('components.price-history-table', [
                        'prices' => $get('_last_prices') ?? [],
                        'ppp' => $get('_current_ppp'),
                    ])->render()),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('article.name')
                    ->label('Artículo')
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
                    ->label('Valor Unitario')
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
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'warning'))
                    ->tooltip('Stock actual del artículo en inventario'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar artículo')
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
                    ->after(fn ($livewire) => $livewire->dispatch('entry-detail-changed')),
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
                    ->modalHeading('¿Eliminar artículo?')
                    ->modalDescription(function ($record) {
                        if (! $this->isTransactionActive()) {
                            return 'Esta acción eliminará el artículo del detalle. No se puede deshacer.';
                        }

                        $service = app(EntryDeletionService::class);
                        $plan = $service->planDetailDeletion($record);

                        return new HtmlString(nl2br(e($plan['blocked'] ?? $service->summarize($plan))));
                    })
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->modalCancelActionLabel('Cancelar')
                    ->disabled(fn () => $this->isMonthClosed())
                    ->before(function (DeleteAction $action, $record) {
                        if ($this->isMonthClosed()) {
                            $this->sendClosedMonthNotification();
                            $action->halt();
                        }

                        if ($this->isTransactionActive()) {
                            $plan = app(EntryDeletionService::class)->planDetailDeletion($record);

                            if ($plan['blocked'] !== null) {
                                Notification::make()->title('No se puede eliminar')->body($plan['blocked'])->danger()->persistent()->send();
                                $action->halt();
                            }
                        }
                    })
                    ->using(function ($record) {
                        if ($this->isTransactionActive()) {
                            app(EntryDeletionService::class)->deleteDetail($record);

                            return true;
                        }

                        return $record->delete();
                    })
                    ->after(fn ($livewire) => $livewire->dispatch('entry-detail-changed')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('id', 'desc')
            ->striped()
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin artículos')
            ->emptyStateDescription('Agrega artículos a esta entrada usando el botón de arriba.');
    }
}
