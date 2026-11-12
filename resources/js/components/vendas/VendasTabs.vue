<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CircleDollarSign, Repeat, RotateCcw } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const page = usePage();
const { t } = useI18n();
const { themePrefix } = useSellerDashboardTemplate();

const medOpenCount = computed(() => Number(page.props.med_open_count ?? 0));

const isVendas = computed(() => {
    const url = page.url.split('?')[0];
    return url === '/vendas' || (
        url.startsWith('/vendas')
        && !url.startsWith('/vendas/assinaturas')
        && !url.startsWith('/vendas/disputas')
        && !url.startsWith('/vendas/reembolsos')
    );
});

const isAssinaturas = computed(() => page.url.split('?')[0].startsWith('/vendas/assinaturas'));

const isDisputas = computed(() => page.url.split('?')[0].startsWith('/vendas/disputas'));

const isReembolsos = computed(() => page.url.split('?')[0].startsWith('/vendas/reembolsos'));

const navClass = computed(() => {
    if (themePrefix.value) return `${themePrefix.value}-subnav`;
    return 'inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80';
});

function linkClass(active) {
    if (themePrefix.value) {
        return [`${themePrefix.value}-subnav-item flex items-center gap-2`, active && `${themePrefix.value}-subnav-item-active`];
    }
    return [
        'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
        active
            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
    ];
}
</script>

<template>
    <nav
        :class="navClass"
        :aria-label="t('sidebar.sales', 'Vendas')"
    >
        <Link href="/vendas" :class="linkClass(isVendas)">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('sales.tab_sales', 'Vendas') }}
        </Link>
        <Link href="/vendas/assinaturas" :class="linkClass(isAssinaturas)">
            <Repeat class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('sales.tab_subscriptions', 'Assinaturas') }}
        </Link>
        <Link href="/vendas/disputas" :class="linkClass(isDisputas)">
            <AlertTriangle class="h-4 w-4 shrink-0" aria-hidden="true" />
            Disputas MED
            <span
                v-if="medOpenCount > 0"
                class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-orange-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
            >
                {{ medOpenCount > 99 ? '99+' : medOpenCount }}
            </span>
        </Link>
        <Link href="/vendas/reembolsos" :class="linkClass(isReembolsos)">
            <RotateCcw class="h-4 w-4 shrink-0" aria-hidden="true" />
            Reembolsos
        </Link>
    </nav>
</template>
