<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notificações do ciclo de vida Telegram — reutiliza mailer da plataforma.
 * Evita flood na API Telegram (mensagens diretas só quando identidade e bot ok).
 */
class TelegramAccessNotificationService
{
    public function __construct(
        private TelegramAccessEventLogger $events,
        private TelegramApiClient $api,
    ) {}

    public function accessExpiringSoon(TelegramAccessGrant $grant, int $daysLeft): void
    {
        $this->mailBuyer(
            $grant,
            'Seu acesso Telegram vence em breve',
            "Seu acesso a \"{$this->productName($grant)}\" vence em {$daysLeft} dia(s). "
            .'Renove para manter a entrada no grupo/canal.'
        );
        $this->events->record($grant, TelegramAccessEvent::EXPIRY_NOTICE_SENT, [
            'days_left' => $daysLeft,
        ]);
    }

    public function accessExpired(TelegramAccessGrant $grant): void
    {
        $this->mailBuyer(
            $grant,
            'Seu acesso Telegram expirou',
            "O prazo de \"{$this->productName($grant)}\" encerrou. "
            .'Se renovar, um novo período começa a partir da confirmação do pagamento.'
        );
    }

    public function accessRenewed(TelegramAccessGrant $grant): void
    {
        $ends = $grant->displayInAppTimezone('access_ends_at') ?? 'vitalício';
        $this->mailBuyer(
            $grant,
            'Renovação Telegram confirmada',
            "A renovação de \"{$this->productName($grant)}\" foi confirmada. Novo vencimento: {$ends}."
        );
        $this->maybeTelegramDm($grant, "Renovação confirmada para {$this->productName($grant)}. Vencimento: {$ends}.");
    }

    public function accessRevoked(TelegramAccessGrant $grant, string $reason): void
    {
        $this->mailBuyer(
            $grant,
            'Acesso Telegram revogado',
            "O acesso a \"{$this->productName($grant)}\" foi revogado ({$reason})."
        );
    }

    public function deliveryFailed(TelegramAccessGrant $grant, string $detail): void
    {
        $this->mailBuyer(
            $grant,
            'Falha na entrega Telegram',
            "Houve uma falha ao entregar o acesso a \"{$this->productName($grant)}\": {$detail}. "
            .'Nossa equipe pode compensar o período afetado quando a falha for técnica.'
        );
    }

    public function compensationApplied(TelegramAccessGrant $grant, int $seconds): void
    {
        $hours = max(1, (int) ceil($seconds / 3600));
        $ends = $grant->displayInAppTimezone('access_ends_at') ?? '—';
        $this->mailBuyer(
            $grant,
            'Compensação de acesso Telegram',
            "Compensamos aproximadamente {$hours}h de indisponibilidade em \"{$this->productName($grant)}\". "
            ."Novo vencimento: {$ends}."
        );
    }

    private function productName(TelegramAccessGrant $grant): string
    {
        $grant->loadMissing('product');

        return (string) ($grant->product?->name ?? 'produto Telegram');
    }

    private function mailBuyer(TelegramAccessGrant $grant, string $subject, string $body): void
    {
        $grant->loadMissing('user');
        $user = $grant->user;
        if (! $user instanceof User) {
            return;
        }
        $email = trim((string) $user->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('telegram.notify.mail_failed', [
                'grant_id' => $grant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function maybeTelegramDm(TelegramAccessGrant $grant, string $text): void
    {
        $grant->loadMissing('bot');
        if (! $grant->telegram_user_id || ! $grant->bot?->plainToken()) {
            return;
        }

        try {
            $this->api->sendMessage($grant->bot->plainToken(), $grant->telegram_user_id, $text);
        } catch (TelegramApiException $e) {
            Log::info('telegram.notify.dm_failed', [
                'grant_id' => $grant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
