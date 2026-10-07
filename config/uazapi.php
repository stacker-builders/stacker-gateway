<?php

return [

    'queue' => env('UAZAPI_QUEUE', 'uazapi'),

    'http_timeout' => (int) env('UAZAPI_HTTP_TIMEOUT', 20),

    'max_message_length' => (int) env('UAZAPI_MAX_MESSAGE_LENGTH', 1000),

    'docs_url' => 'https://docs.uazapi.com/',

    'signup_url' => env('UAZAPI_SIGNUP_URL', 'https://uazapi.dev/'),

    'retry' => [
        'tries' => (int) env('UAZAPI_TRIES', 3),
        'backoff' => [30, 90],
        'timeout' => (int) env('UAZAPI_JOB_TIMEOUT', 60),
    ],

    'defaults' => [
        'messages' => [
            'cart_recovery' => 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}',
            'pix_generated' => '{nome}, seu PIX de {valor} para {produto} está pronto. Pague para concluir: {link}',
            'pix_reminder' => '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}',
        ],
    ],

    'inbound' => [
        'opt_out_keywords' => ['parar', 'stop', 'nao quero', 'não quero', 'sair', 'cancelar'],
        'paid_keywords' => ['ja paguei', 'já paguei', 'paguei', 'ja pagou', 'já pagou'],
        'opt_out_reply' => 'Ok, não enviaremos mais mensagens de recuperação neste número.',
        'paid_reply' => 'Se o pagamento confirmar, o acesso é liberado automaticamente.',
    ],

    'labels' => [
        'abandoned' => ['name' => '{platform} · abandonou', 'color' => 16],
        'hot' => ['name' => '{platform} · quente', 'color' => 2],
        'paid' => ['name' => '{platform} · pagou', 'color' => 15],
    ],

    'campaign' => [
        'max_recipients' => (int) env('UAZAPI_CAMPAIGN_MAX', 200),
        'delay_seconds' => (int) env('UAZAPI_CAMPAIGN_DELAY', 8),
        'abandoned_days' => 7,
        'buyers_days' => 30,
        'defaults' => [
            'abandoned_cart' => 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}',
            'pending_pix' => '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}',
            'buyers' => 'Oi {nome}! Obrigado pela compra de {produto}. Qualquer dúvida, estamos por aqui.',
        ],
    ],

];
