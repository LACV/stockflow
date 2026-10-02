<?php

namespace App\Filament\Resources\EntryOrders\Pages;

use App\Filament\Resources\EntryOrders\EntryOrderResource;
use App\Models\InventoryTransactionDetail;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEntryOrders extends ListRecords
{
    protected static string $resource = EntryOrderResource::class;

    public function getTotalMes(): array
    {
        $value = $this->tableFilters['month_year']['value'] ?? null;

        $label = 'Total Histórico';

        if (filled($value)) {
            [$year, $month] = explode('-', $value);
            $label = 'Total '.Carbon::create((int) $year, (int) $month, 1)->locale('es')->isoFormat('MMMM YYYY');
        }

        $total = InventoryTransactionDetail::query()
            ->whereHas('transaction', function ($query) use ($value) {
                $query->where('type', 'entry')
                    ->where('status', 'active');

                if (filled($value)) {
                    [$year, $month] = explode('-', $value);
                    $query->whereYear('transaction_date', (int) $year)
                        ->whereMonth('transaction_date', (int) $month);
                }
            })
            ->sum('inventory_transaction_details.total_cost');

        return [
            'label' => $label,
            'total' => (float) $total,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('totalMes')
                ->label(function () {
                    $data = $this->getTotalMes();

                    return $data['label'].': $ '.number_format($data['total'], 2, '.', ',');
                })
                ->color('success')
                ->icon('heroicon-o-currency-dollar')
                ->disabled()
                ->extraAttributes(['class' => 'pointer-events-none opacity-100! cursor-default']),
            CreateAction::make(),
        ];
    }
}
