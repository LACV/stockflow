<?php

namespace App\Observers;

use App\Models\MonthlyClosure;
use App\Services\MonthlyClosureService;
use Carbon\Carbon;

class MonthlyClosureObserver
{
    public function __construct(
        private MonthlyClosureService $closureService,
    ) {}

    public function created(MonthlyClosure $closure): void
    {
        if ($closure->is_closed) {
            $this->closureService->regenerateBalances($closure);
            $this->closureService->regenerateSnapshot($closure);
            $this->createNextMonth($closure);
        }
    }

    public function updated(MonthlyClosure $closure): void
    {
        if ($closure->is_closed && ! $closure->getOriginal('is_closed')) {
            $this->closureService->regenerateBalances($closure);
            $this->closureService->regenerateSnapshot($closure);
            $this->createNextMonth($closure);
        }
    }

    protected function createNextMonth(MonthlyClosure $closure): void
    {
        $nextDate = Carbon::create($closure->year, $closure->month, 1)->addMonth();
        $nextMonth = $nextDate->month;
        $nextYear = $nextDate->year;

        $exists = MonthlyClosure::where('month', $nextMonth)
            ->where('year', $nextYear)
            ->exists();

        if (! $exists) {
            MonthlyClosure::create([
                'month' => $nextMonth,
                'year' => $nextYear,
                'is_closed' => false,
            ]);
        }
    }
}
