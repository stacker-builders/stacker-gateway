<?php

namespace App\Jobs;

use App\Models\TelegramAccessGrant;
use App\Services\Telegram\TelegramInviteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Menor que o --timeout do worker (120) e que REDIS_QUEUE_RETRY_AFTER (180). */
    public int $timeout = 90;

    public int $uniqueFor = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(public int $grantId)
    {
        $this->onQueue((string) config('telegram.queue', 'webhooks-inbound'));
    }

    public function uniqueId(): string
    {
        return 'telegram-delivery-'.$this->grantId;
    }

    public function handle(TelegramInviteService $invites): void
    {
        $grant = TelegramAccessGrant::query()->with(['bot', 'communities.community'])->find($this->grantId);
        if (! $grant) {
            return;
        }

        if (in_array($grant->status, [
            TelegramAccessGrant::STATUS_REVOKED,
            TelegramAccessGrant::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        $invites->processGrant($grant);
    }
}
