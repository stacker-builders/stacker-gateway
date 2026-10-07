<?php

namespace App\Services\Uazapi;

use App\Exceptions\UazapiRequestException;
use App\Models\BrandingSetting;
use App\Models\UazapiInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UazapiLabelService
{
    public const ABANDONED = 'abandoned';

    public const HOT = 'hot';

    public const PAID = 'paid';

    public function __construct(private UazapiClient $client) {}

    public function ensureDefaults(UazapiInstance $instance): void
    {
        $token = (string) ($instance->instance_token ?? '');
        if ($token === '') {
            return;
        }

        $defined = $this->resolvedLabelDefinitions();
        if ($defined === []) {
            return;
        }

        $map = is_array($instance->label_map) ? $instance->label_map : [];

        try {
            $api = $this->client->using($instance);
            $existing = $this->indexByName($api->listLabels($token));
            $byId = $this->indexById($existing);
            $dirty = false;

            foreach ($defined as $key => $spec) {
                $desiredName = $spec['name'];
                $color = $spec['color'];
                $mappedId = isset($map[$key]) ? trim((string) $map[$key]) : '';

                if ($mappedId !== '' && isset($byId[$mappedId])) {
                    $currentName = trim((string) ($byId[$mappedId]['name'] ?? ''));
                    if ($currentName !== $desiredName) {
                        $api->editLabel($token, [
                            'labelid' => $mappedId,
                            'name' => $desiredName,
                            'color' => $color,
                            'delete' => false,
                        ]);
                        $existing = $this->indexByName($api->listLabels($token));
                        $byId = $this->indexById($existing);
                    }

                    continue;
                }

                $labelId = $this->extractLabelId($existing[$desiredName] ?? null);
                if ($labelId === null) {
                    $api->editLabel($token, [
                        'labelid' => 'new',
                        'name' => $desiredName,
                        'color' => $color,
                        'delete' => false,
                    ]);
                    $existing = $this->indexByName($api->listLabels($token));
                    $byId = $this->indexById($existing);
                    $labelId = $this->extractLabelId($existing[$desiredName] ?? null);
                }

                if ($labelId !== null) {
                    $map[$key] = $labelId;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $instance->label_map = $map;
                $instance->save();
            }
        } catch (UazapiRequestException $e) {
            Log::warning('UazapiLabelService: falha ao sincronizar etiquetas', [
                'instance_id' => $instance->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function apply(UazapiInstance $instance, string $phone, string $key): void
    {
        $token = (string) ($instance->instance_token ?? '');
        if ($token === '' || $phone === '') {
            return;
        }

        $this->ensureDefaults($instance);
        $instance->refresh();

        $map = is_array($instance->label_map) ? $instance->label_map : [];
        $labelId = isset($map[$key]) ? trim((string) $map[$key]) : '';
        if ($labelId === '') {
            return;
        }

        try {
            $api = $this->client->using($instance);
            $api->setChatLabels($token, [
                'number' => $phone,
                'add_labelid' => $labelId,
            ]);

            if ($key === self::PAID) {
                foreach ([self::ABANDONED, self::HOT] as $removeKey) {
                    $removeId = isset($map[$removeKey]) ? trim((string) $map[$removeKey]) : '';
                    if ($removeId === '') {
                        continue;
                    }
                    $api->setChatLabels($token, [
                        'number' => $phone,
                        'remove_labelid' => $removeId,
                    ]);
                }
            }

            if ($key === self::HOT) {
                $abandonedId = isset($map[self::ABANDONED]) ? trim((string) $map[self::ABANDONED]) : '';
                if ($abandonedId !== '') {
                    $api->setChatLabels($token, [
                        'number' => $phone,
                        'remove_labelid' => $abandonedId,
                    ]);
                }
            }
        } catch (UazapiRequestException $e) {
            Log::debug('UazapiLabelService: falha ao aplicar etiqueta', [
                'instance_id' => $instance->id,
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, array{name: string, color: int}>
     */
    public function resolvedLabelDefinitions(): array
    {
        $platform = $this->platformName();
        $defined = config('uazapi.labels', []);
        if (! is_array($defined) || $defined === []) {
            return [];
        }

        $out = [];
        foreach ($defined as $key => $spec) {
            if (! is_string($key) || ! is_array($spec)) {
                continue;
            }

            $template = trim((string) ($spec['name'] ?? ''));
            if ($template === '') {
                continue;
            }

            $name = str_contains($template, '{platform}')
                ? str_replace('{platform}', $platform, $template)
                : $template;
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
            if ($name === '') {
                continue;
            }

            $out[$key] = [
                'name' => mb_substr($name, 0, 100),
                'color' => (int) ($spec['color'] ?? 0),
            ];
        }

        return $out;
    }

    public function platformName(): string
    {
        $fromBranding = $this->globalBrandingAppName();
        if ($fromBranding !== '') {
            return $this->sanitizePlatformName($fromBranding);
        }

        $fromGetfy = trim((string) config('getfy.app_name', ''));
        if ($fromGetfy !== '') {
            return $this->sanitizePlatformName($fromGetfy);
        }

        return $this->sanitizePlatformName((string) config('app.name', 'Stacker'));
    }

    private function globalBrandingAppName(): string
    {
        try {
            if (! Schema::hasTable('branding_settings')) {
                return '';
            }
        } catch (\Throwable) {
            return '';
        }

        $global = BrandingSetting::query()->whereNull('tenant_id')->first();
        $data = is_array($global?->data) ? $global->data : [];
        $name = $data['app_name'] ?? null;

        return is_string($name) ? trim($name) : '';
    }

    private function sanitizePlatformName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = trim($name, " \t\n\r\0\x0B·|-");
        if ($name === '') {
            return 'Stacker';
        }

        return mb_substr($name, 0, 40);
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, array<string, mixed>>  $labels
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $labels): array
    {
        $indexed = [];
        foreach ($labels as $label) {
            if (! is_array($label)) {
                continue;
            }
            $name = trim((string) ($label['name'] ?? ''));
            if ($name !== '') {
                $indexed[$name] = $label;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, array<string, mixed>>  $labelsByName
     * @return array<string, array<string, mixed>>
     */
    private function indexById(array $labelsByName): array
    {
        $indexed = [];
        foreach ($labelsByName as $label) {
            $id = $this->extractLabelId($label);
            if ($id !== null) {
                $indexed[$id] = $label;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>|null  $label
     */
    private function extractLabelId(?array $label): ?string
    {
        if ($label === null) {
            return null;
        }

        foreach (['labelid', 'id'] as $key) {
            $value = $label[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
