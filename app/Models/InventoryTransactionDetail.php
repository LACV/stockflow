<?php

namespace App\Models;

use App\Observers\InventoryTransactionDetailObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(InventoryTransactionDetailObserver::class)]
class InventoryTransactionDetail extends Model
{
    protected $guarded = [];

    public function transaction()
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }

    public function adjustedDetail()
    {
        return $this->belongsTo(self::class, 'adjusts_detail_id');
    }

    public function adjustments()
    {
        return $this->hasMany(self::class, 'adjusts_detail_id');
    }
}
