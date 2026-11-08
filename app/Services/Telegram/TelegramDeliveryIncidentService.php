<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessCompensation;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramDeliveryIncident;
use App\Services\PlatformAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Estrutura de falhas técnicas e compensação futura (sem aplicação automática na 3.2).
 */
class TelegramDeliveryIncidentService
{
    public function tablesReady(): bool
    {
        return Schema::hasTable('telegram_delivery_incidents');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function openOrRefresh(
        TelegramAccessGrant $grant,
        string $reasonCode,
        string $reasonDetail,
        string $idempotencyKey,
        ?TelegramAccessCommunity $community = null,
        array $context = [],
    ): TelegramDeliveryIncident {
        if (! $this->tablesReady()) {
            throw new InvalidArgumentException('Tabela de incidentes Telegram ausente.');
        }

        $existing = TelegramDeliveryIncident::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $incident = TelegramDeliveryIncident::query()->create([
            'tenant_id' => $grant->tenant_id,
            'telegram_access_grant_id' => $grant->id,
            'telegram_community_id' => $community?->telegram_community_id,
            'idempotency_key' => $idempotencyKey,
            'reason_code' => $reasonCode,
            'reason_detail' => $reasonDetail,
            'started_at' => now()->utc(),
            'ended_at' => null,
            'duration_seconds' => null,
            'affected_user_ids' => [(int) $grant->user_id],
            'affected_community_ids' => $community
                ? [(int) $community->telegram_community_id]
                : $grant->communities()->pluck('telegram_community_id')->map(fn ($id) => (int) $id)->all(),
            'compensation_status' => TelegramDeliveryIncident::COMPENSATION_NONE,
            'compensation_seconds' => null,
            'metadata' => $this->safeMeta($context),
        ]);

        PlatformAuditService::log('telegram.delivery_incident.opened', [
            'incident_id' => $incident->id,
            'tenant_id' => $incident->tenant_id,
            'grant_id' => $grant->id,
            'reason_code' => $reasonCode,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $incident;
    }

    public function resolve(TelegramDeliveryIncident $incident, ?Carbon $endedAt = null): TelegramDeliveryIncident
    {
        if ($incident->ended_at !== null) {
            return $incident;
        }

        $ended = ($endedAt ?? now())->utc();
        $started = $incident->started_at?->utc() ?? $ended;
        $seconds = max(0, $ended->diffInSeconds($started));

        $incident->forceFill([
            'ended_at' => $ended,
            'duration_seconds' => $seconds,
            // Compensação futura: prepara duração sugerida, sem aplicar.
            'compensation_seconds' => $seconds,
            'compensation_status' => TelegramDeliveryIncident::COMPENSATION_PENDING_REVIEW,
        ])->save();

        PlatformAuditService::log('telegram.delivery_incident.resolved', [
            'incident_id' => $incident->id,
            'tenant_id' => $incident->tenant_id,
            'duration_seconds' => $seconds,
            'compensation_status' => $incident->compensation_status,
        ]);

        return $incident->fresh();
    }

    /**
     * Autorização explícita. Aplica automaticamente só se auto_compensation_enabled.
     */
    public function authorizeCompensation(TelegramDeliveryIncident $incident, int $authorizedBy, ?int $seconds = null): TelegramDeliveryIncident
    {
        if ($incident->compensation_status === TelegramDeliveryIncident::COMPENSATION_APPLIED) {
            return $incident;
        }

        $incident->forceFill([
            'compensation_status' => TelegramDeliveryIncident::COMPENSATION_AUTHORIZED,
            'compensation_seconds' => $seconds ?? $incident->compensation_seconds ?? $incident->duration_seconds,
            'compensation_authorized_at' => now()->utc(),
            'authorized_by' => $authorizedBy,
        ])->save();

        PlatformAuditService::log('telegram.delivery_incident.compensation_authorized', [
            'incident_id' => $incident->id,
            'tenant_id' => $incident->tenant_id,
            'authorized_by' => $authorizedBy,
            'compensation_seconds' => $incident->compensation_seconds,
        ]);

        if (TelegramModuleSettings::get()['auto_compensation_enabled'] ?? false) {
            $this->applyCompensation($incident->fresh(), $authorizedBy);
        }

        return $incident->fresh();
    }

    /**
     * Aplica extensão de vigência de forma idempotente (máx. duração do incidente).
     */
    public function applyCompensation(TelegramDeliveryIncident $incident, ?int $authorizedBy = null): TelegramAccessCompensation
    {
        if (! Schema::hasTable('telegram_access_compensations')) {
            throw new InvalidArgumentException('Tabela de compensações Telegram ausente.');
        }

        if (! $incident->telegram_access_grant_id) {
            throw new InvalidArgumentException('Incidente sem grant associado.');
        }

        $seconds = (int) ($incident->compensation_seconds ?? $incident->duration_seconds ?? 0);
        if ($seconds < 1) {
            throw new InvalidArgumentException('Compensação sem duração válida.');
        }

        // Limite de segurança: no máximo 30 dias por incidente.
        $seconds = min($seconds, 30 * 86400);
        $idempotencyKey = 'compensation-incident-'.$incident->id;

        $existing = TelegramAccessCompensation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($incident, $seconds, $idempotencyKey, $authorizedBy) {
            $again = TelegramAccessCompensation::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($again) {
                return $again;
            }

            /** @var TelegramAccessGrant $grant */
            $grant = TelegramAccessGrant::query()
                ->whereKey($incident->telegram_access_grant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($grant->is_lifetime) {
                throw new InvalidArgumentException('Acesso vitalício não recebe compensação de prazo.');
            }

            $previousEnds = $grant->utcMoment('access_ends_at') ?? now()->utc();
            $base = $previousEnds->greaterThan(now()->utc()) ? $previousEnds->copy() : now()->utc();
            $newEnds = $base->copy()->addSeconds($seconds);

            $grant->forceFill([
                'access_ends_at' => $newEnds->utc()->format('Y-m-d H:i:s'),
                'expired_at' => null,
                'status' => $grant->status === TelegramAccessGrant::STATUS_EXPIRED
                    ? TelegramAccessGrant::STATUS_COMPLETED
                    : $grant->status,
            ])->save();

            $row = TelegramAccessCompensation::query()->create([
                'tenant_id' => $grant->tenant_id,
                'telegram_access_grant_id' => $grant->id,
                'telegram_delivery_incident_id' => $incident->id,
                'idempotency_key' => $idempotencyKey,
                'seconds_added' => $seconds,
                'previous_ends_at' => $previousEnds->utc()->format('Y-m-d H:i:s'),
                'new_ends_at' => $newEnds->utc()->format('Y-m-d H:i:s'),
                'status' => TelegramAccessCompensation::STATUS_APPLIED,
                'authorized_by' => $authorizedBy ?? $incident->authorized_by,
                'reason' => $incident->reason_detail ?: $incident->reason_code,
                'metadata' => [
                    'reason_code' => $incident->reason_code,
                ],
            ]);

            $incident->forceFill([
                'compensation_status' => TelegramDeliveryIncident::COMPENSATION_APPLIED,
                'compensation_applied_at' => now()->utc(),
                'compensation_seconds' => $seconds,
            ])->save();

            app(TelegramAccessEventLogger::class)->record($grant, TelegramAccessEvent::COMPENSATION_APPLIED, [
                'incident_id' => $incident->id,
                'seconds_added' => $seconds,
                'new_ends_at' => $newEnds->toIso8601String(),
            ]);

            PlatformAuditService::log('telegram.delivery_incident.compensation_applied', [
                'incident_id' => $incident->id,
                'grant_id' => $grant->id,
                'seconds_added' => $seconds,
            ]);

            try {
                app(TelegramAccessNotificationService::class)->compensationApplied($grant->fresh(), $seconds);
            } catch (\Throwable) {
                // notificação não bloqueia compensação
            }

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function safeMeta(array $meta): array
    {
        unset($meta['token'], $meta['bot_token'], $meta['secret'], $meta['invite_link']);

        return $meta;
    }
}
