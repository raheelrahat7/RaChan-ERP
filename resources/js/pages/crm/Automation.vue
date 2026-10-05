<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrmAutomationRulePanel from '@/components/CrmAutomationRulePanel.vue';
import DateText from '@/components/DateText.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    actionLabel,
    formatDelay,
    groupRules,
    outcomeLabel,
    outcomeTone,
    ruleTarget,
} from '@/lib/crm-automation-board';
import type { Execution, Rule } from '@/lib/crm-automation-board';
import { readableOn } from '@/lib/crm-pipeline-board';

type Stage = {
    id: number;
    name: string;
    type: string;
    active: boolean;
    color?: string;
    position?: number;
};
type Pipeline = { id: number; name: string; active: boolean; stages: Stage[] };

const props = defineProps<{
    rules: Rule[];
    pipelines: Pipeline[];
    conditionFields: { key: string; name: string }[];
    executionHistory: Execution[];
    actionCatalog: string[];
    activityTypes: string[];
}>();

const { t } = useLocale();
const pipelineId = ref(
    props.pipelines.find((pipeline) => pipeline.active)?.id ??
        props.pipelines[0]?.id ??
        0,
);
const query = ref('');
const panelOpen = ref(false);
const editing = ref<Rule | null>(null);
const panelStage = ref<number | null>(null);
const panelTrigger = ref<'stage_entered' | 'lead_created'>('stage_entered');

const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === Number(pipelineId.value)),
);
const stages = computed(() =>
    [...(pipeline.value?.stages ?? [])].sort(
        (a, b) => (a.position ?? a.id) - (b.position ?? b.id),
    ),
);
const lanes = computed(() =>
    groupRules(props.rules, Number(pipelineId.value), query.value),
);
const ruleNames = computed(
    () => new Map(props.rules.map((rule) => [rule.id, rule.name])),
);
const stageNames = computed(
    () =>
        new Map(
            props.pipelines.flatMap((item) =>
                item.stages.map((stage) => [stage.id, stage.name] as const),
            ),
        ),
);
const history = computed(() =>
    props.executionHistory.filter(
        (entry) =>
            props.rules.find((rule) => rule.id === entry.rule_id)
                ?.pipeline_id === Number(pipelineId.value),
    ),
);

function stageName(id: number | null | undefined): string {
    return (id ? stageNames.value.get(id) : null) ?? t('Unknown stage');
}
function add(
    stage: number | null,
    trigger: 'stage_entered' | 'lead_created',
): void {
    editing.value = null;
    panelStage.value = stage;
    panelTrigger.value = trigger;
    panelOpen.value = true;
}
function edit(rule: Rule): void {
    editing.value = rule;
    panelOpen.value = true;
}
function disable(rule: Rule): void {
    if (confirm(`${t('Disable')} ${rule.name}?`)) {
        router.delete(`/crm/automation/${rule.id}`, {
            preserveScroll: true,
            onSuccess: () => (panelOpen.value = false),
        });
    }
}
function headStyle(stage: Stage): Record<string, string> {
    return stage.color
        ? { backgroundColor: stage.color, color: readableOn(stage.color) }
        : {};
}
</script>

<template>
    <Head :title="t('CRM automation')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Automation rules and triggers')"
            :translate="false"
        >
            <template #actions>
                <Link href="/crm/leads" class="text-sm underline">{{
                    t('Back to leads')
                }}</Link>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <select
                v-model="pipelineId"
                :aria-label="t('Pipeline')"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option
                    v-for="item in pipelines"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}{{ !item.active ? ' (inactive)' : '' }}
                </option>
            </select>
            <div class="relative min-w-56 flex-1 sm:max-w-md">
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    :aria-label="t('Search rules')"
                    :placeholder="t('Search')"
                />
            </div>
        </div>

        <section :aria-label="t('Triggers')" class="space-y-2">
            <h2 class="text-eyebrow">{{ t('Triggers') }}</h2>
            <div class="flex flex-wrap gap-3">
                <article
                    v-for="rule in lanes.triggers"
                    :key="rule.id"
                    class="bg-card w-72 space-y-1 rounded-md border p-3 text-sm shadow-xs"
                    :class="{ 'opacity-60': !rule.active }"
                >
                    <p class="text-primary text-xs">
                        {{
                            rule.trigger === 'lead_created'
                                ? t('When a lead is created')
                                : t('Any stage')
                        }}
                        · {{ t(formatDelay(rule.delay_minutes)) }}
                    </p>
                    <p class="font-medium">
                        {{ rule.name }}
                        <Badge v-if="!rule.active" variant="outline">{{
                            t('disabled')
                        }}</Badge>
                    </p>
                    <p class="text-muted-foreground text-xs capitalize">
                        {{ t(actionLabel(rule.action)) }} ·
                        {{ ruleTarget(rule, stageName) }}
                    </p>
                    <button
                        type="button"
                        class="text-primary text-xs underline"
                        @click="edit(rule)"
                    >
                        {{ t('edit') }}
                    </button>
                </article>
                <button
                    type="button"
                    class="text-muted-foreground hover:border-primary hover:text-primary flex h-20 w-72 items-center justify-center gap-1 rounded-md border border-dashed text-sm"
                    @click="add(null, 'lead_created')"
                >
                    <Plus class="size-4" aria-hidden="true" />{{
                        t('Add trigger')
                    }}
                </button>
            </div>
        </section>

        <section :aria-label="t('Automation rules')" class="space-y-2">
            <h2 class="text-eyebrow">{{ t('Automation rules') }}</h2>
            <p v-if="!stages.length" class="text-muted-foreground text-sm">
                {{ t('This pipeline has no stages yet.') }}
            </p>
            <div
                class="flex gap-2 overflow-x-auto pb-4"
                role="region"
                tabindex="0"
                :aria-label="t('Rules by stage')"
            >
                <section
                    v-for="stage in stages"
                    :key="stage.id"
                    class="bg-muted/40 flex min-h-48 w-72 shrink-0 flex-col rounded-md"
                    :aria-labelledby="`auto-stage-${stage.id}`"
                >
                    <h3
                        :id="`auto-stage-${stage.id}`"
                        class="auto-head truncate px-4 py-2 text-sm font-medium"
                        :class="stage.color ? '' : 'bg-primary/15'"
                        :style="headStyle(stage)"
                    >
                        {{ stage.name
                        }}<span v-if="!stage.active">
                            ({{ t('inactive') }})</span
                        >
                    </h3>
                    <div class="flex-1 space-y-2 p-2">
                        <button
                            type="button"
                            class="text-muted-foreground hover:border-primary hover:text-primary flex h-9 w-full items-center justify-center rounded-md border border-dashed"
                            :aria-label="`${t('Add rule')}: ${stage.name}`"
                            @click="add(stage.id, 'stage_entered')"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                        </button>
                        <article
                            v-for="rule in lanes.byStage.get(stage.id) ?? []"
                            :key="rule.id"
                            class="bg-card space-y-1 rounded-md border p-3 text-sm shadow-xs"
                            :class="{ 'opacity-60': !rule.active }"
                        >
                            <p class="text-primary text-xs">
                                {{ t(formatDelay(rule.delay_minutes)) }}
                            </p>
                            <p class="font-medium">
                                {{ rule.name }}
                                <Badge v-if="!rule.active" variant="outline">{{
                                    t('disabled')
                                }}</Badge>
                            </p>
                            <p class="text-muted-foreground text-xs capitalize">
                                {{ t(actionLabel(rule.action)) }}
                            </p>
                            <p class="text-xs">
                                {{ ruleTarget(rule, stageName) }}
                            </p>
                            <p
                                v-if="rule.condition_field"
                                class="text-muted-foreground text-xs"
                            >
                                {{ t('If') }}
                                {{
                                    conditionFields.find(
                                        (field) =>
                                            field.key === rule.condition_field,
                                    )?.name ?? rule.condition_field
                                }}
                                {{ rule.condition_operator?.replace('_', ' ') }}
                                {{ rule.condition_value }}
                            </p>
                            <button
                                type="button"
                                class="text-primary text-xs underline"
                                @click="edit(rule)"
                            >
                                {{ t('edit') }}
                            </button>
                        </article>
                    </div>
                </section>
            </div>
        </section>

        <details class="group">
            <summary class="cursor-pointer text-sm font-medium">
                {{ t('Recent runs') }} ({{ history.length }})
            </summary>
            <div class="mt-2 overflow-x-auto rounded-md border">
                <p
                    v-if="!history.length"
                    class="text-muted-foreground p-4 text-sm"
                >
                    {{ t('No runs yet.') }}
                </p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-xs">
                            <th class="p-2 text-start font-medium">
                                {{ t('Rule') }}
                            </th>
                            <th class="p-2 text-start font-medium">
                                {{ t('Outcome') }}
                            </th>
                            <th class="p-2 text-start font-medium">
                                {{ t('Scheduled') }}
                            </th>
                            <th class="p-2 text-start font-medium">
                                {{ t('Details') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in history"
                            :key="entry.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-2">
                                {{
                                    ruleNames.get(entry.rule_id) ??
                                    `#${entry.rule_id}`
                                }}
                            </td>
                            <td class="p-2">
                                <Badge
                                    :variant="
                                        outcomeTone(entry.outcome) === 'bad'
                                            ? 'destructive'
                                            : outcomeTone(entry.outcome) ===
                                                'ok'
                                              ? 'secondary'
                                              : 'outline'
                                    "
                                    class="capitalize"
                                    >{{ outcomeLabel(entry.outcome) }}</Badge
                                >
                            </td>
                            <td class="p-2">
                                <DateText
                                    :value="
                                        entry.scheduled_at ?? entry.created_at
                                    "
                                    with-time
                                />
                            </td>
                            <td class="text-muted-foreground p-2">
                                {{ entry.failure_reason ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>

        <CrmAutomationRulePanel
            v-if="pipeline"
            v-model:open="panelOpen"
            :pipeline-id="pipeline.id"
            :stages="pipeline.stages"
            :stage-id="panelStage"
            :trigger="panelTrigger"
            :rule="editing"
            :condition-fields="conditionFields"
            :activity-types="activityTypes"
            @disable="disable"
        />
    </div>
</template>

<style scoped>
.auto-head {
    clip-path: polygon(
        0 0,
        calc(100% - 0.75rem) 0,
        100% 50%,
        calc(100% - 0.75rem) 100%,
        0 100%
    );
}
[dir='rtl'] .auto-head {
    clip-path: polygon(100% 0, 0.75rem 0, 0 50%, 0.75rem 100%, 100% 100%);
}
</style>
