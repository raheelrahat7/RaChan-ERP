<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
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
    MEETING_TYPES,
    completeMeetingBody,
    defaultEnd,
    endsBeforeStart,
    hasChanges,
    meetingCreateBody,
    meetingForm,
    meetingUpdateBody,
    whenText,
} from '@/lib/crm-schedule';
import type { LeadMeeting, MeetingForm } from '@/lib/crm-schedule';

const props = defineProps<{
    leadId: number;
    members: { id: number; name: string }[];
    defaultAssignee: number | null;
    canCreate: boolean;
    currentStageId: number;
    stages: { id: number; name: string; active: boolean }[];
}>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/meetings`;
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

type Page = {
    data: LeadMeeting[];
    current_page: number;
    last_page: number;
    total: number;
};
type Listing = { id: number; reference: string };
const meetings = ref<Page>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const listings = ref<Listing[]>([]);
const allowed = ref({ create: false });
const loadError = ref('');
const message = ref('');
const open = ref(false);
const editing = ref<LeadMeeting | null>(null);
const form = ref<MeetingForm>(meetingForm());
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const completing = ref<LeadMeeting | null>(null);
const outcome = ref('');
const moveTo = ref<string | number>('');
const stageOptions = computed(() =>
    props.stages.filter(
        (stage) => stage.active && stage.id !== props.currentStageId,
    ),
);

async function load(page = 1): Promise<void> {
    try {
        const data = await apiJson<{
            meetings: Page;
            permissions: { create: boolean };
        }>(`${base}?page=${page}`);
        meetings.value = data.meetings;
        allowed.value = data.permissions;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load meetings.');
    }
}
async function loadListings(): Promise<void> {
    try {
        const data = await apiJson<{
            matches: { data: { listing: Listing }[] };
        }>(`/crm/leads/${props.leadId}/matches?per_page=100`);
        listings.value = data.matches.data.map((match) => ({
            id: match.listing.id,
            reference: match.listing.reference,
        }));
    } catch {
        listings.value = [];
    }
}

// Fill the required end time as soon as a start is chosen, unless one is already set.
watch(
    () => form.value.starts_at,
    (start) => {
        if (start && !form.value.ends_at) {
            form.value.ends_at = defaultEnd(start);
        }
    },
);

function startAdd(): void {
    editing.value = null;
    form.value = meetingForm(undefined, props.defaultAssignee);
    errors.value = {};
    open.value = true;
}
function startEdit(meeting: LeadMeeting): void {
    editing.value = meeting;
    form.value = meetingForm(meeting);
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
    if (endsBeforeStart(form.value.starts_at, form.value.ends_at)) {
        errors.value = { ends_at: t('The end must be after the start.') };

        return;
    }
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        if (editing.value) {
            const body = meetingUpdateBody(editing.value, form.value);
            if (hasChanges(body)) {
                await apiJson(`${base}/${editing.value.id}`, 'PUT', body);
            }
        } else {
            await apiJson(base, 'POST', meetingCreateBody(form.value));
        }
        open.value = false;
        message.value = t('Saved.');
        await load(meetings.value.current_page);
    } catch (failure) {
        fail(failure);
    } finally {
        busy.value = false;
    }
}

function startComplete(meeting: LeadMeeting): void {
    completing.value = meeting;
    outcome.value = '';
    moveTo.value = '';
    errors.value = {};
}
async function complete(): Promise<void> {
    if (!completing.value) {
        return;
    }
    busy.value = true;
    errors.value = {};
    try {
        const stage = String(moveTo.value) === '' ? null : Number(moveTo.value);
        await apiJson(
            `${base}/${completing.value.id}/complete`,
            'POST',
            completeMeetingBody(
                completing.value,
                outcome.value,
                stage,
                props.currentStageId,
            ),
        );
        completing.value = null;
        message.value = t('Saved.');
        await load(meetings.value.current_page);
    } catch (failure) {
        fail(failure);
    } finally {
        busy.value = false;
    }
}
async function cancel(meeting: LeadMeeting): Promise<void> {
    if (!confirm(`${t('Cancel')} ${meeting.title}?`)) {
        return;
    }
    try {
        await apiJson(`${base}/${meeting.id}/cancel`, 'POST', {
            expected_version: meeting.version,
        });
        await load(meetings.value.current_page);
    } catch (failure) {
        loadError.value =
            failure instanceof ApiError
                ? (Object.values(failure.fieldErrors())[0] ?? failure.message)
                : t('Could not cancel this meeting.');
    }
}

onMounted(() => {
    void load();
    void loadListings();
});
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-center justify-between gap-3">
            <CardTitle class="text-eyebrow"
                >{{ t('Meetings and viewings') }} ({{
                    meetings.total
                }})</CardTitle
            >
            <Button
                v-if="canCreate && allowed.create"
                type="button"
                size="sm"
                @click="startAdd"
                >{{ t('Schedule meeting') }}</Button
            >
        </CardHeader>
        <CardContent class="space-y-2">
            <p v-if="loadError" role="alert" class="text-destructive text-sm">
                {{ loadError }}
            </p>
            <p v-if="message" role="status" class="text-sm">{{ message }}</p>
            <p
                v-if="!meetings.data.length"
                class="text-muted-foreground text-sm"
            >
                {{ t('No meetings or viewings for this lead yet.') }}
            </p>
            <div
                v-for="meeting in meetings.data"
                :key="meeting.id"
                class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
            >
                <div class="min-w-0 space-y-1">
                    <p
                        class="font-medium"
                        :class="
                            meeting.status === 'cancelled'
                                ? 'text-muted-foreground line-through'
                                : ''
                        "
                    >
                        {{ meeting.title }}
                    </p>
                    <p
                        class="text-muted-foreground flex flex-wrap items-center gap-2 text-xs"
                    >
                        <Badge variant="outline" class="capitalize">{{
                            t(meeting.type)
                        }}</Badge>
                        <span
                            >{{ whenText(meeting.starts_at)
                            }}<template v-if="meeting.ends_at">
                                – {{ whenText(meeting.ends_at) }}</template
                            ></span
                        >
                        <span v-if="meeting.location"
                            >· {{ meeting.location }}</span
                        >
                        <span
                            >·
                            {{ meeting.assignee_name ?? t('Unassigned') }}</span
                        >
                        <Badge
                            :variant="
                                meeting.status === 'scheduled'
                                    ? 'outline'
                                    : 'secondary'
                            "
                            class="capitalize"
                            >{{ t(meeting.status) }}</Badge
                        >
                    </p>
                    <p v-if="meeting.outcome" class="text-xs">
                        {{ t('Outcome') }}: {{ meeting.outcome }}
                    </p>
                </div>
                <div v-if="meeting.status === 'scheduled'" class="flex gap-1">
                    <Button
                        v-if="meeting.permissions.complete"
                        type="button"
                        size="sm"
                        @click="startComplete(meeting)"
                        >{{ t('Complete') }}</Button
                    >
                    <Button
                        v-if="meeting.permissions.edit"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startEdit(meeting)"
                        >{{ t('Edit') }}</Button
                    >
                    <Button
                        v-if="meeting.permissions.cancel"
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="cancel(meeting)"
                        >{{ t('Cancel') }}</Button
                    >
                </div>
            </div>
            <div
                v-if="meetings.last_page > 1"
                class="flex items-center justify-center gap-3 pt-2 text-sm"
            >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="meetings.current_page <= 1"
                    @click="load(meetings.current_page - 1)"
                    >{{ t('Previous') }}</Button
                >
                <span
                    >{{ meetings.current_page }} /
                    {{ meetings.last_page }}</span
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="meetings.current_page >= meetings.last_page"
                    @click="load(meetings.current_page + 1)"
                    >{{ t('Next') }}</Button
                >
            </div>
        </CardContent>
    </Card>

    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    editing ? t('Edit meeting') : t('Schedule meeting')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('Meetings and viewings')
                }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-3" @submit.prevent="save">
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <InputError :message="errors.lead" />
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="lm-type">{{ t('Type') }}</Label>
                        <select
                            id="lm-type"
                            v-model="form.type"
                            :class="selectClass"
                        >
                            <option
                                v-for="type in MEETING_TYPES"
                                :key="type"
                                :value="type"
                            >
                                {{ t(type) }}
                            </option>
                        </select>
                        <InputError :message="errors.type" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lm-assignee">{{ t('Assigned to') }}</Label>
                        <select
                            id="lm-assignee"
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
                    <Label for="lm-title">{{ t('Title') }}</Label
                    ><Input
                        id="lm-title"
                        v-model="form.title"
                        required
                        maxlength="255"
                    /><InputError :message="errors.title" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="lm-start">{{ t('Starts') }}</Label
                        ><Input
                            id="lm-start"
                            v-model="form.starts_at"
                            type="datetime-local"
                            required
                        /><InputError :message="errors.starts_at" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lm-end">{{ t('Ends') }}</Label
                        ><Input
                            id="lm-end"
                            v-model="form.ends_at"
                            type="datetime-local"
                        /><InputError :message="errors.ends_at" />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="lm-loc">{{ t('Location') }}</Label
                    ><Input
                        id="lm-loc"
                        v-model="form.location"
                        maxlength="255"
                    /><InputError :message="errors.location" />
                </div>
                <div class="space-y-1">
                    <Label for="lm-listing">{{
                        t('Listing (from matched properties)')
                    }}</Label>
                    <select
                        id="lm-listing"
                        v-model="form.listing_id"
                        :class="selectClass"
                    >
                        <option value="">—</option>
                        <option
                            v-for="listing in listings"
                            :key="listing.id"
                            :value="listing.id"
                        >
                            {{ listing.reference }}
                        </option>
                    </select>
                    <InputError :message="errors.listing_id" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Close') }}</Button
                    >
                    <Button type="submit" :disabled="busy">{{
                        t('Save')
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="completing !== null"
        @update:open="(value) => !value && (completing = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Complete meeting') }}</DialogTitle>
                <DialogDescription>{{ completing?.title }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-3" @submit.prevent="complete">
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <div class="space-y-1">
                    <Label for="lm-outcome">{{ t('Outcome notes') }}</Label>
                    <textarea
                        id="lm-outcome"
                        v-model="outcome"
                        rows="3"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                    <InputError :message="errors.outcome" />
                </div>
                <div v-if="stageOptions.length" class="space-y-1">
                    <Label for="lm-stage">{{
                        t('Move the lead to (optional)')
                    }}</Label>
                    <select id="lm-stage" v-model="moveTo" :class="selectClass">
                        <option value="">
                            {{ t('Keep the current stage') }}
                        </option>
                        <option
                            v-for="stage in stageOptions"
                            :key="stage.id"
                            :value="stage.id"
                        >
                            {{ stage.name }}
                        </option>
                    </select>
                    <InputError :message="errors.stage_id" />
                    <InputError :message="errors.expected_stage_id" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="completing = null"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button type="submit" :disabled="busy">{{
                        t('Complete')
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
