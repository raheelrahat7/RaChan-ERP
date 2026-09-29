<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import {
    RELATED_TYPES,
    isTaskOverdue,
    priorityTone,
    relatedRecordHref,
    relatedRecordIcon,
} from '@/lib/sales-crm-tools';
import type { Priority, RelatedType } from '@/lib/sales-crm-tools';
import type { DataTableColumn } from '@/lib/data-table';

type Task = {
    id: number;
    title: string;
    description: string | null;
    priority: Priority;
    status: 'open' | 'completed';
    assigned_to: number;
    assignee_name: string;
    due_at: string | null;
    related_type: RelatedType | null;
    related_id: number | null;
};

const props = defineProps<{
    tasks: {
        data: Task[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Tasks', href: '/tasks' }] },
});

const { t } = useLocale();
const page = usePage();
const filter = ref<'open' | 'completed' | 'all'>('open');
const visible = computed(() =>
    props.tasks.data.filter(
        (task) => filter.value === 'all' || task.status === filter.value,
    ),
);
const columns = computed<DataTableColumn<Task>[]>(() => [
    { key: 'title', label: 'Title' },
    ...(props.canManage
        ? ([
              { key: 'assignee_name', label: 'Assignee' },
          ] as DataTableColumn<Task>[])
        : []),
    { key: 'priority', label: 'Priority' },
    { key: 'due_at', label: 'Due' },
    { key: 'related_type', label: 'Related' },
    { key: 'id', label: '', align: 'end' },
]);

function canComplete(task: Task): boolean {
    return (
        task.status === 'open' &&
        (props.canManage || task.assigned_to === page.props.auth.user.id)
    );
}

const dialogOpen = ref(false);
const editing = ref<Task | null>(null);
const relatedType = ref<RelatedType | null>(null);
const relatedId = ref<number | null>(null);

const form = useForm({
    title: '',
    description: '',
    priority: 'normal' as Priority,
    assigned_to: page.props.auth.user.id as number,
    due_at: '',
    related_type: null as RelatedType | null,
    related_id: null as number | null,
});

function openCreate(): void {
    editing.value = null;
    relatedType.value = null;
    relatedId.value = null;
    form.reset();
    form.assigned_to = page.props.auth.user.id;
    dialogOpen.value = true;
}

function openEdit(task: Task): void {
    editing.value = task;
    relatedType.value = task.related_type;
    relatedId.value = task.related_id;
    form.title = task.title;
    form.description = task.description ?? '';
    form.priority = task.priority;
    form.assigned_to = task.assigned_to;
    form.due_at = task.due_at ?? '';
    dialogOpen.value = true;
}

function submit(): void {
    form.related_type = relatedType.value;
    form.related_id = relatedType.value ? relatedId.value : null;
    const onSuccess = () => {
        dialogOpen.value = false;
    };
    if (editing.value) {
        form.put(`/tasks/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        form.post('/tasks', { preserveScroll: true, onSuccess });
    }
}

function complete(task: Task): void {
    form.post(`/tasks/${task.id}/complete`, {
        preserveScroll: true,
        only: ['tasks'],
    });
}
</script>

<template>
    <Head :title="t('Tasks')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Workflow"
            title="Tasks"
            :description="
                canManage
                    ? 'Assigned work with a due date.'
                    : 'Your assigned tasks.'
            "
        >
            <template #actions>
                <Button @click="openCreate">{{ t('New task') }}</Button>
            </template>
        </PageHeader>

        <div class="flex gap-2">
            <Button
                v-for="option in ['open', 'completed', 'all'] as const"
                :key="option"
                size="sm"
                :variant="filter === option ? 'default' : 'outline'"
                @click="filter = option"
            >
                {{
                    t(
                        option === 'open'
                            ? 'Open'
                            : option === 'completed'
                              ? 'Completed'
                              : 'All',
                    )
                }}
            </Button>
        </div>

        <DataTable
            :columns="columns"
            :rows="visible"
            :row-key="(row) => row.id"
            :row-label="(row) => row.title"
            :empty-title="
                filter === 'open' ? 'No open tasks.' : 'No tasks yet.'
            "
        >
            <template #cell-title="{ row }">
                <button
                    type="button"
                    class="font-medium hover:underline"
                    @click="openEdit(row)"
                >
                    {{ row.title }}
                </button>
            </template>
            <template #cell-priority="{ row }">
                <span :class="priorityTone(row.priority)">{{
                    t(row.priority)
                }}</span>
            </template>
            <template #cell-due_at="{ row }">
                <span
                    v-if="row.due_at"
                    :class="isTaskOverdue(row) ? 'text-destructive' : ''"
                >
                    <CalendarClock
                        v-if="isTaskOverdue(row)"
                        class="me-1 inline size-3.5"
                    />
                    <DateText :value="row.due_at" />
                </span>
                <span v-else class="text-faint">—</span>
            </template>
            <template #cell-related_type="{ row }">
                <Link
                    v-if="row.related_type"
                    :href="relatedRecordHref(row.related_type)"
                    class="text-accent-text inline-flex items-center gap-1.5 hover:underline"
                >
                    <component
                        :is="relatedRecordIcon(row.related_type)"
                        class="size-3.5"
                    />{{
                        t(
                            RELATED_TYPES.find(
                                (r) => r.value === row.related_type,
                            )?.label ?? '',
                        )
                    }}
                </Link>
                <span v-else class="text-faint">—</span>
            </template>
            <template #cell-id="{ row }">
                <Button
                    v-if="canComplete(row)"
                    size="sm"
                    variant="outline"
                    @click="complete(row)"
                    >{{ t('Complete') }}</Button
                >
            </template>
        </DataTable>
        <Pagination :links="tasks.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t(editing ? 'Edit task' : 'New task')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-title">{{ t('Title') }}</Label>
                        <Input id="task-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-description"
                            >{{ t('Description') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Textarea
                            id="task-description"
                            v-model="form.description"
                        />
                    </div>
                    <div v-if="canManage" class="flex flex-col gap-1.5">
                        <Label>{{ t('Assignee') }}</Label>
                        <Select
                            :model-value="String(form.assigned_to)"
                            @update:model-value="
                                form.assigned_to = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="String(member.id)"
                                    >{{ member.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.assigned_to" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-due"
                            >{{ t('Due date') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="task-due"
                            v-model="form.due_at"
                            type="date"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label
                            >{{ t('Related record') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Select
                            :model-value="relatedType ?? ''"
                            @update:model-value="
                                (value) => {
                                    relatedType = (value ||
                                        null) as RelatedType | null;
                                    relatedId = null;
                                }
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue :placeholder="t('None')"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{
                                    t('None')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="option in RELATED_TYPES"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ t(option.label) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <RecordPicker
                            v-if="relatedType"
                            :type="relatedType"
                            v-model="relatedId"
                            label="Search…"
                        />
                        <InputError :message="form.errors.related_id" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t(editing ? 'Save' : 'Create task')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
