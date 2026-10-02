<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import MetricsNav from '@/components/metrics/MetricsNav.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import { useThemeMode } from '@/composables/useThemeMode';
import {
    Users, MousePointerClick, ShoppingCart, QrCode, BadgeCheck,
    Percent, CircleDollarSign, Wallet, Timer, TrendingUp, CreditCard, Ban,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const {
    pageClass, innerPanelClass, filterPanelClass,
} = usePanelThemeClasses();

const props = defineProps({
    period: { type: String, default: 'hoje' },
    date_from: { type: String, default: null },
    date_to: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    timeseries: { type: Array, default: () => [] },
    funnel: { type: Object, default: () => ({ steps: [] }) },
    by_source: { type: Array, default: () => [] },
    by_campaign: { type: Array, default: () => [] },
    by_device: { type: Array, default: () => [] },
    by_country: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    tab: { type: String, default: 'dashboard' },
    base_path: { type: String, default: '/produtos/coproducao/metricas' },
});

const { isDark } = useThemeMode();

function money(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatSeconds(s) {
    const n = Number(s || 0);
    if (n < 60) return `${n}s`;
    if (n < 3600) return `${Math.round(n / 60)} min`;
    return `${(n / 3600).toFixed(1)} h`;
}

const isHourly = computed(() => props.period === 'hoje' || props.period === 'ontem');
const categories = computed(() => props.timeseries.map((r) => {
    const bucket = String(r.bucket || '');
    if (isHourly.value || /^\d{1,2}h$/i.test(bucket)) {
        return bucket.toLowerCase().endsWith('h') ? bucket : `${Number(bucket)}h`;
    }
    const parts = bucket.split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}` : bucket;
}));
const seriesVisitors = computed(() => [
    { name: 'Visitantes', data: props.timeseries.map((r) => r.visitors) },
    { name: 'Cliques', data: props.timeseries.map((r) => r.clicks) },
    { name: 'Conversões', data: props.timeseries.map((r) => r.conversions) },
]);
const seriesRevenue = computed(() => [
    { name: 'Receita', data: props.timeseries.map((r) => r.revenue) },
    { name: 'PIX gerados', data: props.timeseries.map((r) => r.pix_created) },
]);

const chartOpts = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, background: 'transparent' },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    xaxis: {
        categories: categories.value,
        labels: {
            style: { colors: isDark.value ? '#a1a1aa' : '#71717a', fontSize: isHourly.value ? '11px' : '12px' },
            rotate: isHourly.value ? -45 : 0,
            hideOverlappingLabels: true,
        },
    },
    yaxis: { labels: { style: { colors: isDark.value ? '#a1a1aa' : '#71717a' } } },
    legend: { labels: { colors: isDark.value ? '#e4e4e7' : '#27272a' } },
    grid: { borderColor: isDark.value ? '#3f3f46' : '#e4e4e7' },
    colors: ['#059669', '#2563eb', '#d97706'],
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
}));

const revenueOpts = computed(() => ({
    ...chartOpts.value,
    colors: ['#059669', '#2563eb'],
}));

const deviceOpts = computed(() => ({
    chart: { type: 'donut', background: 'transparent' },
    labels: props.by_device.map((r) => r.label),
    legend: { position: 'bottom', labels: { colors: isDark.value ? '#e4e4e7' : '#27272a' } },
    colors: ['#059669', '#2563eb', '#d97706', '#db2777'],
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
}));

function onProductChange(e) {
    const path = props.base_path;
    const q = { period: props.period, ...props.filters };
    const val = e.target.value;
    if (val) q.product_id = val;
    else delete q.product_id;
    if (props.period === 'personalizado') {
        q.date_from = props.date_from;
        q.date_to = props.date_to;
    }
    router.get(path, q, { preserveState: false });
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            title="Coprodutor - Metricas"
            subtitle="Trafego e conversoes dos produtos em que voce e coprodutor."
        />

        <div class="mt-4 space-y-6">
            <ProdutosTabs />
            <MetricsNav
                :tab="tab"
                :period="period"
                :date_from="date_from"
                :date_to="date_to"
                :filters="filters"
                :base-path="base_path"
            >
                <template #filters>
                    <select
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                        :value="filters.product_id || ''"
                        @change="onProductChange"
                    >
                        <option value="">Todos os produtos</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </template>
            </MetricsNav>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                <AuroraStatCard label="Visitantes únicos" :value="String(summary.unique_visitors || 0)" :icon="Users" />
                <AuroraStatCard label="Sessões" :value="String(summary.sessions || 0)" :icon="MousePointerClick" />
                <AuroraStatCard label="Cliques" :value="String(summary.clicks || 0)" :icon="MousePointerClick" />
                <AuroraStatCard label="Checkouts iniciados" :value="String(summary.checkouts_form_started || 0)" :icon="ShoppingCart" />
                <AuroraStatCard label="Pedidos submetidos" :value="String(summary.checkouts_started || 0)" :icon="ShoppingCart" />
                <AuroraStatCard label="PIX gerados" :value="String(summary.pix_created || 0)" :icon="QrCode" />
                <AuroraStatCard label="Conversão PIX" :value="`${summary.pix_conversion_rate || 0}%`" :icon="QrCode" />
                <AuroraStatCard label="Conversões" :value="String(summary.conversions_approved || 0)" :icon="BadgeCheck" />
                <AuroraStatCard label="Taxa de conversão" :value="`${summary.conversion_rate || 0}%`" :icon="Percent" />
                <AuroraStatCard label="Aprovação cartão" :value="`${summary.card_approval_rate || 0}%`" :icon="CreditCard" />
                <AuroraStatCard label="Recusas" :value="String(summary.payments_refused || 0)" :icon="Ban" />
                <AuroraStatCard label="Receita bruta" :value="money(summary.gross_revenue)" :icon="CircleDollarSign" />
                <AuroraStatCard label="Receita líquida" :value="money(summary.net_revenue)" :icon="Wallet" />
                <AuroraStatCard label="Ticket médio" :value="money(summary.avg_ticket)" :icon="TrendingUp" />
                <AuroraStatCard label="Tempo médio até compra" :value="formatSeconds(summary.avg_seconds_to_convert)" :icon="Timer" />
                <AuroraStatCard label="Receita / visitante" :value="money(summary.revenue_per_visitor)" :icon="Users" />
                <AuroraStatCard label="Receita / clique" :value="money(summary.revenue_per_click)" :icon="MousePointerClick" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div :class="[innerPanelClass, 'p-4']">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Visitantes, cliques e conversões</h3>
                    <VueApexCharts :key="`visitors-${period}`" type="area" height="280" :options="chartOpts" :series="seriesVisitors" />
                </div>
                <div :class="[innerPanelClass, 'p-4']">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Receita e PIX gerados</h3>
                    <VueApexCharts :key="`revenue-${period}`" type="area" height="280" :options="revenueOpts" :series="seriesRevenue" />
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div :class="[innerPanelClass, 'p-4']">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Funil</h3>
                    <ul class="space-y-2">
                        <li
                            v-for="step in (funnel.steps || [])"
                            :key="step.key"
                            class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800/60"
                        >
                            <span class="text-zinc-700 dark:text-zinc-200">{{ step.label }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">
                                {{ step.value }}
                                <span class="ml-1 text-xs font-normal text-zinc-500">({{ step.percent_of_first }}%)</span>
                            </span>
                        </li>
                    </ul>
                </div>
                <div :class="[innerPanelClass, 'p-4']">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Por dispositivo</h3>
                    <VueApexCharts
                        v-if="by_device.length"
                        type="donut"
                        height="260"
                        :options="deviceOpts"
                        :series="by_device.map((r) => r.total)"
                    />
                    <p v-else class="text-sm text-zinc-500">Sem vendas no período.</p>
                </div>
                <div :class="[innerPanelClass, 'p-4']">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Top países</h3>
                    <ul class="space-y-2">
                        <li v-for="row in by_country.slice(0, 8)" :key="row.label" class="flex justify-between text-sm">
                            <span>{{ row.label }}</span>
                            <span class="font-medium">{{ row.total }} · {{ money(row.revenue) }}</span>
                        </li>
                        <li v-if="!by_country.length" class="text-zinc-500">Sem dados.</li>
                    </ul>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div :class="[filterPanelClass, 'overflow-hidden']">
                    <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Top fontes</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                                <tr>
                                    <th class="px-3 py-2">Fonte</th>
                                    <th class="px-3 py-2">Visit.</th>
                                    <th class="px-3 py-2">Conv.</th>
                                    <th class="px-3 py-2">Receita</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in by_source" :key="r.key" class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2">{{ r.label }}</td>
                                    <td class="px-3 py-2">{{ r.visitors }}</td>
                                    <td class="px-3 py-2">{{ r.approved }} ({{ r.conversion_rate }}%)</td>
                                    <td class="px-3 py-2">{{ money(r.revenue) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div :class="[filterPanelClass, 'overflow-hidden']">
                    <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Top campanhas</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                                <tr>
                                    <th class="px-3 py-2">Campanha</th>
                                    <th class="px-3 py-2">Visit.</th>
                                    <th class="px-3 py-2">Conv.</th>
                                    <th class="px-3 py-2">Receita</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in by_campaign" :key="r.key" class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2">{{ r.label }}</td>
                                    <td class="px-3 py-2">{{ r.visitors }}</td>
                                    <td class="px-3 py-2">{{ r.approved }} ({{ r.conversion_rate }}%)</td>
                                    <td class="px-3 py-2">{{ money(r.revenue) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
