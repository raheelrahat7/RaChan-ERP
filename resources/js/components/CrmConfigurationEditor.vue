<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { useForm, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import type { Pipeline, Stage, LostReason } from '@/types/crm-pipeline';
const props = defineProps<{
    kind: 'pipeline' | 'stage' | 'reason';
    pipelineId?: number;
    item?: Pipeline | Stage | LostReason;
    defaultType?: 'normal' | 'on_hold' | 'won' | 'lost';
}>();
const form = useForm({
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    active: props.item?.active ?? true,
    position: props.item && 'position' in props.item ? props.item.position : 1,
    type:
        props.item && 'type' in props.item
            ? props.item.type
            : (props.defaultType ?? 'normal'),
    color: props.item && 'color' in props.item ? props.item.color : '#64748b',
});
const prefix = `${props.kind}-${props.item?.id ?? `new-${props.pipelineId ?? 'pipeline'}`}`;
function save(): void {
    const base =
        props.kind === 'pipeline'
            ? '/crm/pipelines'
            : `/crm/pipelines/${props.pipelineId}/${props.kind === 'stage' ? 'stages' : 'reasons'}`;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (!props.item) form.reset();
        },
    };
    if (props.item) form.put(`${base}/${props.item.id}`, options);
    else form.post(base, options);
}
function remove(): void {
    if (
        props.item &&
        confirm(`Delete unused ${props.kind} “${props.item.name}”?`)
    )
        router.delete(`/crm/configuration/${props.kind}/${props.item.id}`, {
            preserveScroll: true,
        });
}
</script>
<template>
    <form
        class="grid gap-3 rounded-md border p-4 md:grid-cols-2"
        @submit.prevent="save"
    >
        <div>
            <Label :for="`${prefix}-name`">{{ t('Name') }}</Label
            ><Input
                :id="`${prefix}-name`"
                v-model="form.name"
                required
                maxlength="100"
            />
        </div>
        <div>
            <Label :for="`${prefix}-description`">{{ t('Description') }}</Label
            ><Input
                :id="`${prefix}-description`"
                v-model="form.description"
                maxlength="2000"
            />
        </div>
        <div v-if="kind !== 'pipeline'">
            <Label :for="`${prefix}-position`">Display position</Label
            ><Input
                :id="`${prefix}-position`"
                v-model="form.position"
                type="number"
                min="1"
                max="10000"
                required
            />
        </div>
        <template v-if="kind === 'stage'">
            <div>
                <Label :for="`${prefix}-type`">Stage type</Label
                ><select
                    :id="`${prefix}-type`"
                    v-model="form.type"
                    class="h-9 w-full rounded-md border px-3"
                >
                    <option value="normal">Normal</option>
                    <option value="on_hold">{{ t('On hold') }}</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                </select>
            </div>
            <div>
                <Label :for="`${prefix}-color`">Color</Label
                ><Input
                    :id="`${prefix}-color`"
                    v-model="form.color"
                    type="color"
                />
            </div>
        </template>
        <label class="flex items-center gap-2"
            ><input v-model="form.active" type="checkbox" />{{
                t('Active')
            }}</label
        >
        <div class="flex flex-wrap gap-2">
            <Button :disabled="form.processing">{{
                item ? 'Save changes' : `Add ${kind}`
            }}</Button
            ><Button v-if="item" type="button" variant="outline" @click="remove"
                >Delete unused</Button
            >
        </div>
        <div class="md:col-span-2" aria-live="polite">
            <InputError
                v-for="(error, field) in form.errors"
                :key="field"
                :message="error"
            />
        </div>
    </form>
</template>
