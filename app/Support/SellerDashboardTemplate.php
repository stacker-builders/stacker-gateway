<?php

namespace App\Support;

use App\Models\Setting;

class SellerDashboardTemplate
{
    public const KEY = 'seller_dashboard_template';

    public const DEFAULT = 'default';

    public const AURORA = 'aurora';

    public const KAWAII = 'kawaii';

    public const NOIR = 'noir';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::DEFAULT, self::AURORA, self::KAWAII, self::NOIR];
    }

    public static function resolve(?string $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($value, self::allowed(), true) ? $value : self::DEFAULT;
    }

    public static function current(): string
    {
        $raw = Setting::get(self::KEY, self::DEFAULT, null);

        return self::resolve(is_string($raw) ? $raw : null);
    }
}
