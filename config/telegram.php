<?php

return [
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'http_timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 12),
    'queue' => env('TELEGRAM_QUEUE', 'webhooks-inbound'),
    'botfather_url' => 'https://t.me/BotFather',
    // Ciclo de vida 3.1–3.4 implementado; vendas públicas bloqueadas até autorização expressa.
    'product_sales_operational' => (bool) env('TELEGRAM_PRODUCT_SALES_OPERATIONAL', false),
    // Allowlist opcional (CSV de tenant_id) para homologar venda/entrega sem ligar a flag global.
    'product_sales_tenant_ids' => array_values(array_filter(array_map(
        static fn ($value) => (int) trim((string) $value),
        explode(',', (string) env('TELEGRAM_PRODUCT_SALES_TENANT_IDS', ''))
    ), static fn (int $id) => $id > 0)),
    // Deep link /start: validade desde a criação do grant (0 = sem expiração por TTL).
    'identity_link_ttl_hours' => (int) env('TELEGRAM_IDENTITY_LINK_TTL_HOURS', 72),
    'access_starts_notice' => 'O prazo de acesso começa a contar após a confirmação do pagamento, independentemente da data de entrada no grupo ou canal.',
];
