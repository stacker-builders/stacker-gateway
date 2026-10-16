<?php

namespace App\Services;

use App\Exceptions\MemberPdfWatermarkException;
use App\Support\UploadLimits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MemberLessonPdfSource
{
    public function read(string $url, ?int $tenantId): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new MemberPdfWatermarkException('Material não encontrado.');
        }

        $storage = new StorageService($tenantId);

        if (! preg_match('#^https?://#i', $url)) {
            return $this->assertPdf($this->readStorage(
                $storage,
                $this->assertSafeRelativePath($storage->normalizeStoragePath($url))
            ));
        }

        // Armazenamento local: URL do próprio app (/storage/...) lê o disco public.
        // Nunca fazer HTTP para a própria aplicação (timeout / loop).
        if ($storage->isLocal() && ($this->isOwnStorageUrl($storage, $url) || $this->hasLocalStoragePath($url))) {
            $relative = $this->localPublicRelativePathFromUrl($url);
            if ($relative === null) {
                throw new MemberPdfWatermarkException('Não foi possível ler o PDF desta aula.');
            }

            return $this->assertPdf($this->readStorage($storage, $relative));
        }

        // S3/R2 (ou CDN próprio): tenta o disco remoto pela chave normalizada.
        if ($this->isOwnStorageUrl($storage, $url)) {
            $path = $this->assertSafeRelativePath($storage->normalizeStoragePath($url));
            if ($path !== '' && $storage->exists($path)) {
                return $this->assertPdf($this->readStorage($storage, $path));
            }
        }

        return $this->assertPdf($this->fetchRemote($url));
    }

    private function readStorage(StorageService $storage, string $path): string
    {
        $path = $this->assertSafeRelativePath($path);
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            throw new MemberPdfWatermarkException('Não foi possível ler o PDF desta aula.');
        }

        // exists()/get() re-normalizam; para path relativo local já limpo, usar o disco direto
        // evita o bug em que normalizeStoragePath(URL) devolvia "storage/member-area/...".
        try {
            $disk = $storage->disk();
            if (! $disk->exists($path)) {
                throw new MemberPdfWatermarkException('Não foi possível ler o PDF desta aula.');
            }
            $bytes = $disk->get($path);
        } catch (MemberPdfWatermarkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('member_pdf_watermark.storage_read_failed', [
                'message' => $e->getMessage(),
            ]);
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new MemberPdfWatermarkException('Não foi possível ler o PDF desta aula.');
        }

        return $bytes;
    }

    /**
     * Extrai member-area/... a partir de https://app.../storage/member-area/...
     */
    private function localPublicRelativePathFromUrl(string $url): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        if (str_starts_with($urlPath, '/storage/')) {
            $relative = substr($urlPath, strlen('/storage/'));
        } elseif (str_starts_with($urlPath, 'storage/')) {
            $relative = substr($urlPath, strlen('storage/'));
        } else {
            return null;
        }

        $relative = $this->assertSafeRelativePath($relative);
        if ($relative === '') {
            return null;
        }

        // Defesa: se ainda vier com prefixo storage/, remove (diagnóstico confirmado).
        if (str_starts_with($relative, 'storage/')) {
            $relative = $this->assertSafeRelativePath(substr($relative, strlen('storage/')));
        }

        return $relative !== '' ? $relative : null;
    }

    private function assertSafeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return '';
        }

        return $path;
    }

    private function isOwnStorageUrl(StorageService $storage, string $url): bool
    {
        $base = rtrim($storage->publicBaseUrl(), '/');
        if ($base !== '' && str_starts_with($url, $base.'/')) {
            return true;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($appHost) || $appHost === '' || ! is_string($host) || ! is_string($path)) {
            return false;
        }

        return strcasecmp($appHost, $host) === 0 && str_starts_with($path, '/storage/');
    }

    private function hasLocalStoragePath(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/storage/');
    }

    private function fetchRemote(string $url): string
    {
        $current = $url;
        for ($hop = 0; $hop < 3; $hop++) {
            $this->assertFetchableUrl($current);
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'timeout' => 20,
                    'connect_timeout' => 5,
                ])->withHeaders([
                    'Accept' => 'application/pdf,*/*',
                    'User-Agent' => 'StackerGatewayPdfProtection',
                ])->get($current);
            } catch (\Throwable $e) {
                Log::warning('member_pdf_watermark.fetch_failed', [
                    'host' => parse_url($current, PHP_URL_HOST),
                    'message' => $e->getMessage(),
                ]);

                throw new MemberPdfWatermarkException('Não foi possível obter o PDF para proteção.');
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '') {
                    break;
                }
                $current = $this->resolveRedirect($current, trim($location));

                continue;
            }

            if (! $response->successful()) {
                Log::warning('member_pdf_watermark.fetch_rejected', [
                    'host' => parse_url($current, PHP_URL_HOST),
                    'status' => $response->status(),
                ]);

                throw new MemberPdfWatermarkException('Não foi possível obter o PDF para proteção.');
            }

            return $response->body();
        }

        throw new MemberPdfWatermarkException('Não foi possível obter o PDF para proteção.');
    }

    private function resolveRedirect(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($current);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new MemberPdfWatermarkException('Não foi possível obter o PDF para proteção.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }

    private function assertFetchableUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new MemberPdfWatermarkException('O endereço deste PDF não pode ser acessado para proteção.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            throw new MemberPdfWatermarkException('O endereço deste PDF não pode ser acessado para proteção.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $resolved = gethostbyname($host);
            if ($resolved === $host || filter_var($resolved, FILTER_VALIDATE_IP) === false) {
                throw new MemberPdfWatermarkException('Não foi possível localizar o PDF para proteção.');
            }
            $this->assertPublicIp($resolved);

            return;
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip !== '') {
                $this->assertPublicIp($ip);
            }
        }
    }

    private function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new MemberPdfWatermarkException('O endereço deste PDF não pode ser acessado para proteção.');
        }
    }

    private function assertPdf(string $bytes): string
    {
        $maxBytes = UploadLimits::memberBuilderPdfMaxKb() * 1024;
        if (strlen($bytes) > $maxBytes) {
            throw new MemberPdfWatermarkException('Este PDF passa do tamanho máximo permitido para proteção.');
        }
        if (! str_starts_with($bytes, '%PDF')) {
            throw new MemberPdfWatermarkException('O arquivo não é um PDF válido. O original não foi enviado.');
        }

        return $bytes;
    }
}
