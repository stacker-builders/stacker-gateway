<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import PixInOutBadges from '@/components/settings/PixInOutBadges.vue';
import { X, Copy, Check, RefreshCw, ChevronLeft, Plus, ExternalLink, MessageCircle } from 'lucide-vue-next';
import { buildWhatsAppUrl } from '@/lib/whatsappUrl';

const props = defineProps({
    open: { type: Boolean, default: false },
    gateway: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    credentialKeysProp: { type: Array, default: () => [] },
    platformTotpEnabled: { type: Boolean, default: false },
    grossUpPayoutFee: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);
const page = usePage();
const totpEnabled = computed(
    () => Boolean(props.platformTotpEnabled) || Boolean(page.props.auth?.user?.totp_enabled)
);

const API_BASE = '/plataforma/financeiro/cajupay-contas';

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
            document.querySelector('meta[name="X-XSRF-TOKEN"]')?.getAttribute('content') ||
            '')
        : '';
}

const view = ref('list');
const selectedAccountId = ref(null);
const isNewAccount = ref(false);

const account = ref(null);
const credentialKeys = ref([]);
const credentialValues = ref({});
const webhookUrl = ref('');
const webhookHelp = ref('');
const webhookSetupStatus = ref({});
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const rotating = ref(false);
const testMessage = ref(null);
const testSuccess = ref(null);
const webhookCopied = ref(false);
const accountName = ref('');
const isEnabled = ref(true);
const grossUpPayoutFee = ref(Boolean(props.grossUpPayoutFee));
const savingGrossUp = ref(false);
const grossUpMessage = ref(null);
const grossUpSuccess = ref(null);

watch(
    () => props.grossUpPayoutFee,
    (value) => {
        grossUpPayoutFee.value = Boolean(value);
    }
);

async function saveGrossUp(enabled) {
    const previous = grossUpPayoutFee.value;
    grossUpPayoutFee.value = Boolean(enabled);
    savingGrossUp.value = true;
    grossUpMessage.value = null;
    grossUpSuccess.value = null;
    try {
        const { data } = await axios.put(
            '/plataforma/financeiro/cajupay/gross-up-payout-fee',
            { enabled: Boolean(enabled) },
            { headers: { 'X-CSRF-TOKEN': getCsrfToken() } }
        );
        grossUpPayoutFee.value = Boolean(data?.enabled);
        grossUpSuccess.value = true;
        grossUpMessage.value = grossUpPayoutFee.value
            ? 'Ligado: o saque vai com valor a mais para cobrir a taxa.'
            : 'Desligado: o saque envia o valor líquido exato.';
    } catch (e) {
        grossUpPayoutFee.value = previous;
        grossUpSuccess.value = false;
        grossUpMessage.value = e.response?.data?.message || 'Não foi possível salvar o interruptor.';
    } finally {
        savingGrossUp.value = false;
    }
}

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);

function isTotpStepUpError(err) {
    return Boolean(err?.response?.data?.errors?.totp_code);
}

function totpErrorMessage(err) {
    const parts = err?.response?.data?.errors?.totp_code;
    if (Array.isArray(parts) && parts.length) return String(parts[0]);
    return err?.response?.data?.message || 'Informe o código 2FA para continuar.';
}

const gatewayName = computed(() => props.gateway?.name || 'CajuPay');

const supportContacts = computed(() => {
    const rows = props.gateway?.support_contacts;
    if (!Array.isArray(rows)) {
        return [];
    }

    return rows
        .map((contact) => {
            const href = buildWhatsAppUrl(contact?.whatsapp);
            if (!href) {
                return null;
            }
            const name = String(contact?.name ?? '').trim();
            const role = String(contact?.role ?? '').trim();

            return { name, role, href };
        })
        .filter(Boolean);
});

const imageUrl = computed(() => {
    const img = props.gateway?.image;
    if (!img) return null;
    if (img.startsWith('http') || img.startsWith('//')) return img;
    return `/${img.replace(/^\//, '')}`;
});

const webhookOk = computed(() => {
    return account.value?.has_webhook_secret && (webhookSetupStatus.value?.has_enabled_endpoint ?? account.value?.webhook_has_enabled_endpoint);
});

function resetEditState() {
    account.value = null;
    credentialValues.value = {};
    credentialKeys.value = props.credentialKeysProp || [];
    webhookUrl.value = '';
    webhookHelp.value = '';
    webhookSetupStatus.value = {};
    accountName.value = '';
    isEnabled.value = true;
    testMessage.value = null;
    testSuccess.value = null;
}

function openList() {
    view.value = 'list';
    selectedAccountId.value = null;
    isNewAccount.value = false;
    resetEditState();
}

function openEdit(accountId = null, isNew = false) {
    view.value = 'edit';
    selectedAccountId.value = accountId;
    isNewAccount.value = isNew;
    resetEditState();
    if (isNew) {
        accountName.value = 'Nova conta CajuPay';
    }
    loadAccount();
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            openList();
        }
    }
);

async function loadAccount() {
    if (!selectedAccountId.value) {
        credentialKeys.value = props.credentialKeysProp || [];
        return;
    }
    loading.value = true;
    testMessage.value = null;
    try {
        const { data } = await axios.get(`${API_BASE}/${selectedAccountId.value}`, { params: { t: Date.now() } });
        account.value = data.account;
        credentialKeys.value = data.credential_keys || [];
        webhookUrl.value = data.webhook_url || '';
        webhookHelp.value = data.webhook_help || '';
        webhookSetupStatus.value = data.webhook_setup_status || {};
        accountName.value = data.account?.name || '';
        isEnabled.value = data.account?.is_enabled !== false;
        const saved = data.credential_values || {};
        const initial = {};
        for (const k of credentialKeys.value) {
            const key = k.key;
            if (!key || k.type === 'file') continue;
            initial[key] = saved[key] != null ? String(saved[key]) : '';
        }
        credentialValues.value = initial;
    } finally {
        loading.value = false;
    }
}

async function copyWebhookUrl() {
    if (!webhookUrl.value) return;
    try {
        await navigator.clipboard.writeText(webhookUrl.value);
        webhookCopied.value = true;
        setTimeout(() => { webhookCopied.value = false; }, 2000);
    } catch {
        webhookCopied.value = false;
    }
}

async function ensureAccountExists() {
    if (selectedAccountId.value) return selectedAccountId.value;
    const { data } = await axios.post(API_BASE, { name: accountName.value || 'Nova conta CajuPay' }, {
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    });
    selectedAccountId.value = data.account?.id;
    isNewAccount.value = false;
    return data.account?.id;
}

async function persistAccount(totpCode = '') {
    const id = await ensureAccountExists();
    const payload = {
        name: accountName.value,
        is_enabled: isEnabled.value,
        ...credentialValues.value,
    };
    if (totpCode) {
        payload.totp_code = totpCode;
    }
    const { data } = await axios.put(`${API_BASE}/${id}`, payload, {
        headers: { 'X-CSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
    });
    account.value = data.account;
    if (data.webhook_warning) {
        testMessage.value = data.webhook_warning;
        testSuccess.value = false;
    } else {
        testSuccess.value = true;
        testMessage.value = data.message || 'Conta salva.';
    }
    emit('saved');
}

async function saveAccount(totpCode = '') {
    if (typeof totpCode !== 'string') {
        totpCode = '';
    }
    if (totpEnabled.value && !totpCode) {
        stepUpOpen.value = true;
        return;
    }

    saving.value = true;
    testMessage.value = null;
    try {
        await persistAccount(totpCode);
        stepUpOpen.value = false;
    } catch (e) {
        if (isTotpStepUpError(e)) {
            testMessage.value = totpErrorMessage(e);
            testSuccess.value = false;
            stepUpOpen.value = true;
            return;
        }
        testMessage.value = e.response?.data?.message || e.message || 'Erro ao salvar.';
        testSuccess.value = false;
    } finally {
        saving.value = false;
        stepUpLoading.value = false;
    }
}

async function onStepUpConfirm({ totp_code: totpCode }) {
    stepUpLoading.value = true;
    await saveAccount(totpCode || '');
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
}

async function testConnection() {
    testing.value = true;
    testMessage.value = null;
    try {
        const id = await ensureAccountExists();
        const payload = { name: accountName.value, ...credentialValues.value };
        const { data } = await axios.post(`${API_BASE}/${id}/test`, payload, {
            headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        });
        testSuccess.value = data.success;
        testMessage.value = data.message + (data.webhook_warning ? ` — ${data.webhook_warning}` : '');
        if (data.account) account.value = data.account;
        if (data.success) emit('saved');
    } catch (e) {
        testSuccess.value = false;
        testMessage.value = e.response?.data?.message || 'Falha no teste.';
    } finally {
        testing.value = false;
    }
}

async function rotateWebhookSecret() {
    if (!selectedAccountId.value) return;
    rotating.value = true;
    testMessage.value = null;
    try {
        const { data } = await axios.post(`${API_BASE}/${selectedAccountId.value}/rotate-webhook-secret`, {}, {
            headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        });
        testSuccess.value = true;
        testMessage.value = data.message;
        if (data.account) account.value = data.account;
        emit('saved');
    } catch (e) {
        testSuccess.value = false;
        testMessage.value = e.response?.data?.message || 'Falha ao rotacionar secret.';
    } finally {
        rotating.value = false;
    }
}

async function setDefault(accountId) {
    await axios.patch(`${API_BASE}/${accountId}/default`, {}, {
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    });
    emit('saved');
}

async function deleteAccount(accountId) {
    if (!confirm('Excluir esta conta CajuPay? Infoprodutores vinculados devem ser reatribuídos antes.')) return;
    try {
        await axios.delete(`${API_BASE}/${accountId}`, {
            headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        });
        emit('saved');
    } catch (e) {
        alert(e.response?.data?.message || e.response?.data?.errors?.account?.[0] || 'Não foi possível excluir.');
    }
}

function close() {
    closeStepUp();
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl border-l border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            >
                <div
                    class="flex items-center justify-between gap-2 rounded-tl-2xl border-b border-zinc-200 px-4 py-4 dark:border-zinc-700"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <button
                            v-if="view === 'edit'"
                            type="button"
                            class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            aria-label="Voltar"
                            @click="openList"
                        >
                            <ChevronLeft class="h-5 w-5" />
                        </button>
                        <img
                            v-if="imageUrl && view === 'list'"
                            :src="imageUrl"
                            :alt="gatewayName"
                            class="h-8 w-auto max-w-[120px] object-contain"
                        />
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                {{ view === 'list' ? gatewayName : (isNewAccount ? 'Nova conta' : (accountName || 'Conta')) }}
                            </h2>
                            <PixInOutBadges v-if="view === 'list'" slug="cajupay" />
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <!-- Lista de contas -->
                <div v-if="view === 'list'" class="flex flex-1 flex-col overflow-y-auto p-4">
                    <div class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                        <p class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">
                            Taxa de saque da Caju
                        </p>
                        <Toggle
                            :model-value="grossUpPayoutFee"
                            :disabled="savingGrossUp"
                            label="Enviar valor a mais para cobrir a taxa"
                            @update:model-value="saveGrossUp"
                        />
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Desligado, a Caju recebe o valor líquido exato e a taxa sai do saldo da carteira.
                            Ligado, o saque inclui a taxa fixa e o percentual configurados na conta.
                        </p>
                        <p
                            v-if="grossUpMessage"
                            class="mt-2 text-xs"
                            :class="grossUpSuccess ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'"
                        >
                            {{ grossUpMessage }}
                        </p>
                    </div>

                    <div
                        v-if="gateway?.signup_url || supportContacts.length"
                        class="mb-4 space-y-2"
                    >
                        <a
                            v-if="gateway?.signup_url"
                            :href="gateway.signup_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-2 rounded-xl border-2 border-[var(--color-primary)] bg-[var(--color-primary)]/10 px-4 py-3 text-sm font-medium text-[var(--color-primary)] transition hover:bg-[var(--color-primary)]/20"
                        >
                            <ExternalLink class="h-4 w-4 shrink-0" />
                            Criar conta no {{ gatewayName }}
                        </a>
                        <a
                            v-for="contact in supportContacts"
                            :key="contact.href"
                            :href="contact.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-2 rounded-xl border-2 border-emerald-600/40 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-200 dark:hover:bg-emerald-950/70"
                        >
                            <MessageCircle class="h-4 w-4 shrink-0" />
                            <span class="min-w-0">
                                WhatsApp {{ contact.name || 'suporte' }}
                                <span v-if="contact.role" class="font-normal text-emerald-700/80 dark:text-emerald-300/80">
                                    — {{ contact.role }}
                                </span>
                            </span>
                        </a>
                    </div>

                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Gerencie múltiplas contas com credenciais e webhooks independentes. A conta padrão é usada quando o infoprodutor não tiver vínculo individual.
                    </p>

                    <div v-if="accounts?.length" class="space-y-2">
                        <div
                            v-for="acc in accounts"
                            :key="acc.id"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-600"
                        >
                            <div class="min-w-0">
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ acc.name }}
                                    <span
                                        v-if="acc.is_default"
                                        class="ml-2 rounded-full bg-[var(--color-primary)]/10 px-2 py-0.5 text-xs font-medium text-[var(--color-primary)]"
                                    >
                                        Padrão
                                    </span>
                                </p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ acc.is_connected ? 'Conectada' : 'Não conectada' }}
                                    · Webhook {{ acc.has_webhook_secret ? 'OK' : 'pendente' }}
                                    · {{ acc.merchants_count }} infoprodutor(es)
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button v-if="!acc.is_default" type="button" variant="outline" size="sm" @click="setDefault(acc.id)">
                                    Padrão
                                </Button>
                                <Button type="button" variant="outline" size="sm" @click="openEdit(acc.id)">
                                    Configurar
                                </Button>
                                <Button
                                    v-if="!acc.is_default"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="text-red-600"
                                    @click="deleteAccount(acc.id)"
                                >
                                    Excluir
                                </Button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-zinc-300 py-6 text-center text-sm text-zinc-500 dark:border-zinc-600">
                        Nenhuma conta cadastrada.
                    </p>

                    <Button type="button" class="mt-4 w-full" @click="openEdit(null, true)">
                        <Plus class="mr-2 h-4 w-4" />
                        Adicionar conta
                    </Button>
                </div>

                <!-- Edição de conta -->
                <template v-else>
                    <div v-if="loading" class="flex flex-1 items-center justify-center p-8">
                        <p class="text-sm text-zinc-500">Carregando...</p>
                    </div>

                    <div v-else class="flex flex-1 flex-col overflow-y-auto p-4">
                        <div class="mb-4">
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome da conta</label>
                            <input
                                v-model="accountName"
                                type="text"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="Ex.: Conta principal"
                            />
                        </div>

                        <label class="mb-4 flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <input v-model="isEnabled" type="checkbox" class="rounded border-zinc-300" />
                            Conta ativa para roteamento
                        </label>

                        <div v-if="webhookUrl" class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Webhook
                            </h3>
                            <p v-if="webhookHelp" class="mb-2 text-xs text-zinc-600 dark:text-zinc-400">
                                {{ webhookHelp }}
                            </p>
                            <div class="flex gap-2">
                                <input
                                    :value="webhookUrl"
                                    type="text"
                                    readonly
                                    class="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                                <button
                                    type="button"
                                    class="flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    @click="copyWebhookUrl"
                                >
                                    <Check v-if="webhookCopied" class="h-4 w-4 text-emerald-600" />
                                    <Copy v-else class="h-4 w-4" />
                                </button>
                            </div>
                            <p
                                class="mt-2 text-xs"
                                :class="webhookOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                            >
                                {{ webhookOk ? 'Webhook configurado' : 'Salve ou teste a conexão para registrar o webhook' }}
                            </p>
                            <button
                                v-if="selectedAccountId && !webhookOk"
                                type="button"
                                class="mt-2 inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline"
                                :disabled="rotating"
                                @click="rotateWebhookSecret"
                            >
                                <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': rotating }" />
                                Rotacionar secret do webhook
                            </button>
                        </div>

                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Credenciais
                        </h3>
                        <div class="space-y-4">
                            <div v-for="k in credentialKeys" :key="k.key" class="space-y-1">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ k.label }}
                                    <span v-if="k.optional" class="font-normal text-zinc-400">(opcional)</span>
                                </label>
                                <input
                                    v-if="k.type !== 'boolean'"
                                    v-model="credentialValues[k.key]"
                                    :type="k.type === 'password' ? 'password' : 'text'"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                    :placeholder="k.type === 'password' && credentialValues[k.key] === '********' ? '••••••••' : ''"
                                />
                            </div>
                        </div>

                        <p
                            v-if="testMessage"
                            class="mt-4 rounded-lg px-3 py-2 text-sm"
                            :class="testSuccess ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100'"
                        >
                            {{ testMessage }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 border-t border-zinc-200 p-4 dark:border-zinc-700">
                        <Button type="button" variant="outline" :disabled="testing || saving" @click="testConnection">
                            {{ testing ? 'Testando…' : 'Testar conexão' }}
                        </Button>
                        <Button type="button" class="flex-1" :disabled="saving || stepUpLoading" @click="() => saveAccount()">
                            {{ saving || stepUpLoading ? 'Salvando…' : 'Salvar' }}
                        </Button>
                    </div>
                </template>
            </aside>
        </div>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :loading="stepUpLoading || saving"
            :require-totp="true"
            title="Confirmar alteração CajuPay"
            description="Informe o código 2FA para salvar as credenciais desta conta."
            confirm-label="Salvar"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </Teleport>
</template>
