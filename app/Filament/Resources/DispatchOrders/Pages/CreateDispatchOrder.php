<?php

namespace App\Filament\Resources\DispatchOrders\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Concerns\ValidatesClosedMonth;
use App\Filament\Concerns\ValidatesOnePerDay;
use App\Filament\Resources\DispatchOrders\DispatchOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDispatchOrder extends CreateRecord
{
    use PreventsUnsavedChanges, ValidatesClosedMonth, ValidatesOnePerDay;

    protected static string $resource = DispatchOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data['status'] = 'inactive';

        return $data;
    }

    protected function beforeCreate(): void
    {
        if ($this->checkAndNotifyIfMonthClosed('transaction_date')) {
            $this->halt();
        }

        if ($this->checkAndNotifyIfDayAlreadyHasDispatch('transaction_date')) {
            $this->halt();
        }
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
