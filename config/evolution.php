<?php

return [

    'queue' => env('EVOLUTION_QUEUE', 'uazapi'),

    'http_timeout' => (int) env('EVOLUTION_HTTP_TIMEOUT', 20),

    'max_message_length' => (int) env('EVOLUTION_MAX_MESSAGE_LENGTH', 1000),

    'docs_url' => 'https://docs.evolutionfoundation.com.br/evolution-api/connect-instance',

    'signup_url' => env('EVOLUTION_SIGNUP_URL', 'https://docs.evolutionfoundation.com.br/evolution-api/installation'),

    'retry' => [
        'tries' => (int) env('EVOLUTION_TRIES', 3),
        'backoff' => [30, 90],
        'timeout' => (int) env('EVOLUTION_JOB_TIMEOUT', 60),
    ],

    'defaults' => [
        'messages' => [
            'cart_recovery' => 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}',
            'pix_generated' => '{nome}, seu PIX de {valor} para {produto} está pronto. Pague para concluir: {link}',
            'pix_reminder' => '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}',
            'order_paid' => '{nome}, seu PIX de {valor} para {produto} foi confirmado. Obrigado pela compra! Acesso: {link_acesso}',
        ],
    ],

    'inbound' => [
        'opt_out_keywords' => ['parar', 'stop', 'nao quero', 'não quero', 'sair', 'cancelar'],
        'paid_keywords' => ['ja paguei', 'já paguei', 'paguei', 'ja pagou', 'já pagou'],
        'opt_out_reply' => 'Ok, não enviaremos mais mensagens de recuperação neste número.',
        'paid_reply' => 'Se o pagamento confirmar, o acesso é liberado automaticamente.',
    ],

];
