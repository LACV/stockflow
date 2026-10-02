<?php

namespace App\Filament\Support;

use App\Models\Article;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class ArticleSearch
{
    protected static function tokens(string $search): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($search))));
    }

    public static function label(Article $article, bool $withStock = false, ?array $pendingByArticle = null): string
    {
        $label = $article->name
            .($article->reference ? ' · '.$article->reference : '')
            .($article->brand ? ' · ('.$article->brand->name.')' : '');

        if ($withStock) {
            $available = (float) $article->stock_quantity;
            if ($pendingByArticle !== null) {
                $available = max(0.0, $available - (float) ($pendingByArticle[$article->id] ?? 0));
            }
            $stock = rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.');
            $label .= " — Stock: {$stock} ".($article->unit_of_measure ?? 'und');
        }

        return $label;
    }

    public static function forSelect(bool $withStock = false, ?array $pendingByArticle = null): Closure
    {
        return function (Select $component, ?string $search) use ($withStock, $pendingByArticle): array {
            return static::results($search, withStock: $withStock, pendingByArticle: $pendingByArticle);
        };
    }

    public static function results(?string $search, int $limit = 50, bool $withStock = false, ?array $pendingByArticle = null): array
    {
        return static::query($search)
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Article $article) => [$article->id => static::label($article, $withStock, $pendingByArticle)])
            ->toArray();
    }

    public static function applyTo(Builder $query, string $search): Builder
    {
        $tokens = static::tokens($search);

        if (! $tokens) {
            return $query;
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($token) {
                $q->where('articles.name', 'like', "%{$token}%")
                    ->orWhere('articles.reference', 'like', "%{$token}%")
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$token}%"));
            });
        }

        return $query;
    }

    protected static function query(?string $search): Builder
    {
        $query = Article::query()
            ->where('is_active', true)
            ->with('brand')
            ->orderBy('name');

        return static::applyTo($query, (string) $search);
    }
}
