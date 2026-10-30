import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useMemberAreaAdminPreview() {
    const page = usePage();
    const isAdminPreview = computed(() => page.props?.member_area_admin_preview?.active === true);

    return { isAdminPreview };
}
