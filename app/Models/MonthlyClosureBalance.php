<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyClosureBalance extends Model
{
    protected $guarded = [];

    public function closure()
    {
        return $this->belongsTo(MonthlyClosure::class, 'monthly_closure_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }
}
