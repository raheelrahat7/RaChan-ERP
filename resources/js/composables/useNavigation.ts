import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { NAVIGATION, activeHref, visibleNavigation } from '@/lib/navigation';
import type { NavBadge, NavGroup } from '@/lib/navigation';

export function useNavigation() {
    const page = usePage();
    const { currentUrl } = useCurrentUrl();
    const groups = computed<NavGroup[]>(() =>
        visibleNavigation(NAVIGATION, page.props.abilities ?? null),
    );
    const active = computed(() => activeHref(currentUrl.value));

    function badge(key?: NavBadge): number | null {
        if (!key) {
            return null;
        }
        const value = page.props.counts?.[key];

        return typeof value === 'number' && value > 0 ? value : null;
    }

    return { groups, active, badge };
}
