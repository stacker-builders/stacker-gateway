<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { X, ExternalLink, Copy, Check, MessageCircle } from 'lucide-vue-next';
import PixInOutBadges from '@/components/settings/PixInOutBadges.vue';
import { buildWhatsAppUrl } from '@/lib/whatsappUrl';

const props = defineProps({
    open: { type: Boolean, default: false },
    gatewaySlug: { type: String, default: null },
    /** Base path sem barra final, ex.: /plataforma/financeiro/gateways */
    apiBasePath: { type: String, default: '/plataforma/configuracoes/gateways' },
    platformTotpEnabled: { type: Boolean, default: false },
});

const page = usePage();
const apiBase = computed(() => String(props.apiBasePath || '/plataforma/configuracoes/gateways').replace(/\/$/, ''));
const totpEnabled = computed(
    () => Boolean(props.platformTotpEnabled) || Boolean(page.props.auth?.user?.totp_enabled)
);

const emit = defineEmits(['close', 'saved']);

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
            document.querySelector('meta[name="X-XSRF-TOKEN"]')?.getAttribute('content') ||
            '')
        : '';
}

const gateway = ref(null);
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const testMessage = ref(null);
const testSuccess = ref(null);
const lastPixError = ref(null);
const credentialValues = ref({});
const certificateFile = ref(null);
/** Arquivos extras (ex.: OnlyUp mTLS) por campo `credential_keys.key` */
const extraFileUploads = ref({});
const webhookCopied = ref(false);
const disconnecting = ref(false);

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);

function isTotpStepUpError(err) {
    const errors = err?.response?.data?.errors;
    return Boolean(errors?.totp_code);
}

function totpErrorMessage(err) {
    const errors = err?.response?.data?.errors;
    const parts = errors?.totp_code;
    if (Array.isArray(parts) && parts.length) return String(parts[0]);
    return err?.response?.data?.message || 'Informe o código 2FA para continuar.';
}

async function copyWebhookUrl() {
    const url = gateway.value?.webhook_url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        webhookCopied.value = true;
        setTimeout(() => { webhookCopied.value = false; }, 2000);
    } catch {
        webhookCopied.value = false;
    }
}

watch(
    () => [props.open, props.gatewaySlug],
    async ([open, slug]) => {
        if (open && slug) {
            loading.value = true;
            testMessage.value = null;
            lastPixError.value = null;
            webhookCopied.value = false;
            credentialValues.value = {};
            try {
                const { data } = await axios.get(`${apiBase.value}/${encodeURIComponent(slug)}`, {
                    params: { t: Date.now() },
                });
                gateway.value = data;
                lastPixError.value = data.last_pix_error || null;
                const keys = data.credential_keys || [];
                const saved = data.credential_values || {};
                const initial = {};
                for (const k of keys) {
                    if ((k.type || 'text') === 'file') continue;
                    const key = k.key;
                    if (key == null) continue;
                    const v = saved[key];
                    if (k.type === 'boolean') {
                        initial[key] = v === true || v === '1' || v === 'true';
                    } else {
                        initial[key] = v != null && v !== '' ? String(v) : '';
                    }
                }
                if (
                    data?.slug === 'onlyup' &&
                    (!initial.webhook_header_name || String(initial.webhook_header_name).trim() === '')
                ) {
                    initial.webhook_header_name = 'x-onlyup-webhook-token';
                }
                credentialValues.value = { ...initial };
                certificateFile.value = null;
                extraFileUploads.value = {};
            } catch {
                gateway.value = null;
            } finally {
                loading.value = false;
            }
        } else {
            gateway.value = null;
        }
    },
    { immediate: true }
);

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';

function buildTestPayload() {
    const keys = gateway.value?.credential_keys || [];
    const payload = {};
    for (const k of keys) {
        if ((k.type || 'text') === 'file') continue;
        const v = credentialValues.value[k.key];
        if (k.type === 'boolean') {
            payload[k.key] = v === true || v === '1' || v === 'true';
        } else if (v != null && String(v).trim() !== '') {
            payload[k.key] = String(v).trim();
        }
    }
    return payload;
}

function extraFileFieldDefs() {
    const keys = gateway.value?.credential_keys || [];
    const certKey = gateway.value?.certificate_key;
    return keys.filter((k) => (k.type || 'text') === 'file' && k.key !== certKey);
}

function credentialFieldGroups() {
    const keys = gateway.value?.credential_keys || [];
    if (!keys.length) return [];
    const groups = [];
    let current = null;
    for (const field of keys) {
        const groupKey = field.group || '_default';
        const groupLabel = field.group_label || null;
        if (!current || current.key !== groupKey) {
            current = { key: groupKey, label: groupLabel, fields: [] };
            groups.push(current);
        }
        current.fields.push(field);
    }
    return groups;
}

function fileConfiguredLabel(field) {
    const kind = field?.file_kind || '';
    if (kind === 'certificate') return 'Certificado configurado.';
    if (kind === 'private_key') return 'Private key configurada.';
    return 'Arquivo em uso.';
}

const supportContacts = computed(() => {
    const rows = gateway.value?.support_contacts;
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

function secretConfiguredHint(field) {
    if ((field?.type || '') !== 'password') return null;
    const configured = gateway.value?.secrets_configured;
    if (!configured || typeof configured !== 'object') return null;
    if (field.group === 'cash_in' && configured.cash_in) {
        return 'Secret já salvo. Deixe em branco para manter.';
    }
    if (field.group === 'cash_out' && configured.cash_out) {
        return 'Secret já salvo. Deixe em branco para manter.';
    }
    return null;
}

function extraFilesReadyForTest() {
    const ffc = gateway.value?.file_fields_configured || {};
    for (const k of extraFileFieldDefs()) {
        if (extraFileUploads.value[k.key]) continue;
        if (!ffc[k.key]) return false;
    }
    return true;
}

function gatewayUsesMultipartCredentialSave() {
    return extraFileFieldDefs().length > 0;
}

function setExtraFileField(key, file) {
    extraFileUploads.value = { ...extraFileUploads.value, [key]: file || null };
}

async function testConnection() {
    if (!gateway.value?.slug) return;
    const keys = gateway.value.credential_keys || [];
    const certificateKey = gateway.value.certificate_key;
    for (const k of keys) {
        if (k.key === certificateKey) continue;
        if ((k.type || 'text') === 'boolean') continue;
        if ((k.type || 'text') === 'file') continue;
        if (k.optional) {
            if ((k.type || '') === 'password' && gateway.value?.secrets_configured) {
                const secrets = gateway.value.secrets_configured;
                if (k.group === 'cash_in' && secrets.cash_in) continue;
                if (k.group === 'cash_out' && secrets.cash_out) continue;
            }
            continue;
        }
        const v = credentialValues.value[k.key];
        if (v == null || String(v).trim() === '') {
            testMessage.value = 'Preencha as credenciais obrigatórias para testar.';
            testSuccess.value = false;
            return;
        }
    }
    if (certificateKey && !gateway.value.certificate_configured && !certificateFile.value) {
        testMessage.value = 'Envie e salve o certificado P12 antes de testar.';
        testSuccess.value = false;
        return;
    }
    if (gatewayUsesMultipartCredentialSave() && !extraFilesReadyForTest()) {
        testMessage.value = 'Envie e salve todos os arquivos de certificado obrigatórios antes de testar.';
        testSuccess.value = false;
        return;
    }
    const payload = buildTestPayload();
    testing.value = true;
    testMessage.value = null;
    try {
        const { data } = await axios.post(
            `${apiBase.value}/${encodeURIComponent(gateway.value.slug)}/test`,
            payload,
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        testSuccess.value = data.success;
        testMessage.value = data.message || (data.success ? 'Conexão OK.' : 'Falha.');
        if (data.last_pix_error) {
            lastPixError.value = data.last_pix_error;
        } else if (data.success) {
            lastPixError.value = null;
        }
    } catch (err) {
        testSuccess.value = false;
        testMessage.value =
            err.response?.data?.message || 'Erro ao testar conexão.';
    } finally {
        testing.value = false;
    }
}

async function persistCredentials(totpCode = '') {
    if (!gateway.value?.slug) return;
    const keys = gateway.value.credential_keys || [];
    const certificateKey = gateway.value.certificate_key;
    const headersBase = {
        'X-XSRF-TOKEN': getCsrfToken(),
        Accept: 'application/json',
    };

    if (gatewayUsesMultipartCredentialSave()) {
        const form = new FormData();
        // PHP só popula $_FILES em POST — spoof de PUT para multipart
        form.append('_method', 'PUT');
        for (const k of keys) {
            if (k.key === certificateKey || (k.type || 'text') === 'file') continue;
            const v = credentialValues.value[k.key];
            if (k.type === 'boolean') {
                form.append(k.key, v === true || v === '1' || v === 'true' ? '1' : '0');
            } else {
                form.append(k.key, v != null ? String(v).trim() : '');
            }
        }
        for (const fk of extraFileFieldDefs()) {
            const f = extraFileUploads.value[fk.key];
            if (f) form.append(fk.key, f);
        }
        if (totpCode) {
            form.append('totp_code', totpCode);
        }
        const { data } = await axios.post(
            `${apiBase.value}/${encodeURIComponent(gateway.value.slug)}`,
            form,
            { headers: headersBase }
        );
        testSuccess.value = true;
        testMessage.value = data?.message || 'Credenciais salvas.';
        extraFileUploads.value = {};
        emit('saved');
        setTimeout(() => emit('close'), 1500);
        return;
    }

    const payload = {};
    for (const k of keys) {
        if (k.key === certificateKey) continue;
        const v = credentialValues.value[k.key];
        if (k.type === 'boolean') {
            payload[k.key] = v === true || v === '1' || v === 'true';
        } else {
            payload[k.key] = v != null ? String(v).trim() : '';
        }
    }
    if (totpCode) {
        payload.totp_code = totpCode;
    }
    const { data } = await axios.put(
        `${apiBase.value}/${encodeURIComponent(gateway.value.slug)}`,
        payload,
        { headers: { ...headersBase, 'Content-Type': 'application/json' } }
    );

    if (certificateKey && certificateFile.value) {
        const form = new FormData();
        form.append(certificateKey, certificateFile.value);
        await axios.post(
            `${apiBase.value}/${encodeURIComponent(gateway.value.slug)}/certificate`,
            form,
            { headers: headersBase }
        );
    }

    certificateFile.value = null;
    testSuccess.value = true;
    testMessage.value = data?.message || 'Credenciais salvas.';
    emit('saved');
    setTimeout(() => {
        emit('close');
    }, 1500);
}

async function save(totpCode = '') {
    if (!gateway.value?.slug) return;
    if (totpEnabled.value && !totpCode) {
        stepUpOpen.value = true;
        return;
    }

    saving.value = true;
    testMessage.value = null;
    try {
        await persistCredentials(totpCode);
        stepUpOpen.value = false;
    } catch (err) {
        if (isTotpStepUpError(err)) {
            testSuccess.value = false;
            testMessage.value = totpErrorMessage(err);
            stepUpOpen.value = true;
            return;
        }
        testSuccess.value = false;
        const res = err.response?.data;
        let msg = res?.message || 'Erro ao salvar.';
        if (res?.errors && typeof res.errors === 'object') {
            const parts = Object.values(res.errors).flat().filter(Boolean);
            if (parts.length) msg = parts.join(' ');
        }
        testMessage.value = msg;
    } finally {
        saving.value = false;
        stepUpLoading.value = false;
    }
}

async function onStepUpConfirm({ totp_code: totpCode }) {
    stepUpLoading.value = true;
    await save(totpCode || '');
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
}

function close() {
    closeStepUp();
    emit('close');
}

async function disconnectOAuth() {
    const url = gateway.value?.oauth_disconnect_url;
    if (!url) return;
    disconnecting.value = true;
    testMessage.value = null;
    try {
        await axios.post(
            url,
            {},
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        testSuccess.value = true;
        testMessage.value = 'Conta desconectada.';
        emit('saved');
        const slug = gateway.value?.slug;
        if (slug) {
            const { data } = await axios.get(`${apiBase.value}/${encodeURIComponent(slug)}`, {
                params: { t: Date.now() },
            });
            gateway.value = data;
        }
    } catch (err) {
        testSuccess.value = false;
        testMessage.value =
            err.response?.data?.message || 'Não foi possível desconectar.';
    } finally {
        disconnecting.value = false;
    }
}

const hasManualCredentialFields = computed(() => {
    const keys = gateway.value?.credential_keys || [];
    return keys.length > 0 || !!gateway.value?.certificate_key;
});

const canTestConnection = computed(() => {
    if (!gateway.value) return false;
    if (gateway.value.uses_oauth && !gateway.value.oauth_connected) {
        return false;
    }
    return true;
});
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
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ gateway?.name || 'Gateway' }}
                        </h2>
                        <PixInOutBadges :slug="gateway?.slug" />
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

                <div v-if="loading" class="flex flex-1 items-center justify-center p-8">
                    <p class="text-sm text-zinc-500">Carregando...</p>
                </div>

                <div v-else-if="gateway" class="flex flex-1 flex-col overflow-y-auto p-4">
                    <div
                        v-if="gateway.signup_url || supportContacts.length"
                        class="mb-6 space-y-2"
                    >
                        <a
                            v-if="gateway.signup_url"
                            :href="gateway.signup_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-2 rounded-xl border-2 border-[var(--color-primary)] bg-[var(--color-primary)]/10 px-4 py-3 text-sm font-medium text-[var(--color-primary)] transition hover:bg-[var(--color-primary)]/20"
                        >
                            <ExternalLink class="h-4 w-4 shrink-0" />
                            Criar conta no {{ gateway.name }}
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

                    <!-- URL do webhook para configurar no painel do gateway -->
                    <div
                        v-if="gateway.webhook_url"
                        class="mb-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                    >
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            URL do webhook
                        </h3>
                        <p class="mb-2 text-xs text-zinc-600 dark:text-zinc-400">
                            Configure esta URL no painel do {{ gateway.name }} (notificações de pagamento).
                        </p>
                        <div class="flex gap-2">
                            <input
                                :value="gateway.webhook_url"
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
                                {{ webhookCopied ? 'Copiado!' : 'Copiar' }}
                            </button>
                        </div>
                        <p
                            v-if="gateway.webhook_help"
                            class="mt-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400"
                        >
                            {{ gateway.webhook_help }}
                        </p>
                    </div>

                    <h3
                        v-if="hasManualCredentialFields && credentialFieldGroups().length <= 1 && !credentialFieldGroups()[0]?.label"
                        class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                    >
                        Credenciais
                    </h3>
                    <div v-if="hasManualCredentialFields" class="space-y-6">
                        <div
                            v-for="group in credentialFieldGroups()"
                            :key="group.key"
                            class="space-y-4"
                        >
                            <h3
                                v-if="group.label"
                                class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                            >
                                {{ group.label }}
                            </h3>
                            <div
                                v-for="field in group.fields"
                                :key="field.key"
                            >
                            <label
                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                {{ field.label }}
                            </label>
                            <template v-if="field.type === 'file'">
                                <template v-if="field.key === gateway.certificate_key">
                                    <input
                                        type="file"
                                        accept=".p12"
                                        class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-4 file:py-2 file:text-white file:transition dark:text-zinc-400"
                                        @change="certificateFile = $event.target.files?.[0] || null"
                                    />
                                    <p
                                        v-if="gateway.certificate_configured && !certificateFile"
                                        class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span v-if="gateway.certificate_filename" class="font-medium text-zinc-700 dark:text-zinc-300">Em uso: {{ gateway.certificate_filename }}</span>
                                        <template v-else>Certificado já enviado.</template>
                                        <span> Envie novamente para substituir.</span>
                                    </p>
                                </template>
                                <template v-else>
                                    <input
                                        type="file"
                                        :accept="field.accept || '.crt,.pem,.key'"
                                        class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-4 file:py-2 file:text-white file:transition dark:text-zinc-400"
                                        @change="setExtraFileField(field.key, $event.target.files?.[0] || null)"
                                    />
                                    <p
                                        v-if="gateway.file_fields_configured?.[field.key] && !extraFileUploads[field.key]"
                                        class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ fileConfiguredLabel(field) }}</span>
                                        <span> Envie novamente para substituir.</span>
                                    </p>
                                </template>
                            </template>
                            <template v-else-if="field.type === 'boolean'">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        v-model="credentialValues[field.key]"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] dark:border-zinc-600"
                                    />
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Sim (somente para testes)</span>
                                </label>
                            </template>
                            <template v-else-if="field.type === 'textarea'">
                                <textarea
                                    v-model="credentialValues[field.key]"
                                    rows="5"
                                    :placeholder="field.label"
                                    :class="inputClass + ' font-mono text-xs'"
                                    autocomplete="off"
                                    spellcheck="false"
                                />
                            </template>
                            <template v-else>
                                <input
                                    v-model="credentialValues[field.key]"
                                    :type="field.type === 'password' ? 'password' : 'text'"
                                    :placeholder="field.type === 'password' && secretConfiguredHint(field) ? '••••••••' : field.label"
                                    :class="inputClass"
                                    autocomplete="off"
                                />
                                <p
                                    v-if="secretConfiguredHint(field)"
                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ secretConfiguredHint(field) }}
                                </p>
                            </template>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="gateway.uses_oauth"
                        class="mb-6 mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                    >
                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Conectar via OAuth
                        </h3>
                        <div class="mb-4 flex flex-col gap-2">
                            <Button
                                v-if="gateway.oauth_start_url && !gateway.oauth_connected"
                                as="a"
                                :href="gateway.oauth_start_url"
                                variant="primary"
                                class="w-full justify-center text-center no-underline sm:w-full"
                            >
                                <ExternalLink class="h-4 w-4 shrink-0" aria-hidden="true" />
                                Conectar
                            </Button>
                            <p
                                v-if="gateway.oauth_start_url && !gateway.oauth_connected"
                                class="text-center text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                Abre o fluxo de autorização do gateway e, após o consentimento, salva o token no Getfy.
                            </p>
                            <Button
                                v-if="gateway.oauth_disconnect_url && gateway.oauth_connected"
                                type="button"
                                variant="outline"
                                class="w-full justify-center sm:w-auto"
                                :disabled="disconnecting"
                                @click="disconnectOAuth"
                            >
                                {{ disconnecting ? 'Desconectando...' : 'Desconectar' }}
                            </Button>
                        </div>
                        <p
                            v-if="!gateway.oauth_client_configured"
                            class="mb-3 text-xs text-amber-700 dark:text-amber-300"
                        >
                            A identificação do aplicativo OAuth ainda não está configurada neste servidor (variáveis de ambiente ou registro do gateway).
                        </p>
                        <template v-else>
                            <p
                                v-if="gateway.oauth_callback_url && !gateway.oauth_connected"
                                class="mb-3 text-xs text-zinc-600 dark:text-zinc-400"
                            >
                                Na primeira conexão, cadastre a URL de callback no painel do integrador, se solicitado.
                            </p>
                            <p
                                v-if="gateway.oauth_callback_url"
                                class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-400"
                            >
                                URL de redirecionamento (callback)
                            </p>
                            <p
                                v-if="gateway.oauth_callback_url"
                                class="mb-3 break-all rounded-lg bg-white px-2 py-1.5 font-mono text-[11px] text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200"
                            >
                                {{ gateway.oauth_callback_url }}
                            </p>
                        </template>
                        <p
                            v-if="gateway.oauth_connected"
                            class="text-xs text-emerald-700 dark:text-emerald-300"
                        >
                            Conta autorizada. Teste a conexão abaixo ou desconecte.
                        </p>
                    </div>

                    <p
                        v-if="lastPixError && lastPixError !== testMessage"
                        class="mt-4 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300"
                    >
                        Último erro PIX: {{ lastPixError }}
                    </p>
                    <p
                        v-if="testMessage"
                        :class="[
                            'mt-4 rounded-lg px-3 py-2 text-sm',
                            testSuccess
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                        ]"
                    >
                        {{ testMessage }}
                    </p>

                    <div class="mt-6 flex flex-col gap-2">
                        <Button
                            variant="outline"
                            :disabled="testing || !canTestConnection"
                            @click="testConnection"
                        >
                            {{ testing ? 'Testando...' : 'Testar conexão' }}
                        </Button>
                        <Button
                            v-if="hasManualCredentialFields"
                            :disabled="saving || stepUpLoading"
                            @click="() => save()"
                        >
                            {{ saving || stepUpLoading ? 'Salvando...' : 'Salvar' }}
                        </Button>
                    </div>
                </div>
            </aside>
        </div>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :loading="stepUpLoading || saving"
            :require-totp="true"
            title="Confirmar alteração do adquirente"
            description="Informe o código 2FA para salvar as credenciais deste adquirente."
            confirm-label="Salvar"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </Teleport>
</template>
