<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Services\PlatformAuditService;
use Illuminate\Support\Carbon;

class TelegramAccessEventLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        TelegramAccessGrant $grant,
        string $eventType,
        array $payload = [],
        ?TelegramAccessCommunity $community = null,
        ?Carbon $occurredAt = null,
    ): TelegramAccessEvent {
        $safe = $this->sanitize($payload);

        $event = TelegramAccessEvent::query()->create([
            'tenant_id' => $grant->tenant_id,
            'telegram_access_grant_id' => $grant->id,
            'telegram_access_community_id' => $community?->id,
            'event_type' => $eventType,
            'payload' => $safe,
            'occurred_at' => ($occurredAt ?? now())->utc(),
        ]);

        PlatformAuditService::log('telegram.access.'.$eventType, [
            'tenant_id' => $grant->tenant_id,
            'grant_id' => $grant->id,
            'order_id' => $grant->order_id,
            'product_id' => $grant->product_id,
            'user_id' => $grant->user_id,
            'community_id' => $community?->telegram_community_id,
            'payload' => $safe,
        ]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        foreach (['token', 'bot_token', 'secret', 'secret_token', 'invite_link', 'password', 'authorization'] as $key) {
            unset($payload[$key]);
        }

        if (isset($payload['invite_url']) && is_string($payload['invite_url'])) {
            $payload['invite_url'] = '[redacted]';
        }

        return $payload;
    }
}
