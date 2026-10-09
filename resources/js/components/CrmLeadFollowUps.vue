<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { whenText } from '@/lib/crm-schedule';
import { followUps, groupItems } from '@/lib/crm-timeline';
import type { FollowUpGroup } from '@/lib/crm-timeline';

type Activity = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    completed_at: string | null;
    creator?: { name: string } | null;
};

const props = defineProps<{ leadId: number; activities: Activity[] }>();
const emit = defineEmits<{
    open: [tab: 'tasks' | 'meetings' | 'activities'];
}>();
const { t } = useLocale();

const tasks = ref<
    {
        id: number;
        title: string;
        status: string;
        due_at: string | null;
        assignee_name: string | null;
    }[]
>([]);
const meetings = ref<
    {
        id: number;
        title: string;
        type: string;
        status: string;
        starts_at: string | null;
        assignee_name: string | null;
    }[]
>([]);
const loaded = ref(false);
const loadError = ref('');

const items = computed(() =>
    followUps(
        {
            activities: props.activities,
            tasks: tasks.value,
            meetings: meetings.value,
        },
        Date.now(),
    ),
);
const groups: { key: FollowUpGroup; title: string }[] = [
    { key: 'overdue', title: 'Overdue' },
    { key: 'upcoming', title: 'Upcoming' },
    { key: 'undated', title: 'No date' },
    { key: 'done', title: 'Done' },
];
const kindLabel = { activity: 'Activity', task: 'Task', meeting: 'Meeting' };
const tabFor = {
    activity: 'activities',
    task: 'tasks',
    meeting: 'meetings',
} as const;

onMounted(async () => {
    try {
        const [taskData, meetingData] = await Promise.all([
            apiJson<{ tasks: { data: typeof tasks.value } }>(
                `/crm/leads/${props.leadId}/tasks?page=1`,
            ),
            apiJson<{ meetings: { data: typeof meetings.value } }>(
                `/crm/leads/${props.leadId}/meetings?page=1`,
            ),
        ]);
        tasks.value = taskData.tasks.data;
        meetings.value = meetingData.meetings.data;
    } catch {
        loadError.value = t('Could not load the follow-up timeline.');
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <div class="space-y-6">
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <p
            v-else-if="loaded && !items.length"
            class="text-muted-foreground text-sm"
        >
            {{
                t(
                    'Nothing to follow up. Tasks, meetings and dated activities appear here.',
                )
            }}
        </p>
        <template v-for="group in groups" :key="group.key">
            <Card v-if="groupItems(items, group.key).length">
                <CardHeader
                    ><CardTitle
                        class="text-eyebrow"
                        :class="group.key === 'overdue' && 'text-destructive'"
                        >{{ t(group.title) }} ({{
                            groupItems(items, group.key).length
                        }})</CardTitle
                    ></CardHeader
                >
                <CardContent class="space-y-2">
                    <button
                        v-for="item in groupItems(items, group.key)"
                        :key="item.key"
                        type="button"
                        class="hover:bg-muted flex w-full flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-start text-sm"
                        @click="emit('open', tabFor[item.kind])"
                    >
                        <span class="min-w-0">
                            <span
                                class="font-medium"
                                :class="item.cancelled && 'line-through'"
                                >{{ item.title }}</span
                            >
                            <span class="text-muted-foreground block text-xs">
                                {{ whenText(item.at)
                                }}<template v-if="item.who">
                                    · {{ item.who }}</template
                                >
                            </span>
                        </span>
                        <Badge variant="secondary">{{
                            t(kindLabel[item.kind])
                        }}</Badge>
                    </button>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
