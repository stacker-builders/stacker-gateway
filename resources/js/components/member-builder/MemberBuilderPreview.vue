<script setup>
import { computed } from 'vue';
import { MessageSquare } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import CertificateCanvas from '@/components/member-area/CertificateCanvas.vue';

const props = defineProps({
    mode: { type: String, default: 'area' },
    config: { type: Object, default: () => ({}) },
    productName: { type: String, default: '' },
    // Dados iguais aos da área real (Show.vue) — só exibe o que a área real exibe
    sections: { type: Array, default: () => [] },
    internal_products: { type: Array, default: () => [] },
    progress_percent: { type: Number, default: 0 },
    continue_watching: { type: Object, default: null },
    community_enabled: { type: Boolean, default: false },
    /** @see passa :community-pages no pai → Vue normaliza para communityPages */
    communityPages: { type: Array, default: () => [] },
    certificate_enabled: { type: Boolean, default: false },
    can_issue_certificate: { type: Boolean, default: false },
    platformAppName: { type: String, default: '' },
    certificateEditable: { type: Boolean, default: false },
    certificateSelectedField: { type: String, default: null },
});

const emit = defineEmits(['update:certificate-layout', 'select-certificate-field', 'update:print-format']);

const theme = computed(() => props.config?.theme ?? {});
const hero = computed(() => props.config?.hero ?? {});
const heroDesktopBg = computed(() => hero.value?.image_url_desktop || hero.value?.image_url || null);
const heroMobileBg = computed(() => hero.value?.image_url_mobile || hero.value?.image_url_desktop || hero.value?.image_url || null);
const heroGradient = 'linear-gradient(135deg, var(--ma-primary) 0%, #27272a 100%)';
const headerLogo = computed(() => props.config?.header?.logo_url ?? null);
const sidebar = computed(() => props.config?.sidebar ?? {});
const login = computed(() => props.config?.login ?? {});

const sidebarItems = computed(() => sidebar.value?.items ?? [
    { title: 'Início', icon: 'home', link: '/', open_external: false },
]);

const cssVars = computed(() => ({
    '--ma-primary': theme.value.primary || '#0ea5e9',
    '--ma-bg': theme.value.background || '#18181b',
    '--ma-sidebar-bg': theme.value.sidebar_bg || '#27272a',
    '--ma-text': theme.value.text || '#f8fafc',
}));

const certificatePreview = computed(() => {
    const cert = { ...(props.config?.certificate ?? {}) };
    if (!String(cert.platform_name || '').trim()) {
        cert.platform_name = String(props.platformAppName || '').trim() || 'Getfy';
    }
    if (!String(cert.title || '').trim()) {
        cert.title = props.productName || 'Nome do curso';
    }
    if (cert.primary_color == null || cert.primary_color === '') {
        cert.primary_color = theme.value.primary || '#0ea5e9';
    }
    return cert;
});
</script>

<template>
    <div
        class="h-full min-h-[400px] overflow-auto rounded-xl border border-zinc-300 dark:border-zinc-700"
        :style="{ ...cssVars, backgroundColor: 'var(--ma-bg)', color: 'var(--ma-text)' }"
    >
        <!-- Área: estrutura idêntica à página real; scroll único (hero + conteúdo rolam juntos) -->
        <template v-if="mode === 'area'">
            <div class="flex h-full min-h-[500px] w-full flex-col overflow-auto">
                <!-- Hero + header em overlay -->
                <div class="relative shrink-0">
                    <section
                        class="relative -mx-6 flex min-h-[55vh] items-end justify-start overflow-hidden bg-cover bg-center px-8 pb-10 pt-24 md:min-h-[65vh] md:px-10 md:pb-14 md:pt-28"
                        :style="{ backgroundImage: heroGradient }"
                    >
                        <div
                            v-if="heroDesktopBg"
                            class="absolute inset-0 hidden bg-cover bg-center md:block"
                            :style="{ backgroundImage: `url(${heroDesktopBg})` }"
                        />
                        <div
                            v-if="heroMobileBg"
                            class="absolute inset-0 bg-cover bg-center md:hidden"
                            :style="{ backgroundImage: `url(${heroMobileBg})` }"
                        />
                        <div v-if="hero.overlay" class="pointer-events-none absolute inset-0 bg-black/50" />
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                            :style="{ background: `linear-gradient(to top, var(--ma-bg) 0%, transparent 100%)` }"
                        />
                        <div class="relative z-10 max-w-2xl">
                            <h1 class="text-4xl font-bold text-white drop-shadow-lg md:text-5xl">
                                {{ hero.title || productName }}
                            </h1>
                            <p v-if="hero.subtitle" class="mt-3 text-xl text-white/90 drop-shadow md:text-2xl">
                                {{ hero.subtitle }}
                            </p>
                            <p class="mt-5 text-sm text-white/80 md:text-base">
                                Seu progresso: {{ progress_percent }}%
                            </p>
                        </div>
                    </section>
                    <header
                        class="pointer-events-none absolute left-0 top-0 right-0 z-20 flex h-14 items-center justify-start gap-6 px-4 md:px-6"
                        :style="{ color: 'var(--ma-text)' }"
                    >
                        <span class="flex shrink-0 items-center gap-4">
                            <img
                                v-if="headerLogo"
                                :src="headerLogo"
                                :alt="productName || 'Logo'"
                                class="h-8 w-auto max-w-[180px] object-contain object-left"
                            />
                            <span v-else class="text-lg font-semibold text-white drop-shadow-md">
                                {{ productName || 'Área de Membros' }}
                            </span>
                        </span>
                        <nav class="flex items-center gap-1">
                            <span
                                v-for="(item, i) in sidebarItems"
                                :key="i"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-white/90"
                            >
                                {{ item.title }}
                            </span>
                            <span class="rounded-lg px-3 py-2 text-sm text-white/80">Sair</span>
                        </nav>
                    </header>
                </div>
                <!-- Conteúdo (rola junto com a hero) -->
                <main class="shrink-0 px-6 pb-6 pt-0">
                    <div class="space-y-8">
                            <!-- Continuar assistindo — só existe na área real quando há continue_watching -->
                            <section v-if="continue_watching" class="space-y-4">
                                <h2 class="text-xl font-semibold">Continuar assistindo</h2>
                                <div class="flex max-w-md items-center gap-4 rounded-xl border border-zinc-700 bg-zinc-800/50 p-4 transition hover:bg-zinc-800">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-[var(--ma-primary)]/20 text-[var(--ma-primary)]">
                                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ continue_watching.title }}</p>
                                        <p v-if="continue_watching.module_title" class="text-sm text-zinc-400">{{ continue_watching.module_title }}</p>
                                    </div>
                                </div>
                            </section>

                            <!-- Módulos por seção — igual Show.vue, só aparece se houver sections -->
                            <section v-for="section in sections" :key="section.id" class="space-y-4">
                                <h2 class="text-xl font-semibold">{{ section.title }}</h2>
                                <div class="flex gap-4 overflow-x-auto pb-2">
                                    <div
                                        v-for="mod in section.modules"
                                        :key="mod.id"
                                        class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 transition hover:bg-zinc-800"
                                    >
                                        <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative flex w-full items-center justify-center overflow-hidden bg-zinc-700']">
                                            <img
                                                v-if="(section.section_type ?? 'courses') === 'products' ? (mod.thumbnail || mod.related_product?.image_url) : mod.thumbnail"
                                                :src="(section.section_type ?? 'courses') === 'products' ? (mod.thumbnail || mod.related_product?.image_url) : mod.thumbnail"
                                                :alt="mod.title"
                                                class="absolute inset-0 h-full w-full object-cover"
                                            />
                                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                            <div v-if="mod.show_title_on_cover !== false" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                                <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Loja interna — só existe na área real quando há internal_products -->
                            <section v-if="internal_products?.length" class="space-y-4">
                                <h2 class="text-xl font-semibold">Loja</h2>
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div v-for="ip in internal_products" :key="ip.id" class="overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
                                        <div class="flex aspect-video items-center justify-center bg-zinc-700">
                                            <img v-if="ip.image_url || ip.related_product?.image_url" :src="ip.image_url || ip.related_product?.image_url" :alt="ip.related_product?.name || ip.name" class="h-full w-full object-cover" />
                                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                        <div class="p-3">
                                            <p class="truncate font-medium">{{ ip.related_product?.name ?? ip.name ?? '#' + ip.related_product_id }}</p>
                                            <span class="mt-2 inline-block text-sm text-[var(--ma-primary)]">Acessar</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Comunidade e Certificado — mesmos v-if da área real -->
                            <section class="flex flex-wrap gap-4">
                                <div
                                    v-if="community_enabled"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-700 bg-zinc-800/50 px-4 py-3 transition hover:bg-zinc-800"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                    Comunidade
                                </div>
                                <div
                                    v-if="certificate_enabled && can_issue_certificate"
                                    class="inline-flex items-center gap-2 rounded-xl border border-[var(--ma-primary)] bg-[var(--ma-primary)]/20 px-4 py-3 text-[var(--ma-primary)] transition hover:bg-[var(--ma-primary)]/30"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                                    Emitir certificado
                                </div>
                            </section>
                    </div>
                </main>
            </div>
        </template>

        <!-- Header: preview da barra superior (sem fundo, à esquerda) -->
        <template v-else-if="mode === 'sidebar'">
            <div class="flex h-full min-h-[500px] w-full flex-col">
                <header
                    class="flex h-14 shrink-0 items-center justify-start gap-6 px-4 md:px-6"
                    :style="{ color: 'var(--ma-text)' }"
                >
                    <span class="flex shrink-0 items-center">
                        <img
                            v-if="headerLogo"
                            :src="headerLogo"
                            :alt="productName || 'Logo'"
                            class="h-8 w-auto max-w-[180px] object-contain object-left"
                        />
                        <span v-else class="text-lg font-semibold text-white drop-shadow-md">{{ productName || 'Área de Membros' }}</span>
                    </span>
                    <nav class="flex items-center gap-1">
                        <span v-for="(item, i) in sidebarItems" :key="i" class="rounded-lg px-3 py-2 text-sm font-medium text-white/90">{{ item.title }}</span>
                        <span class="rounded-lg px-3 py-2 text-sm text-white/80">Sair</span>
                    </nav>
                </header>
                <div class="flex flex-1 items-center justify-center p-6 text-sm text-zinc-500" :style="{ backgroundColor: 'var(--ma-bg)' }">
                    Conteúdo principal
                </div>
            </div>
        </template>

        <!-- Certificado — preview do certificado (mesma folha A4/A3 paisagem do PDF) -->
        <template v-else-if="mode === 'certificate'">
            <div class="flex min-h-[500px] flex-col items-center justify-start overflow-auto p-6">
                <div class="mb-3 flex w-full max-w-3xl flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Preview do certificado
                        <span class="ml-1 font-normal normal-case">
                            ({{ (certificatePreview.print_format === 'A3' ? 'A3' : 'A4') }} paisagem)
                        </span>
                    </p>
                    <div
                        v-if="certificateEditable"
                        class="inline-flex rounded-lg border border-zinc-300 bg-white p-0.5 text-xs dark:border-zinc-600 dark:bg-zinc-800"
                    >
                        <button
                            type="button"
                            class="rounded-md px-2.5 py-1 font-medium transition"
                            :class="(certificatePreview.print_format || 'A4') !== 'A3'
                                ? 'bg-sky-500 text-white'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                            @click="emit('update:print-format', 'A4')"
                        >
                            A4
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-2.5 py-1 font-medium transition"
                            :class="certificatePreview.print_format === 'A3'
                                ? 'bg-sky-500 text-white'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                            @click="emit('update:print-format', 'A3')"
                        >
                            A3
                        </button>
                    </div>
                </div>
                <p
                    v-if="certificateEditable && certificatePreview.layout?.custom_positions"
                    class="mb-3 text-center text-xs text-zinc-500 dark:text-zinc-400"
                >
                    Arraste os campos no certificado ou use os controles à esquerda.
                </p>
                <div class="w-full max-w-3xl">
                    <CertificateCanvas
                        mode="preview"
                        :certificate="certificatePreview"
                        recipient-name="Nome do Aluno"
                        :product-name="productName"
                        :print-format="certificatePreview.print_format === 'A3' ? 'A3' : 'A4'"
                        :editable="certificateEditable && !!certificatePreview.layout?.custom_positions"
                        :selected-field="certificateSelectedField"
                        @update:layout="emit('update:certificate-layout', $event)"
                        @select-field="emit('select-certificate-field', $event)"
                    />
                </div>
            </div>
        </template>

        <!-- Login — preview idêntico à tela real (MemberAreaApp/Login.vue) -->
        <template v-else-if="mode === 'login'">
            <div
                class="relative flex min-h-full h-full w-full flex-col items-center justify-center bg-cover bg-center px-4 py-12"
                :style="{
                    '--ma-primary': login.primary_color || '#0ea5e9',
                    backgroundColor: login.background_color || '#18181b',
                    backgroundImage: login.background_image ? `url(${login.background_image})` : 'none',
                }"
            >
                <div v-if="login.background_image" class="pointer-events-none absolute inset-0 bg-black/50" aria-hidden="true" />
                <div class="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900/90 p-8 shadow-2xl backdrop-blur-sm">
                    <div class="flex flex-col items-center text-center">
                        <img
                            v-if="login.logo"
                            :src="login.logo"
                            :alt="login.title || 'Logo'"
                            class="mb-6 h-12 w-auto max-w-[200px] object-contain object-center"
                        />
                        <h1 class="text-2xl font-bold text-white">{{ login.title || 'Área de Membros' }}</h1>
                        <p class="mt-1 text-zinc-400">{{ login.subtitle || 'Entre com seu e-mail e senha' }}</p>
                    </div>
                    <div class="mt-8 space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-300">E-mail</label>
                            <div class="h-12 rounded-xl border border-zinc-600 bg-zinc-800/80" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-300">Senha</label>
                            <div class="relative h-12 rounded-xl border border-zinc-600 bg-zinc-800/80">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-4 w-4 rounded border border-zinc-600 bg-zinc-800/80" />
                            <span class="text-sm text-zinc-400">Lembrar de mim</span>
                        </div>
                        <button
                            type="button"
                            disabled
                            class="flex h-12 w-full items-center justify-center rounded-xl font-semibold text-white"
                            :style="{ backgroundColor: login.primary_color || '#0ea5e9' }"
                        >
                            Entrar
                        </button>
                        <div class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 py-3 text-sm font-medium text-white/90 backdrop-blur-sm">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            Instalar App
                        </div>
                        <p class="mt-2 text-center text-xs text-zinc-500">(Visível apenas em mobile, se o app não estiver instalado)</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- Comunidade — preview idêntico à tela real (MemberAreaApp/Comunidade.vue): páginas criadas, mesmo layout e cores -->
        <template v-else-if="mode === 'comunidade'">
            <div class="flex min-h-[500px] w-full flex-col gap-6 overflow-auto p-6 lg:flex-row lg:gap-8">
                <!-- Sidebar: lista de páginas — igual à área real -->
                <aside class="w-full shrink-0 rounded-2xl border border-zinc-700 bg-zinc-800/50 shadow-lg lg:w-72">
                    <div class="border-b border-zinc-700 p-4">
                        <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                            <MessageSquare class="h-4 w-4" />
                            Páginas
                        </h2>
                    </div>
                    <nav class="p-2">
                        <div
                            v-for="p in communityPages"
                            :key="p.id"
                            class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-zinc-300 transition"
                        >
                            <template v-if="p.icon">
                                <component v-if="getCommunityPageIconComponent(p.icon)" :is="getCommunityPageIconComponent(p.icon)" class="h-5 w-5 shrink-0 text-[var(--ma-primary)]" />
                                <span v-else class="text-xl leading-none">{{ p.icon }}</span>
                            </template>
                            <img v-else-if="p.banner_url" :src="p.banner_url" :alt="p.title" class="h-8 w-10 shrink-0 rounded-lg object-cover" />
                            <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--ma-primary)]/20">
                                <MessageSquare class="h-4 w-4 text-[var(--ma-primary)]" />
                            </span>
                            <span class="truncate">{{ p.title }}</span>
                        </div>
                        <p v-if="!communityPages.length" class="px-3 py-4 text-xs text-zinc-500">Nenhuma página. Crie uma no painel à esquerda.</p>
                    </nav>
                </aside>
                <!-- Conteúdo principal — igual à área real -->
                <main class="min-w-0 flex-1 space-y-8">
                    <div>
                        <h1 class="text-3xl font-bold text-white">Comunidade</h1>
                        <p class="mt-2 text-zinc-400">Escolha uma página ao lado ou acesse diretamente:</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="p in communityPages"
                            :key="'card-' + p.id"
                            class="group relative overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-800/50 shadow-lg transition hover:border-[var(--ma-primary)]/40 hover:shadow-xl"
                        >
                            <div v-if="p.banner_url" class="aspect-[2/1] w-full bg-zinc-700">
                                <img :src="p.banner_url" :alt="p.title" class="h-full w-full object-cover transition group-hover:scale-[1.02]" />
                            </div>
                            <div class="flex items-center gap-4 p-4">
                                <template v-if="p.icon">
                                    <span v-if="getCommunityPageIconComponent(p.icon)" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/20">
                                        <component :is="getCommunityPageIconComponent(p.icon)" class="h-6 w-6 text-[var(--ma-primary)]" />
                                    </span>
                                    <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/20 text-2xl">{{ p.icon }}</span>
                                </template>
                                <img v-else-if="p.banner_url" :src="p.banner_url" :alt="p.title" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
                                <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/20">
                                    <MessageSquare class="h-6 w-6 text-[var(--ma-primary)]" />
                                </div>
                                <span class="font-semibold text-zinc-200 group-hover:text-white">{{ p.title }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-if="!communityPages.length" class="rounded-xl border border-zinc-700 bg-zinc-800/30 p-6 text-center text-sm text-zinc-500">Adicione páginas da comunidade no painel à esquerda para vê-las aqui.</p>
                </main>
            </div>
        </template>
    </div>
</template>
