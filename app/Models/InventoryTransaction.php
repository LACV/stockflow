<?php

namespace App\Models;

use App\Observers\InventoryTransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(InventoryTransactionObserver::class)]
class InventoryTransaction extends Model
{
    protected $guarded = [];

    protected $casts = ['transaction_date' => 'date'];

    public function details()
    {
        return $this->hasMany(InventoryTransactionDetail::class, 'inventory_transaction_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
