<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Package, Handshake, UserPlus } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const page = usePage();
const { t } = useI18n();
const { themePrefix } = useSellerDashboardTemplate();

const path = computed(() => page.url.split('?')[0]);

const isCoproducao = computed(() =>
    path.value === '/coproducao'
    || path.value === '/produtos/coproducao'
    || path.value.startsWith('/produtos/coproducao/')
);

const isAfiliados = computed(() =>
    path.value === '/produtos/afiliados'
    || path.value.startsWith('/produtos/afiliados/')
    || /^\/produtos\/[^/]+\/painel-afiliado/.test(path.value)
);

const isProdutos = computed(() => {
    const p = path.value;
    if (
        p === '/coproducao'
        || p === '/produtos/coproducao'
        || p.startsWith('/produtos/coproducao/')
        || p === '/produtos/afiliados'
        || p.startsWith('/produtos/afiliados/')
        || /^\/produtos\/[^/]+\/painel-afiliado/.test(p)
    ) {
        return false;
    }
    return p === '/produtos' || /^\/produtos\/[^/]+/.test(p);
});

const navClass = computed(() => {
    if (themePrefix.value) return `${themePrefix.value}-subnav`;
    return 'inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80';
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
        :aria-label="t('sidebar.products', 'Produtos')"
    >
        <Link href="/produtos" :class="linkClass(isProdutos)">
            <Package class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_products', 'Produtos') }}
        </Link>
        <Link href="/coproducao" :class="linkClass(isCoproducao)">
            <Handshake class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_coproduction', 'Co-produção') }}
        </Link>
        <Link href="/produtos/afiliados" :class="linkClass(isAfiliados)">
            <UserPlus class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_affiliates', 'Afiliados') }}
        </Link>
    </nav>
</template>
