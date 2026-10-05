<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DateText from '@/components/DateText.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { activityColumns, BUCKET_ORDER } from '@/lib/crm-activity-views';
import type { BucketKey, FollowUp } from '@/lib/crm-activity-views';

const props = defineProps<{
    followUps: FollowUp[];
    leads: {
        id: number;
        first_name: string;
        last_name: string;
        converted: boolean;
    }[];
    canComplete: boolean;
}>();
const emit = defineEmits<{ complete: [id: number] }>();

const { t } = useLocale();
const columns = computed(() =>
    activityColumns(props.followUps, props.leads, new Date()),
);
const labels: Record<BucketKey, string> = {
    overdue: 'Overdue',
    today: 'Due today',
    this_week: 'Due this week',
    next_week: 'Due next week',
    idle: 'Idle',
    later: 'Due later',
};
const tones: Record<BucketKey, string> = {
    overdue: 'bg-destructive text-white',
    today: 'bg-primary text-primary-foreground',
    this_week: 'bg-primary/80 text-primary-foreground',
    next_week: 'bg-primary/60 text-primary-foreground',
    idle: 'bg-muted-foreground/70 text-white',
    later: 'bg-primary/40 text-foreground',
};
</script>

<template>
    <div
        role="region"
        tabindex="0"
        :aria-label="t('Activities by due date')"
        class="focus-visible:outline-ring flex gap-2 overflow-x-auto rounded-md pb-4 focus-visible:outline-2"
    >
        <section
            v-for="key in BUCKET_ORDER"
            :key="key"
            class="bg-muted/40 flex max-h-[calc(100vh-14rem)] min-h-48 w-72 shrink-0 flex-col rounded-md"
            :aria-labelledby="`activity-col-${key}`"
        >
            <h3
                :id="`activity-col-${key}`"
                class="flex items-center justify-between px-4 py-2 text-sm font-medium"
                :class="tones[key]"
            >
                <span>{{ t(labels[key]) }}</span>
                <span class="tabular-nums">{{ columns[key].length }}</span>
            </h3>
            <div class="flex-1 space-y-2 overflow-y-auto p-2">
                <p
                    v-if="!columns[key].length"
                    class="text-muted-foreground rounded-md border border-dashed p-4 text-sm"
                >
                    {{ t('Nothing here.') }}
                </p>
                <article
                    v-for="item in columns[key]"
                    :key="item.followUp?.id ?? `lead-${item.lead?.id}`"
                    class="bg-card space-y-1 rounded-md border p-3 text-sm shadow-xs"
                >
                    <template v-if="item.followUp">
                        <p class="font-medium">
                            <Link
                                v-if="item.followUp.lead"
                                :href="`/crm/leads/${item.followUp.lead.id}`"
                                class="hover:underline"
                                >{{ item.followUp.lead.first_name }}
                                {{ item.followUp.lead.last_name }}</Link
                            >
                        </p>
                        <p class="text-muted-foreground text-xs capitalize">
                            {{ item.followUp.type }} ·
                            <DateText :value="item.followUp.due_at" with-time />
                        </p>
                        <p v-if="item.followUp.notes" class="text-xs">
                            {{ item.followUp.notes }}
                        </p>
                        <Button
                            v-if="canComplete"
                            size="sm"
                            variant="outline"
                            @click="emit('complete', item.followUp.id)"
                            >{{ t('Complete') }}</Button
                        >
                    </template>
                    <template v-else-if="item.lead">
                        <p class="font-medium">
                            <Link
                                :href="`/crm/leads/${item.lead.id}`"
                                class="hover:underline"
                                >{{ item.lead.name }}</Link
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ t('No planned activity') }}
                        </p>
                    </template>
                </article>
            </div>
        </section>
    </div>
</template>
