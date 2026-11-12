<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CircleDollarSign, Handshake } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const { t } = useI18n();
const { themePrefix } = useSellerDashboardTemplate();

const props = defineProps({
    tab: { type: String, default: 'painel' },
});

const panelHref = '/coproducao';
const participacoesHref = '/coproducao?tab=participacoes';

const activeTab = computed(() => (props.tab === 'participacoes' ? 'participacoes' : 'painel'));

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
    <nav :class="navClass" :aria-label="t('sidebar.coproduction', 'Co-produção')">
        <Link :href="panelHref" :class="linkClass(activeTab === 'painel')">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_panel', 'Painel') }}
        </Link>
        <Link :href="participacoesHref" :class="linkClass(activeTab === 'participacoes')">
            <Handshake class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_participations', 'Suas participações') }}
        </Link>
    </nav>
</template>
