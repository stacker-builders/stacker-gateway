<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Gateways\Versell\VersellCredentials;
use App\Gateways\Versell\VersellDriver;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\CajuPay\CajuPayWebhookBootstrapService;
use App\Services\Versell\VersellWebhookBootstrapService;
use App\Support\GatewayPluginRequirement;
use App\Support\GatewayWebhookUrl;
use App\Support\PlatformConfigContext;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class GatewaysController extends Controller
{
    use RequiresPlatformStepUp;

    public function index(): JsonResponse
    {
        $tenantId = PlatformConfigContext::settingsTenantId();
        $gateways = $this->buildGatewaysList($tenantId);
        $gatewayOrder = $this->getGatewayOrder($tenantId);

        return response()->json([
            'gateways' => $gateways,
            'gateway_order' => $gatewayOrder,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        if ($locked = $this->pluginLockedResponse($slug)) {
            return $locked;
        }

        $tenantId = PlatformConfigContext::settingsTenantId();
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        if ($credential !== null) {
            $credential = $credential->fresh();
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $credentialValues = [];
        $certificateConfigured = false;
        $certificateFilename = null;
        $decrypted = [];
        $versellSecretsConfigured = null;
        if ($credential !== null && $credential->getRawOriginal('credentials') !== null && $credential->getRawOriginal('credentials') !== '') {
            $decrypted = $credential->getDecryptedCredentials();
            if ($decrypted === [] && (string) $credential->getRawOriginal('credentials') !== '') {
                \Log::warning('GatewaysController::show decryption returned empty', [
                    'slug' => $slug,
                    'tenant_id' => $tenantId,
                ]);
            }
            $certificateConfigured = !empty($decrypted['certificate_path'] ?? '');
            $certificateFilename = $decrypted['certificate_filename'] ?? null;
        }

        $formSource = $decrypted;
        if ($slug === 'versell') {
            $formSource = VersellCredentials::flattenForForm($decrypted);
            $versellSecretsConfigured = VersellCredentials::secretsConfigured($decrypted);
        }

        foreach ($credentialKeys as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                continue;
            }
            $raw = $formSource[$key] ?? null;
            if ($type === 'boolean') {
                $credentialValues[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'password' && $slug === 'versell') {
                // Nunca devolver secret Versell ao browser
                $credentialValues[$key] = '';
            } else {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
            }
        }

        $webhookUrl = null;
        $webhookHelp = null;
        if ($slug === 'pushinpay') {
            $webhookUrl = GatewayWebhookUrl::forGateway('pushinpay');
        } elseif ($slug === 'onlyup') {
            $webhookUrl = GatewayWebhookUrl::forGateway('onlyup');
        } elseif ($slug === 'cajupay') {
            $webhookUrl = GatewayWebhookUrl::forGateway('cajupay');
            $webhookHelp = 'Cadastre esta URL HTTPS no painel CajuPay (Webhooks) ou use “Testar conexão” para registro automático. Eventos: payment.paid, payment.failed, payment.refunded, checkout.payment.paid/failed/refunded/disputed, card.payment.*. Cole o signing secret (cwhsec_…) no campo abaixo.';
        } elseif ($slug === 'mercadopago') {
            $webhookUrl = GatewayWebhookUrl::forGateway('mercadopago');
            $webhookHelp = 'Cadastre esta URL HTTPS em Suas integrações → Webhooks → modo Produção → evento Payments (payment). O Mercado Pago também recebe notificações via notification_url em cada cobrança PIX. Após o pagamento, o sistema consulta GET /v1/payments/{id} para confirmar aprovação.';
        } elseif ($slug === 'pagarme') {
            $webhookUrl = GatewayWebhookUrl::forGateway('pagarme');
            $webhookHelp = 'Cadastre esta URL HTTPS no painel Pagar.me (Configurações → Webhooks), método POST. Eventos de cobrança (charge.paid, charge.payment_failed, charge.refunded) e pedido (order.paid). A URL não é enviada em cada cobrança: o cadastro no painel é obrigatório. Precisa ser pública (não localhost) e HTTPS. Após o evento, o sistema confirma o status em GET /charges/{id}.';
        } elseif ($slug === 'linaopenx') {
            $webhookUrl = GatewayWebhookUrl::forGateway('linaopenx');
            $webhookHelp = 'Cadastre esta URL HTTPS no painel Lina (Embedded Payment / webhooks) para eventos de pagamento Open Finance (PAYMENT, consent, status). Método POST. Opcionais: assinatura HMAC nos headers X-Lina-Signature, X-Webhook-Signature ou X-Signature — cole o mesmo segredo no campo “Segredo do webhook” abaixo. Se não configurar segredo, o webhook ainda é aceito, mas a confirmação do pedido sempre reconsulta a API Lina.';
        } elseif ($slug === 'bspay') {
            $webhookUrl = GatewayWebhookUrl::forGateway('bspay');
            $webhookHelp = 'Cadastre esta URL HTTPS no painel BSPay (webhook/callback de cashin). A mesma URL também é enviada em cada PIX via postback_url. Precisa ser pública (não localhost) e HTTPS.';
        } elseif ($slug === 'versell') {
            $webhookUrl = GatewayWebhookUrl::forGateway('versell');
            $webhookHelp = 'Cash In: …/webhooks/gateways/versell (notifica em /pix). Cash Out: …/transfer, …/cashout e …/infractions (MED). Pix Automático: …/pix-automatico (notifica em /rec e /cobr). “Testar conexão” tenta registrar todos. Use HTTPS público (GETFY_WEBHOOK_PUBLIC_URL).';
        } elseif ($slug === 'cielo') {
            $webhookUrl = GatewayWebhookUrl::forGateway('cielo');
            $webhookHelp = 'Cadastre esta URL HTTPS no Site Cielo (E-commerce → Gestão API E-commerce → URL de notificações), método POST, JSON. A URL é estática (não vai em cada cobrança). Recomendado: configure o mesmo header Key/Value no portal e nos campos abaixo. O Post de Notificação só traz PaymentId; o sistema confirma o status em GET /1/sales/{PaymentId}. PIX Cielo2 (Provider Cielo2) não tem sandbox de pagamento confirmado.';
        } elseif ($slug === 'paypal') {
            $webhookUrl = GatewayWebhookUrl::forGateway('paypal');
            $webhookHelp = 'Cadastre esta URL HTTPS no PayPal Developer Dashboard (Webhooks) do mesmo app REST. Eventos: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED. Depois cole o Webhook ID no campo abaixo. Não altera PIX/cartão das outras adquirentes.';
        }

        $fileFieldsConfigured = [];
        if ($slug === 'versell') {
            $fileFieldsConfigured = VersellCredentials::fileFieldsConfigured($decrypted);
        } else {
            foreach ($credentialKeys as $keyDef) {
                $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
                if (($keyDef['type'] ?? '') !== 'file') {
                    continue;
                }
                $k = $keyDef['key'] ?? '';
                $certKey = $gateway['certificate_key'] ?? null;
                if ($k === '' || ($certKey !== null && $k === $certKey)) {
                    continue;
                }
                $pathKey = $k.'_path';
                $fileFieldsConfigured[$k] = ! empty($decrypted[$pathKey] ?? '');
            }
        }

        $usesOauth = ! empty($gateway['oauth']);
        $oauthRoutePrefix = 'gateways.'.$slug.'.oauth.';
        $oauthStartUrl = $usesOauth && Route::has($oauthRoutePrefix.'start')
            ? route($oauthRoutePrefix.'start')
            : null;
        $oauthDisconnectUrl = $usesOauth && Route::has($oauthRoutePrefix.'disconnect')
            ? route($oauthRoutePrefix.'disconnect')
            : null;
        $oauthCallbackUrl = $usesOauth && Route::has($oauthRoutePrefix.'callback')
            ? route($oauthRoutePrefix.'callback', [], true)
            : null;
        $oauthConnected = $usesOauth
            && ($credential?->is_connected ?? false)
            && trim((string) ($decrypted['access_token'] ?? '')) !== '';

        $oauthClientConfigured = false;

        $payload = [
            'slug' => $gateway['slug'],
            'name' => $gateway['name'],
            'image' => $gateway['image'] ?? null,
            'methods' => $gateway['methods'] ?? [],
            'scope' => $gateway['scope'] ?? 'national',
            'signup_url' => $gateway['signup_url'] ?? null,
            'credential_keys' => $gateway['credential_keys'] ?? [],
            'certificate_key' => $gateway['certificate_key'] ?? null,
            'is_configured' => $credential !== null,
            'is_connected' => $credential?->is_connected ?? false,
            'is_enabled' => $credential === null ? true : ($credential->is_enabled ?? true),
            'credential_values' => $credentialValues,
            'certificate_configured' => $certificateConfigured,
            'certificate_filename' => $certificateFilename && is_string($certificateFilename) ? $certificateFilename : null,
            'webhook_url' => $webhookUrl,
            'webhook_help' => $webhookHelp,
            'uses_oauth' => $usesOauth,
            'oauth_client_configured' => $oauthClientConfigured,
            'oauth_start_url' => $oauthStartUrl,
            'oauth_disconnect_url' => $oauthDisconnectUrl,
            'oauth_callback_url' => $oauthCallbackUrl,
            'oauth_connected' => $oauthConnected,
            'file_fields_configured' => $fileFieldsConfigured,
            'secrets_configured' => $versellSecretsConfigured,
        ];

        return response()->json($payload)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        if ($locked = $this->pluginLockedResponse($slug)) {
            return $locked;
        }

        $this->validatePlatformStepUp($request);

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                $rules[$key] = ['nullable', 'file', 'max:4096'];
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            // Tokens (ex.: Mercado Pago Access Token) podem ultrapassar 500 caracteres
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);

        $tenantId = PlatformConfigContext::settingsTenantId();
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $existingCredentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

        $credentials = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            if ($type === 'file') {
                continue;
            }
            $v = array_key_exists($key, $validated) ? $validated[$key] : $request->input($key);
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            if ($type === 'password' && $slug === 'cajupay' && $key === 'checkout_webhook_signing_secret') {
                $trimmed = is_string($v) ? trim($v) : '';
                if ($trimmed === '' && ! empty($existingCredentials['checkout_webhook_signing_secret'])) {
                    $credentials[$key] = $existingCredentials['checkout_webhook_signing_secret'];
                    continue;
                }
            }
            if ($type === 'password' && $slug === 'versell') {
                $trimmed = is_string($v) ? trim($v) : '';
                // Secret vazio: nestFromFlat preserva o existente
                $credentials[$key] = $trimmed;
                continue;
            }
            $credentials[$key] = is_string($v) ? trim($v) : '';
        }

        foreach (['checkout_webhook_signing_secret', 'webhook_signing_secret', 'webhook_endpoint_id'] as $preserveKey) {
            if (
                (! isset($credentials[$preserveKey]) || $credentials[$preserveKey] === '' || $credentials[$preserveKey] === null)
                && ! empty($existingCredentials[$preserveKey])
            ) {
                $credentials[$preserveKey] = $existingCredentials[$preserveKey];
            }
        }

        // Preserve existing certificate_path when nenhum novo arquivo foi enviado
        if (! empty($existingCredentials['certificate_path'])) {
            $credentials['certificate_path'] = $existingCredentials['certificate_path'];
        }

        if (! empty($gateway['oauth'])) {
            foreach (['access_token', 'refresh_token', 'token_expires_at'] as $oauthKey) {
                if (! isset($credentials[$oauthKey]) || (string) ($credentials[$oauthKey] ?? '') === '') {
                    if (array_key_exists($oauthKey, $existingCredentials)) {
                        $credentials[$oauthKey] = $existingCredentials[$oauthKey];
                    }
                }
            }
        }

        if ($certificateKey && $request->hasFile($certificateKey)) {
            $file = $request->file($certificateKey);
            if ($file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
                $path = $file->storeAs(
                    'gateway_certs/' . ($tenantId ?? 'global'),
                    $slug . '.p12',
                    'local'
                );
                $absolutePath = Storage::path($path);
                $credentials['certificate_path'] = $absolutePath;
                $credentials['certificate_filename'] = $file->getClientOriginalName();
            }
        }

        $certDir = 'gateway_certs/'.($tenantId ?? 'global');
        if ($slug === 'versell') {
            $certDir = 'gateway_certs/'.($tenantId ?? 'global').'/versell';
        }

        foreach ($credentialKeys as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $type !== 'file' || $key === $certificateKey) {
                continue;
            }
            $pathKey = $key.'_path';
            $nameKey = $key.'_filename';
            if ($request->hasFile($key)) {
                $file = $request->file($key);
                if ($file && $file->isValid()) {
                    $stored = $this->storeGatewayCredentialFile(
                        $file,
                        $slug,
                        $key,
                        $certDir,
                        is_string($keyDef['file_kind'] ?? null) ? $keyDef['file_kind'] : null,
                        is_string($keyDef['accept'] ?? null) ? $keyDef['accept'] : null,
                    );
                    if ($stored === null) {
                        return response()->json([
                            'success' => false,
                            'message' => $this->invalidCredentialFileMessage($keyDef),
                        ], 422);
                    }
                    $credentials[$pathKey] = $stored['path'];
                    $credentials[$nameKey] = $stored['filename'];
                }
            } elseif (! empty($existingCredentials[$pathKey])) {
                // Flat legado / re-save sem novo arquivo
                $credentials[$pathKey] = $existingCredentials[$pathKey];
                if (! empty($existingCredentials[$nameKey])) {
                    $credentials[$nameKey] = $existingCredentials[$nameKey];
                }
            } elseif ($slug === 'versell') {
                // Paths aninhados (cash_in.certificate_path) → flat para nestFromFlat
                $nested = VersellCredentials::normalize($existingCredentials);
                $api = str_starts_with($key, 'cash_out_') ? 'cash_out' : 'cash_in';
                $nestedPath = str_contains($key, 'private_key') ? 'private_key_path' : 'certificate_path';
                $nestedName = str_contains($key, 'private_key') ? 'private_key_filename' : 'certificate_filename';
                $existingPath = $nested[$api][$nestedPath] ?? '';
                if (is_string($existingPath) && $existingPath !== '') {
                    $credentials[$pathKey] = $existingPath;
                    $existingName = $nested[$api][$nestedName] ?? '';
                    if (is_string($existingName) && $existingName !== '') {
                        $credentials[$nameKey] = $existingName;
                    }
                }
            }
        }

        if ($slug === 'versell') {
            $credentials = VersellCredentials::nestFromFlat($credentials, $existingCredentials);
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = false;
        $webhookWarning = null;
        $connectionMessage = null;
        $diagnosis = null;
        if ($driver && !empty($credentials)) {
            try {
                if ($slug === 'versell' && $driver instanceof VersellDriver) {
                    $diagnosis = $driver->diagnoseConnection($credentials);
                    $isConnected = $diagnosis['ok'];
                    $connectionMessage = $diagnosis['message'];
                } else {
                    $isConnected = $driver->testConnection($credentials);
                }
            } catch (\Throwable) {
                $isConnected = false;
            }
            if ($isConnected && $slug === 'cajupay' && $driver instanceof CajuPayDriver) {
                $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
            }
            if ($isConnected && $slug === 'versell') {
                $boot = app(VersellWebhookBootstrapService::class)->bootstrap($credentials);
                if (! empty($boot['warning'])) {
                    $webhookWarning = $boot['warning'];
                }
            }
        }

        $credential->is_connected = $isConnected;
        if (! $credential->exists) {
            $credential->is_enabled = true;
        }
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        PlatformAuditService::log('platform.gateway.update', ['gateway_slug' => $slug]);

        $message = 'Credenciais salvas.';
        if (is_string($connectionMessage) && $connectionMessage !== '') {
            $message .= ' '.$connectionMessage;
        }
        if ($isConnected && is_array($diagnosis) && ! ($diagnosis['cash_out']['ok'] ?? false)) {
            $message .= ' Disponível para PIX; Cash Out precisa ser corrigido para saques.';
        } elseif ($isConnected && ! $connectionMessage) {
            $message = 'Credenciais salvas e conexão verificada.';
        }

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'is_enabled' => $credential->is_enabled ?? true,
            'message' => $message,
            'webhook_warning' => $webhookWarning,
            'diagnosis' => $diagnosis !== null ? [
                'cash_in' => $diagnosis['cash_in'],
                'cash_out' => $diagnosis['cash_out'],
            ] : null,
        ]);
    }

    public function updateEnabled(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (! $gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        if ($locked = $this->pluginLockedResponse($slug)) {
            return $locked;
        }

        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $tenantId = PlatformConfigContext::settingsTenantId();

        if ($slug === 'cajupay') {
            $default = \App\Models\CajuPayAccount::query()->default()->first();
            if ($default !== null) {
                $default->is_enabled = $validated['is_enabled'];
                $default->save();

                $legacy = GatewayCredential::forTenant($tenantId)->where('gateway_slug', 'cajupay')->first();
                if ($legacy !== null) {
                    $legacy->is_enabled = $validated['is_enabled'];
                    $legacy->save();
                }

                PlatformAuditService::log('platform.gateway.enabled', [
                    'gateway_slug' => $slug,
                    'is_enabled' => $default->is_enabled,
                ]);

                return response()->json([
                    'success' => true,
                    'is_enabled' => $default->is_enabled,
                    'message' => $default->is_enabled
                        ? 'Adquirente ativado para cobrança.'
                        : 'Adquirente desativado. Não será usado em novos pagamentos.',
                ]);
            }
        }

        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        if ($credential === null) {
            return response()->json([
                'message' => 'Configure as credenciais do adquirente antes de ativar ou desativar.',
            ], 422);
        }

        $credential->is_enabled = $validated['is_enabled'];
        $credential->save();

        PlatformAuditService::log('platform.gateway.enabled', [
            'gateway_slug' => $slug,
            'is_enabled' => $credential->is_enabled,
        ]);

        return response()->json([
            'success' => true,
            'is_enabled' => $credential->is_enabled,
            'message' => $credential->is_enabled
                ? 'Adquirente ativado para cobrança.'
                : 'Adquirente desativado. Não será usado em novos pagamentos.',
        ]);
    }

    public function test(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        if ($locked = $this->pluginLockedResponse($slug)) {
            return $locked;
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            $optional = ! empty($keyDef['optional']);
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            // Arquivos (CRT/KEY Versell etc.) não vão no JSON do teste — já devem estar salvos.
            if ($type === 'file') {
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            $rules[$key] = $optional
                ? ['nullable', 'string', 'max:2000']
                : ['required', 'string', 'max:2000'];
        }
        $validated = $request->validate($rules);

        $tenantId = PlatformConfigContext::settingsTenantId();
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        $existingCredentials = $credential ? $credential->getDecryptedCredentials() : [];
        $credentials = $existingCredentials;
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            $optional = ! empty($keyDef['optional']);
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            if ($type === 'file') {
                continue;
            }
            $v = $validated[$key] ?? null;
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            if (is_string($v)) {
                $credentials[$key] = trim($v);
            }
        }

        if ($certificateKey && empty($credentials['certificate_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Envie e salve o certificado P12 antes de testar a conexão.',
            ], 422);
        }

        if ($slug === 'versell') {
            $credentials = VersellCredentials::nestFromFlat($credentials, $existingCredentials);
            $files = VersellCredentials::fileFieldsConfigured($credentials);
            foreach (['cash_in_certificate', 'cash_in_private_key', 'cash_out_certificate', 'cash_out_private_key'] as $fileKey) {
                if (empty($files[$fileKey])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Envie e salve os certificados CRT e as private keys KEY (Cash In e Cash Out) antes de testar.',
                    ], 422);
                }
            }
            $secrets = VersellCredentials::secretsConfigured($credentials);
            if (! $secrets['cash_in'] || ! $secrets['cash_out']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe e salve os Client Secrets de Cash In e Cash Out antes de testar.',
                ], 422);
            }
        } else {
            foreach ($credentialKeys as $keyDef) {
                $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
                $key = $keyDef['key'] ?? '';
                $type = $keyDef['type'] ?? 'text';
                if ($key === '' || $type !== 'file' || $key === $certificateKey) {
                    continue;
                }
                $pathKey = $key.'_path';
                if (empty($credentials[$pathKey])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Envie e salve todos os arquivos de certificado obrigatórios antes de testar a conexão.',
                    ], 422);
                }
            }
        }

        $driver = GatewayRegistry::driver($slug);
        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'Driver do gateway não disponível.'], 422);
        }

        foreach (['checkout_webhook_signing_secret', 'webhook_signing_secret', 'webhook_endpoint_id'] as $preserveKey) {
            if (
                (! isset($credentials[$preserveKey]) || $credentials[$preserveKey] === '' || $credentials[$preserveKey] === null)
                && ! empty($existingCredentials[$preserveKey])
            ) {
                $credentials[$preserveKey] = $existingCredentials[$preserveKey];
            }
        }

        try {
            if ($slug === 'versell' && $driver instanceof VersellDriver) {
                $diagnosis = $driver->diagnoseConnection($credentials);
                $webhookWarning = null;
                if ($diagnosis['ok']) {
                    $boot = app(VersellWebhookBootstrapService::class)->bootstrap($credentials);
                    if (! empty($boot['warning'])) {
                        $webhookWarning = $boot['warning'];
                    }
                }
                if ($credential) {
                    $credential->is_connected = $diagnosis['ok'];
                    $credential->save();
                }

                return response()->json([
                    'success' => $diagnosis['ok'],
                    'message' => $diagnosis['message'],
                    'diagnosis' => [
                        'cash_in' => $diagnosis['cash_in'],
                        'cash_out' => $diagnosis['cash_out'],
                    ],
                    'webhook_warning' => $webhookWarning,
                ]);
            }

            $ok = $driver->testConnection($credentials);
            $webhookWarning = null;
            if ($ok && $slug === 'cajupay' && $driver instanceof CajuPayDriver) {
                $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
                if ($credential) {
                    $credential->setEncryptedCredentials($credentials);
                    $credential->is_connected = true;
                    $credential->save();
                }
            }
            $failMessage = 'Falha na autenticação. Verifique as credenciais.';
            if (! $ok && $slug === 'woovi') {
                $failMessage = 'Falha na autenticação. Em testes, marque “Sandbox” e use um AppID do painel de sandbox; o AppID de produção só vale com Sandbox desmarcado.';
            }
            if (! $ok && $slug === 'linaopenx') {
                $failMessage = 'Falha na autenticação Lina. Use Client ID/Secret corretos e deixe “Homologação (HML)” desmarcado se as credenciais forem de produção (iam.linaob.com.br). Marque HML só para iam.hml.linaob.com.br.';
            }

            return response()->json([
                'success' => $ok,
                'message' => $ok ? 'Conexão realizada com sucesso.' : $failMessage,
                'webhook_warning' => $webhookWarning,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao testar conexão.',
            ], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function ensureCajuPayWebhookRegistered(CajuPayDriver $driver, array $credentials, ?string &$warning): array
    {
        $result = app(CajuPayWebhookBootstrapService::class)->bootstrap($credentials);
        $warning = $result['warning'];

        return $result['credentials'];
    }

    public function updateCertificate(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        if ($locked = $this->pluginLockedResponse($slug)) {
            return $locked;
        }

        $certificateKey = $gateway['certificate_key'] ?? null;
        if (!$certificateKey) {
            return response()->json([
                'success' => false,
                'message' => 'Este gateway não utiliza certificado.',
            ], 422);
        }

        $rules = [
            $certificateKey => ['required', 'file', 'max:4096'],
        ];
        $request->validate($rules);

        $tenantId = PlatformConfigContext::settingsTenantId();
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $credentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

        if (! $request->hasFile($certificateKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum arquivo recebido. Envie o certificado .p12 novamente.',
            ], 422);
        }

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file($certificateKey);
        if ($file && $file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
            $path = $file->storeAs(
                'gateway_certs/' . ($tenantId ?? 'global'),
                $slug . '.p12',
                'local'
            );
            $absolutePath = Storage::path($path);
            $credentials['certificate_path'] = $absolutePath;
            $credentials['certificate_filename'] = $file->getClientOriginalName();
            \Log::info('GatewaysController::updateCertificate saved certificate', [
                'slug' => $slug,
                'tenant_id' => $tenantId,
                'path' => $absolutePath,
                'keys' => array_keys($credentials),
            ]);
        } elseif ($file && $file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'O certificado deve ser um arquivo .p12.',
            ], 422);
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = $credential->is_connected ?? false;
        if ($driver && !empty($credentials)) {
            try {
                $isConnected = $driver->testConnection($credentials);
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $isConnected ? 'Certificado salvo e conexão verificada.' : 'Certificado salvo.',
        ]);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway_order' => ['required', 'array'],
            'gateway_order.pix' => ['nullable', 'array'],
            'gateway_order.pix.*' => ['string', 'max:64'],
            'gateway_order.card' => ['nullable', 'array'],
            'gateway_order.card.*' => ['string', 'max:64'],
            'gateway_order.boleto' => ['nullable', 'array'],
            'gateway_order.boleto.*' => ['string', 'max:64'],
            'gateway_order.pix_auto' => ['nullable', 'array'],
            'gateway_order.pix_auto.*' => ['string', 'max:64'],
        ]);

        $tenantId = PlatformConfigContext::settingsTenantId();
        $go = $validated['gateway_order'];
        $sanitized = [
            'pix' => GatewayRegistry::filterSlugsToAllowedAcquirers($go['pix'] ?? []),
            'card' => GatewayRegistry::filterSlugsToAllowedAcquirers($go['card'] ?? []),
            'boleto' => GatewayRegistry::filterSlugsToAllowedAcquirers($go['boleto'] ?? []),
            'pix_auto' => GatewayRegistry::filterSlugsToAllowedAcquirers($go['pix_auto'] ?? []),
        ];
        Setting::set('gateway_order', $sanitized, $tenantId);

        return response()->json(['success' => true, 'message' => 'Ordem de redundância atualizada.']);
    }

    /**
     * Build gateways list with is_configured and is_connected for tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGatewaysList(?int $tenantId): array
    {
        $all = GatewayRegistry::allowedAcquirers();
        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->get()
            ->keyBy('gateway_slug');

        return array_map(function ($g) use ($credentialBySlug) {
            $cred = $credentialBySlug->get($g['slug'] ?? '');
            $image = $g['image'] ?? null;
            $pluginUi = GatewayPluginRequirement::uiPropsForDefinition($g);

            return [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
                'scope' => $g['scope'] ?? 'national',
                'signup_url' => $g['signup_url'] ?? null,
                'is_configured' => $cred !== null,
                'is_connected' => $cred?->is_connected ?? false,
                'is_enabled' => $cred === null ? true : ($cred->is_enabled ?? true),
                'requires_plugin' => $pluginUi['requires_plugin'],
                'plugin_locked' => $pluginUi['plugin_locked'],
                'plugin_name' => $pluginUi['plugin_name'],
                'plugin_locked_title' => $pluginUi['plugin_locked_title'],
                'plugin_locked_message' => $pluginUi['plugin_locked_message'],
            ];
        }, $all);
    }

    private function pluginLockedResponse(string $slug): ?JsonResponse
    {
        if (GatewayPluginRequirement::isUnlocked($slug)) {
            return null;
        }

        return response()->json([
            'message' => GatewayPluginRequirement::lockedUserMessage($slug),
            'plugin_locked' => true,
            'requires_plugin' => GatewayPluginRequirement::requiredPluginSlug(GatewayRegistry::get($slug) ?: null),
        ], 403);
    }

    /**
     * @return array{pix: array<string>, card: array<string>, boleto: array<string>, pix_auto: array<string>}
     */
    private function getGatewayOrder(?int $tenantId): array
    {
        $raw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        $default = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        if (! is_array($raw)) {
            return [
                'pix' => GatewayRegistry::filterSlugsToAllowedAcquirers($default['pix'] ?? []),
                'card' => GatewayRegistry::filterSlugsToAllowedAcquirers($default['card'] ?? []),
                'boleto' => GatewayRegistry::filterSlugsToAllowedAcquirers($default['boleto'] ?? []),
                'pix_auto' => GatewayRegistry::filterSlugsToAllowedAcquirers($default['pix_auto'] ?? []),
            ];
        }

        return [
            'pix' => GatewayRegistry::filterSlugsToAllowedAcquirers($raw['pix'] ?? $default['pix'] ?? []),
            'card' => GatewayRegistry::filterSlugsToAllowedAcquirers($raw['card'] ?? $default['card'] ?? []),
            'boleto' => GatewayRegistry::filterSlugsToAllowedAcquirers($raw['boleto'] ?? $default['boleto'] ?? []),
            'pix_auto' => GatewayRegistry::filterSlugsToAllowedAcquirers($raw['pix_auto'] ?? $default['pix_auto'] ?? []),
        ];
    }

    /**
     * @return array{path: string, filename: string}|null
     */
    private function storeGatewayCredentialFile(
        \Illuminate\Http\UploadedFile $file,
        string $slug,
        string $fieldKey,
        string $certDir,
        ?string $fileKind,
        ?string $accept,
    ): ?array {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $allowed = $this->allowedExtensionsForCredentialFile($fileKind, $accept);
        if ($ext === '' || ! in_array($ext, $allowed, true)) {
            return null;
        }

        $safeKey = preg_replace('/[^a-z0-9_-]/i', '_', $fieldKey) ?: 'file';
        $safeName = $slug.'_'.$safeKey.'.'.$ext;
        $path = $file->storeAs($certDir, $safeName, 'local');
        $absolutePath = Storage::path($path);

        @chmod($absolutePath, $fileKind === 'private_key' ? 0600 : 0640);

        $original = $file->getClientOriginalName();
        $safeOriginal = is_string($original) ? basename($original) : $safeName;
        $safeOriginal = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $safeOriginal) ?: $safeName;

        Log::info('GatewaysController stored gateway credential file', [
            'slug' => $slug,
            'field' => $fieldKey,
            'extension' => $ext,
            // Nunca logar path com intenção de vazar conteúdo; só nome sanitizado
            'filename' => $safeOriginal,
        ]);

        return [
            'path' => $absolutePath,
            'filename' => $safeOriginal,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedExtensionsForCredentialFile(?string $fileKind, ?string $accept): array
    {
        if (is_string($accept) && $accept !== '') {
            $parts = preg_split('/\s*,\s*/', $accept) ?: [];
            $exts = [];
            foreach ($parts as $part) {
                $part = strtolower(ltrim(trim($part), '.'));
                if ($part !== '') {
                    $exts[] = $part;
                }
            }
            if ($exts !== []) {
                return array_values(array_unique($exts));
            }
        }

        return match ($fileKind) {
            'certificate' => ['crt', 'pem'],
            'private_key' => ['key', 'pem'],
            default => ['crt', 'key', 'pem'],
        };
    }

    /**
     * @param  array<string, mixed>  $keyDef
     */
    private function invalidCredentialFileMessage(array $keyDef): string
    {
        $label = trim((string) ($keyDef['label'] ?? 'arquivo'));
        $accept = trim((string) ($keyDef['accept'] ?? ''));
        if ($accept !== '') {
            return "O campo {$label} deve ser um arquivo {$accept}.";
        }

        return "Arquivo inválido para {$label}.";
    }
}
