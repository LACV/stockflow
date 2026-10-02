<?php

namespace App\Filament\Resources\AdjustmentOrders\Pages;

use App\Filament\Resources\AdjustmentOrders\AdjustmentOrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdjustmentOrder extends ViewRecord
{
    protected static string $resource = AdjustmentOrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->record->status === 'inactive') {
            $actions[] = Action::make('finalize')
                ->label('Guardar Ajuste')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->url($this->getResource()::getUrl('edit', ['record' => $this->record]));
        }

        $actions[] = EditAction::make();

        return $actions;
    }
}
