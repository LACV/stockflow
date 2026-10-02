<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyClosureSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stock_quantity' => 'float',
        'average_cost' => 'float',
        'total_value' => 'float',
    ];

    public function closure()
    {
        return $this->belongsTo(MonthlyClosure::class, 'monthly_closure_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }
}
