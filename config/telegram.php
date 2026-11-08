<?php

return [
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'http_timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 12),
    'queue' => env('TELEGRAM_QUEUE', 'webhooks-inbound'),
    'botfather_url' => 'https://t.me/BotFather',
    // Ciclo de vida 3.1–3.4 implementado; vendas públicas bloqueadas até autorização expressa.
    'product_sales_operational' => (bool) env('TELEGRAM_PRODUCT_SALES_OPERATIONAL', false),
    // Deep link /start: validade desde a criação do grant (0 = sem expiração por TTL).
    'identity_link_ttl_hours' => (int) env('TELEGRAM_IDENTITY_LINK_TTL_HOURS', 72),
    'access_starts_notice' => 'O prazo de acesso começa a contar após a confirmação do pagamento, independentemente da data de entrada no grupo ou canal.',
];
