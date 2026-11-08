<?php

namespace App\Console\Commands;

use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Services\Telegram\TelegramAccessNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class NotifyTelegramAccessExpiringCommand extends Command
{
    protected $signature = 'telegram:notify-expiring {--days=3 : Dias até o vencimento} {--limit=200}';

    protected $description = 'Notifica compradores com acesso Telegram próximo do vencimento.';

    public function handle(TelegramAccessNotificationService $notifications): int
    {
        if (! Schema::hasTable('telegram_access_grants')) {
            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $now = now()->utc();
        $until = $now->copy()->addDays($days)->format('Y-m-d H:i:s');
        $from = $now->format('Y-m-d H:i:s');

        $grants = TelegramAccessGrant::query()
            ->where('is_lifetime', false)
            ->whereNotNull('access_ends_at')
            ->whereBetween('access_ends_at', [$from, $until])
            ->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
            ])
            ->orderBy('access_ends_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($grants as $grant) {
            $already = TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::EXPIRY_NOTICE_SENT)
                ->where('occurred_at', '>=', $now->copy()->subDays($days)->format('Y-m-d H:i:s'))
                ->exists();
            if ($already) {
                continue;
            }

            $ends = $grant->utcMoment('access_ends_at');
            $daysLeft = max(1, (int) ceil($now->diffInSeconds($ends, false) / 86400));
            $notifications->accessExpiringSoon($grant, $daysLeft);
            $sent++;
        }

        $this->info("Avisos de vencimento enviados: {$sent}");

        return self::SUCCESS;
    }
}
