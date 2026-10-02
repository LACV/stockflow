<?php

namespace App\Filament\Resources\MonthlyClosures\Tables;

use App\Services\MonthlyClosureService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MonthlyClosuresTable
{
    public static function configure(Table $table): Table
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return $table
            ->columns([
                TextColumn::make('month')
                    ->label('Mes')
                    ->formatStateUsing(fn ($state) => $months[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('year')
                    ->label('Año')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('draft_note')
                    ->label('Nota')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_closed')
                    ->label('Cerrado/Abierto')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->getStateUsing(fn ($record) => $record && $record->isReopened() ? false : $record->is_closed),

                IconColumn::make('reopened_at')
                    ->label('Reabierto')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->trueIcon('heroicon-o-lock-open')
                    ->falseIcon('')
                    ->placeholder('—'),

                TextColumn::make('closed_at')
                    ->label('Fecha de Cierre')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->getStateUsing(fn ($record) => $record && $record->is_closed ? $record->closed_at : null),

                TextColumn::make('reopened_at')
                    ->label('Reabierto el')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->visible(fn ($record) => $record && $record->isReopened()),

                TextColumn::make('reopenedBy.name')
                    ->label('Reabierto por')
                    ->placeholder('—')
                    ->visible(fn ($record) => $record && $record->isReopened()),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver'),

                Action::make('reopen')
                    ->label('Reabrir mes')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn ($record) => $record->canBeReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                    ->requiresConfirmation()
                    ->modalHeading('¿Reabrir el mes?')
                    ->modalDescription(fn ($record) => 'Al reabrir este mes podrá editar directamente el inventario histórico del cierre (agregar, modificar o eliminar artículos, cantidades y costos) sin afectar el inventario principal actual. También podrá crear, editar y eliminar transacciones en '.($months[$record->month] ?? $record->month).' de '.$record->year.'.')
                    ->modalSubmitActionLabel('Sí, reabrir mes')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($record) {
                        if (! $record->canBeReopened()) {
                            Notification::make()
                                ->title('No se puede reabrir')
                                ->body('Solo se puede reabrir el último mes cerrado.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'reopened_at' => Carbon::now(),
                            'reopened_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Mes reabierto')
                            ->body('El mes ha sido reabierto.')
                            ->success()
                            ->send();
                    }),

                Action::make('recalculate')
                    ->label('Recalcular saldos')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn ($record) => $record->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                    ->requiresConfirmation()
                    ->modalHeading('¿Recalcular saldos?')
                    ->modalDescription(fn ($record) => 'Se recalcularán los saldos (balances) del cierre a partir del inventario histórico actual. El inventario histórico no se modifica.')
                    ->modalSubmitActionLabel('Sí, recalcular')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($record) {
                        $service = app(MonthlyClosureService::class);
                        $service->regenerateBalances($record);

                        Notification::make()
                            ->title('Saldos recalculados')
                            ->success()
                            ->send();
                    }),
                Action::make('reclose')
                    ->label('Re-cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn ($record) => $record->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                    ->requiresConfirmation()
                    ->modalHeading('¿Re-cerrar el mes?')
                    ->modalDescription(fn ($record) => 'Se finalizarán borradores, se recalcularán balances y se cerrará '.($months[$record->month] ?? $record->month).' '.$record->year.' nuevamente.')
                    ->modalSubmitActionLabel('Sí, cerrar')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($record) {
                        $service = app(MonthlyClosureService::class);
                        $service->finalizePendingTransactions($record);

                        $record->update([
                            'reopened_at' => null,
                            'reopened_by' => null,
                        ]);

                        $service->reclosePreservingExisting($record);

                        Notification::make()
                            ->title('Mes cerrado nuevamente')
                            ->success()
                            ->send();
                    }),

                Action::make('close_month')
                    ->label('Cerrar Mes')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn ($record) => ! $record->is_closed)
                    ->requiresConfirmation()
                    ->modalHeading('¿Cerrar el mes?')
                    ->modalDescription(fn ($record) => '¿Está seguro de que desea cerrar '.($months[$record->month] ?? $record->month).' de '.$record->year.'? Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, cerrar mes')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function ($record) {
                        $service = app(MonthlyClosureService::class);
                        $service->finalizePendingTransactions($record);

                        $record->update([
                            'is_closed' => true,
                            'closed_at' => Carbon::now(),
                            'draft_note' => null,
                        ]);

                        Notification::make()
                            ->title('Mes cerrado exitosamente')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('year', 'desc');
    }
}
