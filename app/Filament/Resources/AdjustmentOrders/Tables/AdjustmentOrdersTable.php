<?php

namespace App\Filament\Resources\AdjustmentOrders\Tables;

use App\Filament\Support\MonthFilter;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class AdjustmentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Fecha del Ajuste')
                    ->date('d/m/Y')
                    ->sortable()
                    ->icon('heroicon-o-calendar-days')
                    ->iconColor('primary'),

                TextColumn::make('invoice_number')
                    ->label('Documento Respaldo')
                    ->searchable()
                    ->placeholder('-')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('gray')
                    ->copyable()
                    ->copyMessage('Documento copiado'),

                TextColumn::make('observations')
                    ->label('Justificación')
                    ->limit(50),

                TextColumn::make('details_count')
                    ->label('Artículos Ajustados')
                    ->counts('details')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->iconColor('warning'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inactive' => 'warning',
                        'active' => 'success',
                        'annulled' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'inactive' => 'Inactiva',
                        'active' => 'Activa',
                        'annulled' => 'Anulada',
                        default => ucfirst($state),
                    }),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                MonthFilter::make(),
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
