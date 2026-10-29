<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class DemoMode
{
    public const SETTING_ADMIN_USER_ID = 'demo_admin_user_id';

    public const SETTING_SELLER_USER_ID = 'demo_seller_user_id';

    public static function isEnabled(): bool
    {
        return (bool) config('getfy.demo_mode', false);
    }

    public static function canConfigure(): bool
    {
        return ! self::isEnabled();
    }

    public static function adminUser(): ?User
    {
        $fromSetting = self::resolveUserFromSetting(self::SETTING_ADMIN_USER_ID, function (User $user): bool {
            return $user->canAccessPlatformPanel();
        });

        if ($fromSetting) {
            return $fromSetting;
        }

        return self::resolveUserFromEnvEmail(
            config('getfy.demo_admin_email'),
            fn (User $user): bool => $user->canAccessPlatformPanel()
        );
    }

    public static function sellerUser(): ?User
    {
        $fromSetting = self::resolveUserFromSetting(self::SETTING_SELLER_USER_ID, function (User $user): bool {
            return $user->canAccessSellerPanel() && $user->tenant_id !== null;
        });

        if ($fromSetting) {
            return $fromSetting;
        }

        return self::resolveUserFromEnvEmail(
            config('getfy.demo_seller_email'),
            fn (User $user): bool => $user->canAccessSellerPanel() && $user->tenant_id !== null
        );
    }

    /**
     * @return list<string>
     */
    public static function allowedMutationRouteNames(): array
    {
        return [
            'login',
            'plataforma.login',
            'logout',
            'plataforma.logout',
            'demo.login.admin',
            'demo.login.seller',
            'mcp.handle',
            'mcp.oauth.register',
            'mcp.oauth.token',
            'mcp.oauth.approve',
        ];
    }

    public static function isAllowedMutation(Request $request): bool
    {
        if (! self::isEnabled()) {
            return true;
        }

        if ($request->isMethodSafe()) {
            return true;
        }

        $routeName = $request->route()?->getName();

        return is_string($routeName) && in_array($routeName, self::allowedMutationRouteNames(), true);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     can_configure: bool,
     *     admin_user_id: int|null,
     *     seller_user_id: int|null,
     *     admin_label: string|null,
     *     seller_label: string|null,
     *     env_admin_email: string|null,
     *     env_seller_email: string|null,
     * }
     */
    public static function configForAdminForm(): array
    {
        $admin = self::adminUser();
        $seller = self::sellerUser();

        return [
            'enabled' => self::isEnabled(),
            'can_configure' => self::canConfigure(),
            'admin_user_id' => self::storedUserId(self::SETTING_ADMIN_USER_ID),
            'seller_user_id' => self::storedUserId(self::SETTING_SELLER_USER_ID),
            'admin_label' => self::userLabel($admin),
            'seller_label' => self::userLabel($seller),
            'env_admin_email' => self::normalizeEmail(config('getfy.demo_admin_email')),
            'env_seller_email' => self::normalizeEmail(config('getfy.demo_seller_email')),
        ];
    }

    /**
     * @return array{enabled: bool, can_configure: bool, admin_label: string|null, seller_label: string|null}
     */
    public static function publicConfig(): array
    {
        $admin = self::adminUser();
        $seller = self::sellerUser();

        return [
            'enabled' => self::isEnabled(),
            'can_configure' => self::canConfigure(),
            'admin_label' => self::userLabel($admin),
            'seller_label' => self::userLabel($seller),
        ];
    }

    public static function saveUserIds(?int $adminUserId, ?int $sellerUserId): void
    {
        if ($adminUserId !== null && $sellerUserId !== null && $adminUserId === $sellerUserId) {
            throw new \InvalidArgumentException('Admin e infoprodutor demo devem ser contas diferentes.');
        }

        if ($adminUserId !== null) {
            $admin = User::query()->find($adminUserId);
            if (! $admin || ! $admin->canAccessPlatformPanel()) {
                throw new \InvalidArgumentException('Conta admin demo inválida.');
            }
        }

        if ($sellerUserId !== null) {
            $seller = User::query()->find($sellerUserId);
            if (! $seller || ! $seller->canAccessSellerPanel() || $seller->tenant_id === null) {
                throw new \InvalidArgumentException('Conta infoprodutor demo inválida.');
            }
        }

        Setting::set(self::SETTING_ADMIN_USER_ID, $adminUserId !== null ? (string) $adminUserId : '', null);
        Setting::set(self::SETTING_SELLER_USER_ID, $sellerUserId !== null ? (string) $sellerUserId : '', null);
    }

    private static function storedUserId(string $key): ?int
    {
        $raw = Setting::get($key, '', null);
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * @param  callable(User): bool  $validator
     */
    private static function resolveUserFromSetting(string $key, callable $validator): ?User
    {
        $id = self::storedUserId($key);
        if ($id === null) {
            return null;
        }

        $user = User::query()->find($id);
        if (! $user instanceof User || ! $validator($user)) {
            return null;
        }

        return $user;
    }

    /**
     * @param  callable(User): bool  $validator
     */
    private static function resolveUserFromEnvEmail(mixed $email, callable $validator): ?User
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        $user = User::query()->where('email', $normalized)->first();
        if (! $user instanceof User || ! $validator($user)) {
            return null;
        }

        return $user;
    }

    private static function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $value = strtolower(trim($email));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private static function userLabel(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim((string) $user->name);
        $email = trim((string) $user->email);

        if ($name !== '' && $email !== '') {
            return $name.' ('.$email.')';
        }

        return $email !== '' ? $email : ($name !== '' ? $name : null);
    }
}
