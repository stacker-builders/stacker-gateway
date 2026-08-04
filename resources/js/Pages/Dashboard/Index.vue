<script setup>
import { ref, computed, onMounted, provide, defineAsyncComponent } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineOptions({ layout: LayoutInfoprodutor });

const page = usePage();
const { isAurora, isKawaii } = useSellerDashboardTemplate();

const dashboardView = computed(() => {
    if (isKawaii.value) {
        return defineAsyncComponent(() => import('@/components/dashboard/DashboardViewKawaii.vue'));
    }
    if (isAurora.value) {
        return defineAsyncComponent(() => import('@/components/dashboard/DashboardViewAurora.vue'));
    }

    return defineAsyncComponent(() => import('@/components/dashboard/DashboardViewDefault.vue'));
});
const hasAchievementsProgress = computed(() => !!(page.props.achievementsProgress ?? null));
const { t } = useI18n();

const valuesVisible = ref(true);
const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const props = defineProps({
    period: { type: String, default: 'hoje' },
    vendas_totais: { type: Number, default: 0 },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_vendas: { type: Array, default: () => [] },
    has_affiliate_enrollments: { type: Boolean, default: false },
    affiliate_stats: { type: Object, default: null },
    affiliate_recent_sales: { type: Array, default: () => [] },
});

const periodOptions = [
    { value: 'hoje', label: t('period.today', 'Hoje') },
    { value: 'ontem', label: t('period.yesterday', 'Ontem') },
    { value: '7dias', label: t('period.7days', '7 dias') },
    { value: 'mes', label: t('period.month', 'Mês') },
    { value: 'ano', label: t('period.year', 'Ano') },
    { value: 'total', label: t('period.total', 'Total') },
];

const dashboardLabels = computed(() => ({
    totalSales: t('dashboard.total_sales', 'Vendas totais'),
    pendingSales: t('dashboard.pending_sales', 'Vendas pendentes'),
    salesCount: t('dashboard.sales_count', 'Quantidade de vendas'),
    avgTicket: t('dashboard.avg_ticket', 'Ticket médio'),
    paymentMethods: t('dashboard.payment_methods', 'Formas de pagamento'),
    noPayments: t('dashboard.no_payments', 'Nenhum pagamento no período'),
    conversionRate: t('dashboard.conversion_rate', 'Taxa de conversão geral'),
    cartAbandonment: t('dashboard.cart_abandonment', 'Abandono de carrinho'),
    refunds: t('dashboard.refunds', 'Reembolso'),
    ordersCount: t('dashboard.orders_count', 'pedido(s)'),
    products: t('dashboard.products', 'Produtos'),
    salesPerformance: t('dashboard.sales_performance', 'Desempenho de vendas'),
    noSalesData: t('dashboard.no_sales_data', 'Nenhum dado de vendas no período'),
}));

function setPeriod(value) {
    router.get('/dashboard', { period: value }, { preserveState: false });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

const chartSeries = computed(() => [
    {
        name: t('sales.tab_sales', 'Vendas'),
        data: valuesVisible.value
            ? props.grafico_vendas.map((d) => d.total)
            : props.grafico_vendas.map(() => 0),
    },
]);

const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        toolbar: { show: false },
        zoom: { enabled: false },
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 600 },
    },
    colors: ['var(--color-primary)'],
    dataLabels: {
        enabled: true,
        formatter: (v) => (valuesVisible.value ? formatBRL(v) : ''),
        style: { fontSize: '11px' },
        offsetY: -4,
    },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 0.3,
            opacityFrom: 0.5,
            opacityTo: 0.08,
        },
    },
    markers: {
        size: 4,
        strokeWidth: 2,
        hover: { size: 6 },
    },
    xaxis: {
        categories: (props.period === 'hoje' || props.period === 'ontem')
            ? props.grafico_vendas.map((d) => `${Number(d.data)}h`)
            : props.grafico_vendas.map((d) => {
                const [y, m, day] = (d.data || '').split('-');
                return day && m ? `${day}/${m}` : d.data;
            }),
        labels: { style: { colors: '#71717a', fontSize: '12px' } },
        axisBorder: { show: true },
        crosshairs: { show: true },
    },
    yaxis: {
        labels: {
            style: { colors: '#71717a', fontSize: '12px' },
            formatter: (v) => formatBRL(v),
        },
    },
    grid: {
        borderColor: 'var(--chart-grid, #e4e4e7)',
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        yaxis: { lines: { show: true } },
        padding: { top: 20, right: 10, bottom: 0, left: 0 },
    },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        shared: true,
        intersect: false,
        x: { format: props.period === 'hoje' || props.period === 'ontem' ? 'HH' : 'dd/MM/yyyy' },
        y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
        style: { fontSize: '13px' },
    },
    crosshairs: {
        stroke: { width: 1, dashArray: 4 },
    },
}));

provide('auroraDashboard', {
    vendas_totais: computed(() => props.vendas_totais),
    grafico_vendas: computed(() => props.grafico_vendas),
    valuesVisible,
    formatCurrency: computed(() => formatBRL),
});

provide('kawaiiDashboard', {
    vendas_totais: computed(() => props.vendas_totais),
    grafico_vendas: computed(() => props.grafico_vendas),
    valuesVisible,
    formatCurrency: computed(() => formatBRL),
});

const sharedViewProps = computed(() => ({
    title: t('dashboard.title', 'Dashboard'),
    subtitle: t('dashboard.subtitle', 'Visão geral de desempenho, vendas e métricas do período.'),
    hasAchievementsProgress: hasAchievementsProgress.value,
    period: props.period,
    periodOptions,
    valuesVisible: valuesVisible.value,
    periodLabel: t('dashboard.period', 'Período'),
    hideValuesLabel: t('dashboard.hide_values', 'Ocultar valores'),
    showValuesLabel: t('dashboard.show_values', 'Mostrar valores'),
    vendas_totais: props.vendas_totais,
    vendas_pendentes: props.vendas_pendentes,
    quantidade_vendas: props.quantidade_vendas,
    ticket_medio: props.ticket_medio,
    formas_pagamento: props.formas_pagamento,
    taxa_conversao: props.taxa_conversao,
    abandono_carrinho: props.abandono_carrinho,
    reembolsos_count: props.reembolsos_count,
    reembolsos_total: props.reembolsos_total,
    quantidade_produtos: props.quantidade_produtos,
    grafico_vendas: props.grafico_vendas,
    chartOptions: chartOptions.value,
    chartSeries: chartSeries.value,
    labels: dashboardLabels.value,
    displayCurrency,
    displayNumber,
}));
</script>

<template>
    <div class="space-y-6">
        <component
            :is="dashboardView"
            v-if="dashboardView"
            v-bind="sharedViewProps"
            @update:period="setPeriod"
            @toggle-values="valuesVisible = !valuesVisible"
        />
    </div>
</template>
