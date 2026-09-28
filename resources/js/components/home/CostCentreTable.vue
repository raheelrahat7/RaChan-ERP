<script setup lang="ts">
import { Building2, MapPin } from '@lucide/vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import type { CostCentreRow } from '@/types/home';

defineProps<{ rows: CostCentreRow[] | null }>();
const { t } = useLocale();
const { number } = useFormat();

function margin(row: CostCentreRow): number {
    if (row.revenue) {
        return Math.round((row.profit / row.revenue) * 100);
    }

    return row.profit < 0 ? -100 : 0;
}
</script>

<template>
    <HomePanel
        title="Cost centre profitability"
        subtitle="Company → branch → department → cost centre"
    >
        <ComingSoon
            v-if="!rows || rows.length === 0"
            reason="Available once companies, branches and cost centres are set up."
        />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-label border-b">
                        <th class="py-2.5 pe-3 text-start font-medium">
                            {{ t('Company / branch') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Department') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Cost centre') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Revenue') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Expenses') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Profit') }}
                        </th>
                        <th class="ps-3 text-start font-medium">
                            {{ t('Margin') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, i) in rows"
                        :key="i"
                        :class="[
                            'border-b last:border-b-0',
                            row.level === 0 ? 'font-semibold' : '',
                        ]"
                    >
                        <td
                            :class="[
                                'py-3 pe-3',
                                row.level === 1
                                    ? 'ps-5'
                                    : row.level === 2
                                      ? 'text-muted-foreground ps-10'
                                      : '',
                            ]"
                        >
                            <Building2
                                v-if="row.level === 0"
                                class="text-faint me-1.5 inline size-3.5"
                            />
                            <MapPin
                                v-else-if="row.level === 1"
                                class="text-faint me-1.5 inline size-3.5"
                            />
                            {{
                                row.level === 0
                                    ? row.company
                                    : row.level === 1
                                      ? row.branch
                                      : ''
                            }}
                        </td>
                        <td class="px-3">{{ row.department ?? '' }}</td>
                        <td class="px-3">{{ row.cost_centre ?? '' }}</td>
                        <td class="px-3 text-end tabular-nums">
                            {{ number(row.revenue) }}
                        </td>
                        <td class="px-3 text-end tabular-nums">
                            {{ number(row.expense) }}
                        </td>
                        <td
                            :class="[
                                'px-3 text-end tabular-nums',
                                row.profit < 0
                                    ? 'text-destructive'
                                    : row.profit > 0
                                      ? 'text-success'
                                      : '',
                            ]"
                        >
                            {{ number(row.profit) }}
                        </td>
                        <td class="ps-3 whitespace-nowrap">
                            <span class="text-muted-foreground text-xs"
                                >{{ margin(row) }}%</span
                            >
                            <span
                                class="bg-surface-sunken relative ms-2 inline-block h-1 w-16 overflow-hidden rounded align-middle"
                            >
                                <span
                                    :class="[
                                        'absolute inset-y-0 start-0 rounded',
                                        margin(row) < 0
                                            ? 'bg-destructive'
                                            : 'bg-success',
                                    ]"
                                    :style="{
                                        width: `${Math.min(100, Math.abs(margin(row)))}%`,
                                    }"
                                />
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </HomePanel>
</template>
