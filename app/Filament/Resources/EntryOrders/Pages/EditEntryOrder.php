<?php

namespace App\Filament\Resources\EntryOrders\Pages;

use App\Filament\Concerns\FinalizesTransaction;
use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Concerns\ReopensTransaction;
use App\Filament\Concerns\ValidatesClosedMonth;
use App\Filament\Resources\EntryOrders\EntryOrderResource;
use App\Models\MonthlyClosure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditEntryOrder extends EditRecord
{
    use FinalizesTransaction, PreventsUnsavedChanges, ReopensTransaction, ValidatesClosedMonth;

    protected static string $resource = EntryOrderResource::class;

    public function getTotalFactura(): string
    {
        $total = $this->record->details()->sum('total_cost');

        return '$ '.number_format((float) $total, 2, '.', ',');
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        $actions[] = Action::make('total')
            ->label(fn () => 'Total Factura: '.$this->getTotalFactura())
            ->color('warning')
            ->icon('heroicon-o-currency-dollar')
            ->disabled()
            ->extraAttributes(['class' => 'pointer-events-none opacity-100! cursor-default']);

        if ($this->record->status === 'inactive') {
            $actions[] = Action::make('finalize')
                ->label('Guardar Entrada')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('¿Guardar Entrada?')
                ->modalDescription('Una vez guardada la entrada no podrá editar los artículos.')
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
    }

    #[On('entry-detail-changed')]
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
    }
}
