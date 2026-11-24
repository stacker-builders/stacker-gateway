<script setup>
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import Button from '@/components/ui/Button.vue';
import { ArrowLeft, Repeat, Ban } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();
const page = usePage();

const props = defineProps({
    subscription: { type: Object, required: true },
    recent_orders: { type: Array, default: () => [] },
    available_plans: { type: Array, default: () => [] },
    cancel_grace_days: { type: Number, default: 14 },
});

const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

const cancelForm = useForm({});
const planForm = useForm({
    subscription_plan_id: '',
});
const reminderForm = useForm({});

const otherPlans = computed(() =>
    (props.available_plans || []).filter((plan) => plan.id !== props.subscription.plan?.id),
);
const canManage = computed(() => props.subscription.status === 'active' || props.subscription.status === 'past_due');
const canResendCharge = computed(() => canManage.value && props.subscription.plan?.interval !== 'lifetime');

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatDate(value) {
    if (!value) return '—';
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        const [year, month, day] = text.split('-');
        return `${day}/${month}/${year}`;
    }
    const date = new Date(text);
    if (Number.isNaN(date.getTime())) return text;
    return date.toLocaleString('pt-BR');
}

function statusBadgeClass(status) {
    const map = {
        active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
        past_due: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
    };
    return map[status] ?? 'bg-zinc-100 text-zinc-700';
}

function statusBadgeLabel(status) {
    const map = {
        active: t('subscriptions.status.active', 'Ativa'),
        past_due: t('subscriptions.status.past_due', 'Em atraso'),
        cancelled: t('subscriptions.status.cancelled', 'Cancelada'),
    };
    return map[status] ?? status ?? '–';
}

function selectedPlan() {
    return otherPlans.value.find((plan) => String(plan.id) === String(planForm.subscription_plan_id));
}

function changePlan() {
    const plan = selectedPlan();
    if (!plan) {
        return;
    }
    const lifetimeNote = plan.interval === 'lifetime'
        ? ' O assinante deixa de receber cobrança de renovação.'
        : ' A próxima renovação usa este plano. Nenhuma cobrança é gerada agora.';
    const automaticNote = props.subscription.gateway_subscription_id
        ? ' O débito automático já autorizado no banco continua no valor antigo.'
        : '';
    if (!confirm(`Trocar para o plano ${plan.name}?${lifetimeNote}${automaticNote}`)) {
        return;
    }
    planForm.post(`/vendas/assinaturas/${props.subscription.id}/plano`, { preserveScroll: true });
}

function resendCharge() {
    if (!confirm('Reenviar o e-mail de renovação do plano atual para o assinante?')) {
        return;
    }
    reminderForm.post(`/vendas/assinaturas/${props.subscription.id}/reenviar-cobranca`, { preserveScroll: true });
}

function cancelSubscription() {
    if (!confirm(t('subscriptions.confirm_cancel', 'Cancelar esta assinatura? O cliente perderá o acesso após o período vigente.'))) {
        return;
    }
    cancelForm.post(`/vendas/assinaturas/${props.subscription.id}/cancel`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <VendasTabs />
        <div>
            <Link
                href="/vendas/assinaturas"
                class="inline-flex items-center gap-2 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
            >
                <ArrowLeft class="h-4 w-4" />
                {{ t('subscriptions.back_to_list', 'Voltar às assinaturas') }}
            </Link>
        </div>

        <div v-if="flashSuccess" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
            {{ flashError }}
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                        <Repeat class="h-6 w-6" />
                    </span>
                    <div>
                        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">
                            {{ subscription.user?.name || '—' }}
                        </h1>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ subscription.user?.email }}</p>
                        <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ subscription.product?.name }} · {{ subscription.plan?.name }}
                            <span class="text-zinc-500">({{ subscription.plan?.interval_label || subscription.plan?.interval }})</span>
                        </p>
                    </div>
                </div>
                <span
                    :class="[
                        'inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-medium',
                        statusBadgeClass(subscription.status),
                    ]"
                >
                    {{ statusBadgeLabel(subscription.status) }}
                </span>
            </div>

            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/40">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('subscriptions.paid_periods', 'Períodos pagos') }}
                    </dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ subscription.paid_periods_count }}</dd>
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/40">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Compra
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ formatDate(subscription.purchased_at) }}</dd>
                    <dd class="mt-1 text-xs text-zinc-500">{{ subscription.payment_method_label }}</dd>
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/40">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Próxima cobrança
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        <template v-if="subscription.next_charge_at">{{ formatDate(subscription.next_charge_at) }} · {{ formatBRL(subscription.next_charge_amount) }}</template>
                        <template v-else>Sem próxima cobrança</template>
                    </dd>
                    <dd class="mt-1 text-xs text-zinc-500">{{ subscription.automatic_charges_count }} cobrança(s) automática(s) paga(s)</dd>
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/40">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('subscriptions.plan_price', 'Valor do plano') }}
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ formatBRL(subscription.plan?.price) }} {{ subscription.plan?.currency }}
                    </dd>
                </div>
            </dl>

            <p v-if="subscription.status !== 'cancelled'" class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
                Após o fim do período, assinaturas em atraso podem ser canceladas automaticamente após
                {{ cancel_grace_days }} dias (configurável via GETFY_SUBSCRIPTION_CANCEL_GRACE_DAYS).
            </p>

            <div v-if="canManage" class="mt-6 space-y-4">
                <form v-if="otherPlans.length" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" @submit.prevent="changePlan">
                    <p class="text-sm font-medium text-zinc-900 dark:text-white">Trocar plano</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        A troca não gera cobrança e mantém o período já pago. A próxima renovação usa o plano escolhido.
                    </p>
                    <p v-if="subscription.gateway_subscription_id" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                        O PIX automático já autorizado no banco continua no valor antigo até uma nova autorização.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <label class="sr-only" for="assinatura-plano">Novo plano</label>
                        <select
                            id="assinatura-plano"
                            v-model="planForm.subscription_plan_id"
                            class="min-w-[16rem] rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            required
                        >
                            <option value="" disabled>Selecione um plano</option>
                            <option v-for="plan in otherPlans" :key="plan.id" :value="plan.id">
                                {{ plan.name }} · {{ formatBRL(plan.price) }} · {{ plan.interval_label }}
                            </option>
                        </select>
                        <Button type="submit" variant="secondary" :disabled="planForm.processing || !planForm.subscription_plan_id">
                            Trocar plano
                        </Button>
                    </div>
                </form>

                <div class="flex flex-wrap gap-3">
                    <Button
                        v-if="canResendCharge"
                        type="button"
                        variant="secondary"
                        :disabled="reminderForm.processing"
                        @click="resendCharge"
                    >
                        Enviar cobrança
                    </Button>
                    <Button type="button" variant="destructive" :disabled="cancelForm.processing" @click="cancelSubscription">
                        <Ban class="mr-2 h-4 w-4" />
                        {{ t('subscriptions.cancel', 'Cancelar assinatura') }}
                    </Button>
                </div>
                <p v-if="canResendCharge" class="text-xs text-zinc-500 dark:text-zinc-400">
                    Reenvia o e-mail com o link de renovação do plano atual. Não cria uma cobrança nova.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ t('subscriptions.recent_orders', 'Pedidos recentes') }}</h2>
            </div>
            <div v-if="recent_orders.length === 0" class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ t('subscriptions.no_orders', 'Nenhum pedido encontrado para este plano.') }}
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50/80 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('sales.order', 'Pedido') }}</th>
                            <th class="px-4 py-3 font-medium">Tipo</th>
                            <th class="px-4 py-3 font-medium">{{ t('sales.status', 'Status') }}</th>
                            <th class="px-4 py-3 font-medium">Método</th>
                            <th class="px-4 py-3 font-medium">{{ t('sales.amount', 'Valor') }}</th>
                            <th class="px-4 py-3 font-medium">Período</th>
                            <th class="px-4 py-3 font-medium">{{ t('sales.date', 'Data') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="o in recent_orders" :key="o.id" class="text-zinc-700 dark:text-zinc-300">
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="font-mono text-[var(--color-primary)] hover:underline"
                                    @click="router.visit(`/vendas?search=${encodeURIComponent(o.public_reference || '')}`)"
                                >
                                    {{ o.public_reference || '#' + o.id }}
                                </button>
                            </td>
                            <td class="px-4 py-3">{{ o.charge_label || (o.is_renewal ? 'Cobrança automática' : 'Compra') }}</td>
                            <td class="px-4 py-3">{{ o.status }}</td>
                            <td class="px-4 py-3">{{ o.payment_method_label || '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ formatBRL(o.amount) }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">{{ formatDate(o.period_start) }} — {{ formatDate(o.period_end) }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">{{ formatDate(o.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
