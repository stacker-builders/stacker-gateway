<?php

namespace App\Services\Withdrawal;

use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Platform\PlatformTotpService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WithdrawalPolicyService
{
    public const MANUAL_APPROVAL_PIN_MIN_LENGTH = 4;

    public const MANUAL_APPROVAL_PIN_MAX_LENGTH = 6;

    /** @var list<int> Segunda (1) a domingo (7), no padrão ISO do Carbon. */
    public const ALL_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    /** @var array<int, string> */
    private const WEEKDAY_LABELS = [
        1 => 'segunda',
        2 => 'terça',
        3 => 'quarta',
        4 => 'quinta',
        5 => 'sexta',
        6 => 'sábado',
        7 => 'domingo',
    ];

    public static function autoWithdrawalEnabled(): bool
    {
        $v = Setting::get('platform_auto_withdrawal_enabled', true, null);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function hoursEnabled(): bool
    {
        $v = Setting::get('platform_withdrawal_hours_enabled', false, null);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function timezone(): string
    {
        $tz = Setting::get('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        return is_string($tz) && $tz !== '' ? $tz : 'America/Sao_Paulo';
    }

    public static function hoursStart(): string
    {
        $v = Setting::get('platform_withdrawal_hours_start', '06:00', null);

        return is_string($v) && $v !== '' ? $v : '06:00';
    }

    public static function hoursEnd(): string
    {
        $v = Setting::get('platform_withdrawal_hours_end', '21:00', null);

        return is_string($v) && $v !== '' ? $v : '21:00';
    }

    /**
     * Dias em que a solicitação de saque é aceita. Sem configuração, todos os dias ficam liberados.
     *
     * @return list<int>
     */
    public static function allowedDays(): array
    {
        $raw = Setting::get('platform_withdrawal_allowed_days', null, null);
        if ($raw === null || $raw === '') {
            return self::ALL_WEEKDAYS;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return self::ALL_WEEKDAYS;
            }
            $raw = $decoded;
        }

        if (! is_array($raw)) {
            return self::ALL_WEEKDAYS;
        }

        return self::normalizeDays($raw);
    }

    /**
     * @param  list<int|string>  $days
     *
     * @throws ValidationException
     */
    public static function setAllowedDays(array $days): void
    {
        $normalized = self::normalizeDays($days);
        if ($normalized === []) {
            throw ValidationException::withMessages([
                'allowed_days' => 'Selecione ao menos um dia da semana.',
            ]);
        }

        Setting::set('platform_withdrawal_allowed_days', $normalized, null);
    }

    public static function intervalEnabled(): bool
    {
        $v = Setting::get('platform_withdrawal_interval_enabled', false, null);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function intervalHours(): int
    {
        $hours = (int) Setting::get('platform_withdrawal_interval_hours', 24, null);

        return max(0, min(8760, $hours));
    }

    public static function setInterval(bool $enabled, int $hours): void
    {
        $hours = max(1, min(8760, $hours));
        Setting::set('platform_withdrawal_interval_enabled', $enabled ? '1' : '0', null);
        Setting::set('platform_withdrawal_interval_hours', (string) $hours, null);
    }

    /**
     * @throws ValidationException
     */
    public static function assertIntervalAllows(int $tenantId): void
    {
        if (! self::intervalEnabled() || $tenantId < 1) {
            return;
        }

        $hours = self::intervalHours();
        if ($hours < 1) {
            return;
        }

        $last = Withdrawal::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['rejected', 'failed'])
            ->latest('id')
            ->first();

        if ($last === null || $last->created_at === null) {
            return;
        }

        $availableAt = $last->created_at->copy()->addHours($hours);
        if (now()->greaterThanOrEqualTo($availableAt)) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => self::intervalBlockedMessage($availableAt, $hours),
        ]);
    }

    public static function intervalBlockedMessage(Carbon $availableAt, int $hours): string
    {
        $when = $availableAt->copy()->timezone(self::timezone())->format('d/m/Y H:i');
        $unit = $hours === 1 ? 'hora' : 'horas';

        return "O intervalo mínimo entre saques é de {$hours} {$unit}. Você pode solicitar outro saque em {$when}.";
    }

    public static function allowsRequestAt(?Carbon $at = null): bool
    {
        $at = self::moment($at);

        if (! self::dayAllowed($at)) {
            return false;
        }

        if (! self::hoursEnabled()) {
            return true;
        }

        return self::withinHours($at);
    }

    /**
     * @throws ValidationException
     */
    public static function assertRequestAllowed(?Carbon $at = null): void
    {
        if (self::allowsRequestAt($at)) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => self::requestBlockedMessage($at),
        ]);
    }

    public static function requestBlockedMessage(?Carbon $at = null): string
    {
        $at = self::moment($at);
        $parts = [];

        if (! self::dayAllowed($at)) {
            $parts[] = 'Saques não são permitidos neste dia da semana. Dias liberados: '.self::allowedDaysLabel().'.';
        }

        if (self::hoursEnabled() && ! self::withinHours($at)) {
            $parts[] = 'Saques permitidos das '
                .self::hoursStart().' às '.self::hoursEnd()
                .' ('.self::timezone().').';
        }

        if ($parts === []) {
            return 'Saque não permitido neste momento.';
        }

        return implode(' ', $parts);
    }

    /**
     * PIN de operação só é exigido quando o admin não tem 2FA ativo.
     */
    public static function requiresOperationPinFor(User $user): bool
    {
        return ! PlatformTotpService::isEnabledFor($user);
    }

    public static function hasPayoutSecurityBarrier(User $user): bool
    {
        return PlatformTotpService::isEnabledFor($user) || self::hasManualApprovalPin();
    }

    public static function hasManualApprovalPin(): bool
    {
        $hash = Setting::get('platform_manual_approval_pin_hash', null, null);

        return is_string($hash) && $hash !== '';
    }

    public static function verifyManualApprovalPin(string $pin): bool
    {
        $hash = Setting::get('platform_manual_approval_pin_hash', null, null);
        if (! is_string($hash) || $hash === '') {
            return false;
        }

        return Hash::check($pin, $hash);
    }

    public static function setManualApprovalPin(string $pin): void
    {
        Setting::set('platform_manual_approval_pin_hash', Hash::make($pin), null);
    }

    public static function changeManualApprovalPin(string $currentPin, string $newPin): void
    {
        if (! self::verifyManualApprovalPin($currentPin)) {
            throw ValidationException::withMessages([
                'current_manual_approval_pin' => 'PIN atual incorreto.',
            ]);
        }

        self::setManualApprovalPin($newPin);
    }

    public static function generateResetPin(): string
    {
        $pin = '';
        for ($i = 0; $i < self::MANUAL_APPROVAL_PIN_MAX_LENGTH; $i++) {
            $pin .= (string) random_int(0, 9);
        }

        return $pin;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFrontendProps(): array
    {
        return [
            'auto_withdrawal_enabled' => self::autoWithdrawalEnabled(),
            'hours_enabled' => self::hoursEnabled(),
            'hours_start' => self::hoursStart(),
            'hours_end' => self::hoursEnd(),
            'allowed_days' => self::allowedDays(),
            'timezone' => self::timezone(),
            'has_manual_approval_pin' => self::hasManualApprovalPin(),
            'interval_enabled' => self::intervalEnabled(),
            'interval_hours' => self::intervalHours() > 0 ? self::intervalHours() : 24,
        ];
    }

    private static function moment(?Carbon $at): Carbon
    {
        return ($at ?? now())->copy()->timezone(self::timezone());
    }

    private static function dayAllowed(Carbon $at): bool
    {
        return in_array((int) $at->dayOfWeekIso, self::allowedDays(), true);
    }

    private static function withinHours(Carbon $at): bool
    {
        $start = self::parseTimeOnDate($at, self::hoursStart());
        $end = self::parseTimeOnDate($at, self::hoursEnd());

        if ($start->lte($end)) {
            return $at->gte($start) && $at->lte($end);
        }

        return $at->gte($start) || $at->lte($end);
    }

    /**
     * @param  array<int|string>  $days
     * @return list<int>
     */
    private static function normalizeDays(array $days): array
    {
        $normalized = [];
        foreach ($days as $day) {
            $n = (int) $day;
            if ($n >= 1 && $n <= 7) {
                $normalized[$n] = $n;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }

    private static function allowedDaysLabel(): string
    {
        $labels = [];
        foreach (self::allowedDays() as $day) {
            if (isset(self::WEEKDAY_LABELS[$day])) {
                $labels[] = self::WEEKDAY_LABELS[$day];
            }
        }

        if ($labels === []) {
            return 'nenhum';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' e '.$last;
    }

    private static function parseTimeOnDate(Carbon $date, string $time): Carbon
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return $date->copy()->setTime($hour, $minute, 0);
    }
}
