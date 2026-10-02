<?php

namespace App\Filament\Support;

use App\Models\MonthlyClosure;
use Carbon\Carbon;

class MonthHelper
{
    public static function isMonthClosed(Carbon|string|null $date): bool
    {
        if (! $date) {
            return false;
        }

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
}
