<?php

namespace App\Filament\Resources\DispatchOrders\Tables;

use App\Filament\Support\MonthFilter;
use App\Services\MovementService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class DispatchOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->icon('heroicon-o-calendar-days')
                    ->iconColor('primary'),

                TextColumn::make('month')
                    ->label('Mes')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('year')
                    ->label('Año')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('observations')
                    ->label('Observaciones')
                    ->limit(50)
                    ->placeholder('_')
                    ->toggleable(),

                TextColumn::make('details_count')
                    ->label('N° Retiros')
                    ->counts('details')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->iconColor('danger'),

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
                    ->label('Fecha Creación')
                    ->dateTime('d/m/Y H:i')
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
                Action::make('finalize')
                    ->label('Guardar')
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->visible(fn ($record) => $record->status === 'inactive' && $record->details()->exists())
                    ->requiresConfirmation()
                    ->modalHeading('¿Guardar Despacho?')
                    ->modalDescription('Una vez guardado el despacho no podrá editar los artículos.')
                    ->modalSubmitActionLabel('Sí, guardar')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($record) {
                        $result = app(MovementService::class)->finalizeTransaction($record);

                        $notification = Notification::make()->title($result['title'])->body($result['body']);
                        $result['ok'] ? $notification->success() : $notification->danger()->persistent();
                        $notification->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }
}
