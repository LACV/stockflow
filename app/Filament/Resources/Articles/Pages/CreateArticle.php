<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    use PreventsUnsavedChanges;

    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['code'])) {
            $data['code'] = Article::generateUniqueCode();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
