<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class AdjustmentOrder extends InventoryTransaction
{
    protected $table = 'inventory_transactions';

    protected static function booted()
    {
        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', 'ADJUSTMENT');
        });

        static::creating(function ($model) {
            $model->type = 'ADJUSTMENT';
        });
    }
}
