<?php

namespace App\Models;

use App\Observers\MonthlyClosureObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(MonthlyClosureObserver::class)]
class MonthlyClosure extends Model
{
    protected $guarded = [];

    protected $casts = [
        'closed_at' => 'datetime',
        'is_closed' => 'boolean',
        'reopened_at' => 'datetime',
    ];

    public function balances()
    {
        return $this->hasMany(MonthlyClosureBalance::class);
    }

    public function snapshots()
    {
        return $this->hasMany(MonthlyClosureSnapshot::class);
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isReopened(): bool
    {
        return $this->reopened_at !== null;
    }

    /**
     * Solo se puede reabrir el ultimo mes cerrado: si existe un cierre posterior ya
     * cerrado, su inicial dependeria de este mes y quedaria desactualizado.
     */
    public function canBeReopened(): bool
    {
        if (! $this->is_closed || $this->isReopened()) {
            return false;
        }

        return ! static::query()
            ->where('is_closed', true)
            ->where(function ($q) {
                $q->where('year', '>', $this->year)
                    ->orWhere(fn ($q2) => $q2->where('year', $this->year)->where('month', '>', $this->month));
            })
            ->exists();
    }
}
