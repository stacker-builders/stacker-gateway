<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AppCard from '@/components/integrations/AppCard.vue';
import SpedySidebar from '@/components/integrations/SpedySidebar.vue';
import UtmifySidebar from '@/components/integrations/UtmifySidebar.vue';
import WebhookSidebar from '@/components/integrations/WebhookSidebar.vue';
import CademiSidebar from '@/components/integrations/CademiSidebar.vue';
import UazapiSidebar from '@/components/integrations/UazapiSidebar.vue';
import EvolutionSidebar from '@/components/integrations/EvolutionSidebar.vue';
import McpSidebar from '@/components/integrations/McpSidebar.vue';
import { Zap } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();

const APPS_BASE = [
    {
        id: 'webhook',
        name: 'Webhook',
        description: t('integrations.webhook.description', 'Envie eventos da plataforma para sua URL. Configure quais eventos deseja receber e use Bearer token para autenticação.'),
        image: 'images/integrations/webhook.png',
    },
    {
        id: 'utmify',
        name: 'UTMIFY',
        description: t('integrations.utmify.description', 'Rastreie vendas e envie eventos para a UTMIFY. Requer apenas a chave de API.'),
        image: 'images/integrations/utmify.jpg',
    },
    {
        id: 'spedy',
        name: 'Spedy',
        description: t('integrations.spedy.description', 'Emissão automática de notas fiscais. Envie vendas para a Spedy e emita NF-e/NFS-e.'),
        image: 'images/integrations/spedy.png',
    },
    {
        id: 'cademi',
        name: 'Cademí',
        description: t('integrations.cademi.description', 'Área de membros externa. Após a compra, sincronize o aluno e conceda acesso na Cademí.'),
        image: 'images/integrations/cademi.png',
    },
    {
        id: 'uazapi',
        name: 'Uazapi',
        description: t('integrations.uazapi.description', 'Recupere carrinhos abandonados e PIX pendentes pelo seu WhatsApp via Uazapi.'),
        image: 'images/integrations/uazapi.avif',
    },
    {
        id: 'evolution',
        name: 'Evolution API',
        description: t('integrations.evolution.description', 'Recupere carrinhos abandonados e PIX pendentes pelo WhatsApp via Evolution API.'),
        image: 'images/integrations/Evolution.svg',
    },
    {
        id: 'mcp',
        name: 'MCP',
        description: t('integrations.mcp.description', 'Conecte Claude, ChatGPT ou outro cliente MCP para montar produtos, funis, área de membros e alunos desta conta.'),
        image: 'images/integrations/mcp.svg',
    },
];

const props = defineProps({
    webhooks: { type: Array, default: () => [] },
    webhook_events: { type: Object, default: () => ({}) },
    utmify_integrations: { type: Array, default: () => [] },
    spedy_integrations: { type: Array, default: () => [] },
    cademi_integrations: { type: Array, default: () => [] },
    uazapi: { type: Object, default: null },
    evolution: { type: Object, default: null },
    mcp: { type: Object, default: null },
    products: { type: Array, default: () => [] },
    visible_integrations: { type: Array, default: () => ['webhook', 'utmify', 'spedy', 'cademi', 'uazapi', 'evolution'] },
});

const APPS = computed(() => {
    const allowed = new Set(props.visible_integrations || []);
    return APPS_BASE.filter((app) => allowed.has(app.id)).map((app) => {
        if (app.id === 'utmify') {
            const hasActive = (props.utmify_integrations || []).some(
                (i) => i.configured && i.is_active
            );
            return {
                ...app,
                status: hasActive ? 'active' : undefined,
            };
        }
        if (app.id === 'spedy') {
            const hasActive = (props.spedy_integrations || []).some(
                (i) => i.configured && i.is_active
            );
            return {
                ...app,
                status: hasActive ? 'active' : undefined,
            };
        }
        if (app.id === 'cademi') {
            const hasActive = (props.cademi_integrations || []).some(
                (i) => i.configured && i.is_active
            );
            return {
                ...app,
                status: hasActive ? 'active' : undefined,
            };
        }
        if (app.id === 'uazapi') {
            return {
                ...app,
                status: props.uazapi?.configured && props.uazapi?.is_active ? 'active' : undefined,
            };
        }
        if (app.id === 'evolution') {
            return {
                ...app,
                status: props.evolution?.configured && props.evolution?.is_active ? 'active' : undefined,
            };
        }
        if (app.id === 'mcp') {
            return {
                ...app,
                status: props.mcp?.connected ? 'active' : undefined,
            };
        }
        return app;
    });
});

const webhookSidebarOpen = ref(false);
const utmifySidebarOpen = ref(false);
const spedySidebarOpen = ref(false);
const cademiSidebarOpen = ref(false);
const uazapiSidebarOpen = ref(false);
const evolutionSidebarOpen = ref(false);
const mcpSidebarOpen = ref(false);

function openWebhookSidebar() {
    webhookSidebarOpen.value = true;
}

function closeWebhookSidebar() {
    webhookSidebarOpen.value = false;
}

function openUtmifySidebar() {
    utmifySidebarOpen.value = true;
}

function closeUtmifySidebar() {
    utmifySidebarOpen.value = false;
}

function openSpedySidebar() {
    spedySidebarOpen.value = true;
}

function closeSpedySidebar() {
    spedySidebarOpen.value = false;
}

function openCademiSidebar() {
    cademiSidebarOpen.value = true;
}

function closeCademiSidebar() {
    cademiSidebarOpen.value = false;
}

function openUazapiSidebar() {
    uazapiSidebarOpen.value = true;
}

function closeUazapiSidebar() {
    uazapiSidebarOpen.value = false;
    router.reload({ only: ['uazapi'] });
}

function openEvolutionSidebar() {
    evolutionSidebarOpen.value = true;
}

function closeEvolutionSidebar() {
    evolutionSidebarOpen.value = false;
    router.reload({ only: ['evolution'] });
}

function openMcpSidebar() {
    mcpSidebarOpen.value = true;
}

function closeMcpSidebar() {
    mcpSidebarOpen.value = false;
}

function onMcpSaved() {
    router.reload({ only: ['mcp'] });
}

function onWebhookSaved() {
    router.reload();
}

function onUtmifySaved() {
    router.reload({ only: ['utmify_integrations', 'products'] });
}

function onSpedySaved() {
    router.reload({ only: ['spedy_integrations', 'products'] });
}

function onCademiSaved() {
    router.reload({ only: ['cademi_integrations', 'products'] });
}

function onAppClick(app) {
    if (app.id === 'webhook') {
        openWebhookSidebar();
    } else if (app.id === 'utmify') {
        openUtmifySidebar();
    } else if (app.id === 'spedy') {
        openSpedySidebar();
    } else if (app.id === 'cademi') {
        openCademiSidebar();
    } else if (app.id === 'uazapi') {
        openUazapiSidebar();
    } else if (app.id === 'evolution') {
        openEvolutionSidebar();
    } else if (app.id === 'mcp') {
        openMcpSidebar();
    }
}
</script>

<template>
    <div class="space-y-6">
        <section>
            <div class="mb-4 flex items-center gap-2">
                <Zap class="h-5 w-5 text-[var(--color-primary)]" aria-hidden="true" />
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ t('integrations.title', 'Apps') }}
                </h2>
            </div>
            <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                {{ t('integrations.subtitle', 'Conecte sua plataforma com sistemas externos via webhooks e outras integrações.') }}
            </p>
            <div v-if="APPS.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <AppCard
                    v-for="app in APPS"
                    :key="app.id"
                    :app="app"
                    @click="onAppClick(app)"
                />
            </div>
            <p v-else class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
                Nenhuma integração está disponível para esta conta no momento.
            </p>
        </section>

        <WebhookSidebar
            :open="webhookSidebarOpen"
            :webhooks="webhooks"
            :webhook-events="webhook_events"
            :products="products"
            @close="closeWebhookSidebar"
            @saved="onWebhookSaved"
        />
        <UtmifySidebar
            :open="utmifySidebarOpen"
            :utmify_integrations="utmify_integrations"
            :products="products"
            @close="closeUtmifySidebar"
            @saved="onUtmifySaved"
        />
        <SpedySidebar
            :open="spedySidebarOpen"
            :spedy_integrations="spedy_integrations"
            :products="products"
            @close="closeSpedySidebar"
            @saved="onSpedySaved"
        />
        <CademiSidebar
            :open="cademiSidebarOpen"
            :cademi_integrations="cademi_integrations"
            :products="products"
            @close="closeCademiSidebar"
            @saved="onCademiSaved"
        />
        <UazapiSidebar
            :open="uazapiSidebarOpen"
            :products="products"
            @close="closeUazapiSidebar"
        />
        <EvolutionSidebar
            :open="evolutionSidebarOpen"
            :products="products"
            @close="closeEvolutionSidebar"
        />
        <McpSidebar
            :open="mcpSidebarOpen"
            :mcp="mcp"
            @close="closeMcpSidebar"
            @saved="onMcpSaved"
        />
    </div>
</template>
