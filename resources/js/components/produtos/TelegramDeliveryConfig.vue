<script setup>
import { computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Checkbox from '@/components/ui/Checkbox.vue';
import Toggle from '@/components/ui/Toggle.vue';

const props = defineProps({
    modelValue: {
        type: Object,
        required: true,
    },
    delivery: {
        type: Object,
        default: () => ({
            module_available: false,
            bots: [],
            integrations_url: '/integracoes/telegram',
            access_duration_options: [],
            invite_expiration_options: [],
            readiness: null,
            expiry_removal_enabled: true,
            sales_blocked_message: '',
        }),
    },
    compact: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const form = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

function patch(partial) {
    emit('update:modelValue', { ...props.modelValue, ...partial });
}

const bots = computed(() => props.delivery?.bots || []);
const selectedBot = computed(() => bots.value.find((b) => Number(b.id) === Number(form.value.bot_id)) || null);
const communities = computed(() => selectedBot.value?.communities || []);
const durationOptions = computed(() => props.delivery?.access_duration_options || []);
const inviteOptions = computed(() => props.delivery?.invite_expiration_options || []);
const readiness = computed(() => props.delivery?.readiness || null);
const isLifetime = computed(() => form.value.access_duration_preset === 'lifetime' || form.value.is_lifetime);
const isCustomDuration = computed(() => form.value.access_duration_preset === 'custom');

watch(
    () => form.value.bot_id,
    (botId, prev) => {
        if (String(botId ?? '') === String(prev ?? '')) return;
        const allowed = new Set((communities.value || []).map((c) => Number(c.id)));
        const nextIds = (form.value.community_ids || []).filter((id) => allowed.has(Number(id)));
        if (nextIds.length !== (form.value.community_ids || []).length) {
            patch({ community_ids: nextIds });
        }
    }
);

watch(
    () => form.value.access_duration_preset,
    (preset) => {
        if (preset === 'lifetime') {
            patch({ is_lifetime: true });
        } else if (form.value.is_lifetime) {
            patch({ is_lifetime: false });
        }
    }
);

function toggleCommunity(id) {
    const current = [...(form.value.community_ids || [])].map(Number);
    const num = Number(id);
    const idx = current.indexOf(num);
    if (idx >= 0) current.splice(idx, 1);
    else current.push(num);
    patch({ community_ids: current });
}

function communitySelected(id) {
    return (form.value.community_ids || []).map(Number).includes(Number(id));
}

function statusLabel(status) {
    if (status === 'connected' || status === 'linked') return 'OK';
    if (status === 'error') return 'Erro';
    if (status === 'disconnected' || status === 'unlinked') return 'Desconectado';
    return status || '—';
}
</script>

<template>
    <div :class="compact ? 'space-y-3' : 'space-y-5'">
        <div
            v-if="delivery?.sales_blocked_message"
            class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100"
        >
            {{ delivery.sales_blocked_message }}
        </div>

        <div
            v-if="!delivery?.module_available"
            class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-zinc-700 dark:bg-zinc-800/50"
        >
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                Integração Telegram não configurada nesta conta.
            </p>
            <Link
                :href="delivery?.integrations_url || '/integracoes/telegram'"
                class="mt-3 inline-flex rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white"
            >
                Ir para Integrações &gt; Telegram
            </Link>
        </div>

        <template v-else>
            <div v-if="bots.length === 0" class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    Nenhum bot conectado. Conecte um bot para configurar a entrega.
                </p>
                <Link
                    :href="delivery?.integrations_url || '/integracoes/telegram'"
                    class="mt-3 inline-flex rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-700"
                >
                    Conectar bot no Telegram
                </Link>
            </div>

            <template v-else>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bot conectado</label>
                    <select
                        :value="form.bot_id ?? ''"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        @change="patch({ bot_id: $event.target.value ? Number($event.target.value) : null, community_ids: [] })"
                    >
                        <option value="">Selecione um bot</option>
                        <option v-for="bot in bots" :key="bot.id" :value="bot.id">
                            @{{ bot.username || bot.first_name || bot.id }} ({{ statusLabel(bot.status) }})
                        </option>
                    </select>
                </div>

                <div v-if="selectedBot">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Grupos e canais
                    </label>
                    <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Selecione um ou mais destinos vinculados a este bot. O prazo abaixo é do direito de acesso do comprador, não só do link de convite.
                    </p>
                    <div v-if="communities.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                        Nenhuma comunidade vinculada a este bot.
                        <Link :href="delivery?.integrations_url || '/integracoes/telegram'" class="underline">Vincular agora</Link>
                    </div>
                    <div v-else class="space-y-2">
                        <label
                            v-for="community in communities"
                            :key="community.id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 px-3 py-2.5 dark:border-zinc-700"
                        >
                            <Checkbox
                                :model-value="communitySelected(community.id)"
                                @update:model-value="toggleCommunity(community.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ community.title || ('Chat ' + community.telegram_chat_id) }}
                                    </span>
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                        {{ community.type }}
                                    </span>
                                    <span
                                        :class="community.ready_for_delivery
                                            ? 'rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                            : 'rounded bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'"
                                    >
                                        {{ community.ready_for_delivery ? 'Permissões OK' : 'Permissões pendentes' }}
                                    </span>
                                </div>
                                <p v-if="!community.ready_for_delivery" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                    Revise as permissões do bot nesta comunidade antes de publicar.
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Duração do acesso
                        </label>
                        <select
                            :value="form.access_duration_preset"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            @change="patch({ access_duration_preset: $event.target.value })"
                        >
                            <option v-for="opt in durationOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Direito de acesso do comprador (independente da cobrança recorrente).
                        </p>
                    </div>
                    <div v-if="isCustomDuration">
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Dias personalizados
                        </label>
                        <input
                            type="number"
                            min="1"
                            max="3650"
                            :value="form.access_duration_days"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            @input="patch({ access_duration_days: Number($event.target.value) || 1 })"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Validade do convite
                        </label>
                        <select
                            :value="form.invite_expiration_hours"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            @change="patch({ invite_expiration_hours: Number($event.target.value) })"
                        >
                            <option v-for="opt in inviteOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Prazo para o comprador usar o link (não é a duração do acesso).
                        </p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Mensagem de boas-vindas
                    </label>
                    <textarea
                        rows="3"
                        :value="form.welcome_message || ''"
                        maxlength="4000"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        placeholder="Opcional. Enviada após a liberação do acesso."
                        @input="patch({ welcome_message: $event.target.value })"
                    />
                </div>

                <div class="flex items-start justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            Remoção automática após vencimento
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <template v-if="isLifetime">Não se aplica a acesso vitalício.</template>
                            <template v-else-if="!delivery?.expiry_removal_enabled">
                                Desabilitado pela política global do Telegram.
                            </template>
                            <template v-else>
                                Respeita as políticas globais; a execução real entra nas próximas etapas.
                            </template>
                        </p>
                    </div>
                    <Toggle
                        :model-value="!!form.auto_remove_on_expiry && !isLifetime && !!delivery?.expiry_removal_enabled"
                        :disabled="isLifetime || !delivery?.expiry_removal_enabled"
                        class="shrink-0"
                        @update:model-value="patch({ auto_remove_on_expiry: $event })"
                    />
                </div>

                <div
                    v-if="readiness"
                    class="rounded-xl border px-3 py-3 text-xs"
                    :class="readiness.ready_for_sale
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100'
                        : 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300'"
                >
                    <p class="font-semibold">
                        {{ readiness.ready_for_sale ? 'Pronto para venda' : 'Preparação para venda' }}
                    </p>
                    <ul v-if="readiness.blocking_reasons?.length" class="mt-1.5 list-disc space-y-0.5 pl-4">
                        <li v-for="(reason, idx) in readiness.blocking_reasons" :key="idx">{{ reason }}</li>
                    </ul>
                </div>
            </template>
        </template>

        <p v-if="errors.telegram" class="text-sm text-red-600 dark:text-red-400">{{ errors.telegram }}</p>
    </div>
</template>
