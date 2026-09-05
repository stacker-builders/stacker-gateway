<?php

namespace App\Services\Platform;

/**
 * Lê storage/logs do Laravel (canal daily) para o painel do operador.
 */
final class SystemLogReader
{
    private const MAX_READ_BYTES = 2_097_152;

    private const MAX_TRACE_CHARS = 8_000;

    private const ENTRY_START = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+(\w+)\.(\w+):\s(.*)$/';

    /** @var list<string> */
    private const WARNING_AND_UP = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** @var list<string> */
    private const ERROR_AND_UP = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(
        private readonly ?string $logsDirectory = null,
    ) {}

    public function directory(): string
    {
        return $this->logsDirectory ?? storage_path('logs');
    }

    /**
     * @return list<string>
     */
    public function availableDates(?string $today = null): array
    {
        $today = $today ?? now()->toDateString();
        $dates = [];
        $dir = $this->directory();
        if (is_dir($dir)) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'laravel-*.log') ?: [] as $file) {
                $base = basename((string) $file);
                if (preg_match('/^laravel-(\d{4}-\d{2}-\d{2})\.log$/', $base, $m)) {
                    $dates[] = $m[1];
                }
            }
        }
        if (! in_array($today, $dates, true)) {
            $dates[] = $today;
        }
        rsort($dates);

        return array_values(array_unique($dates));
    }

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     available_dates: list<string>,
     *     file: array{name: string, exists: bool, size: int, truncated: bool}|null
     * }
     */
    public function query(string $date, string $level, string $search, int $limit): array
    {
        $date = $this->normalizeDate($date);
        $level = $this->normalizeLevel($level);
        $search = trim($search);
        $limit = max(1, min(200, $limit));

        $path = $this->resolveFile($date);
        $fileMeta = null;
        $entries = [];

        if ($path !== null) {
            $size = (int) @filesize($path);
            $read = $this->readTail($path);
            $truncated = $size > self::MAX_READ_BYTES;
            $fileMeta = [
                'name' => basename($path),
                'exists' => true,
                'size' => $size,
                'truncated' => $truncated,
            ];
            $entries = $this->parse($read);
        } else {
            $expected = 'laravel-'.$date.'.log';
            $fileMeta = [
                'name' => $expected,
                'exists' => false,
                'size' => 0,
                'truncated' => false,
            ];
        }

        $allowed = $this->levelsForFilter($level);
        $needle = mb_strtolower($search);
        $filtered = [];
        foreach ($entries as $entry) {
            if ($allowed !== null && ! in_array($entry['level'], $allowed, true)) {
                continue;
            }
            if ($needle !== '') {
                $hay = mb_strtolower($entry['message'].' '.json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE));
                if (! str_contains($hay, $needle)) {
                    continue;
                }
            }
            $filtered[] = $entry;
        }

        $filtered = array_reverse($filtered);
        $filtered = array_slice($filtered, 0, $limit);

        return [
            'entries' => $filtered,
            'available_dates' => $this->availableDates(),
            'file' => $fileMeta,
        ];
    }

    public function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return now()->toDateString();
    }

    public function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return in_array($level, ['all', 'warning', 'error'], true) ? $level : 'warning';
    }

    public function redact(string $text): string
    {
        $redacted = preg_replace(
            '/(?i)"(merchant_key|secret_key|password|api_key|api_secret|access_token|client_secret|authorization)"\s*:\s*"[^"]*"/',
            '"$1":"***"',
            $text
        ) ?? $text;
        $redacted = preg_replace(
            '/(?i)\b(merchant_?key|secret_?key|api_?secret|api_?key|password|access_?token|client_secret|authorization)\s*=\s*["\']?[^\s"\',}\]]+/',
            '$1=***',
            $redacted
        ) ?? $redacted;

        return preg_replace('/(?i)\bBearer\s+[A-Za-z0-9._\-+=\/]+/', 'Bearer ***', $redacted) ?? $redacted;
    }

    private function resolveFile(string $date): ?string
    {
        $dir = realpath($this->directory());
        if ($dir === false || ! is_dir($dir)) {
            return null;
        }

        $candidate = $dir.DIRECTORY_SEPARATOR.'laravel-'.$date.'.log';
        if (is_file($candidate)) {
            return $this->assertInsideLogs($dir, $candidate);
        }

        if ($date === now()->toDateString()) {
            $single = $dir.DIRECTORY_SEPARATOR.'laravel.log';
            if (is_file($single)) {
                return $this->assertInsideLogs($dir, $single);
            }
        }

        return null;
    }

    private function assertInsideLogs(string $dir, string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if ($real !== $dir && ! str_starts_with($real, $dir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function readTail(string $path): string
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return '';
        }
        if ($size <= self::MAX_READ_BYTES) {
            return $this->redact((string) file_get_contents($path));
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, -$this->safeSeek($size), SEEK_END);
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $nl = strpos($chunk, "\n");
        if ($nl !== false) {
            $chunk = substr($chunk, $nl + 1);
        }

        return $this->redact($chunk);
    }

    private function safeSeek(int $size): int
    {
        return min(self::MAX_READ_BYTES, $size);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_START, $line, $m)) {
                if ($current !== null) {
                    $entries[] = $this->finalize($current, count($entries));
                }
                $current = [
                    'logged_at' => $m[1],
                    'environment' => $m[2],
                    'level' => strtoupper($m[3]),
                    'raw' => $m[4],
                    'trace' => '',
                ];

                continue;
            }
            if ($current !== null && $line !== '') {
                $current['trace'] .= ($current['trace'] === '' ? '' : "\n").$line;
            }
        }
        if ($current !== null) {
            $entries[] = $this->finalize($current, count($entries));
        }

        return $entries;
    }

    /**
     * @param  array{logged_at: string, environment: string, level: string, raw: string, trace: string}  $current
     * @return array<string, mixed>
     */
    private function finalize(array $current, int $index): array
    {
        [$message, $context] = $this->splitMessageAndContext($current['raw']);
        $trace = $current['trace'];
        if (mb_strlen($trace) > self::MAX_TRACE_CHARS) {
            $trace = mb_substr($trace, 0, self::MAX_TRACE_CHARS)."\n…";
        }

        $id = substr(sha1($current['logged_at'].'|'.$current['level'].'|'.$message.'|'.$index), 0, 16);

        return [
            'id' => $id,
            'logged_at' => str_replace(' ', 'T', $current['logged_at']),
            'environment' => $current['environment'],
            'level' => $current['level'],
            'message' => $message,
            'context' => $context,
            'trace' => $trace !== '' ? $trace : null,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function splitMessageAndContext(string $raw): array
    {
        $raw = rtrim($raw);
        $pos = strrpos($raw, ' {');
        if ($pos !== false) {
            $maybe = substr($raw, $pos + 1);
            $decoded = json_decode($maybe, true);
            if (is_array($decoded)) {
                return [rtrim(substr($raw, 0, $pos)), $decoded];
            }
        }

        return [$raw, null];
    }

    /**
     * @return list<string>|null
     */
    private function levelsForFilter(string $level): ?array
    {
        return match ($level) {
            'error' => self::ERROR_AND_UP,
            'warning' => self::WARNING_AND_UP,
            default => null,
        };
    }
}
