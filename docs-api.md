# API de Pagamentos e Saques

Integre **PIX**, consulte pagamentos, solicite **saques** e receba **webhooks** em tempo real. Destinada a marketplaces, ERPs, SaaS e parceiros que precisam cobrar e movimentar saldo via REST.

**REST** · **JSON** · **Base URL:** `https://seudominio.com/api/v1` (substitua pelo domínio da instalação).

**Autenticação (recomendado):** headers `X-Public-Key` e `X-Secret-Key` (par obtido no painel em **Chaves da API** → `/aplicacoes-api`). Legado: `Authorization: Bearer …` ou `X-API-Key`.

- Documentação interativa: `/docs/api-pagamentos`
- **Playground de testes (PIX e saques):** `/docs/api-pagamentos/testar`
- Hub para IAs (LLMs): `/docs/api-pagamentos/ia`
- Download pacote Markdown para IA: `/docs/api-pagamentos/llm/full.md`

---

## Pré-requisitos

- Conta de vendedor ativa e aprovada
- **API PIX** habilitada para a conta
- Integração criada em `/aplicacoes-api` com status **ativo**
- Par Public key + Secret key copiado (secret apenas no backend)
- Webhook provisionado via `PUT /api/v1/webhook` (recomendado para parceiros) ou configurado manualmente no painel
- IPs permitidos vazio ou IP do seu servidor na lista

---

## Integração para parceiros (recomendado)

Fluxo automático quando o vendedor conecta as credenciais na sua plataforma:

1. Vendedor habilita **API PIX** e copia Public key + Secret key em `/aplicacoes-api`
2. Vendedor informa as credenciais na sua plataforma
3. **Seu backend chama `PUT /api/v1/webhook`** com a URL HTTPS de recebimento
4. Guarde o `webhook_secret` retornado (exibido apenas na primeira configuração ou com `rotate_secret: true`)
5. Use `POST /api/v1/payments/pix` e valide webhooks com HMAC

### Provisionar webhook via API

```bash
curl -X PUT 'https://seudominio.com/api/v1/webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-Public-Key: gpk_sua_public_key' \
  -H 'X-Secret-Key: gsk_sua_secret_key' \
  -d '{
    "webhook_url": "https://sua-plataforma.com/webhooks/getfy/{merchant_id}"
  }'
```

**Resposta 200 (primeira configuração):**

```json
{
  "webhook_url": "https://sua-plataforma.com/webhooks/getfy/123",
  "webhook_enabled": true,
  "webhook_events": null,
  "events_mode": "all",
  "webhook_secret": "abc123...",
  "has_secret": true
}
```

Todos os eventos de pagamento e saque são habilitados automaticamente (`webhook_events: null` = todos).

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `webhook_url` | Sim | URL HTTPS de recebimento |
| `webhook_enabled` | Não | Default `true` |
| `rotate_secret` | Não | `true` gera e retorna novo secret (reconexão) |

**Reconexão:** chamadas repetidas com a mesma URL **não** devolvem o secret. Use `rotate_secret: true` ou `POST /api/v1/webhook/rotate-secret`.

**Permissão necessária:** `webhooks:write` (chave principal legada tem acesso total).

Configuração manual no painel (`/aplicacoes-api` → Webhooks) permanece disponível como alternativa.

---

## Integração em 5 passos (PIX)

1. Copie Public key e Secret key em `/aplicacoes-api`
2. Provisione o webhook com `PUT /api/v1/webhook` (parceiros) ou configure no painel
3. `POST /api/v1/payments/pix` com `customer.email` e valor
4. Exiba `copy_paste` e/ou `qrcode` ao cliente
5. Confirme via webhook `order.completed` ou `GET /api/v1/payments/{order_id}`

---

## Autenticação e chaves

### Chave principal

A chave principal da integração tem acesso total (pagamentos, saques, consultas). Integrações existentes continuam funcionando sem alteração.

### Chaves adicionais e permissões

No painel é possível criar **chaves adicionais** com permissões limitadas (least-privilege):

| Permissão | Descrição |
|-----------|-----------|
| `payments:read` | Consultar pedidos e status |
| `payments:write` | Criar pagamentos (PIX, cartão, boleto) |
| `payments:refund` | Cancelar e estornar PIX |
| `withdrawals:read` | Consultar saldo e saques |
| `withdrawals:write` | Solicitar saques e configurar chave PIX de destino |
| `webhooks:read` | Consultar configuração do webhook |
| `webhooks:write` | Provisionar webhook via API (`PUT /api/v1/webhook`) |

**Chave de parceiro recomendada:** `payments:read`, `payments:write`, `payments:refund`, `webhooks:write` (+ scopes de saque se necessário).

---

## Regra importante: amount vs product_id

| Cenário | Comportamento |
|---------|---------------|
| **Sem** `product_id` | Valor cobrado = `amount` enviado no body |
| **Com** `product_id` | Valor = preço do produto, oferta ou plano — **`amount` do body é ignorado** |

---

## PIX — criar cobrança

### Exemplo curl

```bash
curl -X POST 'https://seudominio.com/api/v1/payments/pix' \
  -H 'Content-Type: application/json' \
  -H 'X-Public-Key: gpk_sua_public_key' \
  -H 'X-Secret-Key: gsk_sua_secret_key' \
  -H 'Idempotency-Key: pedido-123-pix' \
  -d '{
    "customer": {
      "email": "cliente@exemplo.com",
      "name": "Cliente Teste",
      "cpf": "52998224725"
    },
    "amount": 97.90,
    "currency": "BRL",
    "partner_checkout_url": "https://loja.exemplo.com/checkout/ped-1001",
    "metadata": { "external_id": "ped-1001" }
  }'
```

### Resposta 201 (modo síncrono — padrão)

```json
{
  "order_id": 456,
  "transaction_id": "tx-abc123",
  "qrcode": "data:image/png;base64,...",
  "copy_paste": "00020126580014br.gov.bcb.pix...",
  "status": "pending"
}
```

Por padrão, `POST /payments/pix` responde **201** com QR e copia e cola na mesma requisição.

### Modo assíncrono (opcional)

Para alto volume, envie o header `X-Async: true` e receba **202**:

```json
{
  "order_id": 456,
  "status": "processing"
}
```

Consulte `GET /payments/{order_id}` ou aguarde o webhook `pix.generated`.

### Campos do request

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `customer.email` | Sim | E-mail do comprador |
| `customer.name` | Não | Nome (default: e-mail) |
| `customer.cpf` | Não | CPF |
| `amount` | Sim* | Valor em reais (*ignorado se `product_id` definir preço). Deve ser ≥ ticket mínimo da API PIX configurado pelo admin |
| `currency` | Não | BRL (default), USD ou EUR |
| `product_id` | Não | UUID do produto no catálogo |
| `metadata` | Não | Objeto livre — devolvido no webhook |
| `partner_checkout_url` | Não | URL HTTPS do checkout no site do parceiro (recomendado em produção) |
| `idempotency_key` | Não | Ou header `Idempotency-Key` (máx. 128 chars) |

### Ticket mínimo (API PIX)

O administrador da plataforma define o **ticket mínimo** em **Plataforma → Financeiro → Limites**. Cobranças via API REST e checkout hospedado da API devem ter valor final (incluindo frete, quando aplicável) **igual ou superior** a esse mínimo. O ticket mínimo pode variar por conta se o admin configurar override individual em **Infoprodutores → Editar**.

- Sem `product_id`: o campo `amount` do request deve respeitar o mínimo.
- Com `product_id`: o preço efetivo do produto/oferta/plano (e frete) também deve respeitar o mínimo — o `amount` do body continua sendo ignorado para cálculo, mas o valor cobrado é validado.
- Em violação: HTTP **422** com mensagem indicando o valor mínimo (ex.: *"Valor mínimo para cobrança via API PIX é R$ 5,00."*).

---

## Consultar pagamentos

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/payments` | Lista pedidos da integração (query: `status`, `per_page`) |
| GET | `/api/v1/payments/{order_id}` | Status de um pedido |

Retorna pedidos **somente da mesma aplicação** autenticada.

Status comuns: `pending`, `processing`, `completed`, `cancelled`, `refunded`, `disputed`.

---

## Cancelar e estornar PIX

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/v1/pix/{order_id}/cancel` | Cancelar PIX pendente |
| POST | `/api/v1/pix/{order_id}/refund` | Estornar PIX pago ou em disputa |

---

## Saques

### Consultar saldo

`GET /api/v1/balance`

```json
{
  "available_pix": 1500.00,
  "available_card": 0.00,
  "available_boleto": 0.00,
  "available_balance": 1500.00,
  "pending_balance": 320.50
}
```

Valores em `pending_balance` ainda não estão disponíveis para saque.

### Configurar / validar destino PIX

`PUT /api/v1/payout-destination`

```json
{
  "pix_key": "cliente@exemplo.com",
  "pix_key_type": "email",
  "key_owner_document": "52998224725"
}
```

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `pix_key` | Sim | Chave PIX a validar |
| `pix_key_type` | Sim | `cpf`, `cnpj`, `email`, `phone`, `evp` ou `random` (alias de chave aleatória) |
| `key_owner_document` | Condicional | CPF ou CNPJ do titular (somente dígitos) |

**Regras por tipo de chave:**

| `pix_key_type` | `key_owner_document` |
|----------------|----------------------|
| `email`, `phone`, `evp`, `random` | **Obrigatório** — CPF ou CNPJ do titular real |
| `cpf`, `cnpj` | Opcional — se omitido, usamos os dígitos da própria chave |

Este endpoint **apenas valida** o destino e **não altera** a chave master do infoprodutor (painel Financeiro).
A resposta inclui `persisted_to_merchant: false` e `key_owner_document_masked` (últimos 4 dígitos).

### Solicitar saque

`POST /api/v1/withdrawals`

```json
{
  "amount": 500.00,
  "bucket": "pix",
  "notes": "Saque semanal",
  "pix_key": "cliente@exemplo.com",
  "pix_key_type": "email",
  "key_owner_document": "52998224725"
}
```

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `amount` | Sim | Valor bruto em reais |
| `pix_key` | Sim | Chave PIX de recebimento **deste** saque (gravada na transação) |
| `pix_key_type` | Sim | `cpf`, `cnpj`, `email`, `phone`, `evp` ou `random` |
| `key_owner_document` | Condicional | CPF/CNPJ do titular — obrigatório para email/phone/evp/random |
| `bucket` | Não | `pix` (padrão), `card` ou `boleto` — carteira de origem |
| `notes` | Não | Observação opcional |
| `idempotency_key` | Não* | Ou header `Idempotency-Key` — **recomendado** para evitar duplicatas |

A chave informada fica no saque (`payout_meta.destination_snapshot`) e **não sobrescreve** a chave master do infoprodutor.

**Resposta 201:**

```json
{
  "withdrawal_id": 12,
  "status": "pending",
  "amount": 500.00,
  "fee_amount": 5.00,
  "net_amount": 495.00,
  "bucket": "pix",
  "pix_key_type": "email",
  "pix_key_masked": "****************.com"
}
```

### Listar e consultar saques

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/v1/withdrawals` | Lista saques (query: `status`, `per_page`) |
| GET | `/api/v1/withdrawals/{id}` | Detalhe de um saque |

---

## Resumo dos endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | /api/v1/payments/pix | Criar cobrança PIX |
| GET | /api/v1/payments | Listar pedidos |
| GET | /api/v1/payments/{order_id} | Consultar status do pedido |
| POST | /api/v1/pix/{order_id}/cancel | Cancelar PIX pendente |
| POST | /api/v1/pix/{order_id}/refund | Estornar PIX |
| GET | /api/v1/balance | Consultar saldo |
| POST | /api/v1/withdrawals | Solicitar saque |
| GET | /api/v1/withdrawals | Listar saques |
| GET | /api/v1/withdrawals/{id} | Consultar saque |
| PUT | /api/v1/payout-destination | Validar chave PIX (não altera chave master) |
| GET | /api/v1/webhook | Consultar configuração do webhook |
| PUT | /api/v1/webhook | Provisionar/atualizar webhook (parceiros) |
| POST | /api/v1/webhook/rotate-secret | Rotacionar secret do webhook |

---

## Webhooks

### Provisionamento via API (recomendado para parceiros)

Use `PUT /api/v1/webhook` ao conectar as credenciais do vendedor — veja seção **Integração para parceiros** acima.

### Entrega de eventos

Configure `webhook_url` na integração (via API ou painel). Cada entrega inclui headers:

- `X-Webhook-Signature` — HMAC-SHA256 do body bruto
- `X-Webhook-Id` — ID único do evento (use para deduplicar)
- `X-Webhook-Timestamp` — Unix timestamp do envio

Em caso de falha na entrega (endpoint offline ou não-2xx), reenviamos automaticamente com backoff exponencial. Responda **HTTP 2xx** rapidamente.

### Eventos de pagamento

| Evento | Descrição |
|--------|-----------|
| `order.pending` | Pedido criado; aguardando pagamento |
| `pix.generated` | QR e copia e cola disponíveis (útil no modo assíncrono) |
| `order.completed` | Pagamento confirmado — libere produto/serviço |
| `order.refunded` | Estorno concluído |
| `order.cancelled` | Pedido cancelado |
| `order.expired` | PIX expirou sem pagamento |

**Payload exemplo (`order.completed`):**

```json
{
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
}
```

`amount` continua o valor bruto cobrado. `amount_net` é o valor líquido da tela de vendas, já com as taxas da plataforma.

### Eventos de saque

| Evento | Descrição |
|--------|-----------|
| `withdrawal.created` | Saque solicitado; valor reservado |
| `withdrawal.processing` | Saque enviado para processamento |
| `withdrawal.completed` | Saque concluído |
| `withdrawal.failed` | Falha; saldo restaurado |
| `withdrawal.rejected` | Rejeitado; saldo restaurado |
| `withdrawal.cancelled` | Cancelado antes da conclusão |

**Assinatura:** compare `X-Webhook-Signature` com HMAC-SHA256 do body bruto (string JSON exata) usando o webhook secret da integração.

---

## Confirmação de pagamento e fallbacks

Não confie em um único canal para saber se o PIX foi pago. Implemente **três camadas** complementares:

| Camada | Onde roda | Função |
|--------|-----------|--------|
| **1. Webhook** | Seu backend | Canal **primário** — `order.completed` com HMAC; responda 2xx rápido; idempotente por `event_id` |
| **2. Reconciliação** | Job/cron no servidor | **Fallback obrigatório** — `GET /payments` a cada **60–120 s** por **6–12 h**; mesmo pipeline do webhook |
| **3. Polling na UI** | Browser (checkout) | Apenas UX — **não** libere produto só com polling do frontend |

### Fluxo

```
POST /payments/pix → salvar order_id no pedido interno
       ↓
Webhook order.completed (HMAC) → marcar pago + liberar produto (idempotente)
       ↓ (se falhar / atrasar)
Job reconciliação (1–2 min) → GET /payments → mesmo handler idempotente
       ↓ (paralelo, só UX)
Polling no checkout (opcional) → atualiza tela
```

### Reconciliação em background

Mesmo com webhook configurado, rode um **job no servidor** (scheduler, queue ou cron):

- **Individual:** `GET /api/v1/payments/{order_id}`
- **Em lote:** `GET /api/v1/payments?status=pending&per_page=100`
- **Condição:** `status === "completed"` → chame o mesmo handler idempotente do webhook

Polling **somente na tela do QR** não cobre o caso em que o comprador paga e fecha a aba.

### Checklist

- Webhook provisionado (`PUT /api/v1/webhook`) com URL HTTPS
- `webhook_secret` armazenado com segurança
- Validação HMAC em todo POST recebido
- Deduplicação por `event_id`
- Job de reconciliação em produção (60–120 s)
- Mesmo handler idempotente para webhook e reconciliação
- Secret key apenas no servidor

Pacote completo para IAs: `/docs/api-pagamentos/llm/full.md`

---

## Idempotência

Use `idempotency_key` no body ou header `Idempotency-Key` (máx. 128 caracteres). A mesma chave devolve a resposta original sem duplicar a operação. Recomendado em criações de pagamento e saques.

---

## Erros frequentes

| HTTP | Mensagem | Ação |
|------|----------|------|
| 401 | Missing or invalid API key. | Envie `X-Public-Key` + `X-Secret-Key` |
| 401 | Invalid API key. | Verifique o par em `/aplicacoes-api` |
| 403 | API application is disabled. | Ative a integração |
| 403 | IP not allowed. | Adicione IP ou deixe lista vazia |
| 403 | Insufficient API key permissions. | Use chave principal ou chave com scope correto |
| 403 | API PIX disabled for this tenant. | Habilite API PIX |
| 404 | Pedido não encontrado. | Use `order_id` da mesma app |
| 422 | Não foi possível gerar o PIX. | Verifique amount, customer e status da conta |
| 422 | Valor mínimo para cobrança via API PIX… | `amount` ou preço do produto abaixo do ticket mínimo configurado |
| 429 | Too Many Attempts. | Aguarde; use idempotency em retentativas |

---

## Boas práticas

- Chame a API **apenas do servidor**; não exponha a Secret no frontend
- Use **HTTPS** e **Idempotency-Key** em pagamentos e saques
- Use `metadata` para correlacionar com o pedido no seu sistema
- Valide assinatura do webhook em produção
- Deduplique webhooks por `event_id`
- Implemente job de reconciliação (60–120 s) como fallback do webhook
- Informe destino PIX em cada `POST /withdrawals` (`pix_key`, `pix_key_type`, `key_owner_document` quando exigido) — a chave master do infoprodutor não é alterada

Detalhes completos, exemplos Node.js e tabelas: `/docs/api-pagamentos`
