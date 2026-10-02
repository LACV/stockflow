<?php

namespace App\Filament\Resources\MonthlyClosures\Pages;

use App\Filament\Resources\MonthlyClosures\MonthlyClosureResource;
use App\Services\MonthlyClosureService;
use App\Services\MonthlyMovementsExcelService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewMonthlyClosure extends ViewRecord
{
    protected static string $resource = MonthlyClosureResource::class;

    protected function getHeaderActions(): array
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return [
            Action::make('previewExcel')
                ->label('Vista Previa Excel')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->url(fn () => url('/admin/preview-excel/'.$this->record->id)),

            Action::make('downloadExcel')
                ->label('Descargar Excel del mes')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn () => $this->record->is_closed)
                ->action(function () {
                    return app(MonthlyMovementsExcelService::class)
                        ->stream($this->record->month, $this->record->year);
                }),

            Action::make('reopen')
                ->label('Reabrir mes')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn () => $this->record->canBeReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                ->requiresConfirmation()
                ->modalHeading('¿Reabrir el mes?')
                ->modalDescription('Al reabrir este mes podrá editar directamente el inventario histórico del cierre (agregar, modificar o eliminar artículos, cantidades y costos) sin afectar el inventario principal actual. También podrá crear, editar y eliminar transacciones en '.($months[$this->record->month] ?? $this->record->month).' de '.$this->record->year.'.')
                ->modalSubmitActionLabel('Sí, reabrir mes')
                ->modalCancelActionLabel('Cancelar')
                ->action(function () {
                    if (! $this->record->canBeReopened()) {
                        Notification::make()
                            ->title('No se puede reabrir')
                            ->body('Solo se puede reabrir el último mes cerrado.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->update([
                        'reopened_at' => Carbon::now(),
                        'reopened_by' => auth()->id(),
                    ]);

                    Notification::make()
                        ->title('Mes reabierto')
                        ->body('El mes ha sido reabierto. Ahora puede modificar las transacciones.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['reopened_at']);
                }),

            Action::make('recalculate')
                ->label('Recalcular saldos desde inventario')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->visible(fn () => $this->record->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                ->requiresConfirmation()
                ->modalHeading('¿Recalcular saldos?')
                ->modalDescription('Se recalcularán los saldos (balances) del cierre a partir del inventario histórico actual. El inventario histórico (snapshot) no se modifica.')
                ->modalSubmitActionLabel('Sí, recalcular')
                ->modalCancelActionLabel('Cancelar')
                ->action(function () {
                    $service = app(MonthlyClosureService::class);
                    $service->regenerateBalances($this->record);

                    Notification::make()
                        ->title('Cierre recalculado')
                        ->body('Los balances del cierre han sido recalculados desde las transacciones.')
                        ->success()
                        ->send();
                }),

            Action::make('reclose')
                ->label('Re-cerrar mes')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn () => $this->record->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure'))
                ->requiresConfirmation()
                ->modalHeading('¿Re-cerrar el mes?')
                ->modalDescription('Se finalizarán los borradores pendientes, se recalcularán los balances y se cerrará el mes nuevamente.')
                ->modalSubmitActionLabel('Sí, cerrar mes')
                ->modalCancelActionLabel('Cancelar')
                ->action(function () {
                    $service = app(MonthlyClosureService::class);
                    $service->finalizePendingTransactions($this->record);

                    $this->record->update([
                        'reopened_at' => null,
                        'reopened_by' => null,
                    ]);

                    $service->reclosePreservingExisting($this->record);

                    Notification::make()
                        ->title('Mes cerrado nuevamente')
                        ->body('El mes ha sido cerrado con los cambios aplicados.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['reopened_at']);
                }),

            Action::make('close_month')
                ->label('Cerrar Mes')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn () => ! $this->record->is_closed)
                ->requiresConfirmation()
                ->modalHeading('¿Cerrar el mes?')
                ->modalDescription(fn () => '¿Está seguro de que desea cerrar '.($months[$this->record->month] ?? $this->record->month).' de '.$this->record->year.'? Esta acción marcará el cierre como definitivo.')
                ->modalSubmitActionLabel('Sí, cerrar mes')
                ->modalCancelActionLabel('Cancelar')
                ->action(function () {
                    $this->record->update([
                        'is_closed' => true,
                        'closed_at' => Carbon::now(),
                        'draft_note' => null,
                    ]);

                    Notification::make()
                        ->title('Mes cerrado exitosamente')
                        ->success()
                        ->send();

                    $this->refreshFormData(['is_closed', 'closed_at', 'draft_note']);
                }),
        ];
    }
}
