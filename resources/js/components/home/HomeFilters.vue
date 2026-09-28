<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { HomeFilters } from '@/types/home';

const props = defineProps<{ filters?: HomeFilters }>();
const { t } = useLocale();
const period = computed(() => props.filters?.period ?? 'month');
const purpose = computed(() => props.filters?.purpose ?? 'all');

const HOME_DATA = [
    'filters',
    'kpis',
    'trend',
    'commission_split',
    'top_agents',
    'lead_pipeline',
    'lead_sources',
    'deal_pipeline',
    'cost_centres',
    'insights',
];

function apply(change: Record<string, string | number | null>): void {
    const query: Record<string, string | number> = {};
    const merged: Record<string, string | number | null> = {
        period: period.value,
        purpose: purpose.value,
        company: props.filters?.company_id ?? null,
        branch: props.filters?.branch_id ?? null,
        ...change,
    };
    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }
    router.get('/dashboard', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: HOME_DATA,
    });
}

const groups = computed(() => [
    {
        key: 'period',
        label: 'Period',
        current: period.value,
        options: [
            { value: 'month', label: 'Month' },
            { value: 'quarter', label: 'Quarter' },
            { value: 'year', label: 'Year' },
        ],
    },
    {
        key: 'purpose',
        label: 'Sale or rent',
        current: purpose.value,
        options: [
            { value: 'all', label: 'Sale & rent' },
            { value: 'sale', label: 'Sale' },
            { value: 'rent', label: 'Rent' },
        ],
    },
]);

const branches = computed(() =>
    (props.filters?.options.branches ?? []).filter(
        (branch) =>
            !props.filters?.company_id ||
            branch.company_id === props.filters.company_id,
    ),
);

function selected(event: Event): string | null {
    return (event.target as HTMLSelectElement).value || null;
}
</script>

<template>
    <div v-if="filters" class="flex flex-wrap items-center gap-2">
        <div
            v-for="group in groups"
            :key="group.key"
            class="bg-card inline-flex rounded-md border p-0.5"
            role="group"
            :aria-label="t(group.label)"
        >
            <button
                v-for="option in group.options"
                :key="option.value"
                type="button"
                :aria-pressed="group.current === option.value"
                :class="[
                    'rounded px-3 py-1 text-xs font-medium transition-colors',
                    group.current === option.value
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="apply({ [group.key]: option.value })"
            >
                {{ t(option.label) }}
            </button>
        </div>
        <select
            v-if="filters.options.companies.length"
            class="h-8 rounded-md border px-2 text-xs"
            :value="filters.company_id ?? ''"
            :aria-label="t('Company')"
            @change="apply({ company: selected($event), branch: null })"
        >
            <option value="">{{ t('All companies') }}</option>
            <option
                v-for="company in filters.options.companies"
                :key="company.id"
                :value="company.id"
            >
                {{ company.name }}
            </option>
        </select>
        <select
            v-if="branches.length"
            class="h-8 rounded-md border px-2 text-xs"
            :value="filters.branch_id ?? ''"
            :aria-label="t('Branch')"
            @change="apply({ branch: selected($event) })"
        >
            <option value="">{{ t('All branches') }}</option>
            <option
                v-for="branch in branches"
                :key="branch.id"
                :value="branch.id"
            >
                {{ branch.name }}
            </option>
        </select>
        <span v-if="filters.range" class="text-muted-foreground ms-auto text-xs"
            >{{ filters.range.from }} → {{ filters.range.to }}</span
        >
    </div>
</template>
