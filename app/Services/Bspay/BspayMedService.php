<?php

namespace App\Services\Bspay;

use App\Gateways\Bspay\BspayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Services\Med\MedPolicyService;
use App\Services\Med\MedResolutionService;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * MED / chargeback BSPay → MedDispute + pedido/carteira.
 *
 * @see https://dev.bspay.co/disputes/overview
 */
class BspayMedService
{
    public const REMOTE_ID_PREFIX = 'bspay:';

    public function __construct(
        protected MedPolicyService $policy,
        protected MedResolutionService $resolution,
        protected MedEmailNotifications $notifications,
    ) {}

    public static function isBspayDispute(MedDispute $dispute): bool
    {
        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        if (($meta['provider'] ?? null) === 'bspay') {
            return true;
        }

        $id = trim((string) ($dispute->cajupay_dispute_id ?? ''));

        return str_starts_with($id, self::REMOTE_ID_PREFIX);
    }

    public static function remoteIdFromDispute(MedDispute $dispute): string
    {
        $id = trim((string) ($dispute->cajupay_dispute_id ?? ''));
        if (str_starts_with($id, self::REMOTE_ID_PREFIX)) {
            return substr($id, strlen(self::REMOTE_ID_PREFIX));
        }

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];

        return trim((string) ($meta['bspay_infraction_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhookEvent(string $event, array $payload, Order $order): ?MedDispute
    {
        $data = $this->unwrapData($payload);
        $infractionId = trim((string) ($data['infraction_id'] ?? ''));
        if ($infractionId === '') {
            Log::info('BspayMed: webhook sem infraction_id', [
                'event' => $event,
                'order_id' => $order->id,
            ]);

            return null;
        }

        return match ($event) {
            'chargeback.opened' => $this->syncOpened($order, $data, $payload),
            'chargeback.responded' => $this->syncResponded($order, $data, $payload),
            'chargeback.won' => $this->syncResolved($order, $data, $payload, 'won'),
            'chargeback.lost', 'chargeback.confirmed' => $this->syncResolved($order, $data, $payload, 'lost'),
            'chargeback.canceled' => $this->syncResolved($order, $data, $payload, 'cancelled'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function syncOpened(Order $order, array $data, array $raw = []): MedDispute
    {
        $infractionId = trim((string) ($data['infraction_id'] ?? ''));
        if ($infractionId === '') {
            throw new \InvalidArgumentException('infraction_id ausente no webhook BSPay.');
        }

        $remoteKey = self::REMOTE_ID_PREFIX.$infractionId;
        $existing = MedDispute::query()->where('cajupay_dispute_id', $remoteKey)->first();
        if ($existing !== null && ! $existing->isOpen()) {
            $meta = is_array($existing->metadata) ? $existing->metadata : [];
            $meta['last_sync'] = $data;
            $existing->update(['metadata' => $meta]);

            return $existing->fresh();
        }

        $responsibleParty = $this->policy->responsiblePartyForOrder($order);
        $status = MedDispute::STATUS_OPEN;
        if ($existing !== null && $existing->status === MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $status = MedDispute::STATUS_DEFENSE_SUBMITTED;
        }

        $created = $existing === null;
        $type = strtoupper(trim((string) ($data['type'] ?? 'MED')));
        $reason = trim((string) ($data['reason'] ?? ''));
        $e2e = trim((string) ($data['e2e_id'] ?? ''));
        $amountCents = $this->amountCents($data, $order);

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $remoteKey],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $responsibleParty,
                'cajupay_payment_id' => trim((string) ($order->gateway_id ?? '')),
                'status' => $status,
                'outcome' => null,
                'amount_cents' => $amountCents,
                'currency' => strtoupper(trim((string) ($data['currency'] ?? 'BRL'))) ?: 'BRL',
                'txid' => $e2e !== '' ? $e2e : null,
                'reason' => $reason !== '' ? $reason : null,
                'reason_code' => $type !== '' ? $type : 'MED',
                'opened_at' => $existing?->opened_at ?? now(),
                'metadata' => [
                    'provider' => 'bspay',
                    'bspay_infraction_id' => $infractionId,
                    'bspay_status' => (string) ($data['status'] ?? 'open'),
                    'bspay_deadline_at' => $data['deadline_at'] ?? null,
                    'webhook' => $raw !== [] ? $raw : $data,
                ],
            ]
        );

        if ($this->policy->shouldHoldTenantBalance($dispute) && ! in_array($order->fresh()->status, ['disputed'], true)) {
            try {
                PlatformOrderAdminService::markDisputed($order->fresh());
            } catch (\InvalidArgumentException) {
                //
            }
        }

        if ($created) {
            $this->notifications->medOpened($dispute->fresh());
        }

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function syncResponded(Order $order, array $data, array $raw = []): MedDispute
    {
        $dispute = $this->findOrOpenFromPayload($order, $data, $raw);
        if ($dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
            $meta['bspay_status'] = 'responded';
            $meta['webhook_responded'] = $raw !== [] ? $raw : $data;
            $dispute->update([
                'status' => MedDispute::STATUS_DEFENSE_SUBMITTED,
                'defended_at' => $dispute->defended_at ?? now(),
                'metadata' => $meta,
            ]);
        }

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function syncResolved(Order $order, array $data, array $raw, string $outcome): MedDispute
    {
        $outcome = match (strtolower($outcome)) {
            'lost' => 'lost',
            'cancelled', 'canceled' => 'cancelled',
            default => 'won',
        };

        $dispute = $this->findOrOpenFromPayload($order, $data, $raw);
        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
            $meta['webhook_resolved'] = $raw !== [] ? $raw : $data;
            $dispute->update(['metadata' => $meta]);

            return $dispute->fresh();
        }

        $mappedStatus = match ($outcome) {
            'lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled' => MedDispute::STATUS_CANCELLED,
            default => MedDispute::STATUS_RESOLVED_WON,
        };

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['bspay_status'] = $outcome;
        $meta['webhook_resolved'] = $raw !== [] ? $raw : $data;

        $dispute->update([
            'status' => $mappedStatus,
            'outcome' => $outcome,
            'resolved_at' => now(),
            'metadata' => $meta,
        ]);

        $this->resolution->applyWalletOutcome($dispute->fresh(), $outcome);
        $this->notifications->medResolved($dispute->fresh());

        return $dispute->fresh();
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function submitDefense(MedDispute $dispute, string $text, array $attachments = []): MedDispute
    {
        if (! $dispute->isOpen()) {
            throw new \InvalidArgumentException('Esta disputa não está aberta para defesa.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Informe o texto da defesa.');
        }
        if (mb_strlen($text) > 5000) {
            throw new \InvalidArgumentException('A defesa na BSPay pode ter no máximo 5000 caracteres.');
        }
        if (count($attachments) > 5) {
            throw new \InvalidArgumentException('A BSPay aceita no máximo 5 anexos.');
        }
        foreach ($attachments as $file) {
            if ($file->getSize() > 10 * 1024 * 1024) {
                throw new \InvalidArgumentException('Cada anexo deve ter no máximo 10 MiB.');
            }
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                throw new \InvalidArgumentException('Anexos da BSPay devem ser PDF, JPG ou PNG.');
            }
        }

        $infractionId = self::remoteIdFromDispute($dispute);
        if ($infractionId === '') {
            throw new \InvalidArgumentException('Infração BSPay sem identificador remoto.');
        }

        $credential = GatewayCredential::resolveForPayment(null, 'bspay');
        if ($credential === null || ! $credential->is_connected) {
            throw new \RuntimeException('BSPay não configurada na plataforma.');
        }

        $driver = GatewayRegistry::driver('bspay');
        if (! $driver instanceof BspayDriver) {
            throw new \RuntimeException('Driver BSPay indisponível.');
        }

        $driver->submitInfractionReply(
            $credential->getDecryptedCredentials(),
            $infractionId,
            $text,
            $attachments
        );

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['bspay_status'] = 'responded';

        $dispute->update([
            'defense_text' => $text,
            'defended_at' => now(),
            'status' => MedDispute::STATUS_DEFENSE_SUBMITTED,
            'metadata' => $meta,
        ]);

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    private function findOrOpenFromPayload(Order $order, array $data, array $raw): MedDispute
    {
        $infractionId = trim((string) ($data['infraction_id'] ?? ''));
        if ($infractionId !== '') {
            $existing = MedDispute::query()
                ->where('cajupay_dispute_id', self::REMOTE_ID_PREFIX.$infractionId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $open = MedDispute::query()
            ->where('order_id', $order->id)
            ->open()
            ->latest('id')
            ->first();
        if ($open !== null && self::isBspayDispute($open)) {
            return $open;
        }

        return $this->syncOpened($order, $data, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountCents(array $data, Order $order): int
    {
        if (isset($data['amount_cents']) && is_numeric($data['amount_cents'])) {
            return (int) $data['amount_cents'];
        }
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            return (int) round(((float) $data['amount']) * 100);
        }

        return (int) round(((float) $order->amount) * 100);
    }
}
