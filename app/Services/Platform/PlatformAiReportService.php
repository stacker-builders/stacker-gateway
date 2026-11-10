<?php

namespace App\Services\Platform;

use App\Support\Demo\DemoPlatformData;
use App\Support\DemoMode;
use App\Support\PlatformAiReportSettings;
use App\Support\PlatformDashboardPeriod;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlatformAiReportService
{
    public const OPENAI_MODEL = 'gpt-4o-mini';

    public const GEMINI_MODEL = 'gemini-2.5-flash';

    public function analyze(string $period, ?string $from, ?string $to): string
    {
        if (! PlatformAiReportSettings::isReady()) {
            throw new RuntimeException('O relatório com IA está desativado. Ative em Configurações e informe a chave.');
        }

        $period = PlatformDashboardPeriod::normalize($period);
        $from = PlatformDashboardPeriod::normalizeDate($from);
        $to = PlatformDashboardPeriod::normalizeDate($to);
        if ($period === 'personalizado') {
            $today = now()->toDateString();
            $from = $from ?? $today;
            $to = $to ?? $today;
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
        }

        [$start, $end] = PlatformDashboardPeriod::range($period, $from, $to);
        $analytics = DemoMode::isEnabled()
            ? DemoPlatformData::dashboard($period)
            : PlatformDashboardAnalytics::compute($period, $start, $end);

        $briefing = $this->briefing($analytics, $period, $from, $to);
        $system = $this->systemPrompt();
        $user = "Período: ".$this->periodLabel($period, $from, $to)."\n\n".json_encode(
            $briefing,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return match (PlatformAiReportSettings::provider()) {
            PlatformAiReportSettings::PROVIDER_OPENAI => $this->completeOpenAi($system, $user),
            default => $this->completeGemini($system, $user),
        };
    }

    /**
     * Pacote só com totais. Sem e-mail, documento ou identificador interno.
     *
     * @param  array<string, mixed>  $analytics
     * @return array<string, mixed>
     */
    public function briefing(array $analytics, string $period, ?string $from, ?string $to): array
    {
        $points = [];
        foreach ($analytics['grafico']['points'] ?? [] as $point) {
            if (! is_array($point)) {
                continue;
            }
            $points[] = [
                'periodo' => (string) ($point['label'] ?? $point['key'] ?? ''),
                'volume' => $point['volume'] ?? 0,
                'vendas' => $point['count'] ?? 0,
                'receita_plataforma' => $point['revenue'] ?? 0,
                'reembolsos' => $point['refunds'] ?? 0,
                'volume_periodo_anterior' => $point['prev_volume'] ?? 0,
            ];
        }
        $seriesNote = null;
        if (count($points) > 400) {
            $points = array_slice($points, -400);
            $seriesNote = 'Série limitada aos 400 pontos mais recentes.';
        }

        $sellers = [];
        foreach ($analytics['top_sellers'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sellers[] = [
                'nome' => (string) ($row['nome'] ?? ''),
                'quantidade' => $row['quantidade'] ?? 0,
                'volume' => $row['volume'] ?? 0,
                'ticket_medio' => $row['ticket_medio'] ?? 0,
            ];
        }

        $products = [];
        foreach ($analytics['top_products'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $products[] = [
                'produto' => (string) ($row['produto'] ?? ''),
                'vendedor' => (string) ($row['seller'] ?? ''),
                'quantidade' => $row['quantidade'] ?? 0,
                'volume' => $row['volume'] ?? 0,
            ];
        }

        $alerts = [];
        foreach ($analytics['alerts'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $alerts[] = trim(((string) ($row['count'] ?? '')).' '.((string) ($row['label'] ?? '')));
        }

        $methods = [];
        foreach ($analytics['payment_methods'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $methods[] = [
                'meio' => (string) ($row['label'] ?? ''),
                'volume' => $row['total'] ?? 0,
                'quantidade' => $row['quantidade'] ?? 0,
                'percentual' => $row['percent'] ?? 0,
            ];
        }

        $acquirers = [];
        foreach ($analytics['acquirers'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $acquirers[] = [
                'nome' => (string) ($row['nome'] ?? ''),
                'volume' => $row['volume'] ?? 0,
                'transacoes' => $row['transacoes'] ?? 0,
                'aprovadas' => $row['aprovadas'] ?? 0,
                'recusadas' => $row['recusadas'] ?? 0,
                'taxa_aprovacao' => $row['taxa_aprovacao'] ?? 0,
            ];
        }

        $funnelItems = [];
        foreach ($analytics['funnel']['items'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $funnelItems[] = [
                'status' => (string) ($row['label'] ?? ''),
                'quantidade' => $row['quantidade'] ?? 0,
                'percentual' => $row['percent'] ?? 0,
            ];
        }

        return [
            'periodo' => $this->periodLabel($period, $from, $to),
            'moeda' => 'BRL',
            'legenda' => [
                'kpis.vendas_totais' => 'Volume vendido em reais no período, só pedidos concluídos.',
                'kpis.quantidade_vendas' => 'Quantidade de pedidos concluídos no período.',
                'kpis.ticket_medio' => 'Ticket médio dos pedidos concluídos no período.',
                'kpis.faturamento_taxas_cobradas' => 'Taxas cobradas dos infoprodutores no período.',
                'kpis.faturamento_custo_adquirente_vendas' => 'Custo estimado de adquirente nas vendas do período.',
                'kpis.faturamento_custo_adquirente_saques' => 'Custo estimado de adquirente nos saques pagos do período.',
                'kpis.faturamento_liquido' => 'Taxas cobradas menos os dois custos de adquirente.',
                'kpis.withdrawals_total' => 'Valor de saques pagos no período.',
                'kpis.withdrawals_paid_count' => 'Quantidade de saques pagos no período.',
                'kpis.wallet_available' => 'Saldo disponível agora nas carteiras. Não é do período.',
                'kpis.wallet_pending' => 'Saldo pendente agora nas carteiras. Não é do período.',
                'kpis.withdrawals_pending' => 'Saques em aberto agora. Não é filtrado pelo período.',
                'kpis.withdrawals_pending_count' => 'Quantidade de saques em aberto agora.',
                'alertas' => 'Pendências atuais da operação, não filtradas pelo período.',
                'comparacoes' => 'Variação contra a janela anterior de mesma duração. delta_percent é a variação percentual.',
            ],
            'kpis' => $analytics['kpis'] ?? [],
            'crescimento' => $analytics['growth'] ?? [],
            'comparacoes' => $analytics['comparisons'] ?? null,
            'funil' => [
                'eventos' => $analytics['funnel']['eventos'] ?? 0,
                'taxa_aprovacao' => $analytics['funnel']['taxa_aprovacao'] ?? 0,
                'itens' => $funnelItems,
            ],
            'meios_de_pagamento' => $methods,
            'adquirentes' => $acquirers,
            'top_vendedores' => $sellers,
            'top_produtos' => $products,
            'alertas' => array_values(array_filter($alerts, fn (string $item) => $item !== '')),
            'serie' => $points,
            'serie_observacao' => $seriesNote,
        ];
    }

    private function completeOpenAi(string $system, string $user): string
    {
        try {
            $response = Http::withToken(PlatformAiReportSettings::apiKey())
                ->timeout(40)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => self::OPENAI_MODEL,
                    'temperature' => 0.3,
                    'max_tokens' => 1400,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('Não foi possível falar com a OpenAI. Tente de novo.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('A OpenAI recusou a chave. Confira o token em Configurações.');
        }
        if ($response->status() === 429) {
            throw new RuntimeException('A OpenAI limitou as consultas. Tente de novo em instantes.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('A OpenAI não devolveu a análise. Tente de novo.');
        }

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)
                ->map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part)
                ->implode("\n");
        }

        $text = trim((string) $content);
        if ($text === '') {
            throw new RuntimeException('A OpenAI devolveu uma análise vazia. Tente de novo.');
        }

        return $text;
    }

    private function completeGemini(string $system, string $user): string
    {
        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => PlatformAiReportSettings::apiKey(),
            ])
                ->timeout(40)
                ->acceptJson()
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.self::GEMINI_MODEL.':generateContent', [
                    'systemInstruction' => [
                        'parts' => [['text' => $system]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $user]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 1400,
                    ],
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('Não foi possível falar com o Gemini. Tente de novo.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('O Gemini recusou a chave. Confira o token em Configurações.');
        }
        if ($response->status() === 429) {
            throw new RuntimeException('O Gemini limitou as consultas. Tente de novo em instantes.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('O Gemini não devolveu a análise. Tente de novo.');
        }

        $parts = $response->json('candidates.0.content.parts');
        $text = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part)) {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('O Gemini devolveu uma análise vazia. Tente de novo.');
        }

        return $text;
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Você analisa o painel de uma plataforma de pagamentos e infoprodutos para o operador.',
            'Use somente os números do JSON. Não invente causa, campanha, sazonalidade externa nem dado que não esteja no JSON.',
            'Se um número não estiver no JSON, diga que não há dado para isso.',
            'Valores monetários estão em reais (BRL).',
            'Saldos de carteira, saques em aberto e alertas são o estado atual, não o recorte do período.',
            'Escreva em português do Brasil, com estes blocos e os valores citados:',
            'Resumo',
            'O que os números mostram',
            'Pontos de atenção',
            'O que vale olhar em seguida',
            'Não cite e-mail, documento ou identificador interno.',
        ]);
    }

    private function periodLabel(string $period, ?string $from, ?string $to): string
    {
        $labels = [
            'hoje' => 'Hoje',
            'ontem' => 'Ontem',
            '7dias' => 'Últimos 7 dias',
            'mes' => 'Mês atual',
            'ano' => 'Ano atual',
            'total' => 'Todo o histórico disponível',
            'personalizado' => 'Período personalizado',
        ];
        $label = $labels[$period] ?? 'Hoje';
        if ($period === 'personalizado' && $from && $to) {
            return $label.' ('.$from.' a '.$to.')';
        }

        return $label;
    }
}
