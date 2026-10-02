<?php

namespace App\Observers;

use App\Models\InventoryTransaction;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class InventoryTransactionObserver
{
    public function creating(InventoryTransaction $transaction): void
    {
        $this->setMonthYear($transaction);
        $this->checkIfClosed($transaction);
    }

    public function updating(InventoryTransaction $transaction): void
    {
        if ($transaction->isDirty('transaction_date')) {
            $this->setMonthYear($transaction);
        }
        $this->checkIfClosed($transaction);
    }

    public function deleting(InventoryTransaction $transaction): void
    {
        $this->checkIfClosed($transaction);

        $transaction->loadMissing('details.article');
        foreach ($transaction->details as $detail) {
            $detail->delete();
        }
    }

    protected function setMonthYear(InventoryTransaction $transaction): void
    {
        $date = Carbon::parse($transaction->transaction_date);
        $transaction->month = $date->month;
        $transaction->year = $date->year;
    }

    protected function checkIfClosed(InventoryTransaction $transaction): void
    {
        $date = Carbon::parse($transaction->transaction_date);

        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        if (! $closure || ! $closure->is_closed) {
            return;
        }

        if ($closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure')) {
            return;
        }

        throw ValidationException::withMessages([
            'transaction_date' => "El mes seleccionado ya se encuentra cerrado contablemente ({$date->month}/{$date->year}). No se permiten más movimientos.",
        ]);
    }
}
