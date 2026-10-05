<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import {
    ACTION_GROUPS,
    actionLabel,
    joinDelay,
    splitDelay,
} from '@/lib/crm-automation-board';
import type { DelayUnit, Rule } from '@/lib/crm-automation-board';

type Stage = { id: number; name: string; type: string; active: boolean };

const props = defineProps<{
    pipelineId: number;
    stages: Stage[];
    stageId: number | null;
    trigger: 'stage_entered' | 'lead_created';
    rule: Rule | null;
    conditionFields: { key: string; name: string }[];
    activityTypes: string[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ disable: [rule: Rule] }>();

const { t } = useLocale();
const picking = ref(true);
const groupKey = ref(ACTION_GROUPS[0].key);
const form = useForm({
    name: '',
    pipeline_id: props.pipelineId,
    stage_id: null as number | null,
    trigger: 'stage_entered' as string,
    condition_field: '',
    condition_operator: 'equals',
    condition_value: '',
    action: 'notify_assignee',
    delay_amount: 0,
    delay_unit: 'minutes' as DelayUnit,
    activity_type: 'call',
    due_days: 0,
    target_stage_id: null as number | null,
    active: true,
});
const group = computed(
    () =>
        ACTION_GROUPS.find((item) => item.key === groupKey.value) ??
        ACTION_GROUPS[0],
);
const stageName = computed(
    () =>
        props.stages.find((stage) => stage.id === form.stage_id)?.name ??
        t('Any stage'),
);
const destinations = computed(() =>
    props.stages.filter(
        (stage) =>
            stage.active &&
            stage.type === 'normal' &&
            stage.id !== form.stage_id,
    ),
);
const needsValue = computed(
    () =>
        form.condition_field !== '' &&
        !['empty', 'not_empty'].includes(form.condition_operator),
);

function load(): void {
    form.clearErrors();
    const rule = props.rule;
    if (rule) {
        const delay = splitDelay(rule.delay_minutes);
        form.defaults({
            name: rule.name,
            pipeline_id: rule.pipeline_id,
            stage_id: rule.stage_id,
            trigger: rule.trigger,
            condition_field: rule.condition_field ?? '',
            condition_operator: rule.condition_operator ?? 'equals',
            condition_value: rule.condition_value ?? '',
            action: rule.action,
            delay_amount: delay.amount,
            delay_unit: delay.unit,
            activity_type: rule.activity_type ?? 'call',
            due_days: rule.due_days ?? 0,
            target_stage_id: rule.target_stage_id ?? null,
            active: rule.active,
        }).reset();
        picking.value = false;

        return;
    }
    form.defaults({
        name: '',
        pipeline_id: props.pipelineId,
        stage_id: props.trigger === 'lead_created' ? null : props.stageId,
        trigger: props.trigger,
        condition_field: '',
        condition_operator: 'equals',
        condition_value: '',
        action: 'notify_assignee',
        delay_amount: 0,
        delay_unit: 'minutes',
        activity_type: 'call',
        due_days: 0,
        target_stage_id: null,
        active: true,
    }).reset();
    picking.value = true;
}
function choose(action: string): void {
    form.action = action;
    if (!form.name) {
        form.name = `${actionLabel(action)}${form.stage_id ? ` · ${stageName.value}` : ''}`;
    }
    picking.value = false;
}
function save(): void {
    const follow = form.action === 'create_follow_up';
    form.transform((data) => ({
        name: data.name,
        pipeline_id: data.pipeline_id,
        stage_id: data.trigger === 'lead_created' ? null : data.stage_id,
        trigger: data.trigger,
        condition_field: data.condition_field || null,
        condition_operator: data.condition_field
            ? data.condition_operator
            : null,
        condition_value: needsValue.value ? data.condition_value : null,
        action: data.action,
        delay_minutes: joinDelay(Number(data.delay_amount), data.delay_unit),
        activity_type: follow ? data.activity_type : undefined,
        due_days: follow ? Number(data.due_days) : null,
        target_stage_id:
            data.action === 'change_stage' ? data.target_stage_id : null,
        active: data.active,
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    if (props.rule) {
        form.put(`/crm/automation/${props.rule.id}`, options);
    } else {
        form.post('/crm/automation', options);
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-3xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">
                    {{ rule ? t('Edit rule') : t('Add rule') }}
                    <span class="text-muted-foreground text-base font-normal"
                        >·
                        {{
                            trigger === 'lead_created' && !rule
                                ? t('When a lead is created')
                                : stageName
                        }}</span
                    >
                </SheetTitle>
                <SheetDescription>{{
                    picking
                        ? t('Choose what the rule should do.')
                        : t('Configure when and how it runs.')
                }}</SheetDescription>
            </SheetHeader>

            <div
                v-if="picking"
                class="grid min-h-0 flex-1 sm:grid-cols-[14rem_minmax(0,1fr)]"
            >
                <ul
                    class="bg-muted/40 space-y-1 border-b p-3 sm:border-e sm:border-b-0"
                    role="tablist"
                    :aria-label="t('Rule groups')"
                >
                    <li v-for="item in ACTION_GROUPS" :key="item.key">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="groupKey === item.key"
                            class="hover:bg-muted w-full rounded-md px-3 py-2 text-start text-sm"
                            :class="
                                groupKey === item.key
                                    ? 'bg-background border font-medium'
                                    : ''
                            "
                            @click="groupKey = item.key"
                        >
                            {{ t(item.label) }}
                        </button>
                    </li>
                </ul>
                <div class="space-y-2 overflow-y-auto p-4" role="tabpanel">
                    <button
                        v-for="item in group.actions"
                        :key="item.action"
                        type="button"
                        class="hover:border-primary w-full rounded-md border p-4 text-start"
                        @click="choose(item.action)"
                    >
                        <span class="block font-medium">{{
                            t(item.label)
                        }}</span>
                        <span class="text-muted-foreground block text-sm">{{
                            t(item.hint)
                        }}</span>
                    </button>
                </div>
            </div>

            <form
                v-else
                id="crm-automation-rule"
                class="flex-1 space-y-6 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="font-medium">{{ t(actionLabel(form.action)) }}</p>
                    <Button
                        v-if="!rule"
                        type="button"
                        variant="link"
                        size="sm"
                        @click="picking = true"
                        >{{ t('Change action') }}</Button
                    >
                </div>
                <div class="space-y-1">
                    <Label for="rule-name">{{ t('Rule name') }} *</Label>
                    <Input
                        id="rule-name"
                        v-model="form.name"
                        required
                        maxlength="255"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="rule-trigger">{{ t('Trigger') }}</Label>
                        <select
                            id="rule-trigger"
                            v-model="form.trigger"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="stage_entered">
                                {{ t('Lead enters a stage') }}
                            </option>
                            <option value="lead_created">
                                {{ t('Lead is created') }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="form.trigger === 'stage_entered'"
                        class="space-y-1"
                    >
                        <Label for="rule-stage">{{ t('Stage') }}</Label>
                        <select
                            id="rule-stage"
                            v-model="form.stage_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option :value="null">{{ t('Any stage') }}</option>
                            <option
                                v-for="stage in stages"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.stage_id" />
                    </div>
                </div>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        {{ t('Timing') }}
                    </legend>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <label class="flex items-center gap-2"
                            ><input
                                type="radio"
                                :checked="Number(form.delay_amount) === 0"
                                @change="form.delay_amount = 0"
                            />{{ t('Immediately') }}</label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                type="radio"
                                :checked="Number(form.delay_amount) > 0"
                                @change="
                                    form.delay_amount =
                                        Number(form.delay_amount) > 0
                                            ? form.delay_amount
                                            : 30
                                "
                            />{{ t('After a delay') }}</label
                        >
                        <template v-if="Number(form.delay_amount) > 0">
                            <Input
                                v-model="form.delay_amount"
                                type="number"
                                min="1"
                                class="h-9 w-24"
                                :aria-label="t('Delay amount')"
                            />
                            <select
                                v-model="form.delay_unit"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                :aria-label="t('Delay unit')"
                            >
                                <option value="minutes">
                                    {{ t('minutes') }}
                                </option>
                                <option value="hours">{{ t('hours') }}</option>
                                <option value="days">{{ t('days') }}</option>
                            </select>
                        </template>
                    </div>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>)
                                .delay_minutes
                        "
                    />
                </fieldset>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        {{ t('Condition (optional)') }}
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <select
                            v-model="form.condition_field"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            :aria-label="t('Condition field')"
                        >
                            <option value="">{{ t('Always run') }}</option>
                            <option
                                v-for="field in conditionFields"
                                :key="field.key"
                                :value="field.key"
                            >
                                {{ field.name }}
                            </option>
                        </select>
                        <select
                            v-if="form.condition_field"
                            v-model="form.condition_operator"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            :aria-label="t('Condition operator')"
                        >
                            <option value="equals">{{ t('Equals') }}</option>
                            <option value="not_equals">
                                {{ t('Not equal') }}
                            </option>
                            <option value="contains">
                                {{ t('Contains') }}
                            </option>
                            <option value="empty">{{ t('Empty') }}</option>
                            <option value="not_empty">
                                {{ t('Not empty') }}
                            </option>
                        </select>
                        <Input
                            v-if="needsValue"
                            v-model="form.condition_value"
                            :aria-label="t('Condition value')"
                            maxlength="255"
                        />
                    </div>
                    <InputError
                        :message="
                            form.errors.condition_field ||
                            form.errors.condition_operator ||
                            form.errors.condition_value
                        "
                    />
                </fieldset>
                <div
                    v-if="form.action === 'create_follow_up'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="space-y-1">
                        <Label for="rule-activity">{{
                            t('Activity type')
                        }}</Label>
                        <select
                            id="rule-activity"
                            v-model="form.activity_type"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm capitalize"
                        >
                            <option
                                v-for="type in activityTypes"
                                :key="type"
                                :value="type"
                            >
                                {{ type }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="rule-due">{{
                            t('Due after (days)')
                        }}</Label>
                        <Input
                            id="rule-due"
                            v-model="form.due_days"
                            type="number"
                            min="0"
                            max="365"
                        />
                        <InputError :message="form.errors.due_days" />
                    </div>
                </div>
                <div v-if="form.action === 'change_stage'" class="space-y-1">
                    <Label for="rule-target">{{ t('Move the lead to') }}</Label>
                    <select
                        id="rule-target"
                        v-model="form.target_stage_id"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        required
                    >
                        <option :value="null" disabled>
                            {{ t('Choose a stage') }}
                        </option>
                        <option
                            v-for="stage in destinations"
                            :key="stage.id"
                            :value="stage.id"
                        >
                            {{ stage.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.target_stage_id" />
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.active" type="checkbox" />{{
                        t('Rule is active')
                    }}</label
                >
                <InputError
                    :message="
                        form.errors.pipeline_id ||
                        form.errors.action ||
                        form.errors.trigger
                    "
                />
            </form>

            <SheetFooter
                class="bg-background flex-row items-center justify-between gap-2 border-t p-4"
            >
                <Button
                    v-if="rule && rule.active"
                    type="button"
                    variant="ghost"
                    class="text-destructive"
                    @click="emit('disable', rule)"
                    >{{ t('Disable') }}</Button
                >
                <span v-else />
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button
                        v-if="!picking"
                        type="submit"
                        form="crm-automation-rule"
                        :disabled="form.processing"
                        >{{ t('Save') }}</Button
                    >
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
