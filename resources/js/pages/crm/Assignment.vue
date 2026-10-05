<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import CrmAssignmentRouteEditor from '@/components/CrmAssignmentRouteEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { ref } from 'vue';

type Member = {
    id: number;
    name: string;
    max_active_leads: number;
    available_on: string | null;
    active_leads: number;
};
type Option = { id: number; name: string };
type Route = {
    id: number;
    match_type: string;
    match_value: string;
    target_type: string;
    target_id: number | null;
    member_ids: number[] | null;
    active: boolean;
};
const props = defineProps<{
    canConfigure: boolean;
    timezone: string;
    followUpReminderDays: number | null;
    followUpEscalationEnabled: boolean;
    today: string;
    currentUserId: number;
    selfAvailableOn: string | null;
    members: Member[];
    routes: Route[];
    departments: Option[];
    subdepartments: Option[];
    teams: Option[];
    holds: {
        id: number;
        lead_id: number;
        first_name: string;
        last_name: string;
        reason: string;
        route_label: string;
        created_at: string;
    }[];
    metaPages: {
        id: number;
        page_id: string;
        department_id: number | null;
        graph_version: string | null;
        callback_url: string;
        subscribed_at: string | null;
    }[];
    metaImports: {
        id: number;
        leadgen_id: string;
        form_id: string | null;
        status: string;
        error: string | null;
        lead_id: number | null;
    }[];
}>();
const page = usePage();
const editingMetaPage = ref(false);
const checkInForm = useForm({
    available: props.selfAvailableOn !== props.today,
});
const timezoneForm = useForm({ timezone: props.timezone });
const reminderForm = useForm({ reminder_days: props.followUpReminderDays });
const escalationForm = useForm({ enabled: props.followUpEscalationEnabled });
const quotaForm = useForm({
    user_id: null as number | null,
    max_active_leads: 0,
});
const metaForm = useForm({
    page_id: '',
    page_access_token: '',
    app_secret: '',
    verify_token: '',
    graph_version: '',
    department_id: null as number | null,
});
function checkIn(): void {
    checkInForm.available = props.selfAvailableOn !== props.today;
    checkInForm.post('/crm/assignment/check-in', { preserveScroll: true });
}
function saveQuota(): void {
    quotaForm.post('/crm/assignment/quota', { preserveScroll: true });
}
function saveMeta(): void {
    metaForm.post('/crm/assignment/meta-pages', {
        preserveScroll: true,
        onSuccess: () => {
            metaForm.reset();
            editingMetaPage.value = false;
        },
    });
}
function editMeta(metaPage: (typeof props.metaPages)[number]): void {
    editingMetaPage.value = true;
    metaForm.clearErrors();
    metaForm.page_id = metaPage.page_id;
    metaForm.page_access_token = '';
    metaForm.app_secret = '';
    metaForm.verify_token = '';
    metaForm.graph_version = metaPage.graph_version || '';
    metaForm.department_id = metaPage.department_id;
}
function cancelMetaEdit(): void {
    editingMetaPage.value = false;
    metaForm.reset();
    metaForm.clearErrors();
}
</script>

<template>
    <Head title="CRM assignment routing" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="CRM assignment routing"
            description="Route incoming leads to available agents with capacity."
        >
            <template #actions>
                <Link href="/crm/leads" class="text-sm underline">{{
                    t('Back to leads')
                }}</Link>
            </template>
        </PageHeader>
        <section class="space-y-3 rounded-md border p-4">
            <h2 class="font-medium">My availability</h2>
            <p class="text-muted-foreground text-sm">
                Check-in lasts for {{ today }} in {{ timezone }}. Your manager
                or administrator sets your active-lead quota.
            </p>
            <p class="text-sm">
                {{
                    selfAvailableOn === today
                        ? 'Available today'
                        : 'Not checked in today'
                }}
            </p>
            <Button @click="checkIn">{{
                selfAvailableOn === today ? 'Check out' : 'Check in for today'
            }}</Button>
            <InputError :message="checkInForm.errors.available" />
        </section>
        <template v-if="canConfigure">
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Routing order</h2>
                <p class="text-muted-foreground text-sm">
                    Meta form ID, Meta form name, connected Page, campaign,
                    project, then the stage’s default round-robin pool. A
                    matching rule holds an unassigned lead when no selected
                    agent is checked in and below quota. It never falls through
                    to a broader pool.
                </p>
                <CrmAssignmentRouteEditor
                    :members="members"
                    :departments="departments"
                    :subdepartments="subdepartments"
                    :teams="teams"
                />
                <CrmAssignmentRouteEditor
                    v-for="route in routes"
                    :key="route.id"
                    :route="route"
                    :members="members"
                    :departments="departments"
                    :subdepartments="subdepartments"
                    :teams="teams"
                />
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Agent capacity</h2>
                <p class="text-muted-foreground text-sm">
                    Only assigned, unconverted leads in non-Won and non-Lost
                    stages count. A quota of 0 pauses new assignments to that
                    agent.
                </p>
                <ul class="space-y-1 text-sm">
                    <li v-for="member in members" :key="member.id">
                        {{ member.name }} · {{ member.active_leads }}/{{
                            member.max_active_leads
                        }}
                        active leads ·
                        {{
                            member.available_on === today
                                ? 'checked in'
                                : 'not checked in'
                        }}
                    </li>
                </ul>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="saveQuota"
                >
                    <label class="space-y-1 text-sm"
                        ><span>Agent</span
                        ><select
                            v-model.number="quotaForm.user_id"
                            class="h-9 rounded-md border px-3"
                            required
                        >
                            <option :value="null">Select member</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1 text-sm"
                        ><span>Maximum active leads</span
                        ><Input
                            v-model.number="quotaForm.max_active_leads"
                            type="number"
                            min="0"
                            max="100000"
                            required
                    /></label>
                    <Button :disabled="quotaForm.processing">Save quota</Button>
                </form>
                <InputError
                    v-for="(error, key) in quotaForm.errors"
                    :key="key"
                    :message="error"
                />
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Assignment hold queue</h2>
                <p class="text-muted-foreground text-sm">
                    Held leads retry automatically in arrival order when
                    capacity or check-in changes, and every minute through the
                    scheduler. Owners and Administrators receive an in-app hold
                    notice.
                </p>
                <Button
                    variant="outline"
                    @click="
                        router.post(
                            '/crm/assignment/retry',
                            {},
                            { preserveScroll: true },
                        )
                    "
                    >Retry now</Button
                >
                <ul class="space-y-1 text-sm">
                    <li v-for="hold in holds" :key="hold.id">
                        {{ hold.first_name }} {{ hold.last_name }} ·
                        {{ hold.route_label }} · {{ hold.reason }}
                    </li>
                    <li v-if="!holds.length">No leads on hold.</li>
                </ul>
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Timezone</h2>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="
                        timezoneForm.put('/crm/assignment/timezone', {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="space-y-1 text-sm"
                        ><span>IANA timezone</span
                        ><Input
                            v-model="timezoneForm.timezone"
                            placeholder="Asia/Dubai"
                            required /></label
                    ><Button :disabled="timezoneForm.processing"
                        >Save timezone</Button
                    >
                </form>
                <InputError :message="timezoneForm.errors.timezone" />
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Follow-up reminders</h2>
                <p class="text-muted-foreground text-sm">
                    Send one in-app reminder to the current lead assignee at
                    08:00 in {{ timezone }}, the selected number of local days
                    before an open follow-up is due. Leave blank to turn this
                    off.
                </p>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="
                        reminderForm.put('/crm/assignment/follow-up-reminder', {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="space-y-1 text-sm"
                        ><span>Days before due date</span
                        ><input
                            v-model.number="reminderForm.reminder_days"
                            type="number"
                            min="1"
                            max="30"
                            placeholder="Off"
                            class="h-9 rounded-md border px-3"
                    /></label>
                    <Button :disabled="reminderForm.processing"
                        >Save reminder setting</Button
                    >
                </form>
                <InputError :message="reminderForm.errors.reminder_days" />
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Overdue follow-up escalation</h2>
                <p class="text-muted-foreground text-sm">
                    At 08:00 in {{ timezone }}, send in-app notices when an open
                    follow-up is at least 1 day, 1 week, and 2 weeks overdue.
                    Owners, Administrators, and Managers who can see the lead
                    receive it. The current assignee does not receive an
                    escalation. Each checkpoint sends at most once per
                    recipient. Tasks remain open. This is off by default.
                </p>
                <form
                    class="flex flex-wrap items-center gap-3"
                    @submit.prevent="
                        escalationForm.put(
                            '/crm/assignment/follow-up-escalation',
                            {
                                preserveScroll: true,
                            },
                        )
                    "
                >
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="escalationForm.enabled"
                            type="checkbox"
                        />
                        Enable overdue escalation
                    </label>
                    <Button :disabled="escalationForm.processing"
                        >Save escalation setting</Button
                    >
                </form>
                <InputError :message="escalationForm.errors.enabled" />
            </section>
            <section class="space-y-3 rounded-md border p-4">
                <h2 class="font-medium">Meta Lead Ads pages</h2>
                <p class="text-muted-foreground text-sm">
                    Connect multiple Pages for this organization and optionally
                    choose the department that receives each Page's leads. Enter
                    the Page and app credentials. Secrets are encrypted and
                    never shown again. When editing, leave a secret blank to
                    keep its current value.
                </p>
                <p class="text-sm">
                    For Pages using the same Meta app, configure one of their
                    callback URLs and its verify token in that app. Pages with
                    the same app secret in this organization can share that
                    callback. Use your public HTTPS domain, then subscribe every
                    Page to leadgen.
                </p>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="metaPage in metaPages"
                        :key="metaPage.id"
                        class="flex flex-wrap items-center gap-2"
                    >
                        Page {{ metaPage.page_id }} ·
                        {{
                            departments.find(
                                (item) => item.id === metaPage.department_id,
                            )?.name || 'organization routing'
                        }}
                        ·
                        {{ metaPage.graph_version || 'Graph version not set' }}
                        ·
                        {{
                            metaPage.subscribed_at
                                ? 'subscription requested'
                                : 'not subscribed here yet'
                        }}
                        <Button
                            size="sm"
                            variant="outline"
                            @click="editMeta(metaPage)"
                            >{{ t('Edit') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="
                                router.post(
                                    `/crm/assignment/meta-pages/${metaPage.id}/subscribe`,
                                    {},
                                    { preserveScroll: true },
                                )
                            "
                            >Subscribe leadgen</Button
                        >
                        <span class="basis-full break-all"
                            >Callback: {{ metaPage.callback_url }}</span
                        >
                    </li>
                    <li v-if="!metaPages.length">No pages connected.</li>
                </ul>
                <InputError :message="page.props.errors.meta" />
                <form
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="saveMeta"
                >
                    <label class="space-y-1 text-sm"
                        ><span>Page ID</span
                        ><Input
                            v-model="metaForm.page_id"
                            :readonly="editingMetaPage"
                            required /></label
                    ><label class="space-y-1 text-sm"
                        ><span>Page access token</span
                        ><Input
                            v-model="metaForm.page_access_token"
                            type="password"
                            :required="!editingMetaPage" /></label
                    ><label class="space-y-1 text-sm"
                        ><span>Meta app secret</span
                        ><Input
                            v-model="metaForm.app_secret"
                            type="password"
                            :required="!editingMetaPage" /></label
                    ><label class="space-y-1 text-sm"
                        ><span>Webhook verify token</span
                        ><Input
                            v-model="metaForm.verify_token"
                            type="password"
                            :required="!editingMetaPage" /></label
                    ><label class="space-y-1 text-sm"
                        ><span>Graph API version</span
                        ><Input
                            v-model="metaForm.graph_version"
                            placeholder="v23.0"
                            required /></label
                    ><label class="space-y-1 text-sm"
                        ><span>Destination department</span
                        ><select
                            v-model.number="metaForm.department_id"
                            class="h-9 w-full rounded-md border px-3"
                        >
                            <option :value="null">
                                Use other routing rules
                            </option>
                            <option
                                v-for="department in departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.name }}
                            </option>
                        </select></label
                    ><Button
                        class="w-fit sm:col-span-2"
                        :disabled="metaForm.processing"
                        >{{
                            editingMetaPage
                                ? 'Save Page changes'
                                : 'Connect Page'
                        }}</Button
                    >
                    <Button
                        v-if="editingMetaPage"
                        type="button"
                        variant="outline"
                        @click="cancelMetaEdit"
                        >{{ t('Cancel') }}</Button
                    >
                </form>
                <InputError
                    v-for="(error, key) in metaForm.errors"
                    :key="key"
                    :message="error"
                />
                <h3 class="font-medium">Recent imports</h3>
                <ul class="space-y-1 text-sm">
                    <li v-for="item in metaImports" :key="item.id">
                        {{ item.leadgen_id }} · {{ item.status }}
                        <span v-if="item.error">· {{ item.error }}</span
                        ><Button
                            v-if="item.status === 'failed'"
                            size="sm"
                            variant="outline"
                            @click="
                                router.post(
                                    `/crm/assignment/meta-imports/${item.id}/retry`,
                                    {},
                                    { preserveScroll: true },
                                )
                            "
                            >Retry</Button
                        >
                    </li>
                    <li v-if="!metaImports.length">No Meta imports yet.</li>
                </ul>
            </section>
        </template>
    </div>
</template>
