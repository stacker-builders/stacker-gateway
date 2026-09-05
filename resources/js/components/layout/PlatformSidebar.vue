<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Users,
    UserCircle2,
    Shield,
    PanelRightOpen,
    X,
    Settings,
    Mail,
    MessageSquare,
    Smartphone,
    Wallet,
    Activity,
    ArrowLeftRight,
    AlertTriangle,
    Banknote,
    CircleDollarSign,
    Code2,
    Trophy,
    BadgeCheck,
    Package,
    Puzzle,
    Plug,
    Gift,
    ScrollText,
    Logs,
    ContactRound,
    ChartNoAxesCombined,
    Webhook,
} from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';

const page = usePage();
const { isExpanded, isMobileOpen, toggleSidebar, isMobile, closeMobileSidebarIfOpen } = useSidebar();

const showText = () => isExpanded.value || isMobileOpen.value;

const appSettings = () => page.props.appSettings ?? {};
const appName = () => appSettings().app_name || 'Stacker';
const hasLogoFull = () => !!(appSettings().app_logo || appSettings().app_logo_dark);
const hasLogoIcon = () => !!(appSettings().app_logo_icon || appSettings().app_logo_icon_dark);

/** Classes da imagem conforme Configurações → Personalização (mesma origem do painel vendedor). */
const headerLogoImgClass = computed(() => {
    const expanded = isExpanded.value || isMobileOpen.value;
    return expanded
        ? 'h-10 max-w-[200px] object-contain object-left'
        : 'h-8 max-w-[56px] object-contain object-center';
});

const headerIconImgClass = computed(() => {
    const expanded = isExpanded.value || isMobileOpen.value;
    return expanded ? 'h-9 w-9 shrink-0 object-contain' : 'h-8 w-8 shrink-0 object-contain';
});

const iconMap = {
    Puzzle,
    Plug,
};

const sidebarBadges = computed(() => page.props.platform_admin_sidebar_badges ?? {});

function badgeCount(key) {
    const n = Number(sidebarBadges.value?.[key] ?? 0);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function badgeLabel(count) {
    return count > 99 ? '99+' : String(count);
}

/**
 * Ordem pensada no fluxo do operador:
 * visão geral → pessoas → vendas/risco → dinheiro → crescimento → sistema (ops/saúde).
 */
const navGroupsCore = [
    {
        id: 'geral',
        label: null,
        items: [
            { name: 'Dashboard', href: '/plataforma/dashboard', icon: LayoutDashboard },
        ],
    },
    {
        id: 'pessoas',
        label: 'Pessoas',
        items: [
            { name: 'Infoprodutores', href: '/plataforma/usuarios', icon: Users },
            { name: 'Gerentes de Conta', href: '/plataforma/gerentes-conta', icon: ContactRound },
            { name: 'Clientes', href: '/plataforma/clientes', icon: UserCircle2 },
            { name: 'Verificações KYC', href: '/plataforma/verificacoes-kyc', icon: BadgeCheck, badgeKey: 'kyc' },
        ],
    },
    {
        id: 'operacoes',
        label: 'Operações',
        items: [
            { name: 'Transações', href: '/plataforma/transacoes', icon: ArrowLeftRight, badgeKey: 'transacoes' },
            { name: 'Transações API', href: '/plataforma/transacoes-api', icon: Code2 },
            { name: 'Disputas MED', href: '/plataforma/disputas', icon: AlertTriangle, badgeKey: 'disputas' },
            { name: 'Produtos', href: '/plataforma/produtos', icon: Package },
        ],
    },
    {
        id: 'financeiro',
        label: 'Financeiro',
        items: [
            { name: 'Saques', href: '/plataforma/saques', icon: Banknote, badgeKey: 'saques' },
            { name: 'Saldo', href: '/plataforma/saldo', icon: CircleDollarSign },
            { name: 'Financeiro', href: '/plataforma/financeiro', icon: Wallet, badgeKey: 'financeiro' },
        ],
    },
    {
        id: 'crescimento',
        label: 'Crescimento',
        items: [
            { name: 'Métricas e Tracking', href: '/plataforma/metricas', icon: ChartNoAxesCombined },
            { name: 'Conquistas', href: '/plataforma/conquistas', icon: Trophy },
            { name: 'Indique e Ganhe', href: '/plataforma/indique-e-ganhe', icon: Gift },
            { name: 'E-mail Marketing', href: '/plataforma/email-marketing', icon: Mail },
            { name: 'IntegraX SMS', href: '/plataforma/integrax', icon: MessageSquare },
            { name: 'App', href: '/plataforma/app', icon: Smartphone },
        ],
    },
    {
        id: 'sistema',
        label: 'Sistema',
        items: [
            { name: 'Configurações', href: '/plataforma/configuracoes', icon: Settings },
            { name: 'Saúde de Pagamentos', href: '/plataforma/ops/saude-pagamentos', icon: Activity },
            { name: 'Saúde UTMify', href: '/plataforma/ops/saude-utmify', icon: Activity },
            { name: 'Webhooks', href: '/plataforma/webhooks', icon: Webhook },
            { name: 'Log Infoprodutor', href: '/plataforma/log-infoprodutor', icon: ScrollText },
            { name: 'Log sistema', href: '/plataforma/log-sistema', icon: Logs },
            { name: 'Plugins', href: '/plataforma/gerenciar-plugins', icon: Puzzle },
        ],
    },
];

const pluginNavItems = computed(() => {
    const raw = page.props.pluginNavItems ?? [];
    return raw.map((item) => ({
        name: item.name,
        href: item.href,
        icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
    }));
});

const navGroups = computed(() => {
    const groups = navGroupsCore.map((g) => ({ ...g, items: [...g.items] }));
    const plugins = pluginNavItems.value;
    if (plugins.length) {
        const sistema = groups.find((g) => g.id === 'sistema');
        if (sistema) {
            sistema.items = [...sistema.items, ...plugins];
        } else {
            groups.push({ id: 'plugins', label: 'Plugins', items: plugins });
        }
    }
    return groups;
});

function isNavItemActive(href) {
    if (href.includes('?')) {
        const url = page.url.split('#')[0];

        return url === href;
    }

    return isActive(href);
}

function isActive(href) {
    const url = page.url.split('?')[0];
    if (href === '/plataforma/dashboard') {
        return url === '/plataforma/dashboard';
    }
    if (href === '/plataforma/transacoes-api') {
        return url === '/plataforma/transacoes-api' || url.startsWith('/plataforma/transacoes-api/');
    }
    if (href === '/plataforma/transacoes') {
        return (url === '/plataforma/transacoes' || url.startsWith('/plataforma/transacoes/'))
            && !url.startsWith('/plataforma/transacoes-api');
    }
    if (href === '/plataforma/disputas') {
        return url === '/plataforma/disputas' || url.startsWith('/plataforma/disputas/');
    }
    if (href === '/plataforma/clientes') {
        return url === '/plataforma/clientes' || url.startsWith('/plataforma/clientes/');
    }
    if (href === '/plataforma/produtos') {
        return url === '/plataforma/produtos' || url.startsWith('/plataforma/produtos/');
    }
    if (href === '/plataforma/verificacoes-kyc') {
        return url === '/plataforma/verificacoes-kyc' || url.startsWith('/plataforma/verificacoes-kyc/');
    }
    if (href === '/plataforma/saques') {
        return url === '/plataforma/saques' || url.startsWith('/plataforma/saques/');
    }
    if (href === '/plataforma/saldo') {
        return url === '/plataforma/saldo' || url.startsWith('/plataforma/saldo/');
    }
    if (href === '/plataforma/usuarios') {
        return url === '/plataforma/usuarios' || (url.startsWith('/plataforma/usuarios/') && !url.startsWith('/plataforma/usuarios/create'));
    }
    if (href === '/plataforma/gerentes-conta') {
        return url === '/plataforma/gerentes-conta' || url.startsWith('/plataforma/gerentes-conta/');
    }
    if (href === '/plataforma/financeiro') {
        return url === '/plataforma/financeiro' || url.startsWith('/plataforma/financeiro/');
    }
    if (href === '/plataforma/indique-e-ganhe') {
        return url === '/plataforma/indique-e-ganhe' || url.startsWith('/plataforma/indique-e-ganhe/');
    }
    if (href === '/plataforma/ops/saude-pagamentos') {
        return url === '/plataforma/ops/saude-pagamentos' || url.startsWith('/plataforma/ops/saude-pagamentos/');
    }
    if (href === '/plataforma/ops/saude-utmify') {
        return url === '/plataforma/ops/saude-utmify' || url.startsWith('/plataforma/ops/saude-utmify/');
    }
    if (href === '/plataforma/log-infoprodutor') {
        return url === '/plataforma/log-infoprodutor' || url.startsWith('/plataforma/log-infoprodutor/');
    }
    if (href === '/plataforma/log-sistema') {
        return url === '/plataforma/log-sistema' || url.startsWith('/plataforma/log-sistema/');
    }
    if (href === '/plataforma/webhooks') {
        return url === '/plataforma/webhooks' || url.startsWith('/plataforma/webhooks/');
    }
    if (href === '/plataforma/metricas') {
        return url === '/plataforma/metricas' || url.startsWith('/plataforma/metricas/');
    }
    if (href === '/plataforma/configuracoes') {
        return url === '/plataforma/configuracoes' || url.startsWith('/plataforma/configuracoes/');
    }
    if (href === '/plataforma/gerenciar-plugins') {
        return url === '/plataforma/gerenciar-plugins' || url.startsWith('/plataforma/gerenciar-plugins/');
    }
    if (href === '/plataforma/app') {
        return url === '/plataforma/app' || url.startsWith('/plataforma/app/');
    }
    if (href === '/plataforma/conquistas') {
        return url === '/plataforma/conquistas' || url.startsWith('/plataforma/conquistas/');
    }
    if (href === '/plataforma/email-marketing') {
        return url === '/plataforma/email-marketing' || url.startsWith('/plataforma/email-marketing/');
    }
    if (href === '/plataforma/integrax') {
        return url === '/plataforma/integrax' || url.startsWith('/plataforma/integrax/');
    }
    return url === href || url.startsWith(`${href}/`);
}

const linkActive =
    'bg-[var(--color-primary)]/10 text-zinc-900 dark:text-white';
const linkInactive =
    'text-zinc-600 hover:bg-zinc-200/60 dark:text-zinc-400 dark:hover:bg-zinc-800/70';
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-[99999] flex h-screen w-[260px] flex-col border-r border-zinc-200 bg-zinc-100 transition-all duration-300 ease-in-out dark:border-zinc-800 dark:bg-zinc-900"
        :class="[
            {
                'translate-x-0 shadow-xl': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'pointer-events-none': isMobile && !isMobileOpen,
                'lg:translate-x-0': true,
                'lg:w-[260px]': isExpanded || isMobileOpen,
                'lg:w-[72px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-zinc-200 px-3 dark:border-zinc-800">
            <Link
                href="/plataforma/dashboard"
                class="flex min-w-0 flex-1 cursor-pointer touch-manipulation items-center overflow-hidden py-1.5"
                :class="showText() ? 'justify-start gap-2' : 'justify-center'"
                @click="closeMobileSidebarIfOpen"
            >
                <template v-if="hasLogoFull()">
                    <img
                        v-if="appSettings().app_logo"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        :class="[headerLogoImgClass, appSettings().app_logo_dark ? 'dark:hidden' : '']"
                    />
                    <img
                        v-if="appSettings().app_logo_dark"
                        :src="appSettings().app_logo_dark"
                        :alt="appName()"
                        :class="['hidden dark:block', headerLogoImgClass]"
                    />
                </template>
                <template v-else-if="hasLogoIcon()">
                    <img
                        v-if="appSettings().app_logo_icon"
                        :src="appSettings().app_logo_icon"
                        :alt="appName()"
                        :class="[headerIconImgClass, appSettings().app_logo_icon_dark ? 'dark:hidden' : '']"
                    />
                    <img
                        v-if="appSettings().app_logo_icon_dark"
                        :src="appSettings().app_logo_icon_dark"
                        :alt="appName()"
                        :class="['hidden dark:block', headerIconImgClass]"
                    />
                </template>
                <Shield v-else class="h-8 w-8 shrink-0 text-[var(--color-primary)]" />
            </Link>
            <button
                v-if="isMobile"
                type="button"
                class="flex h-9 w-9 shrink-0 touch-manipulation cursor-pointer select-none items-center justify-center text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-800"
                aria-label="Fechar menu"
                @click="toggleSidebar"
            >
                <X class="h-5 w-5" />
            </button>
            <button
                v-else
                type="button"
                class="hidden h-9 w-9 shrink-0 items-center justify-center text-zinc-500 hover:bg-zinc-200/80 lg:flex dark:hover:bg-zinc-800"
                :title="isExpanded ? 'Recolher' : 'Expandir'"
                aria-label="Alternar largura do menu"
                @click="toggleSidebar"
            >
                <PanelRightOpen class="h-5 w-5" />
            </button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-0 py-3">
            <div v-for="group in navGroups" :key="group.id">
                <p
                    v-if="group.label && showText()"
                    class="mb-1.5 px-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500"
                >
                    {{ group.label }}
                </p>
                <div
                    v-else-if="group.label && !showText()"
                    class="mx-auto mb-1.5 h-px w-6 bg-zinc-300 dark:bg-zinc-700"
                    aria-hidden="true"
                />
                <div>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="relative flex cursor-pointer touch-manipulation items-center gap-3 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="[
                            isNavItemActive(item.href) ? linkActive : linkInactive,
                            showText() ? '' : 'justify-center px-0',
                        ]"
                        :title="!showText() ? item.name : undefined"
                        @click="closeMobileSidebarIfOpen"
                    >
                        <span
                            v-if="isNavItemActive(item.href)"
                            class="absolute inset-y-0 left-0 w-0.5 bg-[var(--color-primary)]"
                            aria-hidden="true"
                        />
                        <span class="relative shrink-0">
                            <component
                                :is="item.icon"
                                class="h-5 w-5"
                                :class="isNavItemActive(item.href) ? 'text-[var(--color-primary)]' : ''"
                            />
                            <span
                                v-if="item.badgeKey && badgeCount(item.badgeKey) > 0 && !showText()"
                                class="absolute -right-1.5 -top-1.5 inline-flex min-h-[1rem] min-w-[1rem] items-center justify-center rounded-full bg-orange-500 px-1 text-[9px] font-bold leading-none text-white"
                                :aria-label="`${badgeCount(item.badgeKey)} pendente(s)`"
                            >
                                {{ badgeCount(item.badgeKey) > 9 ? '9+' : badgeLabel(badgeCount(item.badgeKey)) }}
                            </span>
                        </span>
                        <span v-show="showText()" class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                        <span
                            v-if="item.badgeKey && badgeCount(item.badgeKey) > 0 && showText()"
                            class="ml-auto inline-flex min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-orange-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
                            :aria-label="`${badgeCount(item.badgeKey)} pendente(s)`"
                        >
                            {{ badgeLabel(badgeCount(item.badgeKey)) }}
                        </span>
                    </Link>
                </div>
            </div>
        </nav>

        <div
            v-show="showText()"
            class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-500"
        >
            Operador do gateway
        </div>
    </aside>
</template>
