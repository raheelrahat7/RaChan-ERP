<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import CrmDealFormSheet from '@/components/CrmDealFormSheet.vue';
import type { FinancialRecords } from '@/lib/crm-deal-finance';
import CrmDealFinance from '@/components/CrmDealFinance.vue';
import CrmDealMoveDialog from '@/components/CrmDealMoveDialog.vue';
import CrmDealTransferDialog from '@/components/CrmDealTransferDialog.vue';
import CrmDealStageBar from '@/components/CrmDealStageBar.vue';
import CrmDealStatusStrip from '@/components/CrmDealStatusStrip.vue';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { optionLabel } from '@/lib/crm-deal-commercial';
import type { DealOption } from '@/lib/crm-deal-commercial';
import { amountOf, categoryLabel, contactName } from '@/lib/crm-deals';
import type { Deal, DealPipeline, DealStage } from '@/types/crm-deals';

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
type Activity = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    completed_at: string | null;
    created_at: string;
    creator?: { name: string } | null;
};
type History = {
    id: number;
    from_stage_id: number | null;
    to_stage_id: number;
    changed_at: string;
    notes: string | null;
    snapshot?: { from?: string | null; to?: string | null } | null;
};
type Audit = {
    id: number;
    event: string;
    created_at: string;
    actor?: { name: string } | null;
};
type CustomFieldValue = {
    key: string;
    name: string;
    type: string;
    value: unknown;
};

const props = defineProps<{
    deal: Deal;
    sourceLead: { id: number; first_name: string; last_name: string } | null;
    customFields: CustomFieldValue[];
    stages: DealStage[];
    pipelines: DealPipeline[];
    history: Page<History>;
    timeline: Page<Audit>;
    activities: Page<Activity>;
    categoryOptions?: { code: string; name: string; active: boolean }[];
    dealStatusOptions?: DealOption[];
    dealScenarioOptions?: DealOption[];
    financialRecords?: Partial<FinancialRecords> | null;
}>();
type Tab = 'general' | 'activities' | 'finance' | 'history';
const { t } = useLocale();
const tabs: Tab[] = ['general', 'activities', 'finance', 'history'];
const tab = ref<Tab>('general');
const editOpen = ref(false);
const moveOpen = ref(false);
const transferOpen = ref(false);
const moveStage = ref<number | null>(null);
const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === props.deal.pipeline_id),
);
const canEdit = computed(() => props.deal.permissions?.edit === true);
const canMove = computed(() => props.deal.permissions?.move === true);
const canTransfer = computed(() => props.deal.permissions?.transfer === true);
const isFinal = computed(() =>
    ['won', 'lost'].includes(
        props.stages.find((stage) => stage.id === props.deal.current_stage_id)
            ?.type ?? '',
    ),
);
const stageNames = computed(
    () => new Map(props.stages.map((stage) => [stage.id, stage.name])),
);
const feed = computed(() =>
    [
        ...props.activities.data.map((activity) => ({
            key: `a${activity.id}`,
            at: activity.created_at,
            actor: activity.creator?.name ?? t('System'),
            title: activity.type,
            body: activity.notes,
            activity: true,
        })),
        ...props.timeline.data.map((entry) => ({
            key: `t${entry.id}`,
            at: entry.created_at,
            actor: entry.actor?.name ?? t('System'),
            title: humanEvent(entry.event),
            body: null,
            activity: false,
        })),
    ].sort((a, b) => b.at.localeCompare(a.at)),
);
const open = computed(() => props.deal.permissions?.edit === true);

const activityForm = ref({ type: 'call', notes: '', due_at: '' });
const activityError = ref('');
const busy = ref(false);

function humanEvent(event: string): string {
    return event
        .replace(/^crm\.deal\./, '')
        .replaceAll('_', ' ')
        .replace(/^./, (c) => c.toUpperCase());
}
function startMove(stageId: number | null): void {
    moveStage.value = stageId;
    moveOpen.value = true;
}
function reload(): void {
    router.reload();
}
function fieldText(value: unknown): string {
    return Array.isArray(value)
        ? value.join(', ')
        : value === null || value === undefined || value === ''
          ? '—'
          : String(value);
}
async function addActivity(): Promise<void> {
    busy.value = true;
    activityError.value = '';
    try {
        await apiJson(`/crm/deals/${props.deal.id}/activities`, 'POST', {
            expected_version: props.deal.version,
            type: activityForm.value.type,
            notes: activityForm.value.notes || null,
            due_at: activityForm.value.due_at || null,
        });
        activityForm.value = { type: 'call', notes: '', due_at: '' };
        reload();
    } catch (cause) {
        activityError.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
    } finally {
        busy.value = false;
    }
}
async function complete(activity: Activity): Promise<void> {
    busy.value = true;
    activityError.value = '';
    try {
        await apiJson(
            `/crm/deals/${props.deal.id}/activities/${activity.id}`,
            'PUT',
            {
                expected_version: props.deal.version,
                type: activity.type,
                notes: activity.notes,
                due_at: activity.due_at,
                completed: true,
            },
        );
        reload();
    } catch (cause) {
        activityError.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
    } finally {
        busy.value = false;
    }
}
function fromHash(): void {
    const hash = window.location.hash.slice(1) as Tab;
    if (tabs.includes(hash)) tab.value = hash;
}
onMounted(fromHash);
watch(tab, (value) => window.history.replaceState(null, '', `#${value}`));
</script>

<template>
    <Head :title="deal.title" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="deal.title" :eyebrow="t('Deal')" :translate="false">
            <template #meta>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant="secondary">{{
                        t(categoryLabel(deal.category, categoryOptions))
                    }}</Badge>
                    <span v-if="pipeline" class="text-muted-foreground">{{
                        pipeline.name
                    }}</span>
                    <span class="text-muted-foreground"
                        >· {{ t('Responsible person') }}:
                        {{ deal.assignee?.name ?? t('Unassigned') }}</span
                    >
                    <span v-if="amountOf(deal) !== null" class="font-medium"
                        ><Money
                            :value="amountOf(deal) ?? 0"
                            :currency="deal.currency ?? 'AED'"
                            :decimals="0"
                    /></span>
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    @click="editOpen = true"
                    >{{ t('Edit') }}</Button
                >
                <Button v-if="canMove" type="button" @click="startMove(null)">{{
                    t('Move deal')
                }}</Button>
                <Button
                    v-if="canTransfer"
                    type="button"
                    variant="outline"
                    @click="transferOpen = true"
                    >{{ t('Change pipeline') }}</Button
                >
                <Link href="/deals" class="text-sm underline">{{
                    t('Back to deals')
                }}</Link>
            </template>
        </PageHeader>

        <CrmDealStageBar
            :stages="stages"
            :current-stage-id="deal.current_stage_id"
            :can-move="canMove"
            @move="startMove"
        />

        <CrmDealStatusStrip
            :deal-id="deal.id"
            :version="deal.version"
            :status="deal.deal_status"
            :options="dealStatusOptions ?? []"
            :can-edit="canEdit"
            @changed="reload"
        />

        <Tabs v-model="tab" class="gap-4">
            <TabsList :aria-label="t('Deal sections')"
                ><TabsTrigger
                    v-for="section in tabs"
                    :key="section"
                    :value="section"
                    class="capitalize"
                    >{{ t(section) }}</TabsTrigger
                ></TabsList
            >

            <TabsContent
                value="general"
                class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)]"
            >
                <div class="flex flex-col gap-6">
                    <Card v-if="deal.permissions?.amount">
                        <CardHeader
                            ><CardTitle>{{
                                t('Commercial tracking')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Scenario') }}
                                </p>
                                <p class="text-sm">
                                    {{
                                        optionLabel(
                                            dealScenarioOptions ?? [],
                                            deal.scenario,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Gross commission') }}
                                </p>
                                <p class="text-sm">
                                    <Money
                                        v-if="deal.gross_commission"
                                        :value="Number(deal.gross_commission)"
                                        :currency="deal.currency ?? 'AED'"
                                        :decimals="2"
                                    /><template v-else>—</template>
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Co-broker share (%)') }}
                                </p>
                                <p class="text-sm">
                                    {{ deal.co_broker_share ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Agent share (%)') }}
                                </p>
                                <p class="text-sm">
                                    {{ deal.agent_share ?? '—' }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader
                            ><CardTitle>{{
                                t('General')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Contact') }}
                                </p>
                                <p class="text-sm">
                                    {{ contactName(deal) || '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Company') }}
                                </p>
                                <p class="text-sm">{{ deal.company || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Email') }}
                                </p>
                                <a
                                    v-if="deal.email"
                                    :href="`mailto:${deal.email}`"
                                    class="text-primary text-sm underline-offset-2 hover:underline"
                                    >{{ deal.email }}</a
                                >
                                <p v-else class="text-sm">—</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Phone') }}
                                </p>
                                <a
                                    v-if="deal.phone"
                                    :href="`tel:${deal.phone}`"
                                    class="text-primary text-sm underline-offset-2 hover:underline"
                                    >{{ deal.phone }}</a
                                >
                                <p v-else class="text-sm">—</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Source') }}
                                </p>
                                <p class="text-sm">{{ deal.source || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Expected close date') }}
                                </p>
                                <DateText
                                    v-if="deal.expected_close_date"
                                    :value="deal.expected_close_date"
                                    class="text-sm"
                                />
                                <p v-else class="text-sm">—</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Created') }}
                                </p>
                                <DateText
                                    :value="deal.created_at"
                                    with-time
                                    class="text-sm"
                                />
                            </div>
                            <div v-if="sourceLead">
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Source lead') }}
                                </p>
                                <Link
                                    :href="`/crm/leads/${sourceLead.id}`"
                                    class="text-primary text-sm underline-offset-2 hover:underline"
                                    >{{ sourceLead.first_name }}
                                    {{ sourceLead.last_name }}</Link
                                >
                            </div>
                        </CardContent>
                    </Card>
                    <Card v-if="customFields.length">
                        <CardHeader
                            ><CardTitle>{{
                                t('Custom fields')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent class="grid gap-4 sm:grid-cols-2"
                            ><div
                                v-for="field in customFields"
                                :key="field.key"
                            >
                                <p class="text-muted-foreground text-xs">
                                    {{ field.name }}
                                </p>
                                <p class="text-sm break-words">
                                    {{ fieldText(field.value) }}
                                </p>
                            </div></CardContent
                        >
                    </Card>
                    <Card v-if="deal.notes"
                        ><CardHeader
                            ><CardTitle>{{ t('Notes') }}</CardTitle></CardHeader
                        ><CardContent
                            ><p class="text-sm whitespace-pre-wrap">
                                {{ deal.notes }}
                            </p></CardContent
                        ></Card
                    >
                </div>
                <Card class="h-fit">
                    <CardHeader
                        ><CardTitle>{{ t('Activity') }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <p
                            v-if="!feed.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No activity yet.') }}
                        </p>
                        <ol
                            v-else
                            class="border-border relative ms-2 space-y-5 border-s ps-5"
                        >
                            <li
                                v-for="item in feed.slice(0, 30)"
                                :key="item.key"
                                class="relative"
                            >
                                <span
                                    class="ring-card absolute -start-[1.6rem] top-1.5 size-2.5 rounded-full ring-4"
                                    :class="
                                        item.activity
                                            ? 'bg-primary'
                                            : 'bg-muted-foreground/50'
                                    "
                                    aria-hidden="true"
                                />
                                <p
                                    class="text-sm"
                                    :class="
                                        item.activity
                                            ? 'font-medium capitalize'
                                            : ''
                                    "
                                >
                                    {{ item.title }}
                                </p>
                                <p
                                    v-if="item.body"
                                    class="mt-1 text-sm whitespace-pre-wrap"
                                >
                                    {{ item.body }}
                                </p>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ item.actor }} ·
                                    <DateText :value="item.at" with-time />
                                </p>
                            </li>
                        </ol>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="activities" class="space-y-4">
                <Card v-if="open">
                    <CardHeader
                        ><CardTitle>{{
                            t('Add activity')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <form
                            class="grid gap-3 sm:grid-cols-[10rem_minmax(0,1fr)_14rem_auto] sm:items-end"
                            @submit.prevent="addActivity"
                        >
                            <div class="space-y-1">
                                <Label for="act-type">{{ t('Type') }}</Label
                                ><select
                                    id="act-type"
                                    v-model="activityForm.type"
                                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm capitalize"
                                >
                                    <option
                                        v-for="type in [
                                            'call',
                                            'email',
                                            'meeting',
                                            'task',
                                            'note',
                                        ]"
                                        :key="type"
                                        :value="type"
                                    >
                                        {{ t(type) }}
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <Label for="act-notes">{{ t('Notes') }}</Label
                                ><Input
                                    id="act-notes"
                                    v-model="activityForm.notes"
                                    maxlength="5000"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label for="act-due">{{ t('Due') }}</Label
                                ><Input
                                    id="act-due"
                                    v-model="activityForm.due_at"
                                    type="datetime-local"
                                />
                            </div>
                            <Button type="submit" :disabled="busy">{{
                                t('Add')
                            }}</Button>
                        </form>
                        <div aria-live="polite" class="mt-2">
                            <InputError :message="activityError" />
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle>{{
                            t('Activities')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="space-y-3">
                        <p
                            v-if="!activities.data.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No activities yet.') }}
                        </p>
                        <div
                            v-for="activity in activities.data"
                            :key="activity.id"
                            class="flex flex-wrap items-start justify-between gap-3 border-b pb-3 last:border-0"
                        >
                            <div>
                                <p class="font-medium capitalize">
                                    {{ activity.type
                                    }}<span
                                        v-if="activity.completed_at"
                                        class="text-muted-foreground text-xs font-normal"
                                    >
                                        · {{ t('completed') }}</span
                                    >
                                </p>
                                <p
                                    v-if="activity.notes"
                                    class="text-sm whitespace-pre-wrap"
                                >
                                    {{ activity.notes }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ activity.creator?.name ?? t('System') }}
                                    ·
                                    <DateText
                                        :value="activity.created_at"
                                        with-time
                                    /><template v-if="activity.due_at">
                                        · {{ t('due') }}
                                        <DateText
                                            :value="activity.due_at"
                                            with-time
                                    /></template>
                                </p>
                            </div>
                            <Button
                                v-if="open && !activity.completed_at"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="busy"
                                @click="complete(activity)"
                                >{{ t('Complete') }}</Button
                            >
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="finance">
                <CrmDealFinance
                    :deal-id="deal.id"
                    :version="deal.version"
                    :can-edit="canEdit"
                    :records="financialRecords"
                />
            </TabsContent>

            <TabsContent value="history" class="space-y-4">
                <Card>
                    <CardHeader
                        ><CardTitle>{{
                            t('Stage history')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="overflow-x-auto">
                        <p
                            v-if="!history.data.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No history yet.') }}
                        </p>
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr
                                    class="text-muted-foreground border-b text-xs"
                                >
                                    <th
                                        class="py-2 pe-4 text-start font-medium"
                                    >
                                        {{ t('Date') }}
                                    </th>
                                    <th
                                        class="py-2 pe-4 text-start font-medium"
                                    >
                                        {{ t('Change') }}
                                    </th>
                                    <th class="py-2 text-start font-medium">
                                        {{ t('Notes') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in history.data"
                                    :key="entry.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-3 pe-4 align-top">
                                        <DateText
                                            :value="entry.changed_at"
                                            with-time
                                        />
                                    </td>
                                    <td class="py-3 pe-4 align-top">
                                        {{
                                            stageNames.get(
                                                entry.from_stage_id ?? 0,
                                            ) ??
                                            entry.snapshot?.from ??
                                            t('Created')
                                        }}
                                        →
                                        {{
                                            stageNames.get(entry.to_stage_id) ??
                                            entry.snapshot?.to ??
                                            '—'
                                        }}
                                    </td>
                                    <td class="py-3 align-top">
                                        {{ entry.notes || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle>{{
                            t('All events')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="text-muted-foreground border-b text-xs"
                                >
                                    <th
                                        class="py-2 pe-4 text-start font-medium"
                                    >
                                        {{ t('Date') }}
                                    </th>
                                    <th
                                        class="py-2 pe-4 text-start font-medium"
                                    >
                                        {{ t('Created by') }}
                                    </th>
                                    <th class="py-2 text-start font-medium">
                                        {{ t('Description') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in timeline.data"
                                    :key="entry.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-3 pe-4 align-top">
                                        <DateText
                                            :value="entry.created_at"
                                            with-time
                                        />
                                    </td>
                                    <td class="py-3 pe-4 align-top">
                                        {{ entry.actor?.name ?? t('System') }}
                                    </td>
                                    <td class="py-3 align-top">
                                        {{ humanEvent(entry.event) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>

        <CrmDealFormSheet
            v-model:open="editOpen"
            :pipelines="pipelines"
            :categories="categoryOptions ?? []"
            :deal="deal"
            @saved="reload"
        />
        <CrmDealTransferDialog
            v-model:open="transferOpen"
            :deal="deal"
            :pipelines="pipelines"
            :is-final="isFinal"
            @transferred="reload"
        />
        <CrmDealMoveDialog
            v-model:open="moveOpen"
            :deal="deal"
            :stages="stages"
            :initial-stage-id="moveStage"
            @moved="reload"
        />
    </div>
</template>
