<?php

namespace App\Filament\Resources\EntryOrders\Tables;

use App\Filament\Support\MonthFilter;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class EntryOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('provider'))
            ->columns([
                TextColumn::make('provider.provider')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-building-office-2')
                    ->iconColor('gray')
                    ->description(fn ($record) => $record->invoice_number ? 'Factura: '.$record->invoice_number : null),

                TextColumn::make('invoice_number')
                    ->label('N° Factura')
                    ->searchable(),

                TextColumn::make('purchase_order')
                    ->label('Orden de Compra')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transaction_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-calendar-days')
                    ->iconColor('primary'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inactive' => 'warning',
                        'active' => 'success',
                        'annulled' => 'danger',
                        default => 'warning',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'inactive' => 'heroicon-o-clock',
                        'active' => 'heroicon-o-check-circle',
                        'annulled' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-clock',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'inactive' => 'Inactiva',
                        'active' => 'Activa',
                        'annulled' => 'Anulada',
                        default => ucfirst($state),
                    }),

                TextColumn::make('details_count')
                    ->label('Ítems')
                    ->counts('details')
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-o-cube'),
            ])
            ->filters([
                MonthFilter::make(),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'inactive' => 'Inactiva',
                        'active' => 'Activa',
                        'annulled' => 'Anulada',
                    ]),

                SelectFilter::make('provider_id')
                    ->label('Proveedor')
                    ->relationship('provider', 'provider')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersFormColumns(2)
            ->defaultGroup(
                Group::make('transaction_date')
                    ->label('Fecha')
                    ->date()
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn ($record) => new HtmlString(Carbon::parse($record->transaction_date)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')))
            )
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }
}
