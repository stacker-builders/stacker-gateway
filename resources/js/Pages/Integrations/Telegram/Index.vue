<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { ArrowLeft, CheckCircle2, Circle, ExternalLink, RefreshCw, Send, ShieldAlert, ShieldCheck, ShieldQuestion } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    bots: { type: Array, default: () => [] },
    communities: { type: Array, default: () => [] },
    discovered_communities: { type: Array, default: () => [] },
    wizard: { type: Object, required: true },
    policy: { type: Object, required: true },
    meta: { type: Object, default: () => ({}) },
    open_wizard: { type: Boolean, default: false },
    wizard_step: { type: Number, default: 0 },
    access: { type: Object, default: () => ({ ready: false, counts: {}, grants: [], filters: {} }) },
});

const accessBuyer = ref(props.access?.filters?.buyer || '');
const accessCommunityId = ref(props.access?.filters?.community_id || '');

function filterAccess() {
    router.get('/integracoes/telegram', {
        buyer: accessBuyer.value || undefined,
        community_id: accessCommunityId.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const page = usePage();
const showWizard = ref(Boolean(props.open_wizard) || props.bots.length === 0);
const step = ref(props.wizard_step > 0 ? props.wizard_step : props.wizard.current_step || 1);
const botPreview = ref(null);
const previewing = ref(false);
const previewError = ref('');
const tokenInput = ref('');
const updatingBotId = ref(null);
const newToken = ref('');

const botForm = useForm({ token: '' });
const communityForm = useForm({
    telegram_bot_id: props.bots[0]?.id ?? '',
    chat_id: '',
});
const communityMode = ref('list');
const selectedDiscoveredId = ref('');
let discoveredPoll = null;

const discoveredForBot = computed(() => {
    const botId = Number(communityForm.telegram_bot_id || 0);
    return (props.discovered_communities || []).filter((c) => Number(c.telegram_bot_id) === botId);
});

watch(
    () => props.bots,
    (bots) => {
        if (!communityForm.telegram_bot_id && bots[0]?.id) {
            communityForm.telegram_bot_id = bots[0].id;
        }
    },
    { deep: true },
);

watch(
    discoveredForBot,
    (list) => {
        if (!list.length) {
            selectedDiscoveredId.value = '';
            return;
        }
        const stillThere = list.some((c) => String(c.id) === String(selectedDiscoveredId.value));
        if (!stillThere) {
            selectedDiscoveredId.value = String(list[0].id);
        }
    },
    { immediate: true },
);

watch(
    [() => showWizard.value, () => step.value],
    ([wizardOpen, currentStep]) => {
        if (discoveredPoll) {
            clearInterval(discoveredPoll);
            discoveredPoll = null;
        }
        if (wizardOpen && currentStep === 2) {
            discoveredPoll = setInterval(() => {
                router.reload({ only: ['discovered_communities'], preserveUrl: true });
            }, 5000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (discoveredPoll) {
        clearInterval(discoveredPoll);
    }
});

watch(
    () => props.wizard.current_step,
    (value) => {
        if (!props.wizard_step) {
            step.value = value;
        }
    },
);

function openWizardAt(target) {
    showWizard.value = true;
    step.value = target;
}

function permissionTone(status) {
    if (status === 'green') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200';
    if (status === 'red') return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-200';
    return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200';
}

function permissionIcon(status) {
    if (status === 'green') return ShieldCheck;
    if (status === 'red') return ShieldAlert;
    return ShieldQuestion;
}

function statusLabel(status) {
    return {
        connected: 'Conectado',
        error: 'Com erro',
        disconnected: 'Desconectado',
        linked: 'Vinculado',
        discovered: 'Detectado',
        unlinked: 'Desvinculado',
    }[status] || status;
}

async function validateToken() {
    previewError.value = '';
    botPreview.value = null;
    previewing.value = true;
    try {
        const { data } = await axios.post('/integracoes/telegram/bots/preview', {
            token: tokenInput.value,
        });
        botPreview.value = data.bot;
        botForm.token = tokenInput.value;
    } catch (error) {
        previewError.value = error.response?.data?.errors?.token?.[0]
            || error.response?.data?.message
            || 'Não foi possível validar o token.';
    } finally {
        previewing.value = false;
    }
}

function confirmBot() {
    botForm.token = tokenInput.value;
    botForm.post('/integracoes/telegram/bots', {
        preserveScroll: true,
        onSuccess: () => {
            tokenInput.value = '';
            botPreview.value = null;
            botForm.reset();
        },
    });
}

function linkCommunity() {
    communityForm.post('/integracoes/telegram/communities', {
        preserveScroll: true,
        onSuccess: () => {
            communityForm.chat_id = '';
        },
    });
}

function confirmDiscoveredCommunity() {
    if (!selectedDiscoveredId.value) return;
    router.post(`/integracoes/telegram/communities/${selectedDiscoveredId.value}/confirm`, {}, {
        preserveScroll: true,
    });
}

function testBot(bot) {
    router.post(`/integracoes/telegram/bots/${bot.id}/test`, {}, { preserveScroll: true });
}

function disconnectBot(bot) {
    if (!confirm(`Desconectar @${bot.username || bot.first_name}? Desvincule as comunidades antes.`)) {
        return;
    }
    router.delete(`/integracoes/telegram/bots/${bot.id}`, { preserveScroll: true });
}

function updateToken(bot) {
    if (!newToken.value.trim()) return;
    updatingBotId.value = bot.id;
    router.put(`/integracoes/telegram/bots/${bot.id}/token`, { token: newToken.value }, {
        preserveScroll: true,
        onFinish: () => {
            updatingBotId.value = null;
            newToken.value = '';
        },
    });
}

function verifyCommunity(community) {
    router.post(`/integracoes/telegram/communities/${community.id}/verify`, {}, { preserveScroll: true });
}

function refreshCommunity(community) {
    router.post(`/integracoes/telegram/communities/${community.id}/refresh`, {}, { preserveScroll: true });
}

function unlinkCommunity(community) {
    if (!confirm(`Desvincular "${community.title || community.telegram_chat_id}"?`)) {
        return;
    }
    router.delete(`/integracoes/telegram/communities/${community.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <Link href="/integracoes" class="mb-2 inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                    <ArrowLeft class="h-4 w-4" />
                    Integrações
                </Link>
                <h1 class="flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    <Send class="h-7 w-7 text-[#2AABEE]" />
                    Telegram
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Conecte bots, vincule grupos e canais e verifique as permissões. A venda e a entrega automática entram nas próximas etapas.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="secondary" @click="openWizardAt(wizard.current_step || 1)">
                    Assistente
                </Button>
                <Button type="button" variant="primary" :disabled="!policy.can_create_bot" @click="openWizardAt(1)">
                    Adicionar bot
                </Button>
            </div>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Progresso técnico</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ wizard.ready_for_sales_prep ? 'Pronto para vender (preparação técnica).' : 'Conclua o assistente para deixar a integração pronta.' }}
                    </p>
                </div>
                <span
                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="wizard.ready_for_sales_prep ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'"
                >
                    {{ wizard.ready_for_sales_prep ? 'Pronto' : 'Em configuração' }}
                </span>
            </div>
            <ol class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <li
                    v-for="item in wizard.steps"
                    :key="item.key"
                    class="flex items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700"
                >
                    <CheckCircle2 v-if="item.done" class="h-4 w-4 text-emerald-500" />
                    <Circle v-else class="h-4 w-4 text-zinc-400" />
                    <span>{{ item.label }}</span>
                </li>
            </ol>
        </section>

        <section
            v-if="showWizard"
            class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6"
        >
            <div class="mb-4 flex flex-wrap gap-2">
                <button
                    v-for="item in wizard.steps"
                    :key="`wizard-${item.id}`"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-medium"
                    :class="step === item.id ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'"
                    @click="step = item.id"
                >
                    {{ item.id }}. {{ item.label }}
                </button>
            </div>

            <div v-if="step === 1" class="space-y-4">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">1. Conectar bot</h3>
                <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>Abra o @BotFather no Telegram.</li>
                    <li>Crie um bot com /newbot ou use um existente.</li>
                    <li>Copie o token e cole abaixo. Depois de salvar, o token não será exibido novamente.</li>
                </ol>
                <a
                    :href="meta.botfather_url || 'https://t.me/BotFather'"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
                >
                    Abrir BotFather
                    <ExternalLink class="h-3.5 w-3.5" />
                </a>
                <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                    <input
                        v-model="tokenInput"
                        type="password"
                        autocomplete="off"
                        placeholder="123456:ABC-DEF..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    />
                    <Button type="button" variant="secondary" :disabled="previewing || !tokenInput" @click="validateToken">
                        Validar token
                    </Button>
                </div>
                <p v-if="previewError" class="text-sm text-red-600">{{ previewError }}</p>
                <p v-if="botForm.errors.token" class="text-sm text-red-600">{{ botForm.errors.token }}</p>
                <div v-if="botPreview" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-900 dark:bg-emerald-950/30">
                    <p class="font-medium text-emerald-900 dark:text-emerald-100">{{ botPreview.first_name }} (@{{ botPreview.username }})</p>
                    <p class="mt-1 text-emerald-800 dark:text-emerald-200">ID: {{ botPreview.telegram_bot_id }}</p>
                    <Button type="button" class="mt-3" variant="primary" :disabled="botForm.processing || !policy.can_create_bot" @click="confirmBot">
                        Confirmar conexão
                    </Button>
                </div>
            </div>

            <div v-else-if="step === 2" class="space-y-4">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">2. Vincular grupo ou canal</h3>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Adicione o bot como administrador do grupo/canal. Em alguns segundos ele aparece na lista abaixo (via webhook). Se preferir, informe o ID ou @ manualmente.
                </p>
                <div v-if="!bots.length" class="text-sm text-amber-700 dark:text-amber-300">Conecte um bot na etapa 1 antes de continuar.</div>
                <template v-else>
                    <label class="block text-sm font-medium">
                        Bot
                        <select v-model="communityForm.telegram_bot_id" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                            <option v-for="bot in bots" :key="bot.id" :value="bot.id">
                                {{ bot.first_name }} (@{{ bot.username || 'sem-username' }})
                            </option>
                        </select>
                    </label>

                    <div class="flex flex-wrap gap-2 text-sm">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5"
                            :class="communityMode === 'list' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                            @click="communityMode = 'list'"
                        >
                            Lista detectada
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5"
                            :class="communityMode === 'manual' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                            @click="communityMode = 'manual'"
                        >
                            Informar ID / @
                        </button>
                    </div>

                    <template v-if="communityMode === 'list'">
                        <div v-if="!discoveredForBot.length" class="rounded-lg border border-dashed border-zinc-300 p-4 text-sm text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">
                            Nenhum grupo/canal detectado ainda. Adicione o bot ao chat (como admin) e aguarde — esta lista atualiza sozinha a cada poucos segundos.
                        </div>
                        <template v-else>
                            <label class="block text-sm font-medium">
                                Grupo ou canal detectado
                                <select v-model="selectedDiscoveredId" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                    <option v-for="chat in discoveredForBot" :key="chat.id" :value="String(chat.id)">
                                        {{ chat.title || chat.telegram_chat_id }}
                                        ({{ chat.type }}{{ chat.username ? ` · @${chat.username}` : '' }})
                                    </option>
                                </select>
                            </label>
                            <Button
                                type="button"
                                variant="primary"
                                :disabled="!selectedDiscoveredId || !policy.can_create_community"
                                @click="confirmDiscoveredCommunity"
                            >
                                Vincular selecionado
                            </Button>
                        </template>
                    </template>

                    <template v-else>
                        <label class="block text-sm font-medium">
                            ID ou @ da comunidade
                            <input
                                v-model="communityForm.chat_id"
                                type="text"
                                placeholder="-1001234567890 ou @meucanal"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            />
                        </label>
                        <p v-if="communityForm.errors.chat_id" class="text-sm text-red-600">{{ communityForm.errors.chat_id }}</p>
                        <Button type="button" variant="primary" :disabled="communityForm.processing || !policy.can_create_community" @click="linkCommunity">
                            Vincular comunidade
                        </Button>
                    </template>
                </template>
            </div>

            <div v-else-if="step === 3" class="space-y-4">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">3. Verificar permissões</h3>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Verde: confirmada. Amarelo: opcional/não verificada. Vermelho: obrigatória e ausente.
                </p>
                <div v-if="!communities.length" class="text-sm text-amber-700 dark:text-amber-300">Vincule uma comunidade na etapa 2.</div>
                <article
                    v-for="community in communities"
                    :key="`perm-${community.id}`"
                    class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ community.title || community.telegram_chat_id }}</p>
                            <p class="text-xs text-zinc-500">{{ community.type }} · {{ community.telegram_chat_id }}</p>
                        </div>
                        <Button type="button" variant="secondary" size="sm" @click="verifyCommunity(community)">
                            <RefreshCw class="h-3.5 w-3.5" />
                            Verificar novamente
                        </Button>
                    </div>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="perm in community.permissions || []"
                            :key="`${community.id}-${perm.key}`"
                            class="flex items-start gap-2 rounded-lg px-3 py-2 text-sm"
                            :class="permissionTone(perm.status)"
                        >
                            <component :is="permissionIcon(perm.status)" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                <span class="font-medium">{{ perm.label }}</span>
                                <span class="mt-0.5 block text-xs opacity-90">{{ perm.detail }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-if="!(community.permissions || []).length" class="mt-2 text-sm text-zinc-500">Ainda sem verificação. Clique em “Verificar novamente”.</p>
                </article>
            </div>

            <div v-else class="space-y-4">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">4. Integração concluída</h3>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                        Bots conectados: {{ wizard.summary.bots_count }} · Comunidades: {{ wizard.summary.communities_count }} ·
                        Prontas para entrega: {{ wizard.summary.ready_communities_count }}
                    </p>
                    <p class="mt-3 text-sm font-medium text-zinc-900 dark:text-white">
                        Seu Telegram está configurado! Em breve, você poderá vincular seus grupos e canais diretamente aos seus produtos.
                    </p>
                    <p class="mt-2 text-xs text-zinc-500">
                        “Pronto para vender” indica apenas a preparação técnica da integração. A criação do produto Telegram ainda não está disponível.
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Bots conectados</h2>
                <Button type="button" variant="secondary" size="sm" :disabled="!policy.can_create_bot" @click="openWizardAt(1)">Novo bot</Button>
            </div>
            <p v-if="!bots.length" class="text-sm text-zinc-500">Nenhum bot conectado ainda.</p>
            <div v-else class="space-y-3">
                <article v-for="bot in bots" :key="bot.id" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ bot.first_name }} <span class="text-zinc-500">(@{{ bot.username || '—' }})</span></p>
                            <p class="text-xs text-zinc-500">ID {{ bot.telegram_bot_id }} · {{ statusLabel(bot.status) }}</p>
                            <p v-if="bot.last_error" class="mt-1 text-xs text-red-600">{{ bot.last_error }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Última verificação: {{ bot.last_checked_at || '—' }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="testBot(bot)">Testar</Button>
                            <Button type="button" variant="secondary" size="sm" @click="disconnectBot(bot)">Desconectar</Button>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">
                        <input
                            v-model="newToken"
                            type="password"
                            autocomplete="off"
                            placeholder="Atualizar token (não será exibido depois)"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                        <Button type="button" variant="secondary" size="sm" :disabled="updatingBotId === bot.id || !newToken" @click="updateToken(bot)">
                            Atualizar token
                        </Button>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Grupos e canais</h2>
                <Button type="button" variant="secondary" size="sm" :disabled="!bots.length || !policy.can_create_community" @click="openWizardAt(2)">
                    Vincular comunidade
                </Button>
            </div>
            <p v-if="!communities.length" class="text-sm text-zinc-500">Nenhuma comunidade vinculada.</p>
            <div v-else class="space-y-3">
                <article v-for="community in communities" :key="community.id" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ community.title || community.telegram_chat_id }}</p>
                            <p class="text-xs text-zinc-500">
                                {{ community.type }} · {{ community.telegram_chat_id }}
                                <span v-if="community.bot"> · bot @{{ community.bot.username || community.bot.id }}</span>
                            </p>
                            <p class="mt-1 text-xs" :class="community.ready_for_delivery ? 'text-emerald-600' : 'text-amber-600'">
                                {{ community.ready_for_delivery ? 'Pronta para entrega automática (quando disponível)' : 'Aguardando permissões essenciais' }}
                            </p>
                            <p v-if="community.last_error" class="mt-1 text-xs text-red-600">{{ community.last_error }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="verifyCommunity(community)">Verificar</Button>
                            <Button type="button" variant="secondary" size="sm" @click="refreshCommunity(community)">Atualizar</Button>
                            <Button type="button" variant="secondary" size="sm" @click="unlinkCommunity(community)">Desvincular</Button>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Assinantes e acessos</h2>
            <p class="mt-1 text-sm text-zinc-500">Somente dados do seu tenant. Vendas comerciais ainda aguardam liberação operacional.</p>

            <dl class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Ativos</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.active ?? 0 }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Vencidos</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.expired ?? 0 }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Pendentes</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.pending ?? 0 }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Parciais</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.partial ?? 0 }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Renovações</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.renewals ?? 0 }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Falhas</dt>
                    <dd class="text-lg font-semibold">{{ access.counts?.delivery_failures ?? 0 }}</dd>
                </div>
            </dl>

            <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="filterAccess">
                <input
                    v-model="accessBuyer"
                    type="search"
                    placeholder="Comprador (nome, e-mail ou @)"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <select
                    v-model="accessCommunityId"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 sm:max-w-xs"
                >
                    <option value="">Todas as comunidades</option>
                    <option v-for="c in communities" :key="`acc-${c.id}`" :value="c.id">{{ c.title || c.telegram_chat_id }}</option>
                </select>
                <Button type="submit" variant="secondary" class="shrink-0">Filtrar</Button>
            </form>

            <p v-if="!(access.grants || []).length" class="mt-4 text-sm text-zinc-500">Nenhum acesso encontrado.</p>
            <div v-else class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase text-zinc-500">
                        <tr>
                            <th class="px-2 py-2">Comprador</th>
                            <th class="px-2 py-2">Produto</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="px-2 py-2">Entrega</th>
                            <th class="px-2 py-2">Vencimento</th>
                            <th class="px-2 py-2">Renovações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in access.grants" :key="row.id" class="border-t border-zinc-100 dark:border-zinc-700">
                            <td class="px-2 py-2">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ row.buyer?.name || '—' }}</div>
                                <div class="text-xs text-zinc-500">{{ row.buyer?.email }}</div>
                                <div v-if="row.telegram_username" class="text-xs text-zinc-500">@{{ row.telegram_username }}</div>
                            </td>
                            <td class="px-2 py-2">{{ row.product?.name || '—' }}</td>
                            <td class="px-2 py-2">{{ row.status }}</td>
                            <td class="px-2 py-2">{{ row.delivery_status }}</td>
                            <td class="px-2 py-2">{{ row.access_ends_at_label || '—' }}</td>
                            <td class="px-2 py-2">{{ row.renewal_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
