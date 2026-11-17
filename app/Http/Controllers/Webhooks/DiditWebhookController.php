<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\DiditKycSync;
use App\Support\DiditKycSettings;
use App\Support\DiditWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiditWebhookController extends Controller
{
    public function handle(Request $request, DiditKycSync $sync): JsonResponse
    {
        $secret = DiditKycSettings::webhookSecret();
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return response()->json(['message' => 'Corpo inválido.'], 400);
        }

        $trust = DiditWebhookSignature::verify($raw, $decoded, $secret, $request->header('X-Timestamp'), [
            'x-signature' => $request->header('X-Signature'),
            'x-signature-previous' => $request->header('X-Signature-Previous'),
            'x-signature-v2' => $request->header('X-Signature-V2'),
            'x-signature-v2-previous' => $request->header('X-Signature-V2-Previous'),
            'x-signature-simple' => $request->header('X-Signature-Simple'),
            'x-signature-simple-previous' => $request->header('X-Signature-Simple-Previous'),
        ]);

        if ($trust === null) {
            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        $sync->apply($decoded, $trust);

        return response()->json(['ok' => true]);
    }
}
