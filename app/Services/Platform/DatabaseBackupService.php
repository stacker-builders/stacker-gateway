<?php

namespace App\Services\Platform;

use App\Services\PlatformAuditService;
use App\Support\DatabaseBackupSettings;
use App\Support\RemoteStorage;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DatabaseBackupService
{
    public const FILENAME_PATTERN = '/^stacker-\d{8}-\d{4}-[a-f0-9]{8}\.sql\.gz$/';

    public const LOCK_SECONDS = 1800;

    private ?Filesystem $disk = null;

    /**
     * Gera o dump, envia ao storage (privado) e aplica retenção.
     *
     * @return array{filename: string, bytes: int, destination: string, path: string, pruned: int}
     */
    public function run(string $trigger = 'scheduled'): array
    {
        $lock = Cache::lock('database_backup_running', self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Já existe um backup em andamento. Aguarde a conclusão.');
        }

        $tempSql = null;
        $tempGz = null;

        try {
            @set_time_limit(self::LOCK_SECONDS);
            $filename = $this->makeFilename();
            $tempSql = $this->dumpToTempSql();
            $tempGz = $this->gzipFile($tempSql);
            @unlink($tempSql);
            $tempSql = null;

            $bytes = (int) filesize($tempGz);
            $objectKey = $this->objectKey($filename);
            $this->storePrivateFile($objectKey, $tempGz);
            @unlink($tempGz);
            $tempGz = null;

            $destination = $this->destinationProvider();
            $pruned = $this->prune();

            DatabaseBackupSettings::recordLastRun([
                'status' => 'ok',
                'filename' => $filename,
                'bytes' => $bytes,
                'destination' => $destination,
            ]);

            PlatformAuditService::log('platform.backup.created', [
                'trigger' => $trigger,
                'filename' => $filename,
                'bytes' => $bytes,
                'destination' => $destination,
                'pruned' => $pruned,
            ]);

            Log::info('database.backup.created', [
                'trigger' => $trigger,
                'filename' => $filename,
                'bytes' => $bytes,
                'destination' => $destination,
            ]);

            return [
                'filename' => $filename,
                'bytes' => $bytes,
                'destination' => $destination,
                'path' => $objectKey,
                'pruned' => $pruned,
            ];
        } catch (Throwable $e) {
            DatabaseBackupSettings::recordLastRun([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'destination' => $this->destinationProvider(),
            ]);
            Log::warning('database.backup.failed', [
                'trigger' => $trigger,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (is_string($tempSql) && is_file($tempSql)) {
                @unlink($tempSql);
            }
            if (is_string($tempGz) && is_file($tempGz)) {
                @unlink($tempGz);
            }
            $lock->release();
        }
    }

    /**
     * Gera um dump temporário para download imediato (não envia ao storage).
     *
     * @return array{path: string, filename: string, bytes: int}
     */
    public function createDownloadableDump(): array
    {
        $lock = Cache::lock('database_backup_running', self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Já existe um backup em andamento. Aguarde a conclusão.');
        }

        $tempSql = null;

        try {
            @set_time_limit(self::LOCK_SECONDS);
            $filename = $this->makeFilename();
            $tempSql = $this->dumpToTempSql();
            $tempGz = $this->gzipFile($tempSql);
            @unlink($tempSql);
            $tempSql = null;

            return [
                'path' => $tempGz,
                'filename' => $filename,
                'bytes' => (int) filesize($tempGz),
            ];
        } catch (Throwable $e) {
            if (is_string($tempSql) && is_file($tempSql)) {
                @unlink($tempSql);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function shouldRunScheduledNow(): bool
    {
        if (! DatabaseBackupSettings::enabled()) {
            return false;
        }

        $tz = (string) config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($tz);
        [$hour, $minute] = array_map('intval', explode(':', DatabaseBackupSettings::dailyAt()));
        $target = $now->copy()->setTime($hour, $minute, 0);
        if ($now->lt($target)) {
            return false;
        }

        $lockKey = 'database_backup_dispatch:'.$now->toDateString();

        return Cache::add($lockKey, 1, now()->addHours(26));
    }

    /**
     * @return list<array{filename: string, bytes: int, last_modified: string|null, destination: string}>
     */
    public function listBackups(): array
    {
        try {
            $disk = $this->disk();
            $files = $disk->files($this->directory());
        } catch (Throwable $e) {
            Log::warning('database.backup.list_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $destination = $this->destinationProvider();
        $items = [];
        foreach ($files as $path) {
            $filename = basename(str_replace('\\', '/', $path));
            if (! preg_match(self::FILENAME_PATTERN, $filename)) {
                continue;
            }
            try {
                $bytes = (int) $disk->size($path);
            } catch (Throwable) {
                $bytes = 0;
            }
            $modified = null;
            try {
                $ts = $disk->lastModified($path);
                $modified = $ts ? Carbon::createFromTimestamp($ts)->toIso8601String() : null;
            } catch (Throwable) {
                $modified = null;
            }
            $items[] = [
                'filename' => $filename,
                'bytes' => $bytes,
                'last_modified' => $modified,
                'destination' => $destination,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['last_modified'] ?? ''), (string) ($a['last_modified'] ?? ''));
        });

        return $items;
    }

    public function prune(): int
    {
        $cutoff = now()->subDays(DatabaseBackupSettings::retentionDays())->getTimestamp();
        $removed = 0;

        try {
            $disk = $this->disk();
            $files = $disk->files($this->directory());
        } catch (Throwable $e) {
            Log::warning('database.backup.prune_list_failed', ['message' => $e->getMessage()]);

            return 0;
        }

        foreach ($files as $path) {
            $filename = basename(str_replace('\\', '/', $path));
            if (! preg_match(self::FILENAME_PATTERN, $filename)) {
                continue;
            }
            $mtime = $this->timestampFromFilename($filename);
            if ($mtime <= 0) {
                try {
                    $mtime = (int) $disk->lastModified($path);
                } catch (Throwable) {
                    $mtime = 0;
                }
            }
            if ($mtime > 0 && $mtime < $cutoff) {
                try {
                    $disk->delete($path);
                    $removed++;
                } catch (Throwable $e) {
                    Log::warning('database.backup.prune_delete_failed', [
                        'path' => $path,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $removed;
    }

    public function readStream(string $filename)
    {
        $filename = $this->assertSafeFilename($filename);
        $path = $this->objectKey($filename);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            throw new RuntimeException('Arquivo de backup não encontrado.');
        }

        $stream = $disk->readStream($path);
        if ($stream === false || $stream === null) {
            throw new RuntimeException('Não foi possível ler o arquivo de backup.');
        }

        return $stream;
    }

    public function destinationProvider(): string
    {
        return DatabaseBackupSettings::destinationProvider();
    }

    public function destinationIsRemote(): bool
    {
        return DatabaseBackupSettings::destinationIsRemote();
    }

    public function destinationLabel(): string
    {
        return DatabaseBackupSettings::destinationLabel();
    }

    public function assertSafeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new RuntimeException('Nome de arquivo de backup inválido.');
        }

        return $filename;
    }

    public function directory(): string
    {
        return DatabaseBackupSettings::destinationPrefix();
    }

    public function objectKey(string $filename): string
    {
        return $this->directory().'/'.$this->assertSafeFilename($filename);
    }

    private function disk(): Filesystem
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $creds = DatabaseBackupSettings::resolveDestinationCredentials();
        $provider = (string) ($creds['provider'] ?? 'local');

        if ($provider === 'local'
            || trim((string) ($creds['key'] ?? '')) === ''
            || trim((string) ($creds['secret'] ?? '')) === ''
            || trim((string) ($creds['bucket'] ?? '')) === '') {
            if ($provider !== 'local') {
                throw new RuntimeException(
                    'Destino de backup remoto incompleto. Preencha Access Key, Secret Key e Bucket em Configurações → Backup.'
                );
            }
            $this->disk = Storage::disk('local');

            return $this->disk;
        }

        try {
            $config = RemoteStorage::buildS3DiskConfig($creds);
            // Backup nunca é público — independente do storage de mídias.
            $config['visibility'] = 'private';
            if (($creds['provider'] ?? '') === 'r2' || RemoteStorage::isR2ApiEndpoint($creds['endpoint'] ?? '')) {
                $config['retain_visibility'] = false;
            }
            $config['throw'] = true;
            $this->disk = Storage::build($config);
        } catch (Throwable $e) {
            Log::warning('database.backup.disk_build_failed', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Não foi possível conectar ao destino de backup ('.$this->destinationLabel().'): '.RemoteStorage::friendlyErrorMessage($e),
                0,
                $e
            );
        }

        return $this->disk;
    }

    private function storePrivateFile(string $objectKey, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo de backup para envio.');
        }

        try {
            $disk = $this->disk();
            $provider = $this->destinationProvider();
            $options = [];
            if (in_array($provider, ['s3', 'wasabi'], true)) {
                $options['visibility'] = 'private';
            }

            $written = $disk->writeStream($objectKey, $stream, $options);
            if ($written === false) {
                throw new RuntimeException('Falha ao gravar o backup no destino configurado.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function makeFilename(): string
    {
        $tz = (string) config('app.timezone', 'America/Sao_Paulo');

        return sprintf(
            'stacker-%s-%s.sql.gz',
            Carbon::now($tz)->format('Ymd-Hi'),
            bin2hex(random_bytes(4))
        );
    }

    private function dumpToTempSql(): string
    {
        $driver = (string) config('database.default');
        $connection = config('database.connections.'.$driver, []);
        $dbDriver = (string) ($connection['driver'] ?? $driver);

        $path = $this->tempPath('sql');

        try {
            match ($dbDriver) {
                'pgsql' => $this->dumpPgsql($connection, $path),
                'mysql', 'mariadb' => $this->dumpMysql($connection, $path),
                'sqlite' => $this->dumpSqlite($connection, $path),
                default => throw new RuntimeException('Driver de banco não suportado para backup: '.$dbDriver),
            };
        } catch (Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $e;
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('O dump do banco ficou vazio.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dumpPgsql(array $connection, string $path): void
    {
        $binary = $this->findBinary('pg_dump');
        if ($binary === null) {
            throw new RuntimeException('pg_dump não encontrado no servidor. No Docker, a imagem já inclui postgresql-client.');
        }

        $result = Process::timeout(self::LOCK_SECONDS)
            ->env([
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
                'PGSSLMODE' => (string) ($connection['sslmode'] ?? env('DB_SSLMODE', 'prefer')),
            ])
            ->run([
                $binary,
                '--no-owner',
                '--no-acl',
                '--format=plain',
                '--file', $path,
                '-h', (string) ($connection['host'] ?? '127.0.0.1'),
                '-p', (string) ($connection['port'] ?? '5432'),
                '-U', (string) ($connection['username'] ?? 'postgres'),
                '-d', (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump falhou: '.$this->processError($result));
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dumpMysql(array $connection, string $path): void
    {
        $binary = $this->findBinary('mysqldump');
        if ($binary === null) {
            throw new RuntimeException('mysqldump não encontrado no servidor. Instale o cliente MySQL/MariaDB.');
        }

        $args = [
            $binary,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--result-file='.$path,
            '-h', (string) ($connection['host'] ?? '127.0.0.1'),
            '-P', (string) ($connection['port'] ?? '3306'),
            '-u', (string) ($connection['username'] ?? 'root'),
        ];
        $socket = trim((string) ($connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            $args[] = '--socket='.$socket;
        }
        $args[] = (string) ($connection['database'] ?? '');

        $result = Process::timeout(self::LOCK_SECONDS)
            ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->run($args);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump falhou: '.$this->processError($result));
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dumpSqlite(array $connection, string $path): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database !== '' && $database !== ':memory:' && is_file($database)) {
            $binary = $this->findBinary('sqlite3');
            if ($binary !== null) {
                $result = Process::timeout(self::LOCK_SECONDS)->run([$binary, $database, '.dump']);
                if ($result->successful() && $result->output() !== '') {
                    file_put_contents($path, $result->output());

                    return;
                }
            }
        }

        $this->dumpSqliteViaPdo($path);
    }

    private function dumpSqliteViaPdo(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do dump.');
        }

        fwrite($fh, "-- Stacker SQLite dump\nPRAGMA foreign_keys=OFF;\nBEGIN;\n");

        $tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        if ($tables === false) {
            fclose($fh);
            throw new RuntimeException('Não foi possível listar as tabelas do SQLite.');
        }

        while ($table = $tables->fetch(\PDO::FETCH_ASSOC)) {
            $name = (string) ($table['name'] ?? '');
            $sql = trim((string) ($table['sql'] ?? ''));
            if ($name === '' || $sql === '') {
                continue;
            }
            $quoted = '"'.str_replace('"', '""', $name).'"';
            fwrite($fh, 'DROP TABLE IF EXISTS '.$quoted.";\n".$sql.";\n");

            $rows = $pdo->query('SELECT * FROM '.$quoted);
            if ($rows === false) {
                continue;
            }
            while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                if (! is_array($row) || $row === []) {
                    continue;
                }
                $columns = [];
                $values = [];
                foreach ($row as $column => $value) {
                    $columns[] = '"'.str_replace('"', '""', (string) $column).'"';
                    $values[] = $this->sqliteLiteral($value);
                }
                fwrite($fh, 'INSERT INTO '.$quoted.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).");\n");
            }
        }

        fwrite($fh, "COMMIT;\n");
        fclose($fh);
    }

    private function sqliteLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $string = (string) $value;
        if (! mb_check_encoding($string, 'UTF-8')) {
            return "X'".bin2hex($string)."'";
        }

        return "'".str_replace("'", "''", $string)."'";
    }

    private function gzipFile(string $source): string
    {
        $dest = $this->tempPath('sql.gz');
        $in = fopen($source, 'rb');
        $out = gzopen($dest, 'wb9');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_file($dest)) {
                @unlink($dest);
            }
            throw new RuntimeException('Não foi possível compactar o dump (gzip).');
        }

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);

        return $dest;
    }

    private function tempPath(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sgbak_');
        if ($base === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para o backup.');
        }
        $path = $base.'.'.$suffix;
        @unlink($base);

        return $path;
    }

    private function findBinary(string $name): ?string
    {
        $candidates = [$name];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = $name.'.exe';
        }

        foreach ($candidates as $bin) {
            $probe = PHP_OS_FAMILY === 'Windows'
                ? Process::timeout(8)->run('where '.escapeshellarg($bin))
                : Process::timeout(8)->run(['which', $bin]);
            if (! $probe->successful()) {
                continue;
            }
            $line = trim(strtok($probe->output(), "\n") ?: '');
            if ($line !== '' && (is_file($line) || PHP_OS_FAMILY !== 'Windows')) {
                return $line !== '' ? $line : $bin;
            }
        }

        return null;
    }

    private function processError(mixed $result): string
    {
        $error = trim((string) $result->errorOutput());
        $output = trim((string) $result->output());
        $text = $error !== '' ? $error : $output;

        return mb_substr($text !== '' ? $text : 'código '.$result->exitCode(), 0, 500);
    }

    private function timestampFromFilename(string $filename): int
    {
        if (! preg_match('/^stacker-(\d{8})-(\d{4})-/', $filename, $m)) {
            return 0;
        }
        try {
            $tz = (string) config('app.timezone', 'America/Sao_Paulo');

            return Carbon::createFromFormat('Ymd Hi', $m[1].' '.$m[2], $tz)?->getTimestamp() ?? 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
