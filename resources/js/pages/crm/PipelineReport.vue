<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

type Totals = {
    total: number;
    open: number;
    won: number;
    lost: number;
    unknown: number;
    created: number;
    converted: number;
    cohort_converted: number;
    win_rate: number | null;
    conversion_rate: number | null;
};
const props = defineProps<{
    filters: {
        from_date: string;
        to_date: string;
        pipeline_id: number | null;
        assignee_id: number | null;
    };
    cutoff: string;
    pipelines: { id: number; name: string; active: boolean }[];
    members: { id: number; name: string }[];
    assigneeScoped: boolean;
    limitedVisibility: boolean;
    summary: Totals;
    assignees: (Totals & { key: string; name: string })[];
    lostReasons: {
        key: string;
        pipeline: string;
        reason: string;
        count: number;
    }[];
    stageTimes: {
        key: string;
        pipeline: string;
        stage: string;
        type: string;
        leads: number;
        intervals: number;
        total_hours: number;
        average_hours: number;
    }[];
    stageMovements: {
        key: string;
        from_pipeline: string;
        from_stage: string;
        to_pipeline: string;
        to_stage: string;
        moves: number;
        leads: number;
        share_of_source_exits: number;
    }[];
}>();
const filters = reactive({ ...props.filters });
const page = usePage();
function apply(): void {
    router.get('/crm/pipeline-report', { ...filters });
}
function percent(value: number | null): string {
    return value === null ? '—' : `${value}%`;
}
</script>
<template>
    <Head title="CRM pipeline reporting" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM pipeline reporting"
            description="Review pipeline outcomes, customer conversion, stage movements and recorded stage time."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <div aria-live="polite">
            <InputError
                v-for="(error, field) in page.props.errors"
                :key="field"
                :message="error"
            />
        </div>
        <Card
            ><CardHeader><CardTitle>Report filters</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="apply"
                >
                    <div>
                        <Label for="report-from">{{ t('From') }}</Label
                        ><Input
                            id="report-from"
                            v-model="filters.from_date"
                            type="date"
                            required
                        />
                    </div>
                    <div>
                        <Label for="report-to">{{ t('To') }}</Label
                        ><Input
                            id="report-to"
                            v-model="filters.to_date"
                            type="date"
                            required
                            :min="filters.from_date"
                        />
                    </div>
                    <div>
                        <Label for="report-pipeline">Pipeline</Label
                        ><select
                            id="report-pipeline"
                            v-model="filters.pipeline_id"
                            class="h-9 rounded-md border px-3"
                        >
                            <option :value="null">All pipelines</option>
                            <option
                                v-for="pipeline in pipelines"
                                :key="pipeline.id"
                                :value="pipeline.id"
                            >
                                {{ pipeline.name
                                }}{{ !pipeline.active ? ' (inactive)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div v-if="!assigneeScoped">
                        <Label for="report-assignee">Current assignee</Label
                        ><select
                            id="report-assignee"
                            v-model="filters.assignee_id"
                            class="h-9 rounded-md border px-3"
                        >
                            <option :value="null">
                                {{
                                    limitedVisibility
                                        ? 'All accessible assignees'
                                        : 'All assignees'
                                }}
                            </option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                    </div>
                    <p v-else class="text-muted-foreground self-end text-sm">
                        Showing leads within your access
                    </p>
                    <Button>{{ t('Apply filters') }}</Button>
                </form></CardContent
            ></Card
        >
        <p class="text-muted-foreground text-sm">
            Outcomes use the last recorded stage at {{ cutoff }}. Assignee
            attribution uses current assignments. Customer conversion is the
            explicit contact/account conversion action; moving to Won alone is a
            pipeline outcome.
        </p>
        <Card
            ><CardHeader
                ><CardTitle>Outcomes at period end</CardTitle></CardHeader
            ><CardContent class="grid gap-4 sm:grid-cols-3 md:grid-cols-6"
                ><div>
                    <p class="text-sm">Leads</p>
                    <p class="text-2xl font-semibold">{{ summary.total }}</p>
                </div>
                <div>
                    <p class="text-sm">Open / on hold</p>
                    <p class="text-2xl font-semibold">{{ summary.open }}</p>
                </div>
                <div>
                    <p class="text-sm">Won</p>
                    <p class="text-2xl font-semibold">{{ summary.won }}</p>
                </div>
                <div>
                    <p class="text-sm">Lost</p>
                    <p class="text-2xl font-semibold">{{ summary.lost }}</p>
                </div>
                <div>
                    <p class="text-sm">Untracked</p>
                    <p class="text-2xl font-semibold">{{ summary.unknown }}</p>
                </div>
                <div>
                    <p class="text-sm">Closed-lead win rate</p>
                    <p class="text-2xl font-semibold">
                        {{ percent(summary.win_rate) }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Won ÷ (Won + Lost)
                    </p>
                </div></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Activity during period</CardTitle></CardHeader
            ><CardContent class="grid gap-4 sm:grid-cols-3"
                ><div>
                    <p class="text-sm">New leads</p>
                    <p class="text-2xl font-semibold">{{ summary.created }}</p>
                </div>
                <div>
                    <p class="text-sm">Customer conversions</p>
                    <p class="text-2xl font-semibold">
                        {{ summary.converted }}
                    </p>
                </div>
                <div>
                    <p class="text-sm">New-lead conversion rate</p>
                    <p class="text-2xl font-semibold">
                        {{ percent(summary.conversion_rate) }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ summary.cohort_converted }} converted ÷
                        {{ summary.created }} created in this period
                    </p>
                </div></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Current assignee performance</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">
                        Outcomes at period end and conversion activity by
                        current assignee
                    </caption>
                    <thead>
                        <tr>
                            <th class="p-2" scope="col">{{ t('Assignee') }}</th>
                            <th scope="col">Leads</th>
                            <th scope="col">{{ t('Open') }}</th>
                            <th scope="col">Won</th>
                            <th scope="col">Lost</th>
                            <th scope="col">Untracked</th>
                            <th scope="col">Win rate</th>
                            <th scope="col">Conversions</th>
                            <th scope="col">New-lead conversion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="agent in assignees"
                            :key="agent.key"
                            class="border-t"
                        >
                            <th class="p-2 font-normal" scope="row">
                                {{ agent.name }}
                            </th>
                            <td>{{ agent.total }}</td>
                            <td>{{ agent.open }}</td>
                            <td>{{ agent.won }}</td>
                            <td>{{ agent.lost }}</td>
                            <td>{{ agent.unknown }}</td>
                            <td>{{ percent(agent.win_rate) }}</td>
                            <td>{{ agent.converted }}</td>
                            <td>{{ percent(agent.conversion_rate) }}</td>
                        </tr>
                        <tr v-if="!assignees.length">
                            <td colspan="9" class="text-muted-foreground p-3">
                                No leads in this scope.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Lost reasons at period end</CardTitle></CardHeader
            ><CardContent
                ><p class="text-muted-foreground mb-3 text-sm">
                    Historical reason names are preserved. Leads reopened before
                    the period end are excluded.
                </p>
                <div
                    v-for="reason in lostReasons"
                    :key="reason.key"
                    class="flex justify-between gap-3 border-b py-2"
                >
                    <span>{{ reason.pipeline }} · {{ reason.reason }}</span
                    ><span>{{ reason.count }}</span>
                </div>
                <p
                    v-if="!lostReasons.length"
                    class="text-muted-foreground text-sm"
                >
                    No recorded lost outcomes.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Stage-to-stage movements</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><p class="text-muted-foreground mb-3 text-sm">
                    Observed moves during the selected dates, including
                    cross-pipeline transfers and moves into Won or Lost. New
                    lead creation is excluded. A repeated move counts again;
                    unique leads are shown separately. The share uses all
                    observed exits from the source stage. Names reflect each
                    move's recorded history.
                </p>
                <table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">
                        Observed stage-to-stage movements during the period
                    </caption>
                    <thead>
                        <tr>
                            <th class="p-2" scope="col">{{ t('From') }}</th>
                            <th scope="col">{{ t('To') }}</th>
                            <th scope="col">Moves</th>
                            <th scope="col">Unique leads</th>
                            <th scope="col">Share of source exits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="movement in stageMovements"
                            :key="movement.key"
                            class="border-t"
                        >
                            <th class="p-2 font-normal" scope="row">
                                {{ movement.from_pipeline }} ·
                                {{ movement.from_stage }}
                            </th>
                            <td>
                                {{ movement.to_pipeline }} ·
                                {{ movement.to_stage }}
                            </td>
                            <td>{{ movement.moves }}</td>
                            <td>{{ movement.leads }}</td>
                            <td>
                                {{ percent(movement.share_of_source_exits) }}
                            </td>
                        </tr>
                        <tr v-if="!stageMovements.length">
                            <td colspan="5" class="text-muted-foreground p-3">
                                No recorded stage movements in this period.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Observed time in open stages</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><p class="text-muted-foreground mb-3 text-sm">
                    Intervals are clipped to the selected dates. Terminal
                    Won/Lost waiting time is excluded; reopening starts a new
                    interval. Missing history and time before migration tracking
                    began are excluded. Names reflect recorded history.
                </p>
                <table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">
                        Observed open-stage hours during the period
                    </caption>
                    <thead>
                        <tr>
                            <th class="p-2" scope="col">Pipeline / stage</th>
                            <th scope="col">Leads</th>
                            <th scope="col">Intervals</th>
                            <th scope="col">Total hours</th>
                            <th scope="col">Average hours per interval</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="stage in stageTimes"
                            :key="stage.key"
                            class="border-t"
                        >
                            <th class="p-2 font-normal" scope="row">
                                {{ stage.pipeline }} · {{ stage.stage }}
                            </th>
                            <td>{{ stage.leads }}</td>
                            <td>{{ stage.intervals }}</td>
                            <td>{{ stage.total_hours }}</td>
                            <td>{{ stage.average_hours }}</td>
                        </tr>
                        <tr v-if="!stageTimes.length">
                            <td colspan="5" class="text-muted-foreground p-3">
                                No observed open-stage time in this period.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
    </div>
</template>
