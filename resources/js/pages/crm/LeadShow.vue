<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import CrmLeadActivityEditor from '@/components/CrmLeadActivityEditor.vue';
import CrmDealFormSheet from '@/components/CrmDealFormSheet.vue';
import { whatsappUrl } from '@/lib/crm-parties';
import CrmLeadStageBar from '@/components/CrmLeadStageBar.vue';
import DateText from '@/components/DateText.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import CrmLeadProducts from '@/components/CrmLeadProducts.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import type { CategoryOption, DealPipeline } from '@/types/crm-deals';
import type { Pipeline, TransitionOption } from '@/types/crm-pipeline';

type Entry = {
    id: number;
    at: string | null;
    actor: string;
    event: string;
    detail: string;
};
type Field = {
    key: string;
    name: string;
    type: string;
    value: string | number | boolean | string[] | null;
};
type Activity = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    creator: { name: string } | null;
};
type Tab = 'general' | 'activities' | 'products' | 'history';

const props = defineProps<{
    lead: {
        id: number;
        pipeline_id: number;
        current_stage_id: number;
        converted: boolean;
        assigned_to: number | null;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        company: string | null;
        city: string | null;
        source: string | null;
        notes: string | null;
        status: string;
        stage: { name: string; color: string } | null;
        assignee: { name: string } | null;
        created_at: string;
        updated_at: string;
    };
    pipeline: Pipeline;
    transitionOptions: TransitionOption[];
    canManageCrm: boolean;
    linkedDeal?: { id: number; title: string } | null;
    qualifiedForDeal?: boolean;
    members: { id: number; name: string }[];
    customFields: Field[];
    timeline: Entry[];
    historyFilters: { history_q?: string; history_event?: string };
    historyPagination: { total: number; page: number; per_page: number };
    activities: Activity[];
    canExportActivities: boolean;
    timezone: string;
}>();

const { t } = useLocale();
const historyQuery = ref(props.historyFilters.history_q ?? '');
const historyEvent = ref(props.historyFilters.history_event ?? '');
function loadHistory(history_page = 1): void {
    router.get(
        `/crm/leads/${props.lead.id}`,
        {
            history_q: historyQuery.value,
            history_event: historyEvent.value,
            history_page,
        },
        { preserveState: true, preserveScroll: true },
    );
}
const stageBar = ref<InstanceType<typeof CrmLeadStageBar> | null>(null);
/** First active lost-type stage that is not the current one, for the "Mark lost" shortcut. */
const lostStage = computed(() =>
    props.pipeline.stages.find(
        (stage) =>
            stage.active &&
            stage.type === 'lost' &&
            stage.id !== props.lead.current_stage_id,
    ),
);
const whatsappLink = computed(() => whatsappUrl(props.lead.phone));
const tabs: Tab[] = ['general', 'activities', 'products', 'history'];
const tab = ref<Tab>('general');
const fullName = computed(
    () => `${props.lead.first_name} ${props.lead.last_name}`,
);
const details = computed(() => [
    {
        label: 'Email',
        value: props.lead.email,
        href: props.lead.email ? `mailto:${props.lead.email}` : null,
    },
    {
        label: 'Phone',
        value: props.lead.phone,
        href: props.lead.phone ? `tel:${props.lead.phone}` : null,
    },
    { label: 'Company', value: props.lead.company, href: null },
    { label: 'City', value: props.lead.city, href: null },
    { label: 'Source', value: props.lead.source, href: null },
    {
        label: 'Responsible person',
        value: props.lead.assignee?.name ?? null,
        href: null,
    },
]);
const feed = computed(() =>
    [
        ...props.activities.map((activity) => ({
            key: `a${activity.id}`,
            kind: 'activity' as const,
            at: activity.created_at,
            actor: activity.creator?.name ?? 'System',
            title: activity.type,
            body: activity.notes,
            due: activity.due_at,
            done: activity.completed_at !== null,
        })),
        ...props.timeline.map((entry) => ({
            key: `h${entry.id}`,
            kind: 'history' as const,
            at: entry.at,
            actor: entry.actor,
            title: entry.detail,
            body: null,
            due: null,
            done: false,
        })),
    ].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')),
);

const dealSheetOpen = ref(false);
const dealPipelines = ref<DealPipeline[]>([]);
const dealCategories = ref<CategoryOption[]>([]);
const dealError = ref('');
const dealLoading = ref(false);
async function startDeal(): Promise<void> {
    dealLoading.value = true;
    dealError.value = '';
    try {
        const data = await apiJson<{
            pipelines: DealPipeline[];
            categoryOptions: CategoryOption[];
        }>('/crm/deals');
        dealPipelines.value = data.pipelines;
        dealCategories.value = data.categoryOptions;
        dealSheetOpen.value = true;
    } catch (cause) {
        dealError.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
    } finally {
        dealLoading.value = false;
    }
}
function dealCreated(deal: { id: number }): void {
    router.visit(`/deals/${deal.id}`);
}
function assign(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.put(
        `/crm/leads/${props.lead.id}/assignment`,
        { assigned_to: value ? Number(value) : null },
        { preserveScroll: true },
    );
}
function display(value: Field['value']): string {
    return Array.isArray(value)
        ? value.join(', ')
        : value === null || value === ''
          ? '—'
          : String(value);
}
function fromHash(): void {
    const hash = window.location.hash.slice(1) as Tab;
    if (tabs.includes(hash)) {
        tab.value = hash;
    }
}
onMounted(() => {
    fromHash();
    window.addEventListener('hashchange', fromHash);
});
onUnmounted(() => window.removeEventListener('hashchange', fromHash));
watch(tab, (value) => window.history.replaceState(null, '', `#${value}`));
</script>

<template>
    <Head :title="fullName" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="fullName" :eyebrow="t('Lead')" :translate="false">
            <template #meta>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <Badge
                        v-if="lead.stage"
                        variant="secondary"
                        :style="{ borderColor: lead.stage.color }"
                    >
                        {{ lead.stage.name }}
                    </Badge>
                    <Badge variant="outline">{{ lead.status }}</Badge>
                    <label
                        v-if="canManageCrm && !lead.converted && members.length"
                        class="text-muted-foreground flex items-center gap-2"
                        >{{ t('Responsible person') }}:
                        <select
                            :value="lead.assigned_to ?? ''"
                            class="border-input bg-background text-foreground h-8 rounded-md border px-2 text-sm"
                            @change="assign($event)"
                        >
                            <option value="">{{ t('Unassigned') }}</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                    </label>
                    <span v-else class="text-muted-foreground">
                        {{ t('Responsible person') }}:
                        {{ lead.assignee?.name ?? t('Unassigned') }}
                    </span>
                </div>
            </template>
            <template #actions>
                <Link
                    v-if="linkedDeal"
                    :href="`/deals/${linkedDeal.id}`"
                    class="text-primary text-sm font-medium underline"
                    >{{ t('Open deal') }}: {{ linkedDeal.title }}</Link
                >
                <Button
                    v-else-if="qualifiedForDeal && canManageCrm"
                    type="button"
                    :disabled="dealLoading"
                    @click="startDeal"
                    >{{ t('Create deal') }}</Button
                >
                <Button
                    v-if="lostStage && canManageCrm && !lead.converted"
                    type="button"
                    variant="outline"
                    @click="stageBar?.openStage(lostStage.id)"
                    >{{ t('Mark lost') }}</Button
                >
                <Button v-if="whatsappLink" as-child variant="outline">
                    <a
                        :href="whatsappLink"
                        target="_blank"
                        rel="noopener noreferrer"
                        >{{ t('WhatsApp') }}</a
                    >
                </Button>
                <Link href="/crm/leads" class="text-sm underline">
                    {{ t('Back to leads') }}
                </Link>
            </template>
        </PageHeader>

        <CrmLeadStageBar
            ref="stageBar"
            :lead-id="lead.id"
            :pipeline="pipeline"
            :current-stage-id="lead.current_stage_id"
            :transition-options="transitionOptions"
            :can-move="canManageCrm && !lead.converted"
        />

        <Tabs v-model="tab" class="gap-4">
            <TabsList :aria-label="t('Lead sections')">
                <TabsTrigger
                    v-for="section in tabs"
                    :key="section"
                    :value="section"
                    class="capitalize"
                >
                    {{ t(section) }}
                </TabsTrigger>
            </TabsList>

            <TabsContent
                value="general"
                class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)]"
            >
                <div class="flex flex-col gap-6">
                    <Card>
                        <CardHeader
                            ><CardTitle>{{
                                t('General')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent class="grid gap-4 sm:grid-cols-2">
                            <div v-for="item in details" :key="item.label">
                                <p class="text-muted-foreground text-xs">
                                    {{ t(item.label) }}
                                </p>
                                <a
                                    v-if="item.href && item.value"
                                    :href="item.href"
                                    class="text-primary text-sm break-words underline-offset-2 hover:underline"
                                    >{{ item.value }}</a
                                >
                                <p v-else class="text-sm break-words">
                                    {{ item.value || '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Created') }}
                                </p>
                                <DateText
                                    :value="lead.created_at"
                                    with-time
                                    class="text-sm"
                                />
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    {{ t('Modified') }}
                                </p>
                                <DateText
                                    :value="lead.updated_at"
                                    with-time
                                    class="text-sm"
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader
                            ><CardTitle>{{
                                t('Custom fields')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent class="grid gap-4 sm:grid-cols-2">
                            <p
                                v-if="!customFields.length"
                                class="text-muted-foreground text-sm sm:col-span-2"
                            >
                                {{
                                    t(
                                        'No additional fields are visible to you.',
                                    )
                                }}
                            </p>
                            <div v-for="field in customFields" :key="field.key">
                                <p class="text-muted-foreground text-xs">
                                    {{ field.name }}
                                </p>
                                <p class="text-sm break-words">
                                    {{ display(field.value) }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card v-if="lead.notes">
                        <CardHeader
                            ><CardTitle>{{ t('Notes') }}</CardTitle></CardHeader
                        >
                        <CardContent
                            ><p class="text-sm whitespace-pre-wrap">
                                {{ lead.notes }}
                            </p></CardContent
                        >
                    </Card>
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
                                        item.kind === 'activity'
                                            ? 'bg-primary'
                                            : 'bg-muted-foreground/50'
                                    "
                                    aria-hidden="true"
                                />
                                <p
                                    class="text-sm"
                                    :class="
                                        item.kind === 'activity'
                                            ? 'font-medium capitalize'
                                            : ''
                                    "
                                >
                                    {{ item.title }}
                                    <span
                                        v-if="item.done"
                                        class="text-muted-foreground text-xs font-normal normal-case"
                                        >· {{ t('completed') }}</span
                                    >
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
                                    <template v-if="item.due">
                                        · {{ t('due') }}
                                        <DateText :value="item.due" with-time
                                    /></template>
                                </p>
                            </li>
                        </ol>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="activities">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between gap-3"
                    >
                        <CardTitle>{{
                            t('Activities and comments')
                        }}</CardTitle>
                        <a
                            v-if="canExportActivities"
                            :href="`/crm/leads/export/activities?lead_id=${lead.id}`"
                            class="text-sm underline"
                        >
                            {{ t('Download this lead') }}
                        </a>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p
                            v-if="!activities.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No activities yet.') }}
                        </p>
                        <div
                            v-for="activity in activities"
                            :key="activity.id"
                            class="border-b pb-3 last:border-0"
                        >
                            <p class="font-medium capitalize">
                                {{ activity.type }}
                                <span
                                    v-if="activity.completed_at"
                                    class="text-muted-foreground text-xs font-normal"
                                    >· {{ t('completed') }}</span
                                >
                            </p>
                            <p
                                v-if="activity.notes"
                                class="text-sm whitespace-pre-wrap"
                            >
                                {{ activity.notes }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ activity.creator?.name ?? 'System' }} ·
                                <DateText
                                    :value="activity.created_at"
                                    with-time
                                />
                                <template v-if="activity.due_at">
                                    · {{ t('due') }}
                                    <DateText
                                        :value="activity.due_at"
                                        with-time
                                /></template>
                            </p>
                            <CrmLeadActivityEditor
                                v-if="
                                    canManageCrm &&
                                    !lead.converted &&
                                    !activity.completed_at
                                "
                                :activity="activity"
                                :timezone="timezone"
                            />
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="products">
                <CrmLeadProducts
                    :lead-id="lead.id"
                    :lead-name="fullName"
                    :can-edit="canManageCrm && !lead.converted"
                />
            </TabsContent>

            <TabsContent value="history">
                <Card>
                    <CardHeader
                        ><CardTitle>{{ t('History') }}</CardTitle></CardHeader
                    >
                    <CardContent class="overflow-x-auto">
                        <form
                            class="mb-4 flex flex-wrap gap-2"
                            @submit.prevent="loadHistory()"
                        >
                            <Input
                                v-model="historyQuery"
                                aria-label="Search history"
                                placeholder="Search events or people"
                                class="max-w-sm"
                            />
                            <Input
                                v-model="historyEvent"
                                aria-label="Event type"
                                placeholder="Event key (optional)"
                                class="max-w-sm"
                            />
                            <Button type="submit">{{ t('Search') }}</Button>
                        </form>
                        <p
                            v-if="!timeline.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No history yet.') }}
                        </p>
                        <table v-else class="w-full text-start text-sm">
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
                                    v-for="entry in timeline"
                                    :key="entry.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-3 pe-4 align-top">
                                        <DateText :value="entry.at" with-time />
                                    </td>
                                    <td class="py-3 pe-4 align-top">
                                        {{ entry.actor }}
                                    </td>
                                    <td class="py-3 align-top">
                                        {{ entry.detail }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="mt-4 flex items-center gap-3">
                            <Button
                                variant="outline"
                                :disabled="historyPagination.page <= 1"
                                @click="loadHistory(historyPagination.page - 1)"
                                >{{ t('Previous') }}</Button
                            >
                            <span class="text-muted-foreground text-sm"
                                >{{ historyPagination.total }}
                                {{ t('events') }}</span
                            >
                            <Button
                                variant="outline"
                                :disabled="
                                    historyPagination.page *
                                        historyPagination.per_page >=
                                    historyPagination.total
                                "
                                @click="loadHistory(historyPagination.page + 1)"
                                >{{ t('Next') }}</Button
                            >
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>
        <p v-if="dealError" class="text-destructive text-sm" role="alert">
            {{ dealError }}
        </p>
        <CrmDealFormSheet
            v-if="qualifiedForDeal && !linkedDeal"
            v-model:open="dealSheetOpen"
            :pipelines="dealPipelines"
            :categories="dealCategories"
            :lead-id="lead.id"
            :prefill="{
                title: fullName,
                first_name: lead.first_name,
                last_name: lead.last_name,
                email: lead.email,
                phone: lead.phone,
                company: lead.company,
                source: lead.source,
            }"
            @saved="dealCreated"
        />
    </div>
</template>
