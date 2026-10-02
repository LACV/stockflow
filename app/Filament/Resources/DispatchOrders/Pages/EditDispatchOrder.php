<?php

namespace App\Filament\Resources\DispatchOrders\Pages;

use App\Filament\Concerns\FinalizesTransaction;
use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Concerns\ReopensTransaction;
use App\Filament\Concerns\ValidatesClosedMonth;
use App\Filament\Concerns\ValidatesOnePerDay;
use App\Filament\Resources\DispatchOrders\DispatchOrderResource;
use App\Models\MonthlyClosure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditDispatchOrder extends EditRecord
{
    use FinalizesTransaction, PreventsUnsavedChanges, ReopensTransaction, ValidatesClosedMonth, ValidatesOnePerDay;

    protected static string $resource = DispatchOrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->record->status === 'inactive') {
            $actions[] = Action::make('finalize')
                ->label('Guardar Despacho')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('¿Guardar Despacho?')
                ->modalDescription('Una vez guardado el despacho no podrá editar los artículos.')
                ->modalSubmitActionLabel('Sí, guardar')
                ->modalCancelActionLabel('Cancelar')
                ->action(fn () => $this->finalize());
        }

        $actions[] = ViewAction::make();
        $actions[] = DeleteAction::make();
        $actions[] = $this->getReopenAction();

        return $actions;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeSave(): void
    {
        if ($this->record->status === 'active') {
            $closure = MonthlyClosure::where('month', $this->record->transaction_date->month)
                ->where('year', $this->record->transaction_date->year)
                ->first();
            $isReopened = $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
            if (! $isReopened) {
                $this->halt();
            }
        }
        if ($this->checkAndNotifyIfMonthClosed('transaction_date')) {
            $this->halt();
        }
        if ($this->checkAndNotifyIfDayAlreadyHasDispatch('transaction_date')) {
            $this->halt();
        }
    }

    #[On('dispatch-detail-changed')]
    public function refreshStatus(): void
    {
        $this->record->refresh();
        parent::refreshFormData(['status']);
    }

    public function updated($name, $value)
    {
        if ($name === 'transaction_date' && $this->checkAndNotifyIfMonthClosed('transaction_date')) {
            $this->halt();
        }

        if ($name === 'transaction_date' && $this->checkAndNotifyIfDayAlreadyHasDispatch('transaction_date')) {
            $this->halt();
        }
    }
}
