<?php

namespace App\Filament\Concerns;

use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

trait ReopensTransaction
{
    protected function reopenPermission(): string
    {
        $model = $this->getResource()::getModel();

        return 'Reopen:'.class_basename($model);
    }

    protected function canReopenFactura(): bool
    {
        return $this->record?->status === 'active'
            && auth()->user()?->can($this->reopenPermission());
    }

    protected function getReopenAction(): Action
    {
        return Action::make('reopenFactura')
            ->label('Reabrir factura')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => $this->canReopenFactura())
            ->requiresConfirmation()
            ->modalHeading('¿Reabrir factura?')
            ->modalDescription('Se revertirá el efecto de esta factura sobre el stock y quedará editable para corregir cantidades o costos. Al guardarla de nuevo el stock se recalculará.')
            ->modalSubmitActionLabel('Sí, reabrir')
            ->modalCancelActionLabel('Cancelar')
            ->action(fn () => $this->reopenFactura());
    }

    public function reopenFactura(): void
    {
        $transaction = $this->record;
        $date = Carbon::parse($transaction->transaction_date);

        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        $isReopened = $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
        $monthClosed = $closure && $closure->is_closed && ! $isReopened;

        if ($monthClosed) {
            Notification::make()
                ->title('Mes cerrado')
                ->body('No se puede reabrir esta factura porque su mes está cerrado contablemente. Primero reabre el mes.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        DB::transaction(function () use ($transaction) {
            app(MovementService::class)->revertTransactionStock($transaction);
            $transaction->status = 'inactive';
            $transaction->saveQuietly();
        });

        $this->record->refresh();

        Notification::make()
            ->title('Factura reabierta')
            ->body('La factura quedó editable. El stock se recalculará al guardarla de nuevo.')
            ->success()
            ->persistent()
            ->send();

        if (method_exists($this, 'refreshFormData')) {
            $this->refreshFormData(['status']);
        }
    }
}
