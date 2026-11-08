<?php

namespace App\Jobs;

use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 90;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 45, 90];
    }

    public function __construct(public int $eventId)
    {
        $this->onQueue((string) config('telegram.queue', 'webhooks-inbound'));
    }

    public function handle(TelegramWebhookService $webhooks): void
    {
        $event = TelegramWebhookEvent::query()->find($this->eventId);
        if (! $event) {
            return;
        }

        try {
            $webhooks->processEvent($event);
        } catch (\Throwable $e) {
            $event->forceFill([
                'status' => TelegramWebhookEvent::STATUS_FAILED,
                'error' => $e->getMessage(),
                'processed_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
