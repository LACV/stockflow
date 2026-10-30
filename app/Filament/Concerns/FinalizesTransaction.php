<?php

namespace App\Filament\Concerns;

use App\Services\MovementService;
use Filament\Notifications\Notification;

trait FinalizesTransaction
{
    public function finalize(): void
    {
        $transaction = $this->record;

        if ($transaction->status !== 'inactive') {
            return;
        }

        $result = app(MovementService::class)->finalizeTransaction($transaction);

        if (! $result['ok']) {
            $notification = Notification::make()->title($result['title'])->body($result['body'])->persistent();
            $result['title'] === 'Sin artículos' ? $notification->warning() : $notification->danger();
            $notification->send();

            return;
        }

        $this->record->refresh();

        try {
            Notification::make()
                ->title($result['title'])
                ->body($result['body'])
                ->success()
                ->persistent()
                ->send();

            if (method_exists($this, 'refreshFormData')) {
                $this->refreshFormData(['status']);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $this->redirect($this->getResource()::getUrl('index'));
    }
}
