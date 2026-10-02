<?php

namespace App\Filament\Resources\DispatchOrders\Pages;

use App\Filament\Resources\DispatchOrders\DispatchOrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDispatchOrder extends ViewRecord
{
    protected static string $resource = DispatchOrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->record->status === 'inactive') {
            $actions[] = Action::make('finalize')
                ->label('Guardar Despacho')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->url($this->getResource()::getUrl('edit', ['record' => $this->record]));
        }

        $actions[] = EditAction::make();

        return $actions;
    }
}
