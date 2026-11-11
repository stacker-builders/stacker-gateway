<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedDocument extends Model
{
    public const KIND_CPF = 'cpf';

    public const KIND_CNPJ = 'cnpj';

    protected $fillable = [
        'document',
        'kind',
        'user_id',
        'blocked_by',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }
}
