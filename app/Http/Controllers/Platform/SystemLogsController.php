<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\SystemLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemLogsController extends Controller
{
    public function __construct(
        private readonly SystemLogReader $reader,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Platform/SystemLogs/Index', $this->payload($request));
    }

    public function feed(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $date = $this->reader->normalizeDate((string) $request->query('date', now()->toDateString()));
        $level = $this->reader->normalizeLevel((string) $request->query('level', 'warning'));
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }
        $perPage = (int) $request->query('per_page', 100);
        if (! in_array($perPage, [50, 100, 200], true)) {
            $perPage = 100;
        }

        $result = $this->reader->query($date, $level, $q, $perPage);

        return [
            'entries' => $result['entries'],
            'available_dates' => $result['available_dates'],
            'file' => $result['file'],
            'filters' => [
                'date' => $date,
                'level' => $level,
                'q' => $q !== '' ? $q : null,
                'per_page' => $perPage,
            ],
        ];
    }
}
