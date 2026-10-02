import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    CircleDollarSign,
    Wallet,
    Package,
    Users,
    BarChart3,
    Puzzle,
    Cable,
    Settings,
    Plug,
    Wrench,
    FileCode,
    Box,
    Mail,
    CodeXml,
    Store,
    TicketPercent,
    GraduationCap,
    UserPlus,
    Handshake,
    Gift,
    Truck,
    Zap,
    Activity,
    Code2,
} from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const iconMap = {
    Puzzle,
    Plug,
    Wrench,
    Settings,
    FileCode,
    Box,
    LayoutDashboard,
    Package,
    Users,
    BarChart3,
    Mail,
    CodeXml,
    Code2,
};

/** Hover only no desktop — no mobile o primeiro toque vira hover e compete com a navegação. */
export const panelNavPrefetch = 'hover';

export function navPrefetch(isMobile) {
    return isMobile ? false : panelNavPrefetch;
}

export function useAppSidebarNav() {
    const page = usePage();
    const { t } = useI18n();

    const homeHref = computed(() => (page.props.customer_panel ? '/painel-cliente' : '/dashboard'));

    const appSettings = () => page.props.appSettings ?? {};
    const appName = () => appSettings().app_name || 'Infoprodutor';
    const hasLogoFull = () => !!(appSettings().app_logo || appSettings().app_logo_dark);
    const hasLogoIcon = () => !!(appSettings().app_logo_icon || appSettings().app_logo_icon_dark);

    const pluginNavItems = computed(() => {
        const raw = page.props.pluginNavItems ?? [];
        return raw.map((item) => ({
            name: item.name,
            href: item.href,
            icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
        }));
    });

    const perms = computed(() => page.props.auth?.permissions ?? {});
    const canView = (key) => {
        const role = page.props.auth?.user?.role;
        if (role === 'admin' || role === 'infoprodutor') return true;
        return !!perms.value?.[key];
    };

    const navItems = computed(() => {
        if (page.props.customer_panel) {
            return [{ name: 'Minhas compras', href: '/painel-cliente', icon: Package }];
        }

        const items = [];

        if (canView('dashboard.view')) {
            items.push({ name: t('sidebar.dashboard', 'Dashboard'), href: '/dashboard', icon: LayoutDashboard });
        }

        if (canView('vendas.view')) {
            items.push({
                name: t('sidebar.sales', 'Vendas'),
                href: '/vendas',
                icon: CircleDollarSign,
            });
            if (page.props.api_pix_enabled_effective) {
                items.push({
                    name: t('sidebar.api_transactions', 'Transações API'),
                    href: '/transacoes-api',
                    icon: Code2,
                });
            }
        }

        if (canView('relatorios.view')) {
            items.push({ name: t('sidebar.reports', 'Relatórios'), href: '/relatorios', icon: BarChart3 });
        }

        if (canView('metrics.view')) {
            items.push({
                name: t('sidebar.metrics', 'Métricas e Tracking'),
                href: '/metricas',
                icon: Activity,
            });
        }

        items.push({ separator: true });

        if (canView('produtos.view')) {
            items.push({ name: t('sidebar.products', 'Produtos'), href: '/produtos', icon: Package });
            if (page.props.pixgo_enabled_effective && canView('pixgo.view')) {
                items.push({
                    name: page.props.pixgo_sidebar_label || 'PixGO',
                    href: '/pixgo',
                    icon: Zap,
                });
            }
            items.push({
                name: t('sidebar.affiliate_showcase', 'Vitrine'),
                href: '/produtos/vitrine-afiliacao',
                icon: Store,
            });
            if (page.props.physical_products_enabled_effective) {
                items.push({ name: t('sidebar.shipping', 'Taxas e frete'), href: '/frete', icon: Truck });
            }
            items.push({ name: t('sidebar.coupons', 'Cupons'), href: '/produtos/cupons', icon: TicketPercent });
            items.push({ name: t('sidebar.students', 'Alunos'), href: '/produtos/alunos', icon: GraduationCap });
            items.push({ name: t('sidebar.affiliates_menu', 'Afiliados'), href: '/afiliados', icon: UserPlus });
            items.push({ name: t('sidebar.coproduction', 'Co-produção'), href: '/coproducao', icon: Handshake });
        }

        items.push({ separator: true });

        if (canView('integracoes.view') && page.props.seller_integrations_any_visible !== false) {
            items.push({ name: t('sidebar.integrations', 'Integrações'), href: '/integracoes', icon: Cable });
        }

        if ((page.props.auth?.user?.role === 'admin' || page.props.auth?.user?.role === 'infoprodutor') && pluginNavItems.value.length) {
            items.push(...pluginNavItems.value);
        }

        if (page.props.auth?.user?.role === 'infoprodutor' || canView('equipe.manage')) {
            items.push({ name: t('sidebar.team', 'Equipe'), href: '/usuarios/equipe', icon: Users });
        }

        if (canView('api_pagamentos.view') && page.props.api_pix_enabled_effective) {
            items.push({
                name: 'API PIX',
                href: '/aplicacoes-api',
                icon: CodeXml,
                badge: 'pix-in-out',
            });
        }

        if (canView('financeiro.view')) {
            items.push({ name: t('sidebar.finance', 'Financeiro'), href: '/financeiro', icon: Wallet });
        }

        if (page.props.referral_program?.enabled) {
            items.push({
                name: t('sidebar.referral', 'Indique e Ganhe'),
                href: '/indique-e-ganhe',
                icon: Gift,
            });
        }

        if (!page.props.customer_panel) {
            items.push({ separator: true });
            items.push({ pwaInstall: true });
        }

        return items;
    });

    function isActive(href) {
        const url = page.url.split('?')[0];
        if (href === '/frete') return url === '/frete' || url.startsWith('/frete/');
        if (href === '/dashboard') return url === '/dashboard' || url === '/';
        if (href === '/vendas') {
            return url === '/vendas' || url.startsWith('/vendas/');
        }
        if (href === '/transacoes-api') {
            return url === '/transacoes-api' || url.startsWith('/transacoes-api/');
        }
        if (href === '/produtos/vitrine-afiliacao') {
            return url === '/produtos/vitrine-afiliacao' || url.startsWith('/produtos/vitrine-afiliacao/');
        }
        if (href === '/produtos/cupons') {
            return url.startsWith('/produtos/cupons');
        }
        if (href === '/produtos/alunos') {
            return url.startsWith('/produtos/alunos');
        }
        if (href === '/afiliados') {
            return url === '/afiliados' || url.startsWith('/afiliados/');
        }
        if (href === '/coproducao') {
            return url === '/coproducao' || url.startsWith('/coproducao/') || url.startsWith('/produtos/coproducao');
        }
        if (href === '/produtos') {
            if (
                url.startsWith('/produtos/cupons') ||
                url.startsWith('/produtos/alunos') ||
                url.startsWith('/produtos/coproducao') ||
                url.startsWith('/produtos/vitrine-afiliacao') ||
                url.startsWith('/produtos/afiliados') ||
                /\/painel-afiliado/.test(url)
            ) {
                return false;
            }
            return url === '/produtos' || url.startsWith('/produtos/');
        }
        if (href === '/aplicacoes-api') {
            return url === '/aplicacoes-api' || url.startsWith('/aplicacoes-api/');
        }
        if (href === '/pixgo') {
            return url === '/pixgo' || url.startsWith('/pixgo/');
        }
        return url === href || url.startsWith(href + '/');
    }

    return {
        page,
        homeHref,
        appSettings,
        appName,
        hasLogoFull,
        hasLogoIcon,
        navItems,
        isActive,
    };
}
