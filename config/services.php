<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'plugin_store' => [
        // URL da loja de plugins. Use a URL do seu plugins-getfy (ex.: http://plugins-getfy.test). Vazio = aba loja não carrega plugins.
        'url' => rtrim(env('PLUGIN_STORE_URL', ''), '/'),
        'api_key' => env('PLUGIN_STORE_API_KEY'),
        'submit_url' => env('PLUGIN_STORE_SUBMIT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CajuPay (PIX): autenticação por X-API-Key / X-API-Secret (credenciais no painel do tenant).
    | URL base opcional para homologação ou proxy.
    |--------------------------------------------------------------------------
    */
    'cajupay' => [
        'base_url' => rtrim(env('CAJUPAY_API_BASE_URL', 'https://api.cajupay.com.br'), '/'),
        /** Em checkout HTTP local, repassa chamadas do SDK pelo próprio domínio (evita CORS). Desative com CAJUPAY_SDK_BROWSER_PROXY=false */
        'sdk_browser_proxy' => filter_var(env('CAJUPAY_SDK_BROWSER_PROXY', true), FILTER_VALIDATE_BOOLEAN),
        /** Janela HMAC padrão dos webhooks CajuPay (segundos). Docs: 300. */
        'webhook_signature_skew_seconds' => max(60, (int) env('CAJUPAY_WEBHOOK_SIGNATURE_SKEW_SECONDS', 300)),
        /**
         * MED (med_opened / med_resolved) frequentemente chega em retentativa com t= antigo.
         * Janela ampliada só para esses eventos (padrão 24h).
         */
        'med_webhook_signature_skew_seconds' => max(300, (int) env('CAJUPAY_MED_WEBHOOK_SIGNATURE_SKEW_SECONDS', 86400)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagar.me API v5 (core): mesma URL para teste e produção; o ambiente é
    | definido pelo tipo de chave (sk_test_ / sk_).
    |--------------------------------------------------------------------------
    */
    'pagarme' => [
        'base_url' => rtrim(env('PAGARME_API_BASE_URL', 'https://api.pagar.me/core/v5'), '/'),
    ],

    /*
    | PagBank (API de Pedidos). O ambiente segue a flag sandbox das credenciais.
    */
    'pagbank' => [
        'base_url' => rtrim(env('PAGBANK_API_BASE_URL', 'https://api.pagseguro.com'), '/'),
        'sandbox_url' => rtrim(env('PAGBANK_SANDBOX_API_BASE_URL', 'https://sandbox.api.pagseguro.com'), '/'),
    ],

    'brasilapi' => [
        'cnpj_url' => rtrim(env('BRASILAPI_CNPJ_URL', 'https://brasilapi.com.br/api/cnpj/v1'), '/'),
        'timeout' => (float) env('BRASILAPI_CNPJ_TIMEOUT', 3),
    ],

];
