<?php

namespace App\Services;

use App\Mail\SubscriptionReminderMail;
use App\Models\Subscription;
use App\Support\PublicAppUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SubscriptionReminderService
{
    /**
     * @return list<int>
     */
    public function reminderDays(): array
    {
        $days = config('getfy.subscriptions.reminder_days', [7, 3, 1, 0, -1, -2, -3, -7]);
        if (! is_array($days) || $days === []) {
            return [7, 3, 1, 0, -1, -2, -3, -7];
        }

        return array_values(array_map('intval', $days));
    }

    public function sendScheduledReminders(): void
    {
        $today = Carbon::today();
        $days = $this->reminderDays();
        $windowStart = $today->copy()->addDays(min($days));
        $windowEnd = $today->copy()->addDays(max($days));

        $subscriptions = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->subscriptionPlan || $subscription->subscriptionPlan->isLifetime()) {
                continue;
            }

            $periodEnd = Carbon::parse($subscription->current_period_end)->startOfDay();
            $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($periodEnd, false);
            if (! in_array($daysLeft, $days, true)) {
                continue;
            }

            $this->sendForSubscription($subscription, $this->stageForDaysLeft($daysLeft), $today);
        }
    }

    public function sendForSubscription(Subscription $subscription, string $stage, ?Carbon $today = null): bool
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);

        $plan = $subscription->subscriptionPlan;
        if (! $plan || $plan->isLifetime()) {
            return false;
        }

        $user = $subscription->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($subscription->renewal_token)) {
            $subscription->renewal_token = Subscription::generateRenewalToken();
            $subscription->save();
        }

        $periodEnd = Carbon::parse($subscription->current_period_end)->startOfDay();
        $idempotencyKey = sprintf(
            'subscription_reminder:%s:%s:%s:%s',
            $subscription->id,
            $periodEnd->toDateString(),
            $stage,
            $today->toDateString()
        );
        if (! Cache::add($idempotencyKey, 1, now()->addDays(5))) {
            return false;
        }

        [$subject, $body] = $this->reminderMessage($subscription, $today);

        try {
            $this->sendMail((int) $subscription->tenant_id, $user->email, $subject, $body);
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            Log::warning('SubscriptionReminderService: falha ao enviar lembrete.', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Reenvio pedido pelo infoprodutor. Não usa a trava diária do lembrete automático.
     */
    public function sendManualReminder(Subscription $subscription): void
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)) {
            throw new \RuntimeException('Esta assinatura não pode receber uma cobrança de renovação.');
        }

        $plan = $subscription->subscriptionPlan;
        if (! $plan || $plan->isLifetime()) {
            throw new \RuntimeException('Este plano não tem cobrança de renovação.');
        }

        $user = $subscription->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('O assinante não tem um e-mail válido.');
        }

        $lockKey = 'subscription_reminder_manual:'.$subscription->id;
        if (! Cache::add($lockKey, 1, now()->addMinute())) {
            throw new \RuntimeException('Aguarde um minuto para reenviar a cobrança.');
        }

        if (empty($subscription->renewal_token)) {
            $subscription->renewal_token = Subscription::generateRenewalToken();
            $subscription->save();
        }

        try {
            [$subject, $body] = $this->reminderMessage($subscription, Carbon::today());
            $this->sendMail((int) $subscription->tenant_id, $user->email, $subject, $body);
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function reminderMessage(Subscription $subscription, Carbon $today): array
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;
        $periodEnd = $subscription->current_period_end
            ? Carbon::parse($subscription->current_period_end)->startOfDay()
            : $today->copy();
        $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($periodEnd, false);
        $renewalUrl = rtrim(PublicAppUrl::base(), '/').'/renovar/'.$subscription->renewal_token;
        $planName = e($plan?->name ?? 'assinatura');
        $productName = e($subscription->product?->name ?? 'seu produto');
        $productLabel = $subscription->product?->name ?? 'seu produto';

        if ($daysLeft > 0) {
            $subject = 'Lembrete: sua assinatura de '.$productLabel.' vence em '.$daysLeft.' dia(s)';
            $headline = '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') vence em <strong>'.$daysLeft.' dia(s)</strong>.</p>';
        } elseif ($daysLeft === 0) {
            $subject = 'Atenção: sua assinatura de '.$productLabel.' vence hoje';
            $headline = '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') <strong>vence hoje</strong>.</p>';
        } else {
            $daysOverdue = abs($daysLeft);
            $subject = 'Sua assinatura de '.$productLabel.' está vencida';
            $headline = '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') está vencida há <strong>'.$daysOverdue.' dia(s)</strong>.</p>';
        }

        $greeting = '<p>Olá'.($user?->name ? ', '.e($user->name) : '').'!</p>';
        $body = $greeting.$headline;
        $body .= '<p>Para renovar e manter seu acesso, use o link abaixo:</p>';
        $body .= '<p><a href="'.e($renewalUrl).'" style="display:inline-block;padding:12px 24px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:8px;">Renovar agora</a></p>';
        $body .= '<p>Ou copie e cole no navegador: '.e($renewalUrl).'</p>';

        return [$subject, $body];
    }

    public function stageForDaysLeft(int $daysLeft): string
    {
        if ($daysLeft > 0) {
            return 'd-'.$daysLeft;
        }
        if ($daysLeft === 0) {
            return 'd0';
        }

        return 'd+'.abs($daysLeft);
    }

    private function sendMail(int $tenantId, string $email, string $subject, string $body): void
    {
        $mailConfig = app(TenantMailConfigService::class);
        $attempts = [];

        if ($mailConfig->isEmailConfigured($tenantId)) {
            $attempts[] = function () use ($mailConfig, $tenantId): void {
                $mailConfig->applyMailerConfigForTenant($tenantId, [], null);
            };
        }

        if ($mailConfig->isEmailConfigured(null)) {
            $attempts[] = function () use ($mailConfig): void {
                $mailConfig->applyPlatformGlobalMailerConfig();
            };
        }

        if ($attempts === []) {
            throw new \RuntimeException('Nenhum SMTP configurado para enviar lembrete de assinatura.');
        }

        $lastError = null;
        foreach ($attempts as $apply) {
            try {
                $apply();
                $mailConfig->assertSmtpHostIsConfigured();
                Mail::purge('smtp');
                Mail::mailer('smtp')->to($email)->send(new SubscriptionReminderMail($subject, $body));

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Falha ao enviar lembrete de assinatura.');
    }
}
