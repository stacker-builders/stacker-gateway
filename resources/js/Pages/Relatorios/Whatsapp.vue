<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import {
    MessageCircle,
    Send,
    Eye,
    Reply,
    CircleDollarSign,
    Ban,
    ShoppingCart,
    CreditCard,
    Loader2,
    RotateCcw,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();
const {
    pageClass,
    tablePanel,
    themePrefix,
    innerPanelClass,
} = usePanelThemeClasses();

const props = defineProps({
    period: { type: String, default: '7dias' },
    instance: { type: Object, default: null },
    metrics: { type: Object, default: () => ({}) },
    recent: { type: Array, default: () => [] },
    audience_counts: { type: Object, default: () => ({}) },
    campaigns: { type: Array, default: () => [] },
    campaign_defaults: { type: Object, default: () => ({}) },
    campaigns_available: { type: Boolean, default: false },
});

const periodOptions = [
    { value: '7dias', label: t('period.7days', '7 dias') },
    { value: '30dias', label: t('period.30days', '30 dias') },
];

const audience = ref('abandoned_cart');
const campaignMessage = ref(props.campaign_defaults?.abandoned_cart || '');
const includeImage = ref(true);
const sending = ref(false);
const campaignError = ref(null);
const campaignSuccess = ref(null);
const resendingId = ref(null);
const resendError = ref(null);
const resendSuccess = ref(null);

const audienceOptions = [
    { value: 'abandoned_cart', label: 'Carrinho abandonado' },
    { value: 'pending_pix', label: 'PIX pendente' },
    { value: 'buyers', label: 'Compradores' },
];

const audienceCount = computed(() => Number(props.audience_counts?.[audience.value] ?? 0));

function setPeriod(value) {
    router.get('/relatorios/whatsapp', { period: value }, { preserveState: false });
}

function onAudienceChange() {
    campaignMessage.value = props.campaign_defaults?.[audience.value] || campaignMessage.value;
}

async function sendCampaign() {
    sending.value = true;
    campaignError.value = null;
    campaignSuccess.value = null;
    try {
        const { data } = await axios.post('/integracoes/uazapi/campaigns', {
            audience: audience.value,
            message: campaignMessage.value,
            include_image: includeImage.value,
        });
        campaignSuccess.value = `${data.campaign?.queued_count ?? 0} mensagem(ns) enfileirada(s).`;
        router.reload({ only: ['campaigns', 'audience_counts', 'recent'] });
    } catch (err) {
        campaignError.value = err.response?.data?.message || 'Não foi possível disparar a campanha.';
    } finally {
        sending.value = false;
    }
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatDate(iso) {
    if (!iso) return '–';
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function eventLabel(type) {
    return {
        pix_generated: 'PIX',
        cart_recovery: 'Carrinho',
        campaign: 'Campanha',
    }[type] || type;
}

function audienceLabel(value) {
    return audienceOptions.find((opt) => opt.value === value)?.label || value;
}

function statusLabel(row) {
    if (row.wa_status) return row.wa_status;
    return row.status;
}

async function resendFailed(row) {
    if (!row?.can_resend || !row?.provider || !row?.dispatch_id || resendingId.value) {
        return;
    }
    resendingId.value = row.id;
    resendError.value = null;
    resendSuccess.value = null;
    try {
        const { data } = await axios.post(
            `/relatorios/whatsapp/envios/${row.provider}/${row.dispatch_id}/reenviar`,
        );
        resendSuccess.value = data?.message || 'Reenvio enfileirado.';
        router.reload({ only: ['recent', 'metrics'] });
    } catch (err) {
        resendError.value = err.response?.data?.message || 'Não foi possível reenviar a mensagem.';
    } finally {
        resendingId.value = null;
    }
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            title="Recuperação WhatsApp"
            subtitle="Envios, leituras, respostas e conversões da sequência de recuperação."
        />

        <AuroraPageSection>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav
                    :class="themePrefix ? `${themePrefix}-subnav flex-wrap` : 'flex flex-wrap items-center gap-1'"
                    aria-label="Período"
                >
                    <button
                        v-for="opt in periodOptions"
                        :key="opt.value"
                        type="button"
                        :aria-current="period === opt.value ? 'true' : undefined"
                        :class="[
                            themePrefix
                                ? [`${themePrefix}-subnav-item`, period === opt.value && `${themePrefix}-subnav-item-active`]
                                : 'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            !themePrefix &&
                                (period === opt.value
                                    ? 'bg-[var(--color-primary)] text-white'
                                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'),
                        ]"
                        @click="setPeriod(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </nav>
                <p v-if="instance?.phone" class="text-sm text-zinc-500">
                    Número: <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ instance.phone }}</span>
                    <span v-if="instance.name"> · {{ instance.name }}</span>
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AuroraStatCard :icon="Send" label="Enviadas" :value="String(metrics.sent ?? 0)" />
                <AuroraStatCard :icon="Eye" label="Lidas" :value="String(metrics.read ?? 0)" />
                <AuroraStatCard :icon="Reply" label="Respostas" :value="String(metrics.replied ?? 0)" />
                <AuroraStatCard :icon="CircleDollarSign" label="Convertidas" :value="String(metrics.converted ?? 0)" />
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AuroraStatCard :icon="ShoppingCart" label="Carrinho enviadas" :value="String(metrics.cart_sent ?? 0)" />
                <AuroraStatCard :icon="CreditCard" label="PIX enviadas" :value="String(metrics.pix_sent ?? 0)" />
                <AuroraStatCard :icon="MessageCircle" label="Receita recuperada" :value="formatBRL(metrics.converted_amount)" />
                <AuroraStatCard :icon="Ban" label="Opt-outs" :value="String(metrics.opt_outs ?? 0)" />
            </div>
            <p class="mt-3 text-xs text-zinc-500">
                Qualquer resposta pausa a sequência atual. Palavras como parar, stop ou não quero cancelam envios futuros neste número.
            </p>
        </AuroraPageSection>

        <AuroraPageSection v-if="campaigns_available">
            <div :class="innerPanelClass">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Campanha em lote</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Dispara para o público escolhido, com intervalo entre envios, opt-out e limite de novas conversas.
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="opt in audienceOptions"
                        :key="opt.value"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm"
                        :class="audience === opt.value
                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-zinc-900 dark:text-white'
                            : 'border-zinc-200 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'"
                        @click="audience = opt.value; onAudienceChange()"
                    >
                        <span class="block font-medium">{{ opt.label }}</span>
                        <span class="text-xs text-zinc-500">{{ audience_counts[opt.value] ?? 0 }} destinatário(s)</span>
                    </button>
                </div>
                <textarea
                    v-model="campaignMessage"
                    rows="3"
                    maxlength="1000"
                    class="mt-4 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <Toggle v-model="includeImage" />
                        Incluir imagem do produto
                    </label>
                    <Button
                        size="sm"
                        :disabled="sending || !instance?.connected || audienceCount < 1"
                        @click="sendCampaign"
                    >
                        <Loader2 v-if="sending" class="mr-2 h-4 w-4 animate-spin" />
                        Disparar {{ audienceCount }} mensagem(ns)
                    </Button>
                </div>
                <p v-if="campaignError" class="mt-2 text-sm text-red-600">{{ campaignError }}</p>
                <p v-if="campaignSuccess" class="mt-2 text-sm text-emerald-600">{{ campaignSuccess }}</p>
                <ul v-if="campaigns.length" class="mt-4 space-y-1 text-xs text-zinc-500">
                    <li v-for="row in campaigns" :key="row.id">
                        {{ formatDate(row.created_at) }} · {{ audienceLabel(row.audience) }} · {{ row.queued_count }} enfileirada(s)
                    </li>
                </ul>
            </div>
        </AuroraPageSection>

        <AuroraPageSection flush>
            <h2 class="px-4 pt-4 text-sm font-semibold aurora-fg">Últimos envios</h2>
            <p v-if="resendError" class="px-4 pt-2 text-sm text-red-600">{{ resendError }}</p>
            <p v-if="resendSuccess" class="px-4 pt-2 text-sm text-emerald-600">{{ resendSuccess }}</p>
            <div class="mt-4 overflow-hidden" :class="tablePanel">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-100/80 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500">Quando</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500">Tipo</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500">Telefone</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500">Status</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="row in recent" :key="row.id">
                            <td class="px-4 py-2 text-sm text-zinc-700 dark:text-zinc-300">{{ formatDate(row.sent_at || row.created_at) }}</td>
                            <td class="px-4 py-2 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ eventLabel(row.event_type) }}
                                <span v-if="row.sequence_step !== null" class="text-xs text-zinc-400">· passo {{ row.sequence_step + 1 }}</span>
                            </td>
                            <td class="px-4 py-2 font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ row.phone }}</td>
                            <td class="px-4 py-2 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ statusLabel(row) }}
                                <span v-if="row.error" class="block text-xs text-red-500">{{ row.error }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Button
                                    v-if="row.can_resend"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="resendingId === row.id"
                                    @click="resendFailed(row)"
                                >
                                    <Loader2 v-if="resendingId === row.id" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    <RotateCcw v-else class="mr-1.5 h-3.5 w-3.5" />
                                    Reenviar
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="!recent.length">
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">Nenhum envio neste período.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AuroraPageSection>
    </div>
</template>
