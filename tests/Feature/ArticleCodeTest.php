<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_generated_when_creating_article_without_code(): void
    {
        $article = Article::create(['name' => 'Artículo de prueba']);

        $this->assertNotNull($article->code);
        $this->assertMatchesRegularExpression('/^\d{5}$/', $article->code);
    }

    public function test_code_is_regenerated_when_cleared_on_edit(): void
    {
        $article = Article::create(['name' => 'Artículo de prueba']);

        $article->code = null;
        $article->name = 'Artículo editado';
        $article->save();

        $this->assertNotNull($article->code);
        $this->assertMatchesRegularExpression('/^\d{5}$/', $article->code);
    }

    public function test_generated_code_does_not_collide_with_existing_code(): void
    {
        Article::create(['name' => 'Existente', 'code' => '99999']);
        $article = Article::create(['name' => 'Nuevo']);

        $this->assertNotEquals('99999', $article->code);
    }

    public function test_keeps_manually_provided_code(): void
    {
        $article = Article::create(['name' => 'Manual', 'code' => 'INV123']);

        $this->assertEquals('INV123', $article->code);
    }
}
