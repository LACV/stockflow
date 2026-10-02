<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $guarded = [];

    public static function generateUniqueCode(): string
    {
        do {
            $code = (string) random_int(10000, 99999);
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    public function prices()
    {
        return $this->hasMany(ArticlePrice::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn (Article $article) => $article->prices()->delete());

        static::saving(function (Article $article) {
            $article->stock_quantity = max(0, (float) $article->stock_quantity);
            $article->current_average_cost = abs((float) $article->current_average_cost);

            if (blank($article->code)) {
                $article->code = static::generateUniqueCode();
            }
        });
    }
}
