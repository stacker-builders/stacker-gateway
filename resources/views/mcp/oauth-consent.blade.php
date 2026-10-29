<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autorizar MCP</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 28px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { font-size: 0.95rem; line-height: 1.5; color: #52525b; margin: 0 0 12px; }
        code { font-size: 0.8rem; word-break: break-all; }
        .actions { display: flex; gap: 8px; margin-top: 20px; }
        button { border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 600; cursor: pointer; }
        .approve { background: #0050fc; color: #fff; }
        .deny { background: #f4f4f5; color: #3f3f46; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Conectar MCP</h1>
            <p><strong>{{ $clientName }}</strong> quer acessar o catálogo, a área de membros e o saldo disponível desta conta.</p>
            <p>Isso não troca o token do Claude. O ChatGPT recebe um token próprio. Não inclui cobrança, saque, estorno nem chaves de pagamento.</p>
            <p>Servidor: <code>{{ $resource }}</code></p>
            <form method="post" action="{{ url('/oauth/authorize') }}">
                @csrf
                <div class="actions">
                    <button class="approve" type="submit" name="decision" value="approve">Autorizar</button>
                    <button class="deny" type="submit" name="decision" value="deny">Recusar</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
