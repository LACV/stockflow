<?php

namespace App\Models;

use App\Observers\ArticlePriceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(ArticlePriceObserver::class)]
class ArticlePrice extends Model
{
    protected $guarded = [];

    public function article()
    {
        return $this->belongsTo(Article::class);
    }
}
