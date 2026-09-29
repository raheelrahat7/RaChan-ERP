<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
type Rule = {
    id: number;
    name: string;
    pipeline_id: number;
    stage_id: number | null;
    trigger: string;
    condition_field: string | null;
    condition_operator: string | null;
    condition_value: string | null;
    action: string;
    due_days: number | null;
    active: boolean;
};
type Pipeline = {
    id: number;
    name: string;
    active: boolean;
    stages: { id: number; name: string; active: boolean }[];
};
const props = defineProps<{
    rules: Rule[];
    pipelines: Pipeline[];
    conditionFields: { key: string; name: string }[];
}>();
const selected = ref<Rule | null>(null);
const form = useForm({
    name: '',
    pipeline_id: props.pipelines[0]?.id ?? 0,
    stage_id: null as number | null,
    trigger: 'stage_entered',
    condition_field: '',
    condition_operator: '',
    condition_value: '',
    action: 'notify_assignee',
    due_days: 0,
    active: true,
});
const stages = computed(
    () =>
        props.pipelines.find(
            (pipeline) => pipeline.id === Number(form.pipeline_id),
        )?.stages ?? [],
);
function edit(rule: Rule): void {
    selected.value = rule;
    form.name = rule.name;
    form.pipeline_id = rule.pipeline_id;
    form.stage_id = rule.stage_id;
    form.trigger = rule.trigger;
    form.condition_field = rule.condition_field ?? '';
    form.condition_operator = rule.condition_operator ?? '';
    form.condition_value = rule.condition_value ?? '';
    form.action = rule.action;
    form.due_days = rule.due_days ?? 0;
    form.active = rule.active;
}
function reset(): void {
    selected.value = null;
    form.reset();
    form.clearErrors();
}
function save(): void {
    const payload = {
        ...form.data(),
        condition_field: form.condition_field || null,
        condition_operator: form.condition_operator || null,
        condition_value: form.condition_value || null,
    };
    const options = { preserveScroll: true, onSuccess: reset };
    if (selected.value)
        form.transform(() => payload).put(
            `/crm/automation/${selected.value.id}`,
            options,
        );
    else form.transform(() => payload).post('/crm/automation', options);
}
function disable(rule: Rule): void {
    if (confirm(`Disable ${rule.name}?`))
        router.delete(`/crm/automation/${rule.id}`, { preserveScroll: true });
}
</script>
<template>
    <Head title="CRM automation" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM automation"
            description="Run a notification or follow-up once when a lead enters a configured pipeline stage."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]"
        >
            <Card
                ><CardHeader
                    ><CardTitle>Rules and triggers</CardTitle></CardHeader
                ><CardContent class="space-y-3"
                    ><p
                        v-if="!rules.length"
                        class="text-muted-foreground text-sm"
                    >
                        No rules yet. Existing stage notifications and
                        follow-ups continue to work.
                    </p>
                    <div
                        v-for="rule in rules"
                        :key="rule.id"
                        class="flex flex-wrap justify-between gap-3 rounded-md border p-3"
                    >
                        <div>
                            <p class="font-medium">
                                {{ rule.name }}
                                <span
                                    v-if="!rule.active"
                                    class="text-muted-foreground"
                                    >(disabled)</span
                                >
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ rule.trigger.replace('_', ' ') }} ·
                                {{ rule.action.replaceAll('_', ' ') }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="edit(rule)"
                                >Edit</Button
                            ><Button
                                v-if="rule.active"
                                variant="ghost"
                                size="sm"
                                @click="disable(rule)"
                                >Disable</Button
                            >
                        </div>
                    </div></CardContent
                ></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle>{{
                        selected ? 'Edit rule' : 'Create rule'
                    }}</CardTitle></CardHeader
                ><CardContent
                    ><form class="space-y-4" @submit.prevent="save">
                        <div>
                            <Label for="rule-name">Rule name</Label
                            ><Input
                                id="rule-name"
                                v-model="form.name"
                                required
                                maxlength="255"
                            />
                        </div>
                        <div>
                            <Label for="rule-pipeline">Pipeline</Label
                            ><select
                                id="rule-pipeline"
                                v-model="form.pipeline_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                                @change="form.stage_id = null"
                            >
                                <option
                                    v-for="pipeline in pipelines.filter(
                                        (item) => item.active,
                                    )"
                                    :key="pipeline.id"
                                    :value="pipeline.id"
                                >
                                    {{ pipeline.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="rule-stage">Stage</Label
                            ><select
                                id="rule-stage"
                                v-model="form.stage_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                            >
                                <option :value="null">Any stage</option>
                                <option
                                    v-for="stage in stages.filter(
                                        (item) => item.active,
                                    )"
                                    :key="stage.id"
                                    :value="stage.id"
                                >
                                    {{ stage.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="rule-trigger">Trigger</Label
                            ><select
                                id="rule-trigger"
                                v-model="form.trigger"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                            >
                                <option value="stage_entered">
                                    Stage entered
                                </option>
                                <option value="lead_created">
                                    Lead created
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="rule-field"
                                >Condition field (optional)</Label
                            ><select
                                id="rule-field"
                                v-model="form.condition_field"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                            >
                                <option value="">Always</option>
                                <option
                                    v-for="field in conditionFields"
                                    :key="field.key"
                                    :value="field.key"
                                >
                                    {{ field.name }}
                                </option>
                            </select>
                        </div>
                        <div
                            v-if="form.condition_field"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <div>
                                <Label for="rule-operator">Operator</Label
                                ><select
                                    id="rule-operator"
                                    v-model="form.condition_operator"
                                    class="border-input bg-background h-9 w-full rounded-md border px-3"
                                >
                                    <option value="">Choose</option>
                                    <option
                                        v-for="operator in [
                                            'equals',
                                            'not_equals',
                                            'contains',
                                            'empty',
                                            'not_empty',
                                        ]"
                                        :key="operator"
                                        :value="operator"
                                    >
                                        {{ operator.replace('_', ' ') }}
                                    </option>
                                </select>
                            </div>
                            <div
                                v-if="
                                    !['empty', 'not_empty'].includes(
                                        form.condition_operator,
                                    )
                                "
                            >
                                <Label for="rule-value">Value</Label
                                ><Input
                                    id="rule-value"
                                    v-model="form.condition_value"
                                    maxlength="255"
                                />
                            </div>
                        </div>
                        <div>
                            <Label for="rule-action">Action</Label
                            ><select
                                id="rule-action"
                                v-model="form.action"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                            >
                                <option value="notify_assignee">
                                    Notify responsible person in app
                                </option>
                                <option value="create_follow_up">
                                    Create follow-up task
                                </option>
                            </select>
                        </div>
                        <div v-if="form.action === 'create_follow_up'">
                            <Label for="rule-days">Due after days</Label
                            ><Input
                                id="rule-days"
                                v-model.number="form.due_days"
                                type="number"
                                min="0"
                                max="365"
                                required
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.active"
                                type="checkbox"
                            />Active</label
                        >
                        <div aria-live="polite">
                            <InputError
                                v-for="(error, key) in form.errors"
                                :key="key"
                                :message="error"
                            />
                        </div>
                        <div class="flex gap-2">
                            <Button :disabled="form.processing">{{
                                selected ? 'Save rule' : 'Create rule'
                            }}</Button
                            ><Button
                                v-if="selected"
                                type="button"
                                variant="outline"
                                @click="reset"
                                >Cancel</Button
                            >
                        </div>
                    </form></CardContent
                ></Card
            >
        </div>
    </div>
</template>
