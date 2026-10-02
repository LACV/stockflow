<?php

namespace App\Filament\Concerns;

use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;

trait PreventsUnsavedChanges
{
    use HasUnsavedDataChangesAlert;

    public function mountPreventsUnsavedChanges(): void
    {
        $this->rememberData();
    }

    public function updated($name, $value): void
    {
        $this->rememberData();
    }

    protected function hasUnsavedChangesAlert(): bool
    {
        return true;
    }
}
