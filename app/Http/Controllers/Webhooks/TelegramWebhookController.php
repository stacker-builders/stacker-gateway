<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, string $secret, TelegramWebhookService $webhooks): JsonResponse
    {
        $result = $webhooks->handleInbound($request, $secret);

        return response()->json($result);
    }
}
