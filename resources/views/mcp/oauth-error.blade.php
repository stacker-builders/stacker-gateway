<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCP OAuth</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 28px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { font-size: 0.95rem; line-height: 1.5; color: #52525b; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Não foi possível autorizar</h1>
            <p>{{ $message }}</p>
        </div>
    </div>
</body>
</html>
