<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import CustomerAccessDossierSidebar from '@/components/platform/CustomerAccessDossierSidebar.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { ArrowLeft, ClipboardList, Package } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    customer: { type: Object, required: true },
    address: { type: Object, required: true },
    summary: { type: Object, required: true },
    orders: { type: Object, required: true },
    pending_orders: { type: Array, default: () => [] },
    access_products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filter_options: { type: Object, default: () => ({ products: [], sellers: [], payment_methods: [] }) },
    status_labels: { type: Object, default: () => ({}) },
    security: {
        type: Object,
        default: () => ({ totp_enabled: false, has_operation_pin: false, can_authorize: false }),
    },
    issued_password: { type: String, default: '' },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashError = computed(() => page.props.flash?.error || '');
const totpEnabled = computed(() => Boolean(props.security?.totp_enabled));
const canAuthorize = computed(() => Boolean(props.security?.can_authorize));
const stepUpOpen = ref(false);
const pendingAction = ref('');
const passwordCopied = ref(false);

const emailForm = useForm({
    email: props.customer?.email || '',
    totp_code: '',
    manual_approval_pin: '',
});
const passwordForm = useForm({
    totp_code: '',
    manual_approval_pin: '',
});

watch(
    () => props.customer?.email,
    (email) => {
        emailForm.email = email || '';
    }
);

const stepUpTitle = computed(() =>
    pendingAction.value === 'password' ? 'Gerar nova senha' : 'Alterar e-mail'
);
const stepUpDescription = computed(() =>
    pendingAction.value === 'password'
        ? 'A senha atual deixa de valer. A nova aparece uma vez nesta ficha para você passar ao cliente.'
        : 'O cliente passa a entrar com este e-mail na área de membros e no painel.'
);

const NOT_INFORMED = 'Não informado';
const accessSidebarOpen = ref(false);
const accessProductId = ref(null);

const form = reactive({
    status: props.filters?.status ?? '',
    product_id: props.filters?.product_id ?? '',
    seller_id: props.filters?.seller_id ? String(props.filters.seller_id) : '',
    payment_method: props.filters?.payment_method ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    per_page: String(props.filters?.per_page ?? 25),
    pending_only: !!props.filters?.pending_only,
});

watch(
    () => props.filters,
    (f) => {
        form.status = f?.status ?? '';
        form.product_id = f?.product_id ?? '';
        form.seller_id = f?.seller_id ? String(f.seller_id) : '';
        form.payment_method = f?.payment_method ?? '';
        form.date_from = f?.date_from ?? '';
        form.date_to = f?.date_to ?? '';
        form.per_page = String(f?.per_page ?? 25);
        form.pending_only = !!f?.pending_only;
    },
    { deep: true }
);

const orderRows = computed(() => props.orders?.data ?? []);

function display(value) {
    if (value === null || value === undefined || value === '') return NOT_INFORMED;
    return value;
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatDate(iso) {
    if (!iso) return NOT_INFORMED;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? NOT_INFORMED : d.toLocaleString('pt-BR');
}

function formatDateOnly(iso) {
    if (!iso) return NOT_INFORMED;
    if (/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        const [y, m, d] = iso.split('-');
        return `${d}/${m}/${y}`;
    }
    return formatDate(iso);
}

function accessAsCustomer() {
    const name = props.customer?.name || props.customer?.email || 'este cliente';
    if (!confirm(`Abrir o painel de ${name} em uma nova aba?\n\nEsta ficha continua aberta. A outra aba mostra as compras e os acessos dele, sem alterar a conta.`)) {
        return;
    }
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `/plataforma/clientes/${props.customer.id}/acessar`;
    form.target = '_blank';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_token';
    input.value = token;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function applyFilters() {
    const params = {
        per_page: form.per_page || 25,
    };
    if (form.pending_only) {
        params.pending_only = 1;
    } else if (form.status) {
        params.status = form.status;
    }
    if (form.product_id) params.product_id = form.product_id;
    if (form.seller_id) params.seller_id = form.seller_id;
    if (form.payment_method) params.payment_method = form.payment_method;
    if (form.date_from) params.date_from = form.date_from;
    if (form.date_to) params.date_to = form.date_to;

    router.get(`/plataforma/clientes/${props.customer.id}`, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    router.get(
        `/plataforma/clientes/${props.customer.id}`,
        { per_page: form.per_page || 25 },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

const summaryCards = computed(() => [
    { label: 'Total de compras', value: String(props.summary?.total_orders ?? 0) },
    { label: 'Aprovadas', value: String(props.summary?.approved_count ?? 0) },
    { label: 'Pendentes', value: String(props.summary?.pending_count ?? 0) },
    { label: 'Canceladas', value: String(props.summary?.cancelled_count ?? 0) },
    { label: 'Reembolsadas', value: String(props.summary?.refunded_count ?? 0) },
    { label: 'MED', value: String(props.summary?.disputed_count ?? 0) },
    { label: 'Valor aprovado', value: formatBRL(props.summary?.approved_total) },
    { label: 'Valor pendente', value: formatBRL(props.summary?.pending_total) },
    { label: 'Ticket médio', value: formatBRL(props.summary?.average_ticket) },
    { label: 'Primeira compra', value: formatDate(props.summary?.first_purchase_at) },
    { label: 'Última compra', value: formatDate(props.summary?.last_purchase_at) },
]);

function requestEmailChange() {
    emailForm.clearErrors();
    const next = String(emailForm.email || '').trim().toLowerCase();
    const current = String(props.customer?.email || '').trim().toLowerCase();
    if (!next || next === current) {
        emailForm.setError('email', 'Informe um e-mail diferente do atual.');
        return;
    }
    if (!canAuthorize.value) return;
    pendingAction.value = 'email';
    stepUpOpen.value = true;
}

function requestPasswordReset() {
    if (!canAuthorize.value || passwordForm.processing) return;
    pendingAction.value = 'password';
    stepUpOpen.value = true;
}

function closeStepUp() {
    if (emailForm.processing || passwordForm.processing) return;
    stepUpOpen.value = false;
    pendingAction.value = '';
}

function onStepUpConfirm(payload) {
    const totp = payload?.totp_code || '';
    const pin = payload?.manual_approval_pin || '';
    const options = {
        preserveScroll: true,
        onError: (errors) => {
            const msg = errors?.totp_code || errors?.email || errors?.manual_approval_pin || 'Não foi possível concluir.';
            window.alert(Array.isArray(msg) ? msg[0] : msg);
        },
        onSuccess: () => {
            stepUpOpen.value = false;
            pendingAction.value = '';
        },
    };
    if (pendingAction.value === 'password') {
        passwordForm.totp_code = totp;
        passwordForm.manual_approval_pin = pin;
        passwordForm.post(`/plataforma/clientes/${props.customer.id}/senha`, options);
        return;
    }
    emailForm.totp_code = totp;
    emailForm.manual_approval_pin = pin;
    emailForm.post(`/plataforma/clientes/${props.customer.id}/email`, options);
}

async function copyIssuedPassword() {
    const value = props.issued_password;
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        passwordCopied.value = true;
        setTimeout(() => {
            passwordCopied.value = false;
        }, 2000);
    } catch {
        window.prompt('Copiar senha:', value);
    }
}

function openAccessDossier(productId = null) {
    accessProductId.value = productId || null;
    accessSidebarOpen.value = true;
}

function closeAccessDossier() {
    accessSidebarOpen.value = false;
    accessProductId.value = null;
}

function formatEnrolledAt(iso) {
    if (!iso) return null;
    try {
        return new Date(iso).toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    } catch (_) {
        return null;
    }
}
</script>

<template>
    <div class="space-y-6">
        <p
            v-if="flashSuccess"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
        >
            {{ flashSuccess }}
        </p>
        <p
            v-if="flashError"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100"
        >
            {{ flashError }}
        </p>
        <div
            v-if="issued_password"
            class="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/40"
        >
            <p class="text-sm font-medium text-amber-950 dark:text-amber-100">Nova senha de acesso</p>
            <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">
                Copie agora. Esta senha não fica salva em texto e some ao sair desta tela.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <code class="rounded-lg bg-white px-3 py-2 font-mono text-base text-zinc-900 dark:bg-zinc-900 dark:text-white">{{ issued_password }}</code>
                <Button type="button" size="sm" variant="secondary" @click="copyIssuedPassword">
                    {{ passwordCopied ? 'Copiada' : 'Copiar' }}
                </Button>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <Link
                href="/plataforma/clientes"
                class="inline-flex items-center gap-2 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
                <ArrowLeft class="h-4 w-4" />
                Voltar para clientes
            </Link>
        </div>

        <header class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ display(customer.name) }}</h1>
                    <p class="mt-1 text-sm text-zinc-500">ID {{ customer.id }} · {{ display(customer.email) }}</p>
                </div>
                <Button type="button" size="sm" variant="secondary" @click="accessAsCustomer">
                    Acessar como cliente
                </Button>
            </div>
            <p
                v-if="customer.is_infoprodutor"
                class="mt-2 inline-flex rounded-lg bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
            >
                Também é infoprodutor
            </p>
        </header>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Dados pessoais</h2>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs text-zinc-500">Nome completo</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ display(customer.name) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">E-mail</dt>
                    <dd class="mt-1 break-all text-sm text-zinc-900 dark:text-white">{{ display(customer.email) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Telefone / WhatsApp</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ display(customer.phone) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">CPF</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ display(customer.document) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Data de nascimento</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatDateOnly(customer.birth_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Cadastro</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatDate(customer.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Última atualização</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatDate(customer.updated_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Status da conta</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ display(customer.account_status_label) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">E-mail verificado</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">
                        {{ customer.email_verified ? 'Sim' : 'Não' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Acesso da conta</h2>
            <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                O e-mail e a senha abaixo são os do login na área de membros e no painel do cliente.
                Os pedidos continuam com o e-mail digitado na compra.
            </p>
            <p
                v-if="customer.is_infoprodutor"
                class="mt-2 text-sm text-amber-800 dark:text-amber-200"
            >
                Esta conta também é de infoprodutor. Trocar o e-mail ou a senha vale para o painel do seller.
            </p>
            <p v-if="!canAuthorize" class="mt-3 text-sm text-red-700 dark:text-red-300">
                Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro → Saques para alterar o acesso.
            </p>
            <form class="mt-4 max-w-xl space-y-3" @submit.prevent="requestEmailChange">
                <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-100" for="customer-login-email">
                    E-mail de login
                </label>
                <input
                    id="customer-login-email"
                    v-model="emailForm.email"
                    type="email"
                    autocomplete="off"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <p v-if="emailForm.errors.email" class="text-sm text-red-600">{{ emailForm.errors.email }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" size="sm" :disabled="!canAuthorize || emailForm.processing">
                        Salvar e-mail
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        :disabled="!canAuthorize || passwordForm.processing"
                        @click="requestPasswordReset"
                    >
                        Gerar nova senha
                    </Button>
                </div>
            </form>
        </section>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :title="stepUpTitle"
            :description="stepUpDescription"
            :require-totp="totpEnabled"
            :require-pin="!totpEnabled"
            :loading="emailForm.processing || passwordForm.processing"
            confirm-label="Confirmar"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Endereço</h2>
            <p v-if="!address?.has_address" class="mt-4 text-sm text-zinc-500">
                Este cliente ainda não possui endereço cadastrado.
            </p>
            <template v-else>
                <p class="mt-4 text-sm text-zinc-800 dark:text-zinc-200">{{ display(address.formatted) }}</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500">CEP</dt>
                        <dd class="mt-1 text-sm">{{ display(address.zip) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Logradouro</dt>
                        <dd class="mt-1 text-sm">{{ display(address.street) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Número</dt>
                        <dd class="mt-1 text-sm">{{ display(address.number) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Complemento</dt>
                        <dd class="mt-1 text-sm">{{ display(address.complement) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Bairro</dt>
                        <dd class="mt-1 text-sm">{{ display(address.neighborhood) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Cidade</dt>
                        <dd class="mt-1 text-sm">{{ display(address.city) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Estado</dt>
                        <dd class="mt-1 text-sm">{{ display(address.state) }}</dd>
                    </div>
                </dl>
            </template>
        </section>

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">Resumo de compras</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="card in summaryCards"
                    :key="card.label"
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <p class="text-xs font-medium text-zinc-500">{{ card.label }}</p>
                    <p class="mt-2 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ card.value }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Compras pendentes</h2>
            <p v-if="!pending_orders.length" class="mt-4 text-sm text-zinc-500">Nenhuma compra pendente.</p>
            <div v-else class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead>
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-3 py-2">ID</th>
                            <th class="px-3 py-2">Produto</th>
                            <th class="px-3 py-2">Valor</th>
                            <th class="px-3 py-2">Criação</th>
                            <th class="px-3 py-2">Pagamento</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Cobrança</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="o in pending_orders" :key="`pending-${o.id}`">
                            <td class="px-3 py-2">{{ o.id }}</td>
                            <td class="px-3 py-2">{{ display(o.product_name) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ formatBRL(o.amount) }}</td>
                            <td class="px-3 py-2">{{ formatDate(o.created_at) }}</td>
                            <td class="px-3 py-2">{{ display(o.payment_method_label) }}</td>
                            <td class="px-3 py-2">{{ display(o.status_label) }}</td>
                            <td class="px-3 py-2">
                                <a
                                    v-if="o.charge_url"
                                    :href="o.charge_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-[var(--color-primary)] hover:underline"
                                >
                                    Abrir link
                                </a>
                                <span v-else>{{ NOT_INFORMED }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Histórico de compras</h2>
            </div>

            <form class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
                <label class="text-xs text-zinc-500">
                    Status
                    <select
                        v-model="form.status"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        :disabled="form.pending_only"
                    >
                        <option value="">Todos</option>
                        <option v-for="(label, key) in status_labels" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>
                <label class="text-xs text-zinc-500">
                    Produto
                    <select
                        v-model="form.product_id"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <option value="">Todos</option>
                        <option v-for="p in filter_options.products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>
                <label class="text-xs text-zinc-500">
                    Seller
                    <select
                        v-model="form.seller_id"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <option value="">Todos</option>
                        <option v-for="s in filter_options.sellers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </select>
                </label>
                <label class="text-xs text-zinc-500">
                    Pagamento
                    <select
                        v-model="form.payment_method"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <option value="">Todos</option>
                        <option v-for="m in filter_options.payment_methods" :key="m" :value="m">{{ m }}</option>
                    </select>
                </label>
                <label class="text-xs text-zinc-500">
                    Data inicial
                    <input
                        v-model="form.date_from"
                        type="date"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    />
                </label>
                <label class="text-xs text-zinc-500">
                    Data final
                    <input
                        v-model="form.date_to"
                        type="date"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    />
                </label>
                <label class="text-xs text-zinc-500">
                    Por página
                    <select
                        v-model="form.per_page"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
                <label class="flex items-end gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                    <input v-model="form.pending_only" type="checkbox" class="rounded border-zinc-300" />
                    Somente pendentes
                </label>
                <div class="flex flex-wrap items-end gap-2 md:col-span-2 lg:col-span-4">
                    <Button type="submit" size="sm">Filtrar</Button>
                    <Button type="button" size="sm" variant="secondary" @click="clearFilters">Limpar</Button>
                </div>
            </form>

            <p v-if="!orderRows.length" class="mt-6 text-sm text-zinc-500">Nenhuma compra encontrada para este cliente.</p>

            <div v-else class="mt-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead>
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-3 py-2">ID</th>
                            <th class="px-3 py-2">Data</th>
                            <th class="px-3 py-2">Produto</th>
                            <th class="px-3 py-2">Seller</th>
                            <th class="px-3 py-2">Pagamento</th>
                            <th class="px-3 py-2">Valor</th>
                            <th class="px-3 py-2">Desconto</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="o in orderRows" :key="o.id">
                            <td class="px-3 py-2">{{ o.id }}</td>
                            <td class="px-3 py-2">{{ formatDate(o.created_at) }}</td>
                            <td class="px-3 py-2">
                                <div>{{ display(o.product_name) }}</div>
                                <ul v-if="o.has_multiple_items" class="mt-1 space-y-0.5 text-xs text-zinc-500">
                                    <li v-for="(item, idx) in o.items" :key="`${o.id}-item-${idx}`">
                                        {{ item.name }} · {{ formatBRL(item.amount) }}
                                    </li>
                                </ul>
                            </td>
                            <td class="px-3 py-2">{{ display(o.seller?.name) }}</td>
                            <td class="px-3 py-2">{{ display(o.payment_method_label) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ formatBRL(o.amount) }}</td>
                            <td class="px-3 py-2 tabular-nums">
                                <template v-if="o.discount_amount != null">{{ formatBRL(o.discount_amount) }}</template>
                                <template v-else-if="o.coupon_code">Cupom {{ o.coupon_code }}</template>
                                <template v-else>{{ NOT_INFORMED }}</template>
                            </td>
                            <td class="px-3 py-2">{{ display(o.status_label) }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-col gap-1 whitespace-nowrap sm:flex-row sm:items-center sm:gap-3">
                                    <a :href="o.transactions_url" class="text-[var(--color-primary)] hover:underline">Ver pedido</a>
                                    <button
                                        v-if="o.has_access_dossier"
                                        type="button"
                                        class="text-left text-[var(--color-primary)] hover:underline"
                                        @click="openAccessDossier(o.product_id)"
                                    >
                                        Ver Acessos
                                    </button>
                                    <a
                                        v-if="o.contestacao_url"
                                        :href="o.contestacao_url"
                                        class="text-left text-[var(--color-primary)] hover:underline"
                                    >
                                        Emitir contestação
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="(orders?.links?.length ?? 0) > 3"
                class="mt-4 flex flex-wrap items-center justify-center gap-2"
                aria-label="Paginação do histórico"
            >
                <a
                    v-for="link in orders.links"
                    :key="link.label + String(link.url)"
                    :href="link.url || undefined"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-disabled="!link.url"
                    :class="[
                        'relative inline-flex min-h-[2.25rem] items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                        link.active
                            ? 'z-10 bg-[var(--color-primary)] text-white'
                            : link.url
                              ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                              : 'cursor-not-allowed text-zinc-400 dark:text-zinc-500',
                    ]"
                    v-text="htmlToText(link.label)"
                    @click.prevent="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                />
            </nav>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Produtos com acesso</h2>
            <p class="mt-2 text-sm text-zinc-500">
                Mesmo dossiê que o seller vê em Alunos: matrícula, progresso e linha do tempo (IP, aulas e downloads).
            </p>
            <p v-if="!access_products.length" class="mt-4 text-sm text-zinc-500">
                Nenhuma matrícula encontrada para este cliente.
            </p>
            <div v-else class="mt-4 space-y-2">
                <button
                    v-for="p in access_products"
                    :key="p.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-left hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
                    @click="openAccessDossier(p.id)"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <Package class="h-4 w-4 shrink-0 text-zinc-500" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ p.name }}</span>
                            <span class="block truncate text-xs text-zinc-500">
                                <template v-if="p.seller?.name">{{ p.seller.name }}</template>
                                <template v-if="formatEnrolledAt(p.enrolled_at)">
                                    <template v-if="p.seller?.name"> · </template>
                                    desde {{ formatEnrolledAt(p.enrolled_at) }}
                                </template>
                            </span>
                        </span>
                    </span>
                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-[var(--color-primary)]">
                        <ClipboardList class="h-3.5 w-3.5" />
                        Ver Acessos
                    </span>
                </button>
            </div>
        </section>

        <CustomerAccessDossierSidebar
            :open="accessSidebarOpen"
            :customer="customer"
            :products="access_products"
            :initial-product-id="accessProductId"
            @close="closeAccessDossier"
        />
    </div>
</template>
