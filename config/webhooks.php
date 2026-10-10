<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política quando a reconfirmação via API do gateway falha (null) em
    | eventos destrutivos: cancelamento, rejeição e reembolso.
    |--------------------------------------------------------------------------
    |
    | accept = comportamento legado: aplica a mudança local mesmo sem conseguir
    |         consultar o gateway (ex.: API indisponível).
    | reject = fail-closed: não altera o pedido; o job pode retentar (útil quando
    |         o webhook de entrada não é confiável, ex. Mercado Pago sem assinatura).
    |
    */
    'reconfirm_fail_policy' => [
        'default' => env('WEBHOOK_RECONFIRM_FAIL_POLICY', 'reject'),

        'mercadopago' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_MERCADOPAGO', 'reject'),
        'spacepag' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_SPACEPAG', 'reject'),
        'pushinpay' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_PUSHINPAY', 'reject'),
        'asaas' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_ASAAS', 'reject'),
        'woovi' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_WOOVI', 'reject'),
        'xflow' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_XFLOW', 'reject'),
        'bspay' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_BSPAY', 'reject'),
        'okto' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_OKTO', 'reject'),
        'efi' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_EFI'),
        'stripe' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_STRIPE'),
        'onlyup' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_ONLYUP', 'reject'),
    ],
];
