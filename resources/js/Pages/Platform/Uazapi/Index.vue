<script setup>
import { computed, ref } from 'vue';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PlatformChannelTab from './PlatformChannelTab.vue';
import { MessageCircle, ExternalLink } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    uazapi: { type: Object, default: () => ({ docs_url: '', instances: [], recent_dispatches: [] }) },
    evolution: { type: Object, default: () => ({ docs_url: '', instances: [], recent_dispatches: [] }) },
    platform: {
        type: Object,
        default: () => ({
            channel: {},
            templates: [],
            recent_dispatches: [],
            recent_campaigns: [],
            campaign_defaults: {},
        }),
    },
});

const tabs = [
    { id: 'uazapi', label: 'Uazapi' },
    { id: 'evolution', label: 'Evolution API' },
    { id: 'platform', label: 'Canal da plataforma' },
];

const activeTab = ref('uazapi');

const current = computed(() => {
    if (activeTab.value === 'evolution') return props.evolution;
    if (activeTab.value === 'uazapi') return props.uazapi;
    return null;
});

const emptyInstances = computed(() =>
    activeTab.value === 'evolution'
        ? 'Nenhum infoprodutor conectou a Evolution API ainda.'
        : 'Nenhum infoprodutor conectou a Uazapi ainda.'
);

function statusLabel(status) {
    return {
        connected: 'Conectado',
        connecting: 'Conectando',
        disconnected: 'Desconectado',
        hibernated: 'Hibernado',
    }[status] || status;
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                <MessageCircle class="h-7 w-7 text-[#25D366]" />
                WhatsApp
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Monitore as integrações dos infoprodutores (Uazapi e Evolution) ou configure o canal oficial da plataforma.
            </p>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-zinc-200 dark:border-zinc-700">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium transition"
                :class="activeTab === tab.id ? 'border-[var(--color-primary)] text-[var(--color-primary)]' : 'border-transparent text-zinc-500'"
                :aria-current="activeTab === tab.id ? 'page' : undefined"
                @click="activeTab = tab.id"
            >
                {{ tab.label }}
            </button>
        </div>

        <PlatformChannelTab v-if="activeTab === 'platform'" :platform="platform" />

        <template v-else>
            <a
                v-if="current?.docs_url"
                :href="current.docs_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
            >
                Documentação {{ activeTab === 'evolution' ? 'Evolution API' : 'Uazapi' }}
                <ExternalLink class="h-3.5 w-3.5" />
            </a>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Instâncias dos infoprodutores</h2>
                <div v-if="current?.instances?.length" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                                <th class="px-2 py-2">Tenant</th>
                                <th class="px-2 py-2">Conta</th>
                                <th v-if="activeTab === 'evolution'" class="px-2 py-2">Instância</th>
                                <th class="px-2 py-2">Status</th>
                                <th class="px-2 py-2">Número</th>
                                <th class="px-2 py-2">Perfil</th>
                                <th class="px-2 py-2">Carrinho</th>
                                <th class="px-2 py-2">PIX</th>
                                <th class="px-2 py-2">PIX pago</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in current.instances" :key="row.id || row.tenant_id" class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="px-2 py-2 font-mono text-xs">{{ row.tenant_id }}</td>
                                <td class="px-2 py-2">
                                    {{ row.name || '—' }}
                                    <span v-if="row.is_default" class="ml-1 text-xs text-zinc-500">padrão</span>
                                </td>
                                <td v-if="activeTab === 'evolution'" class="px-2 py-2 font-mono text-xs">{{ row.instance_name || '—' }}</td>
                                <td class="px-2 py-2">{{ statusLabel(row.status) }}</td>
                                <td class="px-2 py-2">{{ row.phone || '—' }}</td>
                                <td class="px-2 py-2">{{ row.profile_name || '—' }}</td>
                                <td class="px-2 py-2">{{ row.cart_recovery_enabled ? 'Sim' : 'Não' }}</td>
                                <td class="px-2 py-2">{{ row.pix_recovery_enabled ? 'Sim' : 'Não' }}</td>
                                <td class="px-2 py-2">{{ row.order_paid_enabled ? 'Sim' : 'Não' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-zinc-500">{{ emptyInstances }}</p>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Últimos envios</h2>
                <div v-if="current?.recent_dispatches?.length" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                                <th class="px-2 py-2">ID</th>
                                <th class="px-2 py-2">Evento</th>
                                <th class="px-2 py-2">Status</th>
                                <th class="px-2 py-2">WhatsApp</th>
                                <th class="px-2 py-2">Tenant</th>
                                <th class="px-2 py-2">Erro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in current.recent_dispatches" :key="row.id" class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="px-2 py-2 font-mono text-xs">{{ row.id }}</td>
                                <td class="px-2 py-2">{{ row.event_type }}</td>
                                <td class="px-2 py-2">{{ row.status }}</td>
                                <td class="px-2 py-2">{{ row.wa_status || '—' }}</td>
                                <td class="px-2 py-2 font-mono text-xs">{{ row.tenant_id }}</td>
                                <td class="px-2 py-2 text-xs text-red-600 dark:text-red-400">{{ row.error || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-zinc-500">Nenhum envio registrado.</p>
            </section>
        </template>
    </div>
</template>
