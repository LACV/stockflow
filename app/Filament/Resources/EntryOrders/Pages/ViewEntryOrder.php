<?php

namespace App\Filament\Resources\EntryOrders\Pages;

use App\Filament\Resources\EntryOrders\EntryOrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEntryOrder extends ViewRecord
{
    protected static string $resource = EntryOrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->record->status === 'inactive') {
            $actions[] = Action::make('finalize')
                ->label('Guardar Entrada')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->url($this->getResource()::getUrl('edit', ['record' => $this->record]));
        }

        $actions[] = EditAction::make();

        return $actions;
    }
}
