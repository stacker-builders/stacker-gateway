<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\RemoteStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageService
{
    private ?int $tenantId = null;

    private ?Filesystem $disk = null;

    private bool $isLocal = true;

    /** @var array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url: string}|null */
    private ?array $remoteCredentials = null;

    public function __construct(?int $tenantId = null)
    {
        $this->tenantId = $tenantId ?? auth()->user()?->tenant_id;
    }

    /**
     * @return array{configured: bool, key: string, secret: string, bucket: string, endpoint: string, url: string, region: string}
     */
    private function r2EnvConfig(): array
    {
        $key = (string) env('R2_ACCESS_KEY_ID', '');
        $secret = (string) env('R2_SECRET_ACCESS_KEY', '');
        $bucket = (string) env('R2_BUCKET', '');
        $endpoint = (string) env('R2_ENDPOINT', '');
        $url = (string) env('R2_PUBLIC_URL', '');
        $region = (string) env('R2_REGION', 'auto');

        $configured = $key !== '' && $secret !== '' && $bucket !== '' && $endpoint !== '';

        return [
            'configured' => $configured,
            'key' => $key,
            'secret' => $secret,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'url' => $url,
            'region' => $region ?: 'auto',
        ];
    }

    /**
     * Tenant cujas settings de storage serão lidas.
     * Quando o tenant não escolheu storage explicitamente, herda a config global da plataforma.
     */
    private function storageSettingsTenantId(): ?int
    {
        if ($this->tenantId === null) {
            return null;
        }

        $tenantProvider = Setting::get('storage_provider', null, $this->tenantId);
        if ($tenantProvider !== null && $tenantProvider !== '') {
            return $this->tenantId;
        }

        return null;
    }

    /**
     * @return array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url: string}
     */
    public function resolveRemoteCredentials(): array
    {
        if ($this->remoteCredentials !== null) {
            return $this->remoteCredentials;
        }

        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $r2Env = $this->r2EnvConfig();
        $settingsTenantId = $this->storageSettingsTenantId();

        $provider = Setting::get('storage_provider', null, $settingsTenantId);
        if ($provider === null || $provider === '') {
            $provider = ($cloudMode && $r2Env['configured']) ? 'r2' : 'local';
        }

        if ($provider === 'local' || $provider === '') {
            $this->remoteCredentials = [
                'provider' => 'local',
                'key' => '',
                'secret' => '',
                'bucket' => '',
                'region' => '',
                'endpoint' => '',
                'url' => '',
            ];

            return $this->remoteCredentials;
        }

        $key = (string) Setting::get('storage_s3_key', '', $settingsTenantId);
        $secretRaw = Setting::get('storage_s3_secret', '', $settingsTenantId);
        $secret = '';
        if ($secretRaw) {
            try {
                $secret = Crypt::decryptString($secretRaw);
            } catch (\Throwable) {
                $secret = '';
            }
        }
        $bucket = (string) Setting::get('storage_s3_bucket', '', $settingsTenantId);
        $region = (string) Setting::get('storage_s3_region', 'us-east-1', $settingsTenantId);
        $endpoint = (string) Setting::get('storage_s3_endpoint', '', $settingsTenantId);
        $url = (string) Setting::get('storage_s3_url', '', $settingsTenantId);

        $useEnvR2 = $cloudMode
            && $provider === 'r2'
            && $r2Env['configured']
            && trim($key) === ''
            && trim($bucket) === ''
            && trim($endpoint) === ''
            && trim($url) === ''
            && trim((string) $secretRaw) === '';

        if ($useEnvR2) {
            $key = $r2Env['key'];
            $secret = $r2Env['secret'];
            $bucket = $r2Env['bucket'];
            $endpoint = $r2Env['endpoint'];
            $url = $r2Env['url'];
            $region = $r2Env['region'];
        }

        $this->remoteCredentials = [
            'provider' => (string) $provider,
            'key' => $key,
            'secret' => $secret,
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint,
            'url' => RemoteStorage::normalizePublicBaseUrl($url),
        ];

        return $this->remoteCredentials;
    }

    /**
     * URL pública base (CDN / pub-*.r2.dev). Vazio se não configurado.
     */
    public function publicBaseUrl(): string
    {
        $creds = $this->resolveRemoteCredentials();
        if (($creds['provider'] ?? 'local') === 'local') {
            return '';
        }

        return RemoteStorage::resolvePublicBaseUrlForProvider(
            $creds['provider'],
            $creds['url'],
            $this->r2EnvConfig()
        );
    }

    /**
     * Get the active storage disk for the current tenant.
     */
    public function disk(): Filesystem
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $creds = $this->resolveRemoteCredentials();

        if (($creds['provider'] ?? 'local') === 'local'
            || $creds['key'] === ''
            || $creds['secret'] === ''
            || $creds['bucket'] === '') {
            $this->disk = Storage::disk('public');
            $this->isLocal = true;

            return $this->disk;
        }

        try {
            $diskConfig = RemoteStorage::buildS3DiskConfig($creds);
            $this->disk = Storage::build($diskConfig);
            $this->isLocal = false;
        } catch (\Throwable $e) {
            Log::warning('storage.disk_build_failed', [
                'provider' => $creds['provider'] ?? null,
                'message' => $e->getMessage(),
            ]);
            try {
                $this->disk = Storage::build([
                    'driver' => 's3',
                    'key' => $creds['key'],
                    'secret' => $creds['secret'],
                    'region' => ($creds['provider'] ?? '') === 'r2' ? 'auto' : ($creds['region'] ?: 'us-east-1'),
                    'bucket' => $creds['bucket'],
                    'endpoint' => $creds['endpoint'] ?? null,
                    'url' => $creds['url'] ?? null,
                    'use_path_style_endpoint' => RemoteStorage::isR2ApiEndpoint($creds['endpoint'] ?? ''),
                    'visibility' => ($creds['provider'] ?? '') === 'r2' ? 'private' : 'public',
                    'retain_visibility' => false,
                    'throw' => false,
                    'report' => false,
                    'request_checksum_calculation' => 'when_required',
                    'response_checksum_validation' => 'when_required',
                ]);
                $this->isLocal = false;
            } catch (\Throwable) {
                $this->disk = Storage::disk('public');
                $this->isLocal = true;
            }
        }

        return $this->disk;
    }

    /**
     * Whether the current disk is local (public) or remote (S3/R2).
     */
    public function isLocal(): bool
    {
        $this->disk();

        return $this->isLocal;
    }

    /**
     * Store an uploaded file and return the path.
     */
    public function putFile(string $directory, UploadedFile $file, ?string $name = null): string
    {
        $name = $name ?? $file->hashName();

        return $this->putFileAs($directory, $file, $name);
    }

    /**
     * Store file with putFileAs.
     *
     * @param  array<string, mixed>  $extraOptions
     */
    public function putFileAs(string $directory, UploadedFile $file, string $name, array $extraOptions = []): string
    {
        $directory = RemoteStorage::normalizeObjectKey($directory);
        $name = RemoteStorage::normalizeObjectKey($name);
        $creds = $this->resolveRemoteCredentials();
        $provider = (string) ($creds['provider'] ?? 'local');

        if (RemoteStorage::requiresPublicBaseUrl($provider) && $this->publicBaseUrl() === '') {
            throw new \RuntimeException(
                'Configure a URL pública do R2 (ex.: https://media.seudominio.com) em Configurações → Storage antes de enviar imagens.'
            );
        }

        try {
            $stored = $this->disk()->putFileAs(
                $directory,
                $file,
                $name,
                array_merge(RemoteStorage::uploadOptionsForProvider($provider), $extraOptions)
            );
        } catch (\Throwable $e) {
            Log::warning('storage.put_file_failed', [
                'provider' => $provider,
                'directory' => $directory,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(RemoteStorage::friendlyErrorMessage($e), 0, $e);
        }

        if ($stored === false || $stored === '') {
            throw new \RuntimeException(
                'Não foi possível enviar o arquivo. Verifique credenciais do storage e, no R2, o acesso público ao bucket.'
            );
        }

        return RemoteStorage::normalizeObjectKey($stored);
    }

    /**
     * Grava arquivo público e retorna path relativo (para DB) + URL absoluta (para resposta imediata).
     *
     * @return array{path: string, url: string}
     */
    public function storeUploadedPublicFile(UploadedFile $file, string $directory): array
    {
        $path = RemoteStorage::normalizeObjectKey($this->putFile($directory, $file));

        return [
            'path' => $path,
            'url' => $this->resolvePublicUrl($path),
        ];
    }

    /**
     * Get the public URL for a stored file (path relativo no bucket/disco).
     */
    public function url(string $path): string
    {
        return $this->resolvePublicUrl($path);
    }

    /**
     * Converte valor salvo no banco (path, /storage/... ou URL) na URL pública atual (local ou CDN/R2).
     */
    public function resolvePublicUrl(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        try {
            return $this->finalizePublicUrl($this->resolvePublicUrlUnsafe(trim($stored)));
        } catch (\Throwable $e) {
            Log::warning('storage.resolve_public_url_failed', [
                'message' => $e->getMessage(),
            ]);

            return $this->finalizePublicUrl($this->fallbackPublicUrl(trim($stored)));
        }
    }

    /**
     * Evita src relativo no HTML (ex.: avatars/foto.png → 404 em /plataforma/meu-perfil).
     */
    private function finalizePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $this->upgradeUrlForCurrentRequest($url);
        }

        if (str_starts_with($url, '/')) {
            return $this->absoluteFromRequest($url);
        }

        return $this->absoluteFromRequest('/storage/'.ltrim($url, '/'));
    }

    private function absoluteFromRequest(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if (! app()->runningInConsole()) {
            $request = request();
            if ($request) {
                $host = $request->getHttpHost();
                if ($host !== '') {
                    $scheme = $request->isSecure() ? 'https' : $request->getScheme();

                    return $scheme.'://'.$host.$path;
                }
            }
        }

        return url($path);
    }

    private function upgradeUrlForCurrentRequest(string $url): string
    {
        $normalizer = new StorageUrlNormalizer;
        if ($normalizer->isLocalStorageUrl($url)) {
            $relative = '/storage/'.ltrim($normalizer->toRelativePath($url), '/');

            return $this->absoluteFromRequest($relative);
        }

        if (! app()->runningInConsole()) {
            $request = request();
            if ($request && $request->isSecure() && str_starts_with(strtolower($url), 'http://')) {
                return 'https://'.substr($url, 7);
            }
        }

        return $url;
    }

    private function resolvePublicUrlUnsafe(string $stored): string
    {
        if ($this->isPublicWebAssetPath($stored)) {
            return $this->publicWebAssetPath($stored);
        }

        $stored = RemoteStorage::normalizeStoredObjectReference($stored);
        $normalizer = new StorageUrlNormalizer;
        $creds = $this->resolveRemoteCredentials();
        $bucket = $creds['bucket'] ?? '';

        $base = '';
        $this->disk();
        if (! $this->isLocal) {
            $base = $this->publicBaseUrl();
        }

        if (preg_match('#^https?://#i', $stored)) {
            if ($base !== '') {
                $repaired = RemoteStorage::repairEmbeddedPublicHostUrl($stored, $base);
                if ($repaired !== null) {
                    return $repaired;
                }
            }
            if ($normalizer->isLocalStorageUrl($stored)) {
                $stored = $normalizer->toRelativePath($stored);
            } elseif (RemoteStorage::isLikelyNonPublicUrl($stored)) {
                $key = RemoteStorage::extractObjectKeyFromUrl($stored, $bucket !== '' ? $bucket : null);
                $stored = $key ?? $stored;
            } else {
                return $stored;
            }
        } elseif (str_starts_with($stored, '/storage/')) {
            $stored = ltrim(substr($stored, strlen('/storage/')), '/');
        } elseif (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}/#i', $stored)) {
            return RemoteStorage::ensureAbsoluteUrl($stored);
        }

        if ($this->isLocal) {
            return '/storage/'.ltrim($stored, '/');
        }

        if ($base !== '') {
            return RemoteStorage::buildPublicUrl($base, $stored);
        }

        $adapterUrl = $this->disk->url($stored);
        if (RemoteStorage::isLikelyNonPublicUrl($adapterUrl)) {
            return '/storage/'.ltrim($stored, '/');
        }

        return $adapterUrl;
    }

    private function fallbackPublicUrl(string $stored): string
    {
        if ($this->isPublicWebAssetPath($stored)) {
            return $this->publicWebAssetPath($stored);
        }

        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }

        if (str_starts_with($stored, '/storage/')) {
            return $stored;
        }

        return '/storage/'.ltrim($stored, '/');
    }

    /**
     * Arquivos em public/images e public/icons — não são objetos de storage/R2.
     * Usado pelas badges da gamificação (/images/level-badge/...).
     */
    private function isPublicWebAssetPath(string $stored): bool
    {
        $path = trim($stored);
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return false;
        }

        $path = '/'.ltrim(rawurldecode($path), '/');

        return str_starts_with($path, '/images/') || str_starts_with($path, '/icons/');
    }

    private function publicWebAssetPath(string $stored): string
    {
        $path = '/'.ltrim(trim($stored), '/');
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            $segments[$i] = rawurlencode(rawurldecode($segment));
        }

        return implode('/', $segments);
    }

    /**
     * Normaliza URL/caminho recebido do front para gravar no banco (preferir path relativo).
     */
    public function toStoragePath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $normalizer = new StorageUrlNormalizer;
        $creds = $this->resolveRemoteCredentials();
        $bucket = $creds['bucket'] ?? '';

        if (preg_match('#^https?://#i', $value)) {
            if ($normalizer->isLocalStorageUrl($value)) {
                return RemoteStorage::normalizeObjectKey($normalizer->toRelativePath($value));
            }

            if (RemoteStorage::isLikelyNonPublicUrl($value)) {
                $key = RemoteStorage::extractObjectKeyFromUrl($value, $bucket !== '' ? $bucket : null);

                return $key !== null && $key !== '' ? RemoteStorage::normalizeObjectKey($key) : null;
            }

            $key = RemoteStorage::extractObjectKeyFromUrl($value, $bucket !== '' ? $bucket : null);
            if ($key !== null && $key !== '') {
                return RemoteStorage::normalizeObjectKey($key);
            }

            return RemoteStorage::normalizeStoredObjectReference($value);
        }

        if (str_starts_with($value, '/storage/')) {
            return RemoteStorage::normalizeObjectKey(ltrim(substr($value, strlen('/storage/')), '/'));
        }

        return RemoteStorage::normalizeObjectKey($value);
    }

    /**
     * Resolve URLs de mídia dentro de member_area_config (logos, hero, login, etc.).
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public function resolveMediaUrlsInConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return $this->resolveMediaUrlsInArray($config);
    }

    /**
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function resolveMediaUrlsInArray(array $arr): array
    {
        foreach ($arr as $key => $value) {
            if (is_string($value) && $this->shouldResolveConfigMediaString($value)) {
                $arr[$key] = $this->resolvePublicUrl($value);
            } elseif (is_array($value)) {
                $arr[$key] = $this->resolveMediaUrlsInArray($value);
            }
        }

        return $arr;
    }

    private function shouldResolveConfigMediaString(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $value)) {
            if ((new StorageUrlNormalizer)->isLocalStorageUrl($value)) {
                return true;
            }

            return RemoteStorage::isLikelyNonPublicUrl($value);
        }

        if (str_starts_with($value, '/storage/')) {
            return true;
        }

        $prefixes = [
            'member-area/',
            'member-area-gamification/',
            'products/',
            'checkout/',
            'branding/',
            'email-templates/',
            'dashboard-banners/',
            'white-label/',
            'conquistas/',
            'avatars/',
            'platform/',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a file.
     */
    public function delete(string $path): bool
    {
        $path = $this->normalizeStoragePath($path);
        if ($path === '') {
            return false;
        }

        try {
            return $this->disk()->delete($path);
        } catch (\Throwable $e) {
            Log::warning('storage.delete_failed', ['path' => $path, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool
    {
        $path = $this->normalizeStoragePath($path);
        if ($path === '') {
            return false;
        }

        try {
            return $this->disk()->exists($path);
        } catch (\Throwable $e) {
            Log::warning('storage.exists_failed', ['path' => $path, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Converte URL completa ou /storage/... no path relativo do bucket/disco.
     */
    public function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            // Upload local devolve http(s)://host/storage/... — a chave no disco é sem o prefixo /storage/.
            if (is_string($urlPath) && str_starts_with($urlPath, '/storage/')) {
                return ltrim(substr($urlPath, strlen('/storage/')), '/');
            }

            $bucket = $this->resolveRemoteCredentials()['bucket'] ?? '';
            $key = RemoteStorage::extractObjectKeyFromUrl(
                $path,
                is_string($bucket) && $bucket !== '' ? $bucket : null
            );

            return $key ?? $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        return ltrim($path, '/');
    }
}
