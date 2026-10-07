<script setup lang="ts">
import { onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    PRIORITIES,
    hasChanges,
    taskCreateBody,
    taskForm,
    taskUpdateBody,
    whenText,
} from '@/lib/crm-schedule';
import type { LeadTask, TaskForm } from '@/lib/crm-schedule';

const props = defineProps<{
    leadId: number;
    members: { id: number; name: string }[];
    defaultAssignee: number | null;
    canCreate: boolean;
}>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/tasks`;
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

type Page = {
    data: LeadTask[];
    current_page: number;
    last_page: number;
    total: number;
};
const tasks = ref<Page>({ data: [], current_page: 1, last_page: 1, total: 0 });
const allowed = ref({ create: false });
const loadError = ref('');
const message = ref('');
const open = ref(false);
const editing = ref<LeadTask | null>(null);
const form = ref<TaskForm>(taskForm());
const errors = ref<Record<string, string>>({});
const busy = ref(false);

async function load(page = 1): Promise<void> {
    try {
        const data = await apiJson<{
            tasks: Page;
            permissions: { create: boolean };
        }>(`${base}?page=${page}`);
        tasks.value = data.tasks;
        allowed.value = data.permissions;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load tasks.');
    }
}

function startAdd(): void {
    editing.value = null;
    form.value = taskForm(undefined, props.defaultAssignee);
    errors.value = {};
    open.value = true;
}
function startEdit(task: LeadTask): void {
    editing.value = task;
    form.value = taskForm(task);
    errors.value = {};
    open.value = true;
}
function fail(failure: unknown): void {
    if (failure instanceof ApiError) {
        const found = failure.fieldErrors();
        errors.value = Object.keys(found).length
            ? found
            : { form: failure.message };
    } else {
        errors.value = { form: t('Could not save.') };
    }
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        if (editing.value) {
            const body = taskUpdateBody(editing.value, form.value);
            if (hasChanges(body)) {
                await apiJson(`${base}/${editing.value.id}`, 'PUT', body);
            }
        } else {
            await apiJson(base, 'POST', taskCreateBody(form.value));
        }
        open.value = false;
        message.value = t('Saved.');
        await load(tasks.value.current_page);
    } catch (failure) {
        fail(failure);
    } finally {
        busy.value = false;
    }
}

async function complete(task: LeadTask): Promise<void> {
    message.value = '';
    try {
        await apiJson(`${base}/${task.id}/complete`, 'POST', {
            expected_version: task.version,
        });
        await load(tasks.value.current_page);
    } catch (failure) {
        loadError.value =
            failure instanceof ApiError
                ? (Object.values(failure.fieldErrors())[0] ?? failure.message)
                : t('Could not complete this task.');
    }
}

onMounted(() => void load());
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-center justify-between gap-3">
            <CardTitle class="text-eyebrow"
                >{{ t('Tasks') }} ({{ tasks.total }})</CardTitle
            >
            <Button
                v-if="canCreate && allowed.create"
                type="button"
                size="sm"
                @click="startAdd"
                >{{ t('Add task') }}</Button
            >
        </CardHeader>
        <CardContent class="space-y-2">
            <p v-if="loadError" role="alert" class="text-destructive text-sm">
                {{ loadError }}
            </p>
            <p v-if="message" role="status" class="text-sm">{{ message }}</p>
            <p v-if="!tasks.data.length" class="text-muted-foreground text-sm">
                {{ t('No tasks for this lead yet.') }}
            </p>
            <div
                v-for="task in tasks.data"
                :key="task.id"
                class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
            >
                <div class="min-w-0 space-y-1">
                    <p
                        class="font-medium"
                        :class="
                            task.status === 'completed'
                                ? 'text-muted-foreground line-through'
                                : ''
                        "
                    >
                        {{ task.title }}
                    </p>
                    <p
                        v-if="task.description"
                        class="text-muted-foreground text-xs"
                    >
                        {{ task.description }}
                    </p>
                    <p
                        class="text-muted-foreground flex flex-wrap items-center gap-2 text-xs"
                    >
                        <Badge variant="outline" class="capitalize">{{
                            t(task.priority)
                        }}</Badge>
                        <span>{{ task.assignee_name ?? t('Unassigned') }}</span>
                        <span v-if="task.due_at"
                            >· {{ t('Due') }} {{ whenText(task.due_at) }}</span
                        >
                        <Badge
                            :variant="
                                task.status === 'completed'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            >{{
                                task.status === 'completed'
                                    ? t('Completed')
                                    : t('Open')
                            }}</Badge
                        >
                    </p>
                </div>
                <div class="flex gap-1">
                    <Button
                        v-if="
                            task.status === 'open' && task.permissions.complete
                        "
                        type="button"
                        size="sm"
                        @click="complete(task)"
                        >{{ t('Complete') }}</Button
                    >
                    <Button
                        v-if="task.status === 'open' && task.permissions.edit"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startEdit(task)"
                        >{{ t('Edit') }}</Button
                    >
                </div>
            </div>
            <div
                v-if="tasks.last_page > 1"
                class="flex items-center justify-center gap-3 pt-2 text-sm"
            >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="tasks.current_page <= 1"
                    @click="load(tasks.current_page - 1)"
                    >{{ t('Previous') }}</Button
                >
                <span>{{ tasks.current_page }} / {{ tasks.last_page }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="tasks.current_page >= tasks.last_page"
                    @click="load(tasks.current_page + 1)"
                    >{{ t('Next') }}</Button
                >
            </div>
        </CardContent>
    </Card>

    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    editing ? t('Edit task') : t('Add task')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('Tasks for this lead')
                }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-3" @submit.prevent="save">
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <InputError :message="errors.lead" />
                <div class="space-y-1">
                    <Label for="lt-title">{{ t('Title') }}</Label
                    ><Input
                        id="lt-title"
                        v-model="form.title"
                        required
                        maxlength="255"
                    /><InputError :message="errors.title" />
                </div>
                <div class="space-y-1">
                    <Label for="lt-desc">{{ t('Description') }}</Label>
                    <textarea
                        id="lt-desc"
                        v-model="form.description"
                        rows="2"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                    <InputError :message="errors.description" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="lt-priority">{{ t('Priority') }}</Label>
                        <select
                            id="lt-priority"
                            v-model="form.priority"
                            :class="selectClass"
                        >
                            <option
                                v-for="priority in PRIORITIES"
                                :key="priority"
                                :value="priority"
                            >
                                {{ t(priority) }}
                            </option>
                        </select>
                        <InputError :message="errors.priority" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lt-assignee">{{ t('Assigned to') }}</Label>
                        <select
                            id="lt-assignee"
                            v-model="form.assigned_to"
                            :class="selectClass"
                            required
                        >
                            <option value="" disabled>—</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                        <InputError :message="errors.assigned_to" />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="lt-due">{{ t('Due') }}</Label
                    ><Input
                        id="lt-due"
                        v-model="form.due_at"
                        type="datetime-local"
                    /><InputError :message="errors.due_at" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button type="submit" :disabled="busy">{{
                        t('Save')
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
