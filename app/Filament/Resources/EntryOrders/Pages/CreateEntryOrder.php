<?php

namespace App\Filament\Resources\EntryOrders\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Concerns\ValidatesClosedMonth;
use App\Filament\Resources\EntryOrders\EntryOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEntryOrder extends CreateRecord
{
    use PreventsUnsavedChanges, ValidatesClosedMonth;

    protected static string $resource = EntryOrderResource::class;

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
    }

    public function updated($name, $value)
    {
        if ($name === 'transaction_date' && $this->checkAndNotifyIfMonthClosed('transaction_date')) {
            $this->halt();
        }
    }
}
