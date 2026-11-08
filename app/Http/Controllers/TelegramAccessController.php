<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramDeliveryJob;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Services\Telegram\TelegramAccessGrantService;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramInviteService;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TelegramAccessController extends Controller
{
    public function show(
        Request $request,
        int $grant,
        TelegramAccessGrantService $grants,
        TelegramInviteService $invites,
        TelegramApiClient $api,
        TelegramWebhookService $webhooks,
    ): Response {
        $access = $grants->findForBuyer((int) $request->user()->id, $grant);
        abort_if($access === null, 404);
        $access->loadMissing('bot');

        // Se o webhook do bot estiver quebrado, tenta re-registrar (ex.: CSRF 419 antigo).
        $bot = $access->bot;
        $token = $bot?->plainToken();
        if ($bot && $token) {
            try {
                $info = $api->getWebhookInfo($token);
                $url = (string) ($info['url'] ?? '');
                $lastError = (string) ($info['last_error_message'] ?? '');
                $expected = route('webhooks.telegram', ['secret' => $bot->webhook_path_secret], absolute: true);
                if ($url === '' || $lastError !== '' || ! str_starts_with($url, rtrim($expected, '/'))) {
                    $webhooks->syncWebhook($bot);
                }
            } catch (TelegramApiException $e) {
                Log::info('telegram.access.webhook_heal_skipped', [
                    'grant_id' => $access->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Recuperação: se a fila não gerou o convite ainda, tenta na abertura da página.
        $needsInvite = $access->communities->contains(function (TelegramAccessCommunity $row) {
            return ! in_array($row->status, [
                TelegramAccessCommunity::STATUS_JOINED,
                TelegramAccessCommunity::STATUS_REVOKED,
            ], true)
                && (empty($row->invite_link)
                    || ($row->invite_link_expires_at && $row->invite_link_expires_at->isPast()));
        });

        if ($needsInvite) {
            try {
                $invites->processGrant($access, false);
                ProcessTelegramDeliveryJob::dispatch((int) $access->id);
            } catch (\Throwable $e) {
                report($e);
            }

            $access = $grants->findForBuyer((int) $request->user()->id, $grant) ?? $access;
            $access->loadMissing('bot');
        }

        return Inertia::render('Cliente/TelegramAccess', [
            'grant' => $access->toBuyerArray(),
            'events' => $access->events
                ->take(30)
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'event_label' => TelegramAccessEvent::labelFor($e->event_type),
                    'occurred_at' => $e->occurred_at?->utc()->toIso8601String(),
                    'occurred_at_label' => $e->occurred_at
                        ?->timezone(config('app.timezone'))
                        ->format('d/m/Y H:i'),
                    'payload' => $e->payload,
                ])
                ->values()
                ->all(),
            'pageTitle' => 'Acesso Telegram',
        ]);
    }

    public function refreshInvites(
        Request $request,
        int $grant,
        TelegramAccessGrantService $grants,
        TelegramInviteService $invites,
    ): RedirectResponse {
        $access = $grants->findForBuyer((int) $request->user()->id, $grant);
        abort_if($access === null, 404);

        $invites->processGrant($access, false);
        ProcessTelegramDeliveryJob::dispatch((int) $access->id);

        return back()->with('success', 'Atualizamos seus convites. Se ainda faltarem links, aguarde alguns segundos e recarregue.');
    }
}
