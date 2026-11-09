<?php

namespace App\Services\CajuPay;

use App\Models\Setting;

/**
 * Quando ligado, o saque CajuPay inclui a taxa de saída no valor enviado à API
 * (comportamento antigo). Desligado — padrão — envia o líquido exato; a taxa
 * sai do saldo da carteira na Caju.
 */
class CajuPayPayoutGrossUp
{
    public const SETTING_KEY = 'cajupay_gross_up_payout_fee';

    public static function enabled(): bool
    {
        $raw = Setting::get(self::SETTING_KEY, '0', null);
        if ($raw === null || $raw === '' || $raw === '0' || $raw === 0 || $raw === false) {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_KEY, $enabled ? '1' : '0', null);
    }
}
