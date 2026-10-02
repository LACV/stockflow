<?php

namespace App\Filament\Resources\AdjustmentOrders\Pages;

use App\Filament\Resources\AdjustmentOrders\AdjustmentOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdjustmentOrders extends ListRecords
{
    protected static string $resource = AdjustmentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
