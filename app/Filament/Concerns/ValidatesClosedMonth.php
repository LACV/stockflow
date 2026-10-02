<?php

namespace App\Filament\Concerns;

use App\Models\MonthlyClosure;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

trait ValidatesClosedMonth
{
    protected function isMonthClosed(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        if (! $closure || ! $closure->is_closed) {
            return false;
        }

        if ($closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure')) {
            return false;
        }

        return true;
    }

    protected function notifyMonthClosed(Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $monthName = $this->getMonthName($date->month);

        Notification::make()
            ->title('Mes cerrado')
            ->body("El mes de {$monthName} {$date->year} está cerrado. No se pueden registrar movimientos en este período.")
            ->danger()
            ->persistent()
            ->send();
    }

    protected function checkAndNotifyIfMonthClosed(string $dateField): bool
    {
        $data = $this->form->getState();
        $rawDate = $data[$dateField] ?? null;

        if (! $rawDate) {
            return false;
        }

        if ($this->isMonthClosed($rawDate)) {
            $this->notifyMonthClosed($rawDate);

            return true;
        }

        return false;
    }

    private function getMonthName(int $month): string
    {
        return [
            1 => 'Enero',    2 => 'Febrero',   3 => 'Marzo',
            4 => 'Abril',    5 => 'Mayo',       6 => 'Junio',
            7 => 'Julio',    8 => 'Agosto',     9 => 'Septiembre',
            10 => 'Octubre',  11 => 'Noviembre',  12 => 'Diciembre',
        ][$month] ?? "Mes {$month}";
    }
}
