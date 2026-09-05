<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Versell\VersellMedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Cash Out Versell — Infrações (MED).
 *
 * POST /webhooks/gateways/versell/infractions
 */
class VersellInfractionWebhookController extends Controller
{
    public function __construct(
        private readonly VersellMedService $medService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        if ($payload === []) {
            $raw = $request->getContent();
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ($payload === []) {
            return response()->json(['message' => 'empty body'], 400);
        }

        // Alguns payloads vêm como lista em data[]
        $items = [];
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            $items = $payload['data'];
        } else {
            $items = [$payload];
        }

        $synced = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            try {
                $dispute = $this->medService->syncFromInfractionPayload($item);
                if ($dispute !== null) {
                    $synced++;
                }
            } catch (\Throwable $e) {
                Log::warning('VersellInfractionWebhook: falha ao processar', [
                    'gateway' => 'versell',
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return response()->json([
            'message' => 'ok',
            'synced' => $synced,
        ]);
    }
}
