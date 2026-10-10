<?php

namespace App\Support;

use App\Models\BrandingSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Ícones do PWA do painel — mesma prioridade que {@see \App\Http\Controllers\PanelPwaController::manifest}.
 */
final class PanelPwaIconUrls
{
    /**
     * Pares (src absoluto, sizes) na mesma ordem do manifest antes de `purpose`/duplicação.
     *
     * @return list<array{src: string, sizes: string}>
     */
    public static function manifestIconSpecs(): array
    {
        $specs = [];

        $pwa192 = is_string($v = config('getfy.pwa_icon_192')) ? trim($v) : '';
        $pwa512 = is_string($v = config('getfy.pwa_icon_512')) ? trim($v) : '';
        $has192 = $pwa192 !== '';
        $has512 = $pwa512 !== '';

        if ($has192 || $has512) {
            if ($has192 && $has512) {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '192x192'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '512x512'];
            } elseif ($has192) {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '192x192'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '512x512'];
            } else {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '512x512'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '192x192'];
            }

            return $specs;
        }

        $iconsDir = public_path('icons');
        $file192 = is_file($iconsDir.'/icon-192x192.png');
        $file512 = is_file($iconsDir.'/icon-512x512.png');
        $icon192Url = url('/icons/icon-192x192.png');
        $icon512Url = url('/icons/icon-512x512.png');

        if ($file192) {
            $specs[] = ['src' => $icon192Url, 'sizes' => '192x192'];
        }
        if ($file512) {
            $specs[] = ['src' => $icon512Url, 'sizes' => '512x512'];
        }
        if ($specs === []) {
            $fallbackIcon = self::toAbsoluteUrl((string) config('getfy.app_logo_icon', '/images/favicon.png'));
            $specs[] = ['src' => $fallbackIcon, 'sizes' => '192x192'];
            $specs[] = ['src' => $fallbackIcon, 'sizes' => '512x512'];

            return $specs;
        }
        if ($file512 && ! $file192) {
            $specs[] = ['src' => $icon512Url, 'sizes' => '192x192'];
        }
        if ($file192 && ! $file512) {
            $specs[] = ['src' => $icon192Url, 'sizes' => '512x512'];
        }

        return $specs;
    }

    /** URL única para icon de Web Push (prefere entrada 192x192). */
    public static function primaryNotificationIconUrl(): string
    {
        self::ensureBrandingIconsInConfig();
        $specs = self::manifestIconSpecs();
        foreach ($specs as $spec) {
            if (($spec['sizes'] ?? '') === '192x192') {
                return $spec['src'];
            }
        }

        return $specs[0]['src'] ?? url('/icons/icon-192x192.png');
    }

    public static function withVersion(string $src, ?string $v): string
    {
        $src = trim($src);
        if ($src === '' || $v === null || $v === '') {
            return $src;
        }
        if (str_contains($src, 'v=')) {
            return $src;
        }

        return str_contains($src, '?') ? ($src.'&v='.$v) : ($src.'?v='.$v);
    }

    /**
     * Fila e comandos não passam pelo middleware de branding. Sem isto o push
     * cai no arquivo padrão do produto em vez do ícone configurado no admin.
     */
    private static function ensureBrandingIconsInConfig(): void
    {
        $pwa192 = is_string($v = config('getfy.pwa_icon_192')) ? trim($v) : '';
        $pwa512 = is_string($v = config('getfy.pwa_icon_512')) ? trim($v) : '';
        if ($pwa192 !== '' || $pwa512 !== '') {
            return;
        }

        try {
            if (! Schema::hasTable('branding_settings')) {
                return;
            }
            $row = BrandingSetting::query()->whereNull('tenant_id')->first();
        } catch (\Throwable) {
            return;
        }

        $data = is_array($row?->data) ? $row->data : [];
        $merge = [];
        foreach ([
            'pwa_icon_192' => 'getfy.pwa_icon_192',
            'pwa_icon_512' => 'getfy.pwa_icon_512',
            'app_logo_icon' => 'getfy.app_logo_icon',
        ] as $jsonKey => $configKey) {
            $value = $data[$jsonKey] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $resolved = BrandingAssetUrls::resolve(trim($value));
            if ($resolved !== '') {
                $merge[$configKey] = $resolved;
            }
        }

        if ($merge !== []) {
            config($merge);
        }
    }

    private static function toAbsoluteUrl(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return $src;
        }

        return BrandingAssetUrls::resolve($src);
    }
}
