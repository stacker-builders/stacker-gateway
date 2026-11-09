<?php

namespace App\Http\Controllers\Platform;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\CajuPayAccount;
use App\Models\User;
use App\Services\CajuPay\CajuPayPayoutGrossUp;
use App\Services\CajuPay\CajuPayWebhookBootstrapService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CajuPayAccountsController extends Controller
{
    use RequiresPlatformStepUp;
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForAdmin(): array
    {
        return CajuPayAccount::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (CajuPayAccount $a) => $a->toAdminSummary())
            ->values()
            ->all();
    }

    public function show(CajuPayAccount $cajuPayAccount): JsonResponse
    {
        $gateway = GatewayRegistry::get('cajupay');
        if (! $gateway) {
            abort(404);
        }

        $decrypted = $cajuPayAccount->getDecryptedCredentials();
        $credentialValues = [];
        foreach ($gateway['credential_keys'] ?? [] as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $type === 'file') {
                continue;
            }
            $raw = $decrypted[$key] ?? null;
            if ($type === 'boolean') {
                $credentialValues[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'password') {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? '********' : '';
            } else {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
            }
        }

        $bootstrap = app(CajuPayWebhookBootstrapService::class);

        return response()->json([
            'account' => $cajuPayAccount->toAdminSummary(),
            'credential_keys' => $gateway['credential_keys'] ?? [],
            'credential_values' => $credentialValues,
            'webhook_url' => $bootstrap->webhookUrl(),
            'webhook_help' => 'O webhook é registrado automaticamente ao salvar as credenciais (checkout + PIX).',
            'webhook_setup_status' => $cajuPayAccount->webhook_setup_status ?? [],
        ]);
    }

    public function updateGrossUp(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = $request->boolean('enabled');
        CajuPayPayoutGrossUp::setEnabled($enabled);

        PlatformAuditService::log('platform.cajupay.gross_up_payout_fee', [
            'enabled' => $enabled,
        ], $request);

        return response()->json([
            'success' => true,
            'enabled' => CajuPayPayoutGrossUp::enabled(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $isFirst = ! CajuPayAccount::query()->exists();

        $account = CajuPayAccount::create([
            'name' => trim($validated['name']),
            'is_default' => $isFirst,
            'is_connected' => false,
            'is_enabled' => true,
        ]);

        PlatformAuditService::log('platform.cajupay_account.create', ['account_id' => $account->id]);

        return response()->json([
            'success' => true,
            'account' => $account->fresh()->toAdminSummary(),
        ], 201);
    }

    public function update(Request $request, CajuPayAccount $cajuPayAccount): JsonResponse
    {
        $gateway = GatewayRegistry::get('cajupay');
        if (! $gateway) {
            abort(404);
        }

        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $cajuPayAccount->name = trim($validated['name']);
        }
        if ($request->has('is_enabled')) {
            $cajuPayAccount->is_enabled = $request->boolean('is_enabled');
        }

        $existing = $cajuPayAccount->getDecryptedCredentials();
        $credentials = $this->buildCredentialsFromRequest($request, $gateway, $existing);

        $driver = GatewayRegistry::driver('cajupay');
        $isConnected = false;
        $webhookWarning = null;
        if ($driver instanceof CajuPayDriver && $credentials !== []) {
            try {
                $isConnected = $driver->testConnection($credentials);
            } catch (\Throwable) {
                $isConnected = false;
            }

            if ($isConnected) {
                $bootstrap = app(CajuPayWebhookBootstrapService::class)->bootstrap($credentials);
                $credentials = $bootstrap['credentials'];
                $webhookWarning = $bootstrap['warning'];
                $cajuPayAccount->webhook_setup_status = $bootstrap['setup_status'] ?: null;
            }
        }

        $cajuPayAccount->is_connected = $isConnected;
        $cajuPayAccount->setEncryptedCredentials($credentials);
        $cajuPayAccount->save();

        PlatformAuditService::log('platform.cajupay_account.update', ['account_id' => $cajuPayAccount->id]);

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'account' => $cajuPayAccount->fresh()->toAdminSummary(),
            'webhook_warning' => $webhookWarning,
        ]);
    }

    public function test(Request $request, CajuPayAccount $cajuPayAccount): JsonResponse
    {
        $gateway = GatewayRegistry::get('cajupay');
        if (! $gateway) {
            abort(404);
        }

        $existing = $cajuPayAccount->getDecryptedCredentials();
        $credentials = $this->buildCredentialsFromRequest($request, $gateway, $existing);

        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return response()->json(['success' => false, 'message' => 'Driver CajuPay indisponível.'], 422);
        }

        $webhookWarning = null;
        try {
            $ok = $driver->testConnection($credentials);
            if ($ok) {
                $bootstrap = app(CajuPayWebhookBootstrapService::class)->bootstrap($credentials);
                $credentials = $bootstrap['credentials'];
                $webhookWarning = $bootstrap['warning'];
                $cajuPayAccount->webhook_setup_status = $bootstrap['setup_status'] ?: null;
                $cajuPayAccount->is_connected = true;
                $cajuPayAccount->setEncryptedCredentials($credentials);
                $cajuPayAccount->save();
            }

            return response()->json([
                'success' => $ok,
                'message' => $ok ? 'Conexão realizada com sucesso.' : 'Falha na autenticação. Verifique as credenciais.',
                'webhook_warning' => $webhookWarning,
                'account' => $cajuPayAccount->fresh()->toAdminSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao testar conexão.',
            ], 422);
        }
    }

    public function rotateWebhookSecret(CajuPayAccount $cajuPayAccount): JsonResponse
    {
        $credentials = $cajuPayAccount->getDecryptedCredentials();
        if ($credentials === []) {
            throw ValidationException::withMessages(['credentials' => 'Configure as credenciais antes de rotacionar o webhook.']);
        }

        $bootstrap = app(CajuPayWebhookBootstrapService::class)->bootstrap($credentials, true);
        $cajuPayAccount->webhook_setup_status = $bootstrap['setup_status'] ?: null;
        $cajuPayAccount->setEncryptedCredentials($bootstrap['credentials']);
        $cajuPayAccount->save();

        PlatformAuditService::log('platform.cajupay_account.rotate_webhook', ['account_id' => $cajuPayAccount->id]);

        return response()->json([
            'success' => true,
            'account' => $cajuPayAccount->fresh()->toAdminSummary(),
            'webhook_warning' => $bootstrap['warning'],
            'message' => ! empty($bootstrap['credentials']['checkout_webhook_signing_secret'])
                ? 'Secret do webhook atualizado.'
                : 'Solicitação enviada; se o secret não retornou, tente novamente.',
        ]);
    }

    public function setDefault(CajuPayAccount $cajuPayAccount): JsonResponse
    {
        DB::transaction(function () use ($cajuPayAccount) {
            CajuPayAccount::query()->where('is_default', true)->update(['is_default' => false]);
            $cajuPayAccount->is_default = true;
            $cajuPayAccount->save();
        });

        PlatformAuditService::log('platform.cajupay_account.set_default', ['account_id' => $cajuPayAccount->id]);

        return response()->json([
            'success' => true,
            'accounts' => self::listForAdmin(),
        ]);
    }

    public function destroy(CajuPayAccount $cajuPayAccount): JsonResponse
    {
        if ($cajuPayAccount->is_default) {
            throw ValidationException::withMessages([
                'account' => 'A conta padrão não pode ser excluída. Defina outra conta como padrão antes.',
            ]);
        }

        $linked = User::query()->where('cajupay_account_id', $cajuPayAccount->id)->count();
        if ($linked > 0) {
            throw ValidationException::withMessages([
                'account' => "Esta conta está vinculada a {$linked} infoprodutor(es). Reatribua-os antes de excluir.",
            ]);
        }

        $id = $cajuPayAccount->id;
        $cajuPayAccount->delete();

        PlatformAuditService::log('platform.cajupay_account.delete', ['account_id' => $id]);

        return response()->json([
            'success' => true,
            'accounts' => self::listForAdmin(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $gateway
     * @param  array<string, mixed>  $existingCredentials
     * @return array<string, mixed>
     */
    private function buildCredentialsFromRequest(Request $request, array $gateway, array $existingCredentials): array
    {
        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $credentials = [];

        foreach ($credentialKeys as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $type === 'file') {
                continue;
            }

            $v = $request->input($key);
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            if ($type === 'password' && $key === 'checkout_webhook_signing_secret') {
                $trimmed = is_string($v) ? trim($v) : '';
                if ($trimmed === '' || $trimmed === '********') {
                    if (! empty($existingCredentials['checkout_webhook_signing_secret'])) {
                        $credentials[$key] = $existingCredentials['checkout_webhook_signing_secret'];
                    }
                    continue;
                }
            }

            if ($type === 'password' && $key === 'secret_key') {
                $trimmed = is_string($v) ? trim($v) : '';
                if ($trimmed === '' || $trimmed === '********') {
                    if (! empty($existingCredentials['secret_key'])) {
                        $credentials[$key] = $existingCredentials['secret_key'];
                    }
                    continue;
                }
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

        return $credentials;
    }
}
