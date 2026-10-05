<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import {
    calendarWeeks,
    dayKey,
    followUpsByDay,
} from '@/lib/crm-activity-views';
import type { FollowUp } from '@/lib/crm-activity-views';

const props = defineProps<{ followUps: FollowUp[] }>();
const { t, locale } = useLocale();
const cursor = ref(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1),
);
const weeks = computed(() =>
    calendarWeeks(cursor.value.getFullYear(), cursor.value.getMonth()),
);
const byDay = computed(() => followUpsByDay(props.followUps));
const todayKey = dayKey(new Date());
const title = computed(() =>
    new Intl.DateTimeFormat(locale.value, {
        month: 'long',
        year: 'numeric',
    }).format(cursor.value),
);
const weekdays = computed(() =>
    weeks.value[0].map((day) =>
        new Intl.DateTimeFormat(locale.value, { weekday: 'short' }).format(day),
    ),
);
function shift(months: number): void {
    cursor.value = new Date(
        cursor.value.getFullYear(),
        cursor.value.getMonth() + months,
        1,
    );
}
function inMonth(day: Date): boolean {
    return day.getMonth() === cursor.value.getMonth();
}
</script>

<template>
    <section class="space-y-3" :aria-label="t('Follow-up calendar')">
        <div class="flex items-center gap-2">
            <Button
                type="button"
                size="icon"
                variant="outline"
                :aria-label="t('Previous month')"
                @click="shift(-1)"
                ><ChevronLeft class="size-4" aria-hidden="true"
            /></Button>
            <h2 class="font-display min-w-40 text-center text-xl">
                {{ title }}
            </h2>
            <Button
                type="button"
                size="icon"
                variant="outline"
                :aria-label="t('Next month')"
                @click="shift(1)"
                ><ChevronRight class="size-4" aria-hidden="true"
            /></Button>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="
                    cursor = new Date(
                        new Date().getFullYear(),
                        new Date().getMonth(),
                        1,
                    )
                "
                >{{ t('Today') }}</Button
            >
        </div>
        <div class="overflow-x-auto">
            <div
                class="bg-border grid min-w-[44rem] grid-cols-7 gap-px rounded-md border text-sm"
                role="grid"
            >
                <div
                    v-for="name in weekdays"
                    :key="name"
                    class="bg-muted px-2 py-1 text-xs font-medium"
                    role="columnheader"
                >
                    {{ name }}
                </div>
                <template v-for="week in weeks" :key="dayKey(week[0])">
                    <div
                        v-for="day in week"
                        :key="dayKey(day)"
                        role="gridcell"
                        class="bg-card min-h-24 space-y-1 p-1.5"
                        :class="{ 'opacity-50': !inMonth(day) }"
                    >
                        <p
                            class="text-xs tabular-nums"
                            :class="
                                dayKey(day) === todayKey
                                    ? 'bg-primary text-primary-foreground inline-block rounded-full px-1.5 font-medium'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ day.getDate() }}
                        </p>
                        <Link
                            v-for="item in (byDay.get(dayKey(day)) ?? []).slice(
                                0,
                                3,
                            )"
                            :key="item.id"
                            :href="
                                item.lead ? `/crm/leads/${item.lead.id}` : '#'
                            "
                            class="block truncate rounded px-1 py-0.5 text-xs hover:underline"
                            :class="
                                item.is_overdue
                                    ? 'bg-destructive/15 text-destructive'
                                    : 'bg-primary/10 text-primary'
                            "
                            :title="`${item.type}${item.notes ? ' · ' + item.notes : ''}`"
                            >{{ item.lead?.first_name }}
                            {{ item.lead?.last_name }} · {{ item.type }}</Link
                        >
                        <p
                            v-if="(byDay.get(dayKey(day)) ?? []).length > 3"
                            class="text-muted-foreground text-xs"
                        >
                            +{{ (byDay.get(dayKey(day)) ?? []).length - 3 }}
                            {{ t('more') }}
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>
