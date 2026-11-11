import {
    BookOpen,
    FileCode,
    Key,
    Layers,
    QrCode,
    Shield,
    Wallet,
    Webhook,
} from 'lucide-vue-next';

export const fieldColumns = [
    { key: 'field', label: 'Campo' },
    { key: 'type', label: 'Tipo' },
    { key: 'required', label: 'Obrigatório' },
    { key: 'desc', label: 'Descrição' },
];

export const customerFields = [
    { field: 'customer', type: 'objeto', required: 'Sim', desc: 'Dados do cliente' },
    { field: 'customer.email', type: 'string', required: 'Sim', desc: 'E-mail' },
    { field: 'customer.name', type: 'string', required: 'Não', desc: 'Nome (se vazio, usa o e-mail)' },
    { field: 'customer.cpf', type: 'string', required: 'Não', desc: 'CPF (somente dígitos ou formatado)' },
    { field: 'customer.phone', type: 'string', required: 'Não', desc: 'Telefone' },
];

export const pixRequestFields = [
    ...customerFields,
    {
        field: 'amount',
        type: 'number',
        required: 'Sim*',
        desc: 'Valor em reais (ex.: 97.90). Obrigatório no body; ignorado se product_id definir o preço. Deve ser ≥ ticket mínimo API PIX da instalação',
    },
    { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' },
    {
        field: 'product_id',
        type: 'string (UUID)',
        required: 'Não',
        desc: 'Produto do catálogo do vendedor. Se informado, o valor vem do produto/oferta/plano',
    },
    { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'Oferta vinculada ao product_id' },
    { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'Plano de assinatura do product_id' },
    {
        field: 'metadata',
        type: 'objeto',
        required: 'Não',
        desc: 'Dados livres (ex.: external_id) — devolvidos no webhook e em GET /payments',
    },
    {
        field: 'partner_checkout_url',
        type: 'string (URL HTTPS)',
        required: 'Não',
        desc: 'URL da página de checkout no site do parceiro onde o comprador iniciou o pagamento. Recomendado em produção (compliance)',
    },
    {
        field: 'idempotency_key',
        type: 'string',
        required: 'Não',
        desc: 'Chave única (máx. 128). Também aceito no header Idempotency-Key',
    },
];

export const pixResponseFields = [
    { field: 'order_id', type: 'integer', required: 'Sim', desc: 'ID do pedido — use em GET, cancel e refund' },
    { field: 'transaction_id', type: 'string', required: 'Não', desc: 'Referência da transação no processador' },
    {
        field: 'qrcode',
        type: 'string',
        required: 'Não',
        desc: 'Imagem QR em base64 ou data URI',
    },
    { field: 'copy_paste', type: 'string', required: 'Não', desc: 'Código PIX copia e cola (EMV)' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'pending na criação síncrona; processing na criação assíncrona' },
];

export const pixAsyncResponseFields = [
    { field: 'order_id', type: 'integer', required: 'Sim', desc: 'ID do pedido — consulte GET /payments/{order_id} ou aguarde webhook' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'processing — PIX sendo gerado em background' },
];

export const paymentStatusResponseFields = [
    { field: 'order_id', type: 'integer', required: 'Sim', desc: 'ID do pedido' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'pending, completed, cancelled, refunded, disputed…' },
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor do pedido' },
    { field: 'email', type: 'string', required: 'Sim', desc: 'E-mail do comprador' },
    { field: 'transaction_id', type: 'string', required: 'Não', desc: 'Referência da transação (quando disponível)' },
    { field: 'copy_paste', type: 'string', required: 'Não', desc: 'Código PIX (quando status pending e PIX já gerado)' },
    { field: 'qrcode', type: 'string', required: 'Não', desc: 'QR Code (quando disponível)' },
    { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Metadados enviados na criação' },
    { field: 'created_at', type: 'string (ISO 8601)', required: 'Não', desc: 'Data de criação' },
    { field: 'updated_at', type: 'string (ISO 8601)', required: 'Não', desc: 'Última atualização' },
];

export const orderStatusValues = [
    { status: 'pending', quando: 'PIX gerado; aguardando pagamento' },
    { status: 'processing', quando: 'Pedido criado; PIX ainda sendo gerado (modo assíncrono)' },
    { status: 'completed', quando: 'Pagamento confirmado' },
    { status: 'cancelled', quando: 'Cancelado via API ou expiração' },
    { status: 'refunded', quando: 'Estorno concluído via POST /pix/{id}/refund' },
    { status: 'disputed', quando: 'Disputa em andamento' },
];

export const integrationPrerequisites = [
    'Conta de vendedor ativa e aprovada',
    'API PIX habilitada para a conta',
    'Integração criada em /aplicacoes-api com status ativo',
    'Par Public key + Secret key copiado (secret só no backend)',
    'Webhook provisionado via PUT /api/v1/webhook (recomendado) ou configurado no painel',
    'IPs permitidos vazio (qualquer IP) ou seu servidor na lista',
];

export const integrationSteps = [
    'Obter Public key e Secret key em /aplicacoes-api',
    'Provisionar webhook com PUT /api/v1/webhook (parceiros) ou configurar no painel',
    'Chamar POST /api/v1/payments/pix com customer.email e amount (ou product_id)',
    'Exibir copy_paste e/ou qrcode ao cliente final',
    'Confirmar pagamento via webhook order.completed ou polling GET /payments/{order_id}',
];

export const partnerIntegrationSteps = [
    'Vendedor habilita API PIX e copia Public key + Secret key em /aplicacoes-api',
    'Vendedor informa as credenciais na sua plataforma',
    'Seu backend chama PUT /api/v1/webhook com URL HTTPS de recebimento',
    'Armazene o webhook_secret retornado (só na 1ª vez ou com rotate_secret: true)',
    'Use POST /api/v1/payments/pix e valide webhooks com HMAC-SHA256',
];

export const webhookProvisionFields = [
    { field: 'webhook_url', type: 'string (URL HTTPS)', required: 'Sim', desc: 'Endpoint de recebimento na sua plataforma' },
    { field: 'webhook_enabled', type: 'boolean', required: 'Não', desc: 'Default true — pausar entregas sem remover URL' },
    { field: 'rotate_secret', type: 'boolean', required: 'Não', desc: 'true gera e retorna novo webhook_secret (reconexão)' },
];

export const webhookProvisionExample = `{
  "webhook_url": "https://sua-plataforma.com/webhooks/getfy/merchant-123",
  "webhook_enabled": true
}`;

export const webhookProvisionResponseExample = `{
  "webhook_url": "https://sua-plataforma.com/webhooks/getfy/merchant-123",
  "webhook_enabled": true,
  "webhook_events": null,
  "events_mode": "all",
  "webhook_secret": "abc123secretonlyshownonce",
  "has_secret": true
}`;

export const webhookEvents = [
    { event: 'order.pending', desc: 'Pedido criado; aguardando pagamento' },
    { event: 'pix.generated', desc: 'QR Code e copia e cola disponíveis (útil no modo assíncrono)' },
    { event: 'order.completed', desc: 'Pagamento confirmado — libere o produto/serviço' },
    { event: 'order.refunded', desc: 'Estorno concluído' },
    { event: 'order.cancelled', desc: 'Pedido cancelado' },
    { event: 'order.expired', desc: 'PIX expirou sem pagamento' },
];

export const withdrawalWebhookEvents = [
    { event: 'withdrawal.created', desc: 'Saque solicitado; valor reservado' },
    { event: 'withdrawal.processing', desc: 'Saque enviado para processamento' },
    { event: 'withdrawal.completed', desc: 'Saque concluído — PIX enviado' },
    { event: 'withdrawal.failed', desc: 'Falha no saque; saldo restaurado' },
    { event: 'withdrawal.rejected', desc: 'Saque rejeitado; saldo restaurado' },
    { event: 'withdrawal.cancelled', desc: 'Saque cancelado antes da conclusão' },
];

export const apiKeyScopes = [
    { scope: 'payments:read', desc: 'Consultar pedidos e status' },
    { scope: 'payments:write', desc: 'Criar pagamentos (PIX, cartão, boleto)' },
    { scope: 'payments:refund', desc: 'Cancelar e estornar PIX' },
    { scope: 'withdrawals:read', desc: 'Consultar saldo e saques' },
    { scope: 'withdrawals:write', desc: 'Solicitar saques e configurar chave PIX de destino' },
    { scope: 'webhooks:read', desc: 'Consultar configuração do webhook (GET /webhook)' },
    { scope: 'webhooks:write', desc: 'Provisionar webhook via API (PUT /webhook)' },
];

export const partnerRecommendedScopes = 'payments:read, payments:write, payments:refund, webhooks:write';

export const payoutDestinationFields = [
    { field: 'pix_key', type: 'string', required: 'Sim', desc: 'Chave PIX a validar (não altera a chave master do infoprodutor)' },
    {
        field: 'pix_key_type',
        type: 'string',
        required: 'Sim',
        desc: 'cpf, cnpj, email, phone, evp ou random (alias de evp — chave aleatória)',
    },
    {
        field: 'key_owner_document',
        type: 'string',
        required: 'Condicional',
        desc: 'CPF ou CNPJ do titular (somente dígitos). Obrigatório para email, phone e evp/random. Para cpf/cnpj pode omitir — usamos os dígitos da própria chave',
    },
];

export const payoutDestinationKeyTypeRules = [
    { pix_key_type: 'email, phone, evp, random', key_owner_document: 'Obrigatório — CPF ou CNPJ do titular real da chave' },
    { pix_key_type: 'cpf, cnpj', key_owner_document: 'Opcional — se omitido, usa os dígitos da própria chave PIX' },
];

export const withdrawalRequestFields = [
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor bruto do saque em reais' },
    { field: 'pix_key', type: 'string', required: 'Sim', desc: 'Chave PIX de recebimento deste saque (gravada na transação; não altera a chave master)' },
    {
        field: 'pix_key_type',
        type: 'string',
        required: 'Sim',
        desc: 'cpf, cnpj, email, phone, evp ou random',
    },
    {
        field: 'key_owner_document',
        type: 'string',
        required: 'Condicional',
        desc: 'CPF/CNPJ do titular — obrigatório para email, phone e evp/random',
    },
    { field: 'bucket', type: 'string', required: 'Não', desc: 'pix (padrão), card ou boleto — carteira de origem' },
    { field: 'notes', type: 'string', required: 'Não', desc: 'Observação opcional' },
    { field: 'idempotency_key', type: 'string', required: 'Não*', desc: 'Ou header Idempotency-Key. Obrigatório em chaves novas com idempotência reforçada' },
];

export const withdrawalResponseFields = [
    { field: 'withdrawal_id', type: 'integer', required: 'Sim', desc: 'ID do saque' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'pending, processing, paid, failed, rejected' },
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor bruto solicitado' },
    { field: 'fee_amount', type: 'number', required: 'Sim', desc: 'Taxa de saque' },
    { field: 'net_amount', type: 'number', required: 'Sim', desc: 'Valor líquido enviado via PIX' },
    { field: 'bucket', type: 'string', required: 'Sim', desc: 'Carteira de origem' },
    { field: 'pix_key_type', type: 'string', required: 'Não', desc: 'Tipo da chave PIX gravada neste saque' },
    { field: 'pix_key_masked', type: 'string', required: 'Não', desc: 'Chave PIX mascarada gravada neste saque' },
];

export const balanceResponseFields = [
    { field: 'available_pix', type: 'number', required: 'Sim', desc: 'Saldo disponível para saque (PIX)' },
    { field: 'available_card', type: 'number', required: 'Sim', desc: 'Saldo disponível (cartão)' },
    { field: 'available_boleto', type: 'number', required: 'Sim', desc: 'Saldo disponível (boleto)' },
    { field: 'available_balance', type: 'number', required: 'Sim', desc: 'Total disponível' },
    { field: 'pending_balance', type: 'number', required: 'Sim', desc: 'Total em liquidação (ainda não disponível)' },
];

export const webhookPayloadExample = `{
  "event": "order.completed",
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "order_id": 456,
  "amount": 97.90,
  "amount_net": 92.01,
  "status": "completed",
  "transaction_id": "tx-abc123",
  "payment_method": "pix",
  "paid_at": "2026-06-13T14:05:12.000000Z",
  "email": "cliente@exemplo.com",
  "metadata": { "external_id": "ped-1001", "source": "api" },
  "customer": { "name": "Cliente", "email": "cliente@exemplo.com", "document": "52998224725" },
  "created_at": "2026-06-13T14:00:00.000000Z",
  "updated_at": "2026-06-13T14:05:12.000000Z"
}`;

export const withdrawalWebhookExample = `{
  "event": "withdrawal.completed",
  "event_id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "withdrawal_id": 12,
  "status": "paid",
  "amount": 500.00,
  "fee_amount": 5.00,
  "net_amount": 495.00,
  "bucket": "pix",
  "created_at": "2026-06-13T10:00:00.000000Z",
  "updated_at": "2026-06-13T10:05:00.000000Z"
}`;

export const webhookVerifyPhpExample = `// Body bruto (antes de json_decode)
$raw = file_get_contents('php://input');
$secret = 'seu_webhook_secret';
$expected = hash_hmac('sha256', $raw, $secret);
$received = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$eventId = $_SERVER['HTTP_X_WEBHOOK_ID'] ?? '';
if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}`;

export const webhookVerifyNodeExample = `import crypto from 'crypto';

const expected = crypto
  .createHmac('sha256', process.env.WEBHOOK_SECRET)
  .update(rawBody) // Buffer/string do body bruto
  .digest('hex');

if (req.headers['x-webhook-signature'] !== expected) {
  return res.status(401).end();
}`;

export const paymentConfirmationLayers = [
    {
        layer: '1. Webhook',
        where: 'Seu backend',
        role: 'Canal primário — order.completed com HMAC; responda 2xx rápido; idempotente por event_id',
    },
    {
        layer: '2. Reconciliação',
        where: 'Job/cron no servidor',
        role: 'Fallback obrigatório — GET /payments a cada 60–120 s por 6–12 h; mesmo pipeline do webhook',
    },
    {
        layer: '3. Polling na UI',
        where: 'Browser (checkout)',
        role: 'Apenas UX — não libere produto só com polling do frontend',
    },
];

export const paymentConfirmationChecklist = [
    'Webhook provisionado via PUT /api/v1/webhook com URL HTTPS',
    'webhook_secret armazenado com segurança (env/secret manager)',
    'Validação HMAC em todo POST recebido',
    'Deduplicação por event_id e/ou order_id já processado',
    'Job de reconciliação em produção (intervalo 60–120 s)',
    'Mesmo handler idempotente para webhook e reconciliação',
    'Secret key apenas no servidor — nunca no browser',
    'Idempotency-Key em POST /payments/pix',
];

export const reconciliationPhpExample = `// Scheduler: $schedule->job(new ReconcilePendingPixPaymentsJob)->everyMinute();

public function handle(GetfyApiClient $getfy): void
{
    $cutoff = now()->subHours(12);

    $pendingOrders = Order::query()
        ->where('payment_status', 'pending')
        ->whereNotNull('getfy_order_id')
        ->where('created_at', '>=', $cutoff)
        ->get();

    foreach ($pendingOrders as $order) {
        $payment = $getfy->getPayment($order->getfy_order_id);
        if (($payment['status'] ?? '') === 'completed') {
            app(MarkOrderPaidAction::class)->handle($order, source: 'reconciliation');
        }
    }
}`;

export const reconciliationNodeExample = `import cron from 'node-cron';

cron.schedule('*/2 * * * *', async () => {
  const pending = await db.orders.findMany({
    where: { paymentStatus: 'pending', getfyOrderId: { not: null } },
  });

  for (const order of pending) {
    const res = await fetch(\`\${GETFY_BASE}/api/v1/payments/\${order.getfyOrderId}\`, {
      headers: {
        'X-Public-Key': process.env.GETFY_PUBLIC_KEY,
        'X-Secret-Key': process.env.GETFY_SECRET_KEY,
      },
    });
    const payment = await res.json();
    if (payment.status === 'completed') {
      await markOrderPaid(order.id, { source: 'reconciliation' });
    }
  }
});`;

export const idempotentHandlerExample = `public function markOrderPaid(Order $order, string $source): void
{
    if ($order->payment_status === 'paid') {
        return; // já processado (webhook ou reconciliação anterior)
    }
    $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
    // liberar produto, enviar e-mail, etc.
}`;

export const commonApiErrors = [
    {
        code: '401',
        message: 'Missing or invalid API key.',
        cause: 'Headers X-Public-Key / X-Secret-Key ausentes ou incompletos',
        action: 'Envie os dois headers em toda requisição',
    },
    {
        code: '401',
        message: 'Invalid API key.',
        cause: 'Par de chaves incorreto ou secret errado',
        action: 'Copie novamente em /aplicacoes-api → Revelar secret',
    },
    {
        code: '403',
        message: 'API application is disabled.',
        cause: 'Aplicação desativada no painel',
        action: 'Ative a integração em /aplicacoes-api',
    },
    {
        code: '403',
        message: 'IP not allowed.',
        cause: 'Seu IP não está na lista de IPs permitidos',
        action: 'Adicione o IP do servidor ou deixe a lista vazia',
    },
    {
        code: '403',
        message: 'Insufficient API key permissions.',
        cause: 'Chave sem o escopo necessário para o endpoint',
        action: 'Use chave principal ou crie chave com o scope correto no painel',
    },
    {
        code: '403',
        message: 'API PIX disabled for this tenant.',
        cause: 'API PIX desligada para a conta',
        action: 'Habilite API PIX nas configurações da conta',
    },
    {
        code: '404',
        message: 'Pedido não encontrado.',
        cause: 'order_id inexistente ou pertence a outra aplicação',
        action: 'Use o order_id retornado na criação com as mesmas chaves',
    },
    {
        code: '422',
        message: '(validação)',
        cause: 'Campo obrigatório ausente, amount abaixo do ticket mínimo API PIX, produto indisponível',
        action: 'Corrija o body; erros Laravel vêm em errors.{campo}',
    },
    {
        code: '422',
        message: 'Valor mínimo para cobrança via API PIX é R$ X,XX.',
        cause: 'amount ou preço efetivo (com product_id) abaixo do limite definido pelo admin',
        action: 'Aumente o valor ou consulte o administrador da plataforma (Financeiro → Limites)',
    },
    {
        code: '422',
        message: 'Não foi possível gerar o PIX.',
        cause: 'Conta indisponível para receber ou dados inválidos',
        action: 'Verifique amount, customer e status da conta',
    },
    {
        code: '429',
        message: 'Too Many Attempts.',
        cause: 'Rate limit (throttle:api)',
        action: 'Aguarde e use idempotency_key em retentativas',
    },
];

export const errorCodes = [
    { code: '401', meaning: 'Credenciais ausentes ou inválidas' },
    { code: '403', meaning: 'Integração inativa, IP bloqueado ou API PIX desabilitada' },
    { code: '404', meaning: 'Pedido não encontrado (outra app ou ID inválido)' },
    { code: '422', meaning: 'Validação ou falha ao gerar PIX' },
    { code: '429', meaning: 'Rate limit' },
    { code: '500', meaning: 'Erro interno' },
];

export const sessionFields = [
    ...customerFields,
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor (ex.: 97.90)' },
    { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' },
    { field: 'product_id', type: 'string (UUID)', required: 'Não', desc: 'ID do produto no catálogo' },
    { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'ID da oferta' },
    { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'ID do plano de assinatura' },
    { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Dados livres para webhook' },
    { field: 'return_url', type: 'string', required: 'Não', desc: 'URL após concluir o pagamento' },
    { field: 'expires_in', type: 'integer', required: 'Não', desc: 'Minutos até expirar (5–1440; default: 30)' },
];

export const endpointSummary = [
    { method: 'POST', endpoint: '/api/v1/payments/pix', desc: 'Criar cobrança PIX' },
    { method: 'GET', endpoint: '/api/v1/payments', desc: 'Listar pedidos' },
    { method: 'GET', endpoint: '/api/v1/payments/{order_id}', desc: 'Consultar status do pedido' },
    { method: 'POST', endpoint: '/api/v1/pix/{order_id}/cancel', desc: 'Cancelar PIX pendente' },
    { method: 'POST', endpoint: '/api/v1/pix/{order_id}/refund', desc: 'Estornar PIX pago' },
    { method: 'GET', endpoint: '/api/v1/balance', desc: 'Consultar saldo disponível' },
    { method: 'POST', endpoint: '/api/v1/withdrawals', desc: 'Solicitar saque' },
    { method: 'GET', endpoint: '/api/v1/withdrawals', desc: 'Listar saques' },
    { method: 'GET', endpoint: '/api/v1/withdrawals/{id}', desc: 'Consultar saque' },
    { method: 'PUT', endpoint: '/api/v1/payout-destination', desc: 'Validar chave PIX (não altera a chave master do infoprodutor)' },
    { method: 'GET', endpoint: '/api/v1/webhook', desc: 'Consultar configuração do webhook' },
    { method: 'PUT', endpoint: '/api/v1/webhook', desc: 'Provisionar webhook (todos os eventos; retorna secret na 1ª vez)' },
    { method: 'POST', endpoint: '/api/v1/webhook/rotate-secret', desc: 'Rotacionar secret do webhook' },
];

export const whenToUse = [
    { cenario: 'PIX na sua própria tela', sugestao: 'POST /payments/pix' },
    { cenario: 'Alto volume / resposta rápida', sugestao: 'POST /payments/pix com header X-Async: true' },
    { cenario: 'Sacar saldo para conta PIX', sugestao: 'POST /withdrawals com pix_key + pix_key_type (+ key_owner_document quando exigido)' },
    { cenario: 'Valor avulso sem produto', sugestao: 'Envie amount; omita product_id' },
    { cenario: 'Cobrar preço do catálogo', sugestao: 'Envie product_id (amount no body é ignorado)' },
    { cenario: 'Compliance / auditoria', sugestao: 'Envie partner_checkout_url com a URL HTTPS do seu checkout' },
    { cenario: 'Onboarding de parceiro', sugestao: 'PUT /webhook ao conectar credenciais do vendedor' },
];

/** @type {Array<{ id: string, label: string, group: 'pix'|'saques', method: string, path: string, description: string, defaultBody: object|null, defaultHeaders?: Record<string,string>, pathFields?: Array<{ key: string, label: string, placeholder: string }> }>} */
export const apiPlaygroundOperations = [
    {
        id: 'post-payments-pix',
        label: 'Criar cobrança PIX',
        group: 'pix',
        method: 'POST',
        path: '/payments/pix',
        description: 'Cria pedido + QR Code e copia e cola.',
        defaultBody: {
            customer: {
                email: 'cliente@exemplo.com',
                name: 'Cliente Teste',
                cpf: '52998224725',
                phone: '11999999999',
            },
            amount: 5.0,
            currency: 'BRL',
            metadata: { external_id: 'teste-playground-001' },
            partner_checkout_url: 'https://loja.exemplo.com/checkout/teste',
        },
        defaultHeaders: {
            'Idempotency-Key': 'playground-pix-001',
        },
    },
    {
        id: 'get-payments-order-id',
        label: 'Consultar pedido',
        group: 'pix',
        method: 'GET',
        path: '/payments/{order_id}',
        description: 'Status e dados PIX do pedido.',
        defaultBody: null,
        pathFields: [{ key: 'order_id', label: 'Order ID', placeholder: '456' }],
    },
    {
        id: 'get-payments-list',
        label: 'Listar pedidos',
        group: 'pix',
        method: 'GET',
        path: '/payments',
        description: 'Lista pedidos da integração. Query: status, per_page.',
        defaultBody: null,
    },
    {
        id: 'post-pix-cancel',
        label: 'Cancelar PIX pendente',
        group: 'pix',
        method: 'POST',
        path: '/pix/{order_id}/cancel',
        description: 'Cancela cobrança PIX ainda não paga.',
        defaultBody: null,
        pathFields: [{ key: 'order_id', label: 'Order ID', placeholder: '456' }],
    },
    {
        id: 'post-pix-refund',
        label: 'Reembolsar PIX',
        group: 'pix',
        method: 'POST',
        path: '/pix/{order_id}/refund',
        description: 'Estorna PIX pago ou em MED.',
        defaultBody: null,
        pathFields: [{ key: 'order_id', label: 'Order ID', placeholder: '456' }],
    },
    {
        id: 'get-balance',
        label: 'Consultar saldo',
        group: 'saques',
        method: 'GET',
        path: '/balance',
        description: 'Saldo disponível e pendente por bucket.',
        defaultBody: null,
    },
    {
        id: 'put-payout-destination',
        label: 'Configurar destino PIX',
        group: 'saques',
        method: 'PUT',
        path: '/payout-destination',
        description: 'Valida chave PIX de destino (não altera a chave master do infoprodutor).',
        defaultBody: {
            pix_key: 'cliente@exemplo.com',
            pix_key_type: 'email',
            key_owner_document: '52998224725',
        },
    },
    {
        id: 'post-withdrawals',
        label: 'Solicitar saque',
        group: 'saques',
        method: 'POST',
        path: '/withdrawals',
        description: 'Solicita saque do saldo disponível para a chave informada neste request.',
        defaultBody: {
            amount: 10.0,
            bucket: 'pix',
            notes: 'Saque teste playground',
            pix_key: 'cliente@exemplo.com',
            pix_key_type: 'email',
            key_owner_document: '52998224725',
        },
        defaultHeaders: {
            'Idempotency-Key': 'playground-saque-001',
        },
    },
    {
        id: 'get-withdrawals-list',
        label: 'Listar saques',
        group: 'saques',
        method: 'GET',
        path: '/withdrawals',
        description: 'Lista saques. Query: status, per_page.',
        defaultBody: null,
    },
    {
        id: 'get-withdrawals-id',
        label: 'Consultar saque',
        group: 'saques',
        method: 'GET',
        path: '/withdrawals/{id}',
        description: 'Detalhe de um saque.',
        defaultBody: null,
        pathFields: [{ key: 'id', label: 'Withdrawal ID', placeholder: '12' }],
    },
];

export const apiPlaygroundGroups = [
    { id: 'pix', label: 'PIX' },
    { id: 'saques', label: 'Saques' },
];

export const navSections = [
    {
        title: 'Introdução',
        icon: BookOpen,
        items: [
            { title: 'Início rápido', id: 'inicio-rapido' },
            { title: 'Testar API', href: '/docs/api-pagamentos/testar' },
            { title: 'Integração em 5 passos', id: 'fluxo-pix' },
            { title: 'Pré-requisitos', id: 'pre-requisitos' },
            { title: 'Integração para parceiros', id: 'para-parceiros' },
            { title: 'Visão geral', id: 'visao-geral' },
            { title: 'Quando usar', id: 'quando-usar' },
        ],
    },
    {
        title: 'Autenticação',
        icon: Key,
        items: [
            { title: 'Envio das chaves', id: 'envio-api-key' },
            { title: 'Obtenção das chaves', id: 'obtencao-api-key' },
            { title: 'Chaves adicionais e permissões', id: 'api-keys-scopes' },
            { title: 'Segurança', id: 'seguranca' },
        ],
    },
    {
        title: 'Conta e integração',
        icon: Layers,
        items: [
            { title: 'Chaves e configuração', id: 'integracao-conta' },
            { title: 'Provisionar webhook (API)', id: 'webhook-provision-api' },
            { title: 'Webhooks (configuração)', id: 'processadora-webhooks' },
        ],
    },
    {
        title: 'PIX',
        icon: QrCode,
        items: [
            { title: 'POST /payments/pix', id: 'post-payments-pix' },
            { title: 'Modo assíncrono (X-Async)', id: 'pix-async' },
            { title: 'Campos do request', id: 'post-payments-pix-campos' },
            { title: 'Exemplo curl', id: 'exemplo-curl' },
            { title: 'GET /payments (listar)', id: 'get-payments-list' },
            { title: 'GET /payments/{order_id}', id: 'get-payments-order-id' },
            { title: 'Status do pedido', id: 'status-pedido' },
            { title: 'POST /pix/cancel e refund', id: 'post-pix-cancel' },
            { title: 'Idempotência', id: 'idempotencia-pix' },
        ],
    },
    {
        title: 'Saques',
        icon: Wallet,
        items: [
            { title: 'GET /balance', id: 'get-balance' },
            { title: 'PUT /payout-destination', id: 'put-payout-destination' },
            { title: 'POST /withdrawals', id: 'post-withdrawals' },
            { title: 'GET /withdrawals', id: 'get-withdrawals' },
            { title: 'Webhooks de saque', id: 'webhooks-saque' },
        ],
    },
    {
        title: 'Segurança',
        icon: Shield,
        items: [
            { title: 'Confirmação e fallbacks', id: 'confirmacao-pagamento-fallbacks' },
        ],
    },
    {
        title: 'Webhooks',
        icon: Webhook,
        items: [
            { title: 'Eventos de pagamento', id: 'webhooks-eventos' },
            { title: 'Formato do payload', id: 'webhooks-formato' },
            { title: 'Assinatura e entrega', id: 'webhooks-assinatura' },
            { title: 'Boas práticas', id: 'webhooks-boas-praticas' },
        ],
    },
    {
        title: 'Referência',
        icon: FileCode,
        items: [
            { title: 'Erros comuns', id: 'erros-comuns' },
            { title: 'Códigos de erro', id: 'codigos-de-erro' },
            { title: 'Boas práticas gerais', id: 'boas-praticas' },
            { title: 'Resumo de endpoints', id: 'resumo-endpoints' },
        ],
    },
];
