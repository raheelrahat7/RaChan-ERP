<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DateText from '@/components/DateText.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import type { ServerActivityBoard } from '@/lib/crm-activity-views';
const props = defineProps<{ board: ServerActivityBoard }>();
const emit = defineEmits<{ page: [page: number] }>();
const { t } = useLocale();
const labels: Record<string, string> = {
    overdue: 'Overdue',
    due_today: 'Due today',
    due_this_week: 'Due this week',
    due_next_week: 'Due next week',
    idle: 'Idle',
    due_later: 'Due later',
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
            v-for="lane in props.board.lanes"
            :key="lane.key"
            class="bg-muted/40 flex max-h-[calc(100vh-14rem)] min-h-48 w-72 shrink-0 flex-col rounded-md"
            :aria-labelledby="`activity-col-${lane.key}`"
        >
            <h3
                :id="`activity-col-${lane.key}`"
                class="bg-primary text-primary-foreground flex items-center justify-between px-4 py-2 text-sm font-medium"
            >
                <span>{{ t(labels[lane.key] ?? lane.key) }}</span
                ><span class="tabular-nums">{{ lane.total }}</span>
            </h3>
            <div class="flex-1 space-y-2 overflow-y-auto p-2">
                <p
                    v-if="!lane.leads.length"
                    class="text-muted-foreground rounded-md border border-dashed p-4 text-sm"
                >
                    {{ t('Nothing here.') }}
                </p>
                <article
                    v-for="lead in lane.leads"
                    :key="lead.id"
                    class="bg-card space-y-2 rounded-md border p-3 text-sm shadow-xs"
                >
                    <Link
                        :href="`/crm/leads/${lead.id}#activities`"
                        class="font-medium hover:underline"
                        >{{ lead.first_name }} {{ lead.last_name }}</Link
                    >
                    <p
                        v-if="lead.next_due_at"
                        class="text-muted-foreground text-xs"
                    >
                        <DateText :value="lead.next_due_at" with-time />
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ t('Responsible person') }}:
                        {{ lead.assignee?.name ?? '—' }}
                    </p>
                </article>
            </div>
            <div class="flex justify-between gap-2 p-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="lane.page <= 1"
                    @click="emit('page', lane.page - 1)"
                    >{{ t('Previous') }}</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="lane.page * lane.per_page >= lane.total"
                    @click="emit('page', lane.page + 1)"
                    >{{ t('Next') }}</Button
                >
            </div>
        </section>
    </div>
</template>
