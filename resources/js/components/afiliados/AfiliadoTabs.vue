<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { UserPlus, LayoutDashboard, CircleDollarSign, BarChart3, Package, ChartNoAxesCombined } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const page = usePage();
const { t } = useI18n();
const { themePrefix } = useSellerDashboardTemplate();

const path = computed(() => page.url.split('?')[0]);

const isMeusProdutos = computed(() => path.value === '/produtos/afiliados');
const isDashboard = computed(() => path.value === '/produtos/afiliados/dashboard');
const isVendas = computed(() => path.value === '/produtos/afiliados/vendas');
const isRelatorios = computed(() => path.value === '/produtos/afiliados/relatorios');
const isMetricas = computed(() => path.value === '/produtos/afiliados/metricas' || path.value.startsWith('/produtos/afiliados/metricas/'));
const isPainelProduto = computed(() => /^\/produtos\/[^/]+\/painel-afiliado/.test(path.value));

const navClass = computed(() => {
    if (themePrefix.value) return `${themePrefix.value}-subnav`;
    return 'inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80';
});

function linkClass(active) {
    if (themePrefix.value) {
        return [`${themePrefix.value}-subnav-item flex items-center gap-2`, active && `${themePrefix.value}-subnav-item-active`];
    }
    return [
        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
        active
            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
    ];
}
</script>

<template>
    <nav :class="navClass" :aria-label="t('products.tab_affiliates', 'Afiliados')">
        <Link href="/produtos/afiliados" :class="linkClass(isMeusProdutos)">
            <Package class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_products', 'Meus produtos') }}
        </Link>
        <Link href="/produtos/afiliados/dashboard" :class="linkClass(isDashboard)">
            <LayoutDashboard class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_dashboard', 'Dashboard') }}
        </Link>
        <Link href="/produtos/afiliados/vendas" :class="linkClass(isVendas)">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_sales', 'Vendas') }}
        </Link>
        <Link href="/produtos/afiliados/relatorios" :class="linkClass(isRelatorios)">
            <BarChart3 class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_reports', 'Relatórios') }}
        </Link>
        <Link href="/produtos/afiliados/metricas" :class="linkClass(isMetricas)">
            <ChartNoAxesCombined class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_metrics', 'Métricas') }}
        </Link>
        <span
            v-if="isPainelProduto"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-[var(--color-primary)]"
        >
            <UserPlus class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('affiliate.tab_panel', 'Painel do produto') }}
        </span>
    </nav>
</template>
