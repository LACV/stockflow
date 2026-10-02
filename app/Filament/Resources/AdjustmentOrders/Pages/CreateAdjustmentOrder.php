<?php

namespace App\Filament\Resources\AdjustmentOrders\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Concerns\ValidatesClosedMonth;
use App\Filament\Resources\AdjustmentOrders\AdjustmentOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdjustmentOrder extends CreateRecord
{
    use PreventsUnsavedChanges, ValidatesClosedMonth;

    protected static string $resource = AdjustmentOrderResource::class;

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
        // inactive: sin artículos. El observer lo pasa a active automáticamente
        // cuando se agrega el primer artículo al detalle.
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
