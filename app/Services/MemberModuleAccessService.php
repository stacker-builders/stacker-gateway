<?php

namespace App\Services;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberModuleAccess;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonInterface;

class MemberModuleAccessService
{
    public const META_RENEWAL = 'member_module_renewal';

    public const META_MODULE_ID = 'member_module_id';

    /** @var array<string, CarbonInterface> */
    private array $expireStartCache = [];

    public static function isRenewalOrder(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        return ! empty($meta[self::META_RENEWAL]);
    }

    public static function renewalModuleId(Order $order): ?int
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $id = $meta[self::META_MODULE_ID] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function enrollmentStartAt(Product $product, User $user): CarbonInterface
    {
        if ($this->isPreviewInspector($user, $product)) {
            return now()->subYears(20);
        }

        $createdAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('created_at');

        if ($createdAt) {
            return Carbon::parse($createdAt);
        }

        return now();
    }

    /**
     * @return array{
     *     is_locked: bool,
     *     available_at: ?string,
     *     expires_at: ?string,
     *     lock_message: ?string,
     *     lock_reason: ?string,
     *     can_renew: bool,
     *     renewal_amount: ?float
     * }
     */
    public function moduleLockPayload(MemberModule $module, Product $product, User $user, CarbonInterface $now): array
    {
        if ($this->isPreviewInspector($user, $product)) {
            return $this->unlockedLockPayload($module);
        }

        $enrollmentStartAt = $this->enrollmentStartAt($product, $user);
        $drip = $this->scheduleMeta($module->release_after_days, $module->release_at_date, $enrollmentStartAt);
        $dripLock = $this->dripLockPayload($drip['available_at'], $now, $drip['mode']);

        $expire = $this->expireMeta($module, $product, $user, $enrollmentStartAt);
        $expired = $expire['expires_at'] instanceof Carbon && $now->greaterThan($expire['expires_at']);

        if ($expired && ! $this->ignoresExpiration($user, $product)) {
            return [
                'is_locked' => true,
                'available_at' => $dripLock['available_at'],
                'expires_at' => $expire['expires_at']->toIso8601String(),
                'lock_message' => $this->expiredMessage($expire['expires_at'], $expire['mode']),
                'lock_reason' => 'expired',
                'can_renew' => $this->canRenew($module, $user, $product),
                'renewal_amount' => $this->renewalAmount($module),
            ];
        }

        return [
            'is_locked' => $dripLock['is_locked'],
            'available_at' => $dripLock['available_at'],
            'expires_at' => $expire['expires_at']?->toIso8601String(),
            'lock_message' => $dripLock['lock_message'],
            'lock_reason' => $dripLock['is_locked'] ? 'drip' : null,
            'can_renew' => false,
            'renewal_amount' => $this->renewalAmount($module),
        ];
    }

    /**
     * @return array{
     *     is_locked: bool,
     *     available_at: ?string,
     *     expires_at: ?string,
     *     lock_message: ?string,
     *     lock_reason: ?string,
     *     can_renew: bool,
     *     renewal_amount: ?float
     * }
     */
    public function lessonLockPayload(MemberLesson $lesson, ?MemberModule $module, Product $product, User $user, CarbonInterface $now): array
    {
        if ($this->isPreviewInspector($user, $product)) {
            return $this->unlockedLockPayload($module);
        }

        $moduleLock = $module ? $this->moduleLockPayload($module, $product, $user, $now) : null;
        if ($moduleLock && ($moduleLock['is_locked'] ?? false) === true) {
            return $moduleLock;
        }

        $enrollmentStartAt = $this->enrollmentStartAt($product, $user);
        $lessonMeta = $this->scheduleMeta($lesson->release_after_days, $lesson->release_at_date, $enrollmentStartAt);
        $moduleMeta = $module
            ? $this->scheduleMeta($module->release_after_days, $module->release_at_date, $enrollmentStartAt)
            : ['available_at' => null, 'mode' => null];

        $lessonAt = $lessonMeta['available_at'];
        $moduleAt = $moduleMeta['available_at'];
        $availableAt = null;
        $mode = null;
        if ($lessonAt && $moduleAt) {
            if ($lessonAt->greaterThanOrEqualTo($moduleAt)) {
                $availableAt = $lessonAt;
                $mode = $lessonMeta['mode'];
            } else {
                $availableAt = $moduleAt;
                $mode = $moduleMeta['mode'];
            }
        } elseif ($lessonAt) {
            $availableAt = $lessonAt;
            $mode = $lessonMeta['mode'];
        } elseif ($moduleAt) {
            $availableAt = $moduleAt;
            $mode = $moduleMeta['mode'];
        }

        $dripLock = $this->dripLockPayload($availableAt, $now, $mode);

        return [
            'is_locked' => $dripLock['is_locked'],
            'available_at' => $dripLock['available_at'],
            'expires_at' => is_array($moduleLock) ? ($moduleLock['expires_at'] ?? null) : null,
            'lock_message' => $dripLock['lock_message'],
            'lock_reason' => $dripLock['is_locked'] ? 'drip' : null,
            'can_renew' => false,
            'renewal_amount' => $module ? $this->renewalAmount($module) : null,
        ];
    }

    public function grantFromOrder(Order $order): ?MemberModuleAccess
    {
        if (! self::isRenewalOrder($order) || ! $order->user_id) {
            return null;
        }

        $moduleId = self::renewalModuleId($order);
        if (! $moduleId) {
            return null;
        }

        $module = MemberModule::query()->find($moduleId);
        if (! $module || (string) $module->product_id !== (string) $order->product_id) {
            return null;
        }

        return $this->grant($order->user_id, $module, $order);
    }

    public function grant(int $userId, MemberModule $module, ?Order $order = null, ?CarbonInterface $startsAt = null): MemberModuleAccess
    {
        $startsAt = $startsAt ?? now();

        if ($order) {
            $existing = MemberModuleAccess::query()->where('order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $created = MemberModuleAccess::create([
            'member_module_id' => $module->id,
            'user_id' => $userId,
            'order_id' => $order?->id,
            'access_starts_at' => $startsAt,
        ]);
        unset($this->expireStartCache[$userId.':'.$module->id]);

        return $created;
    }

    public function revokeFromOrder(Order $order): void
    {
        if (! Schema::hasTable('member_module_accesses') || ! $order->id) {
            return;
        }

        MemberModuleAccess::query()->where('order_id', $order->id)->delete();
        $this->expireStartCache = [];
    }

    public function canRenew(MemberModule $module, User $user, Product $product): bool
    {
        if ($this->ignoresExpiration($user, $product)) {
            return false;
        }

        if (! $this->renewalAmount($module)) {
            return false;
        }

        return is_numeric($module->expire_after_days) && (int) $module->expire_after_days > 0;
    }

    /**
     * @return array{available_at: ?Carbon, mode: ?string}
     */
    private function scheduleMeta(mixed $afterDays, mixed $atDate, CarbonInterface $accessStartAt): array
    {
        if ($atDate instanceof CarbonInterface) {
            return ['available_at' => $atDate->copy()->startOfDay(), 'mode' => 'date'];
        }
        if (is_string($atDate) && $atDate !== '') {
            return ['available_at' => Carbon::createFromFormat('Y-m-d', $atDate)->startOfDay(), 'mode' => 'date'];
        }
        if (is_numeric($afterDays) && (int) $afterDays > 0) {
            return ['available_at' => $accessStartAt->copy()->addDays((int) $afterDays), 'mode' => 'days'];
        }

        return ['available_at' => null, 'mode' => null];
    }

    /**
     * @return array{expires_at: ?Carbon, mode: ?string}
     */
    private function expireMeta(MemberModule $module, Product $product, User $user, CarbonInterface $enrollmentStartAt): array
    {
        $atDate = $module->expire_at_date;
        if ($atDate instanceof CarbonInterface) {
            return ['expires_at' => $atDate->copy()->endOfDay(), 'mode' => 'date'];
        }
        if (is_string($atDate) && $atDate !== '') {
            return ['expires_at' => Carbon::createFromFormat('Y-m-d', $atDate)->endOfDay(), 'mode' => 'date'];
        }

        $days = $module->expire_after_days;
        if (is_numeric($days) && (int) $days > 0) {
            $start = $this->moduleExpireStartAt($module, $user, $enrollmentStartAt);

            return ['expires_at' => $start->copy()->addDays((int) $days), 'mode' => 'days'];
        }

        return ['expires_at' => null, 'mode' => null];
    }

    private function moduleExpireStartAt(MemberModule $module, User $user, CarbonInterface $enrollmentStartAt): CarbonInterface
    {
        $cacheKey = $user->id.':'.$module->id;
        if (isset($this->expireStartCache[$cacheKey])) {
            return $this->expireStartCache[$cacheKey]->copy();
        }

        $latest = null;
        if (Schema::hasTable('member_module_accesses')) {
            $latest = MemberModuleAccess::query()
                ->where('member_module_id', $module->id)
                ->where('user_id', $user->id)
                ->orderByDesc('access_starts_at')
                ->value('access_starts_at');
        }

        $start = $latest ? Carbon::parse($latest) : $enrollmentStartAt;
        $this->expireStartCache[$cacheKey] = $start->copy();

        return $start->copy();
    }

    /**
     * @return array{is_locked: bool, available_at: ?string, lock_message: ?string}
     */
    private function dripLockPayload(?CarbonInterface $availableAt, CarbonInterface $now, ?string $mode): array
    {
        if (! $availableAt) {
            return ['is_locked' => false, 'available_at' => null, 'lock_message' => null];
        }
        if ($availableAt->lessThanOrEqualTo($now)) {
            return ['is_locked' => false, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => null];
        }
        $message = null;
        if ($mode === 'date') {
            $message = 'Disponível em '.$availableAt->format('d/m/Y');
        } elseif ($mode === 'days') {
            $days = max(1, (int) round($now->diffInDays($availableAt, true)));
            $message = $days === 1
                ? 'Disponível em 1 dia'
                : 'Disponível em '.$days.' dias';
        } else {
            $message = 'Disponível em '.$availableAt->format('d/m/Y H:i');
        }

        return ['is_locked' => true, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => $message];
    }

    private function expiredMessage(CarbonInterface $expiresAt, ?string $mode): string
    {
        if ($mode === 'days') {
            $days = max(0, (int) round($expiresAt->diffInDays(now(), true)));
            if ($days <= 0) {
                return 'Acesso encerrado';
            }
        }

        return 'Acesso encerrado em '.$expiresAt->format('d/m/Y');
    }

    private function renewalAmount(MemberModule $module): ?float
    {
        $price = $module->renewal_price;
        if ($price === null || (float) $price <= 0) {
            return null;
        }

        return round((float) $price, 2);
    }

    public function isPreviewInspector(User $user, Product $product): bool
    {
        return ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id)
            || $user->canAccessPlatformPanel();
    }

    /**
     * Admin da plataforma e infoprodutor do tenant abrem aulas/módulos para verificar o conteúdo
     * (drip por data, validade e renovação não se aplicam).
     *
     * @return array{
     *     is_locked: bool,
     *     available_at: ?string,
     *     expires_at: ?string,
     *     lock_message: ?string,
     *     lock_reason: ?string,
     *     can_renew: bool,
     *     renewal_amount: ?float
     * }
     */
    private function unlockedLockPayload(?MemberModule $module): array
    {
        return [
            'is_locked' => false,
            'available_at' => null,
            'expires_at' => null,
            'lock_message' => null,
            'lock_reason' => null,
            'can_renew' => false,
            'renewal_amount' => $module ? $this->renewalAmount($module) : null,
        ];
    }

    private function ignoresExpiration(User $user, Product $product): bool
    {
        return $this->isPreviewInspector($user, $product);
    }
}
