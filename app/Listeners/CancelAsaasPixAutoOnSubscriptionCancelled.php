<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Services\Asaas\AsaasPixAutoLifecycleService;

class CancelAsaasPixAutoOnSubscriptionCancelled
{
    public function handle(SubscriptionCancelled $event): void
    {
        app(AsaasPixAutoLifecycleService::class)->cancelRemote($event->subscription);
    }
}
