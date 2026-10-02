<?php

namespace App\Filament\Concerns;

use App\Models\DispatchOrder;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

trait ValidatesOnePerDay
{
    protected function hasDispatchOnDate(Carbon|string $date, ?int $excludeId = null): bool
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $query = DispatchOrder::withoutGlobalScopes()
            ->where('type', 'EXIT')
            ->whereDate('transaction_date', $date->toDateString())
            ->where('status', '!=', 'annulled');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    protected function notifyDayAlreadyHasDispatch(Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        Notification::make()
            ->title('Salida ya existente')
            ->body("Ya existe una salida registrada para el día {$date->format('d/m/Y')}. Solo se permite una salida por día.")
            ->danger()
            ->persistent()
            ->send();
    }

    protected function checkAndNotifyIfDayAlreadyHasDispatch(string $dateField): bool
    {
        $data = $this->form->getState();
        $rawDate = $data[$dateField] ?? null;

        if (! $rawDate) {
            return false;
        }

        $excludeId = $this->record?->id ?? null;

        if ($this->hasDispatchOnDate($rawDate, $excludeId)) {
            $this->notifyDayAlreadyHasDispatch($rawDate);

            return true;
        }

        return false;
    }
}
