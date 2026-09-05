<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\DatabaseBackupService;
use App\Support\DatabaseBackupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
    ) {}

    public function run(Request $request): JsonResponse
    {
        try {
            $result = $this->backups->run('manual');
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Backup gerado e enviado ao storage.',
            'file' => [
                'filename' => $result['filename'],
                'bytes' => $result['bytes'],
                'destination' => $result['destination'],
            ],
            'files' => $this->backups->listBackups(),
            'status' => DatabaseBackupSettings::lastRun(),
            'pruned' => $result['pruned'],
        ]);
    }

    public function download(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $dump = $this->backups->createDownloadableDump();
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $path = $dump['path'];
        $filename = $dump['filename'];

        return response()->streamDownload(function () use ($path): void {
            $stream = fopen($path, 'rb');
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }, $filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    public function file(Request $request, string $filename): StreamedResponse|JsonResponse
    {
        try {
            $safe = $this->backups->assertSafeFilename($filename);
            $stream = $this->backups->readStream($safe);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'não encontrado') ? 404 : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $safe, [
            'Content-Type' => 'application/gzip',
        ]);
    }
}
