<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class DispatchOrder extends InventoryTransaction
{
    protected $table = 'inventory_transactions';

    protected static function booted()
    {
        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', 'EXIT');
        });

        static::creating(function ($model) {
            $model->type = 'EXIT';
        });
    }
}
