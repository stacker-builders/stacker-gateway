<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Services\Med\MedPolicyService;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * MED / infrações Versell (Cash Out API) → MedDispute + alertas do sistema.
 *
 * @see https://docs.versell.com.br/docs/cash-out/infractions
 */
class VersellMedService
{
    public const REMOTE_ID_PREFIX = 'versell:';

    public function __construct(
        protected MedPolicyService $policy,
        protected MedEmailNotifications $notifications,
        protected VersellHttpClient $client = new VersellHttpClient(),
    ) {}

    public static function isVersellDispute(MedDispute $dispute): bool
    {
        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        if (($meta['provider'] ?? null) === 'versell') {
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

        return trim((string) ($meta['versell_infraction_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload  body do webhook ou item da API
     */
    public function syncFromInfractionPayload(array $payload): ?MedDispute
    {
        $infraction = $this->normalizeInfraction($payload);
        $infractionId = $infraction['id'];
        if ($infractionId === '') {
            Log::info('VersellMed: payload sem id de infração', [
                'gateway' => 'versell',
                'keys' => array_keys($payload),
            ]);

            return null;
        }

        $endToEndId = $infraction['end_to_end_id'];
        $order = $endToEndId !== '' ? $this->findOrderByEndToEndId($endToEndId) : null;
        if ($order === null) {
            Log::warning('VersellMed: pedido não encontrado para infração', [
                'gateway' => 'versell',
                'infraction_id' => $infractionId,
                'has_e2e' => $endToEndId !== '',
                'status' => $infraction['status'],
            ]);

            return null;
        }

        return $this->syncOpened($order, $infraction);
    }

    /**
     * @param  array{id: string, status: string, end_to_end_id: string, amount_cents: int, reason: ?string, deadline: ?string, raw: array<string, mixed>}  $infraction
     */
    public function syncOpened(Order $order, array $infraction): MedDispute
    {
        $remoteKey = self::REMOTE_ID_PREFIX.$infraction['id'];
        $existing = MedDispute::query()->where('cajupay_dispute_id', $remoteKey)->first();
        if ($existing !== null && ! $existing->isOpen()) {
            $meta = is_array($existing->metadata) ? $existing->metadata : [];
            $meta['versell_status'] = $infraction['status'];
            $meta['last_sync'] = $infraction['raw'];
            $existing->update(['metadata' => $meta]);

            return $existing->fresh();
        }

        $responsibleParty = $this->policy->responsiblePartyForOrder($order);
        $status = $this->mapOpenStatus($infraction['status']);
        // Se já está DEFENDED localmente, não rebaixar para open.
        if ($existing !== null && $existing->status === MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $status = MedDispute::STATUS_DEFENSE_SUBMITTED;
        }

        $created = $existing === null;

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $remoteKey],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $responsibleParty,
                'cajupay_payment_id' => trim((string) ($order->gateway_id ?? '')),
                'status' => $status,
                'outcome' => null,
                'amount_cents' => $infraction['amount_cents'] > 0
                    ? $infraction['amount_cents']
                    : (int) round(((float) $order->amount) * 100),
                'currency' => 'BRL',
                'txid' => $infraction['end_to_end_id'] !== '' ? $infraction['end_to_end_id'] : null,
                'reason' => $infraction['reason'],
                'reason_code' => 'MED',
                'opened_at' => $existing?->opened_at ?? now(),
                'metadata' => [
                    'provider' => 'versell',
                    'versell_infraction_id' => $infraction['id'],
                    'versell_status' => $infraction['status'],
                    'versell_deadline' => $infraction['deadline'],
                    'webhook' => $infraction['raw'],
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
        if (count($attachments) > 10) {
            throw new \InvalidArgumentException('Máximo de 10 anexos.');
        }
        foreach ($attachments as $file) {
            if ($file->getSize() > 8 * 1024 * 1024) {
                throw new \InvalidArgumentException('Cada anexo deve ter no máximo 8 MiB.');
            }
        }

        $infractionId = self::remoteIdFromDispute($dispute);
        if ($infractionId === '') {
            throw new \InvalidArgumentException('Infração Versell sem identificador remoto.');
        }

        $credential = GatewayCredential::resolveForPayment(null, 'versell');
        if ($credential === null || ! $credential->is_connected) {
            throw new \RuntimeException('Versell não configurada na plataforma.');
        }
        $credentials = $credential->getDecryptedCredentials();
        if (! VersellCredentials::isCashOutReady($credentials)) {
            throw new \RuntimeException('Credenciais Cash Out da Versell incompletas.');
        }

        $multipart = [
            ['name' => 'defense', 'contents' => $text],
        ];
        foreach ($attachments as $i => $file) {
            $multipart[] = [
                'name' => 'files',
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName() ?: ('evidence-'.$i.'.bin'),
            ];
        }

        $response = $this->client->requestMultipart(
            VersellCredentials::API_CASH_OUT,
            $credentials,
            'POST',
            '/infractions/'.rawurlencode($infractionId).'/defense',
            $multipart
        );

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            throw new \RuntimeException('Versell recusou a defesa: '.$problem['message']);
        }

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['versell_status'] = 'DEFENDED';
        $meta['defense_response'] = $response->json();

        $dispute->update([
            'defense_text' => $text,
            'defended_at' => now(),
            'status' => MedDispute::STATUS_DEFENSE_SUBMITTED,
            'metadata' => $meta,
        ]);

        return $dispute->fresh();
    }

    /**
     * Poll GET /infractions e abre disputas novas.
     *
     * @return array{synced: int, skipped: int, errors: int}
     */
    public function reconcileRecent(int $lookbackHours = 72): array
    {
        $credential = GatewayCredential::resolveForPayment(null, 'versell');
        if ($credential === null || ! $credential->is_connected) {
            return ['synced' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $credentials = $credential->getDecryptedCredentials();
        if (! VersellCredentials::isCashOutReady($credentials)) {
            return ['synced' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $end = now()->utc();
        $start = now()->utc()->subHours(max(1, $lookbackHours));
        $synced = 0;
        $skipped = 0;
        $errors = 0;
        $page = 1;

        do {
            try {
                $response = $this->client->request(
                    VersellCredentials::API_CASH_OUT,
                    $credentials,
                    'GET',
                    '/infractions?'.http_build_query([
                        'last_change_start' => $start->format('Y-m-d\TH:i:s\Z'),
                        'last_change_end' => $end->format('Y-m-d\TH:i:s\Z'),
                        'page_offset' => $page,
                        'page_limit' => 50,
                        'status' => 'ALL',
                    ])
                );
            } catch (\Throwable $e) {
                Log::warning('VersellMed: falha ao listar infrações', [
                    'gateway' => 'versell',
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);

                return ['synced' => $synced, 'skipped' => $skipped, 'errors' => $errors + 1];
            }

            if (! $response->successful()) {
                $errors++;
                break;
            }

            $json = $response->json();
            $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                try {
                    $before = MedDispute::query()->where(
                        'cajupay_dispute_id',
                        self::REMOTE_ID_PREFIX.trim((string) ($row['id'] ?? ''))
                    )->exists();
                    $dispute = $this->syncFromInfractionPayload($row);
                    if ($dispute !== null && ! $before) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('VersellMed: erro ao sincronizar infração', [
                        'gateway' => 'versell',
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ]);
                }
            }

            $totalPages = (int) ($json['pagination']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 20);

        return ['synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function findOrderByEndToEndId(string $endToEndId): ?Order
    {
        $endToEndId = trim($endToEndId);
        if ($endToEndId === '') {
            return null;
        }

        $byMeta = Order::query()
            ->where('gateway', 'versell')
            ->where(function ($q) use ($endToEndId) {
                $q->where('metadata->versell_end_to_end_id', $endToEndId)
                    ->orWhere('metadata->end_to_end_id', $endToEndId)
                    ->orWhere('metadata->endToEndId', $endToEndId);
            })
            ->latest('id')
            ->first();
        if ($byMeta !== null) {
            return $byMeta;
        }

        return Order::query()
            ->where(function ($q) use ($endToEndId) {
                $q->where('metadata->versell_end_to_end_id', $endToEndId)
                    ->orWhere('metadata->end_to_end_id', $endToEndId);
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string, end_to_end_id: string, amount_cents: int, reason: ?string, deadline: ?string, raw: array<string, mixed>}
     */
    public function normalizeInfraction(array $payload): array
    {
        $data = $payload;
        if (isset($payload['data']) && is_array($payload['data']) && ! isset($payload['id']) && ! isset($payload['endToEndId'])) {
            $data = $payload['data'];
        }

        $amount = $data['transactionAmount']['amount']
            ?? $data['transaction_amount']['amount']
            ?? $data['amount']
            ?? null;
        $amountCents = 0;
        if (is_numeric($amount)) {
            $amountCents = (int) round(((float) $amount) * 100);
        }

        $reason = trim((string) ($data['reportDetails'] ?? $data['report_details'] ?? $data['reason'] ?? ''));
        $deadline = $data['deadline'] ?? null;

        return [
            'id' => trim((string) ($data['id'] ?? $data['infractionId'] ?? '')),
            'status' => strtoupper(trim((string) ($data['status'] ?? ''))),
            'end_to_end_id' => trim((string) ($data['endToEndId'] ?? $data['end_to_end_id'] ?? '')),
            'amount_cents' => $amountCents,
            'reason' => $reason !== '' ? $reason : null,
            'deadline' => is_string($deadline) && $deadline !== '' ? $deadline : null,
            'raw' => $data,
        ];
    }

    private function mapOpenStatus(string $remoteStatus): string
    {
        $remoteStatus = strtoupper(trim($remoteStatus));
        if ($remoteStatus === 'DEFENDED') {
            return MedDispute::STATUS_DEFENSE_SUBMITTED;
        }

        // CLOSED e demais: abertura/atualização sem fechar carteira automaticamente
        // (resolução won/lost fica para admin ou webhook com outcome explícito).
        return MedDispute::STATUS_OPEN;
    }
}
