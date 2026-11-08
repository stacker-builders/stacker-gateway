<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccessEvent extends Model
{
    public const PAYMENT_CONFIRMED = 'payment_confirmed';

    public const ACCESS_GRANTED = 'access_granted';

    public const IDENTITY_LINKED = 'identity_linked';

    public const INVITE_GENERATED = 'invite_generated';

    public const JOIN_REQUEST_APPROVED = 'join_request_approved';

    public const ENTRY_CONFIRMED = 'entry_confirmed';

    public const DELIVERY_PARTIAL = 'delivery_partial';

    public const DELIVERY_COMPLETED = 'delivery_completed';

    public const TECHNICAL_FAILURE = 'technical_failure';

    public const MANUAL_CORRECTION = 'manual_correction';

    public const INVITE_REPROCESSED = 'invite_reprocessed';

    public const ACCESS_RENEWED = 'access_renewed';

    public const ACCESS_EXPIRED = 'access_expired';

    public const ACCESS_REVOKED = 'access_revoked';

    public const MEMBER_REMOVED = 'member_removed';

    public const MEMBER_REMOVAL_SKIPPED = 'member_removal_skipped';

    public const MEMBER_REMOVAL_FAILED = 'member_removal_failed';

    public const COMPENSATION_APPLIED = 'compensation_applied';

    public const EXPIRY_NOTICE_SENT = 'expiry_notice_sent';

    protected $fillable = [
        'tenant_id',
        'telegram_access_grant_id',
        'telegram_access_community_id',
        'event_type',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(TelegramAccessGrant::class, 'telegram_access_grant_id');
    }

    public static function labelFor(?string $eventType): string
    {
        return match ((string) $eventType) {
            self::PAYMENT_CONFIRMED => 'Pagamento confirmado',
            self::ACCESS_GRANTED => 'Acesso concedido',
            self::IDENTITY_LINKED => 'Telegram vinculado',
            self::INVITE_GENERATED => 'Convite gerado',
            self::JOIN_REQUEST_APPROVED => 'Solicitação de entrada aprovada',
            self::ENTRY_CONFIRMED => 'Entrada confirmada',
            self::DELIVERY_PARTIAL => 'Entrega parcial',
            self::DELIVERY_COMPLETED => 'Entrega concluída',
            self::TECHNICAL_FAILURE => 'Falha técnica',
            self::MANUAL_CORRECTION => 'Correção manual',
            self::INVITE_REPROCESSED => 'Convite reprocessado',
            self::ACCESS_RENEWED => 'Acesso renovado',
            self::ACCESS_EXPIRED => 'Acesso vencido',
            self::ACCESS_REVOKED => 'Acesso revogado',
            self::MEMBER_REMOVED => 'Membro removido',
            self::MEMBER_REMOVAL_SKIPPED => 'Remoção dispensada',
            self::MEMBER_REMOVAL_FAILED => 'Falha na remoção',
            self::COMPENSATION_APPLIED => 'Compensação aplicada',
            self::EXPIRY_NOTICE_SENT => 'Aviso de vencimento enviado',
            default => $eventType !== null && $eventType !== '' ? $eventType : 'Evento',
        };
    }
}
