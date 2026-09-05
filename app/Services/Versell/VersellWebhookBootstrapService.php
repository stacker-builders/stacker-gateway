<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Log;

/**
 * Registra webhooks Versell:
 * - Cash In: PUT /webhook/{chave} (notifica em {url}/pix)
 * - Cash Out: POST /webhooks/transfer, /cashout e /infractions (MED)
 */
class VersellWebhookBootstrapService
{
    public function __construct(
        private readonly VersellHttpClient $client = new VersellHttpClient()
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, warning: ?string}
     */
    public function bootstrap(array $credentials): array
    {
        $warnings = [];

        $cashIn = $this->bootstrapCashIn($credentials);
        if (! empty($cashIn['warning'])) {
            $warnings[] = $cashIn['warning'];
        }

        $cashOut = $this->bootstrapCashOut($credentials);
        if (! empty($cashOut['warning'])) {
            $warnings[] = $cashOut['warning'];
        }

        $pixAuto = $this->bootstrapPixAutomatico($credentials);
        if (! empty($pixAuto['warning'])) {
            $warnings[] = $pixAuto['warning'];
        }

        $warning = $warnings === [] ? null : implode(' ', $warnings);

        return [
            'ok' => ($cashIn['ok'] ?? false) || ($cashOut['ok'] ?? false) || ($pixAuto['ok'] ?? false),
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, warning: ?string}
     */
    public function bootstrapCashIn(array $credentials): array
    {
        $block = VersellCredentials::apiBlock($credentials, VersellCredentials::API_CASH_IN);
        $chave = trim((string) ($block['pix_key'] ?? ''));
        if ($chave === '') {
            return ['ok' => false, 'warning' => 'Versell: chave PIX ausente; webhook Cash In não registrado.'];
        }

        $webhookUrl = GatewayWebhookUrl::forGateway('versell');
        if ($webhookUrl === '' || str_contains($webhookUrl, 'localhost')) {
            return [
                'ok' => false,
                'warning' => 'Versell: configure GETFY_WEBHOOK_PUBLIC_URL (HTTPS público) para registrar o webhook Cash In.',
            ];
        }

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_IN,
                $credentials,
                'PUT',
                '/webhook/'.rawurlencode($chave),
                ['webhookUrl' => $webhookUrl]
            );
        } catch (\Throwable $e) {
            Log::warning('VersellWebhookBootstrap cash_in failed', [
                'gateway' => 'versell',
                'api' => 'cash_in',
                'endpoint' => '/webhook/{chave}',
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['ok' => false, 'warning' => 'Versell: falha ao registrar webhook Cash In. Cadastre manualmente no painel Finance.'];
        }

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellWebhookBootstrap cash_in rejected', [
                'gateway' => 'versell',
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);

            return ['ok' => false, 'warning' => 'Versell: não foi possível registrar o webhook Cash In ('.$problem['message'].').'];
        }

        return ['ok' => true, 'warning' => null];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, warning: ?string}
     */
    public function bootstrapCashOut(array $credentials): array
    {
        if (! VersellCredentials::isCashOutReady($credentials)) {
            return ['ok' => false, 'warning' => null];
        }

        $base = GatewayWebhookUrl::forGateway('versell');
        if ($base === '' || str_contains($base, 'localhost')) {
            return [
                'ok' => false,
                'warning' => 'Versell: configure GETFY_WEBHOOK_PUBLIC_URL para webhooks Cash Out (transfer/cashout).',
            ];
        }

        $types = [
            'transfer' => GatewayWebhookUrl::forGateway('versell.transfer'),
            'cashout' => GatewayWebhookUrl::forGateway('versell.cashout'),
            'infractions' => GatewayWebhookUrl::forGateway('versell.infractions'),
        ];

        $failed = [];
        foreach ($types as $type => $uri) {
            try {
                $response = $this->client->request(
                    VersellCredentials::API_CASH_OUT,
                    $credentials,
                    'POST',
                    '/webhooks/'.$type,
                    [
                        'uri' => $uri,
                        'enabled' => true,
                        'method' => 'POST',
                        'pauseOnFail' => false,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('VersellWebhookBootstrap cash_out failed', [
                    'gateway' => 'versell',
                    'type' => $type,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
                $failed[] = $type;

                continue;
            }

            // 409 = já existe — ok
            if ($response->successful() || $response->status() === 409) {
                continue;
            }

            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellWebhookBootstrap cash_out rejected', [
                'gateway' => 'versell',
                'type' => $type,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);
            $failed[] = $type;
        }

        if ($failed !== []) {
            return [
                'ok' => false,
                'warning' => 'Versell: falha ao registrar webhook(s) Cash Out ('.implode(', ', $failed).'). Cadastre no painel Finance.',
            ];
        }

        return ['ok' => true, 'warning' => null];
    }

    /**
     * PUT /webhookrec e /webhookcobr (notificam em {url}/rec e {url}/cobr).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, warning: ?string}
     */
    public function bootstrapPixAutomatico(array $credentials): array
    {
        if (! VersellCredentials::isCashInReady($credentials)) {
            return ['ok' => false, 'warning' => null];
        }

        $base = GatewayWebhookUrl::forGateway('versell.pix_auto');
        if ($base === '' || str_contains($base, 'localhost')) {
            return [
                'ok' => false,
                'warning' => 'Versell: configure GETFY_WEBHOOK_PUBLIC_URL para webhooks Pix Automático (rec/cobr).',
            ];
        }

        $failed = [];
        foreach (['webhookrec', 'webhookcobr'] as $path) {
            try {
                $response = $this->client->request(
                    VersellCredentials::API_CASH_IN,
                    $credentials,
                    'PUT',
                    '/'.$path,
                    ['webhookUrl' => $base]
                );
            } catch (\Throwable $e) {
                Log::warning('VersellWebhookBootstrap pix_auto failed', [
                    'gateway' => 'versell',
                    'endpoint' => '/'.$path,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
                $failed[] = $path;

                continue;
            }

            if ($response->successful() || $response->status() === 409) {
                continue;
            }

            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellWebhookBootstrap pix_auto rejected', [
                'gateway' => 'versell',
                'endpoint' => '/'.$path,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);
            $failed[] = $path;
        }

        if ($failed !== []) {
            return [
                'ok' => false,
                'warning' => 'Versell: falha ao registrar webhook(s) Pix Automático ('.implode(', ', $failed).').',
            ];
        }

        return ['ok' => true, 'warning' => null];
    }
}
