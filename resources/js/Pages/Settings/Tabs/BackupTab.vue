<script setup>
import { computed, ref, watch } from 'vue';
import {
    AlertCircle,
    Clock,
    DatabaseBackup,
    Download,
    HardDrive,
    RefreshCw,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    form: { type: Object, required: true },
    files: { type: Array, default: () => [] },
    status: { type: Object, default: null },
    storage: { type: Object, default: () => ({}) },
});

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';

const destinationProviders = [
    { id: 'local', label: 'Local', description: 'Pasta privada no servidor' },
    { id: 's3', label: 'AWS S3', description: 'Amazon S3', endpoint: '' },
    { id: 'wasabi', label: 'Wasabi', description: 'S3-compatível', endpoint: 'https://s3.wasabisys.com' },
    { id: 'r2', label: 'Cloudflare R2', description: 'S3-compatível', endpoint: 'https://ACCOUNT_ID.r2.cloudflarestorage.com' },
];

const backupFiles = ref([...(props.files || [])]);
const backupStatus = ref(props.status ? { ...props.status } : null);

watch(
    () => props.files,
    (files) => {
        backupFiles.value = [...(files || [])];
    },
);
watch(
    () => props.status,
    (status) => {
        backupStatus.value = status ? { ...status } : null;
    },
);

const runLoading = ref(false);
const downloadLoading = ref(false);
const actionMessage = ref({ status: null, text: '' });

const isRemoteDestination = computed(() => {
    const p = props.form.backup_destination_provider;
    return p === 's3' || p === 'wasabi' || p === 'r2';
});

const destinationLabel = computed(() => {
    const found = destinationProviders.find((p) => p.id === props.form.backup_destination_provider);
    return found?.label || props.storage?.label || 'Local';
});

function onDestinationChange(providerId) {
    props.form.backup_destination_provider = providerId;
    const found = destinationProviders.find((p) => p.id === providerId);
    if (providerId === 'r2') {
        props.form.backup_destination_s3_region = 'auto';
    } else if (!props.form.backup_destination_s3_region || props.form.backup_destination_s3_region === 'auto') {
        props.form.backup_destination_s3_region = 'us-east-1';
    }
    if (found?.endpoint && !(props.form.backup_destination_s3_endpoint || '').trim()) {
        props.form.backup_destination_s3_endpoint = found.endpoint;
    }
    if (providerId === 's3' && (props.form.backup_destination_s3_endpoint || '').includes('r2.cloudflarestorage.com')) {
        props.form.backup_destination_s3_endpoint = '';
    }
}

function humanBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    return `${(n / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function formatWhen(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('pt-BR');
}

async function messageFromAxiosError(e) {
    const data = e?.response?.data;
    if (data instanceof Blob) {
        try {
            const json = JSON.parse(await data.text());
            return json.message || 'Não foi possível gerar o backup.';
        } catch {
            return 'Não foi possível gerar o backup.';
        }
    }
    return data?.message || data?.error || 'Não foi possível gerar o backup.';
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'stacker-backup.sql.gz';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

function filenameFromDisposition(header, fallback) {
    if (!header) return fallback;
    const match = /filename\*?=(?:UTF-8'')?["']?([^";\n]+)/i.exec(header);
    return match ? decodeURIComponent(match[1].replace(/["']/g, '')) : fallback;
}

async function runBackup() {
    runLoading.value = true;
    actionMessage.value = { status: null, text: '' };
    try {
        const res = await window.axios.post('/plataforma/configuracoes/backup/run');
        backupFiles.value = res.data?.files || [];
        backupStatus.value = res.data?.status || backupStatus.value;
        actionMessage.value = {
            status: 'success',
            text: res.data?.message || 'Backup gerado e enviado ao destino configurado.',
        };
    } catch (e) {
        actionMessage.value = { status: 'error', text: await messageFromAxiosError(e) };
    } finally {
        runLoading.value = false;
    }
}

async function downloadNow() {
    downloadLoading.value = true;
    actionMessage.value = { status: null, text: '' };
    try {
        const res = await window.axios.post('/plataforma/configuracoes/backup/download', {}, {
            responseType: 'blob',
        });
        const type = res.headers?.['content-type'] || '';
        if (type.includes('application/json')) {
            const json = JSON.parse(await res.data.text());
            actionMessage.value = { status: 'error', text: json.message || 'Não foi possível baixar o backup.' };
            return;
        }
        const filename = filenameFromDisposition(res.headers?.['content-disposition'], 'stacker-backup.sql.gz');
        saveBlob(res.data, filename);
        actionMessage.value = { status: 'success', text: 'Download iniciado. Guarde o arquivo em um local seguro.' };
    } catch (e) {
        actionMessage.value = { status: 'error', text: await messageFromAxiosError(e) };
    } finally {
        downloadLoading.value = false;
    }
}

function downloadStored(filename) {
    window.location.href = `/plataforma/configuracoes/backup/arquivos/${encodeURIComponent(filename)}`;
}
</script>

<template>
    <div class="space-y-6">
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Backup do banco de dados</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Destino exclusivo para dumps (.sql.gz). Não altera o storage de mídias da plataforma (imagens, vídeos, etc.).
                </p>
            </div>
            <div class="space-y-6 p-6">
                <div>
                    <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Onde guardar o backup</label>
                    <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                        <button
                            v-for="prov in destinationProviders"
                            :key="prov.id"
                            type="button"
                            :class="[
                                'rounded-xl border-2 p-4 text-left transition',
                                form.backup_destination_provider === prov.id
                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 dark:bg-[var(--color-primary)]/10'
                                    : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500',
                            ]"
                            @click="onDestinationChange(prov.id)"
                        >
                            <p class="font-medium text-zinc-900 dark:text-white">{{ prov.label }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ prov.description }}</p>
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Independente da aba Storage. Você pode manter mídias em Local e enviar o backup para R2, S3 ou Wasabi.
                    </p>
                </div>

                <div
                    v-if="isRemoteDestination"
                    class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-600 dark:bg-zinc-800/50"
                >
                    <h3 class="text-sm font-medium text-zinc-900 dark:text-white">Credenciais do destino de backup</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Access Key</label>
                            <input
                                v-model="form.backup_destination_s3_key"
                                type="text"
                                :class="inputClass"
                                placeholder="AKIA..."
                                autocomplete="off"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Secret Key</label>
                            <input
                                v-model="form.backup_destination_s3_secret"
                                type="password"
                                :class="inputClass"
                                :placeholder="form.backup_destination_secret_configured ? '•••••••• (deixe em branco para manter)' : '••••••••'"
                                autocomplete="new-password"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Bucket</label>
                            <input
                                v-model="form.backup_destination_s3_bucket"
                                type="text"
                                :class="inputClass"
                                placeholder="meu-bucket-backups"
                            />
                        </div>
                        <div v-if="form.backup_destination_provider !== 'r2'">
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Region</label>
                            <input
                                v-model="form.backup_destination_s3_region"
                                type="text"
                                :class="inputClass"
                                placeholder="us-east-1"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                Endpoint
                                <span v-if="form.backup_destination_provider === 'r2'" class="text-zinc-400">(obrigatório no R2)</span>
                            </label>
                            <input
                                v-model="form.backup_destination_s3_endpoint"
                                type="text"
                                :class="inputClass"
                                :placeholder="form.backup_destination_provider === 'r2'
                                    ? 'https://ACCOUNT_ID.r2.cloudflarestorage.com'
                                    : 'https://s3.wasabisys.com ou vazio para AWS'"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Pasta / prefixo no bucket</label>
                            <input
                                v-model="form.backup_destination_prefix"
                                type="text"
                                :class="inputClass"
                                placeholder="backups/db"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Caminho dentro do bucket onde os dumps serão gravados (privado). Sem URL pública.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20"
                >
                    <AlertCircle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="text-sm text-amber-800 dark:text-amber-200">
                        <p>
                            Destino <strong>Local</strong>: dumps em
                            <code class="rounded bg-white/70 px-1 text-xs dark:bg-zinc-800">storage/app/private/{{ form.backup_destination_prefix || 'backups/db' }}</code>.
                        </p>
                        <p class="mt-1 text-xs">
                            Isso fica no mesmo servidor. Para cópia externa, escolha R2, S3 ou Wasabi acima (sem mudar a aba Storage).
                        </p>
                        <div class="mt-3">
                            <label class="mb-1 block text-xs font-medium">Pasta local (relativa a storage/app/private)</label>
                            <input
                                v-model="form.backup_destination_prefix"
                                type="text"
                                :class="inputClass"
                                placeholder="backups/db"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/50 dark:bg-emerald-900/20"
                >
                    <HardDrive class="mt-0.5 h-5 w-5 shrink-0 text-emerald-700 dark:text-emerald-300" />
                    <p class="text-sm text-emerald-900 dark:text-emerald-100">
                        Destino atual do backup: <strong>{{ destinationLabel }}</strong>
                        <template v-if="form.backup_destination_prefix"> · pasta <code class="text-xs">{{ form.backup_destination_prefix }}</code></template>.
                        O storage de mídias continua configurado à parte, na aba Storage.
                    </p>
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        :checked="form.backup_enabled === '1'"
                        @change="form.backup_enabled = $event.target.checked ? '1' : '0'"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Backup automático diário</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Exige o cron da plataforma ativo (Configurações → Cron).
                        </span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                            <Clock class="h-3.5 w-3.5" />
                            Horário diário
                        </label>
                        <input
                            v-model="form.backup_daily_at"
                            type="time"
                            :class="inputClass"
                        />
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Recomendado <strong>03:00</strong> — menor fluxo de pagamentos. Fuso America/Sao_Paulo.
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Retenção (dias)</label>
                        <input
                            v-model.number="form.backup_retention_days"
                            type="number"
                            min="1"
                            max="90"
                            :class="inputClass"
                        />
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Dumps mais antigos que este período são apagados do destino ao gerar um novo backup (1–90, padrão 7).
                        </p>
                    </div>
                </div>

                <div
                    v-if="backupStatus?.status"
                    class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-600 dark:bg-zinc-800/80"
                >
                    <p class="font-medium text-zinc-900 dark:text-white">Último backup</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                        <span
                            :class="backupStatus.status === 'ok'
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : 'text-red-600 dark:text-red-400'"
                        >
                            {{ backupStatus.status === 'ok' ? 'Concluído' : 'Falhou' }}
                        </span>
                        · {{ formatWhen(backupStatus.at) }}
                        <template v-if="backupStatus.filename"> · {{ backupStatus.filename }}</template>
                        <template v-if="backupStatus.bytes"> · {{ humanBytes(backupStatus.bytes) }}</template>
                        <template v-if="backupStatus.destination"> · {{ backupStatus.destination }}</template>
                    </p>
                    <p v-if="backupStatus.status === 'failed' && backupStatus.error" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ backupStatus.error }}
                    </p>
                </div>

                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <Button type="button" :disabled="runLoading || downloadLoading" @click="runBackup">
                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': runLoading }" />
                        {{ runLoading ? 'Gerando…' : 'Fazer backup agora' }}
                    </Button>
                    <button
                        type="button"
                        :disabled="runLoading || downloadLoading"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)] sm:w-auto"
                        @click="downloadNow"
                    >
                        <Download class="h-4 w-4" :class="{ 'animate-pulse': downloadLoading }" />
                        {{ downloadLoading ? 'Preparando download…' : 'Baixar agora no computador' }}
                    </button>
                    <p
                        v-if="actionMessage.status"
                        :class="[
                            'text-sm sm:ml-1',
                            actionMessage.status === 'success'
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400',
                        ]"
                    >
                        {{ actionMessage.text }}
                    </p>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Lembre de clicar em <strong>Salvar alterações</strong> depois de mudar destino, horário ou retenção.
                </p>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Arquivos no destino de backup</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Cópias já enviadas para {{ destinationLabel }}. Download pelo painel (privado). A retenção limpa antigos ao gerar novo backup.
                </p>
            </div>
            <div class="p-6">
                <div v-if="backupFiles.length === 0" class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                    <DatabaseBackup class="mt-0.5 h-5 w-5 shrink-0 text-zinc-400" />
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Nenhum backup armazenado ainda. Salve o destino, use “Fazer backup agora” ou aguarde o horário automático.
                    </p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Arquivo</th>
                                <th class="pb-2 pr-4 font-medium">Data</th>
                                <th class="pb-2 pr-4 font-medium">Tamanho</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="file in backupFiles"
                                :key="file.filename"
                                class="border-b border-zinc-100 last:border-0 dark:border-zinc-800"
                            >
                                <td class="py-3 pr-4 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ file.filename }}</td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400">{{ formatWhen(file.last_modified) }}</td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400">{{ humanBytes(file.bytes) }}</td>
                                <td class="py-3 text-right">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-[var(--color-primary)] hover:underline"
                                        @click="downloadStored(file.filename)"
                                    >
                                        <Download class="h-4 w-4" />
                                        Baixar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
