<?php

namespace Tests\Unit;

use App\Filament\Support\ArticleSearch;
use App\Models\Article;
use Tests\TestCase;

class ArticleSearchTest extends TestCase
{
    public function test_label_shows_live_stock_without_pending(): void
    {
        $article = new Article([
            'name' => 'Guantes',
            'stock_quantity' => 500,
            'unit_of_measure' => 'und',
        ]);

        $this->assertSame('Guantes — Stock: 500 und', ArticleSearch::label($article, true));
    }

    public function test_label_shows_projected_stock_with_pending(): void
    {
        $article = new Article([
            'id' => 7,
            'name' => 'Guantes',
            'stock_quantity' => 500,
            'unit_of_measure' => 'und',
        ]);

        $this->assertSame('Guantes — Stock: 400 und', ArticleSearch::label($article, true, [7 => 100]));
    }

    public function test_label_clamps_projected_stock_to_zero(): void
    {
        $article = new Article([
            'id' => 7,
            'name' => 'Guantes',
            'stock_quantity' => 500,
            'unit_of_measure' => 'und',
        ]);

        $this->assertStringContainsString('Stock: 0 und', ArticleSearch::label($article, true, [7 => 700]));
    }

    public function test_label_ignores_pending_when_stock_flag_off(): void
    {
        $article = new Article(['id' => 7, 'name' => 'Guantes']);

        $this->assertSame('Guantes', ArticleSearch::label($article, false, [7 => 100]));
    }
}
