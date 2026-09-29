<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

type Field = {
    id: number;
    key: string;
    name: string;
    type: string;
    options: string[] | null;
    required: boolean;
};
type FieldValue = string | number | boolean | string[] | null;
const props = defineProps<{
    fields: Field[];
    modelValue: Record<string, FieldValue>;
    members: { id: number; name: string }[];
    prefix: string;
}>();
const emit = defineEmits<{
    'update:modelValue': [value: Record<string, FieldValue>];
}>();
function current(key: string): FieldValue {
    return props.modelValue[key] ?? '';
}
function set(key: string, value: FieldValue): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
function changed(field: Field, event: Event): void {
    const target = event.target as
        | HTMLInputElement
        | HTMLSelectElement
        | HTMLTextAreaElement;
    if (field.type === 'checkbox')
        set(field.key, (target as HTMLInputElement).checked);
    else if (field.type === 'multi_select')
        set(
            field.key,
            Array.from((target as HTMLSelectElement).selectedOptions).map(
                (option) => option.value,
            ),
        );
    else set(field.key, target.value);
}
function inputType(type: string): string {
    if (['number', 'currency'].includes(type)) return 'number';
    if (type === 'datetime') return 'datetime-local';
    if (['date', 'email', 'url', 'phone'].includes(type))
        return type === 'phone' ? 'tel' : type;
    return 'text';
}
</script>
<template>
    <div v-for="field in fields" :key="field.id" class="space-y-2">
        <Label :for="`${prefix}-${field.key}`"
            >{{ field.name }}<span v-if="field.required"> *</span></Label
        >
        <textarea
            v-if="field.type === 'long_text'"
            :id="`${prefix}-${field.key}`"
            :value="String(current(field.key))"
            :required="field.required"
            class="border-input bg-background min-h-20 w-full rounded-md border p-2"
            @input="changed(field, $event)"
        />
        <select
            v-else-if="
                ['single_select', 'multi_select', 'user'].includes(field.type)
            "
            :id="`${prefix}-${field.key}`"
            :value="current(field.key)"
            :multiple="field.type === 'multi_select'"
            :required="field.required"
            class="border-input bg-background min-h-9 w-full rounded-md border px-3"
            @change="changed(field, $event)"
        >
            <option v-if="field.type !== 'multi_select'" value="">
                Choose…
            </option>
            <option
                v-for="option in field.type === 'user'
                    ? members.map((member) => ({
                          value: String(member.id),
                          label: member.name,
                      }))
                    : (field.options ?? []).map((option) => ({
                          value: option,
                          label: option,
                      }))"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <label
            v-else-if="field.type === 'checkbox'"
            class="flex h-9 items-center gap-2"
            ><input
                :id="`${prefix}-${field.key}`"
                type="checkbox"
                :checked="Boolean(current(field.key))"
                @change="changed(field, $event)"
            /><span class="text-sm">Yes</span></label
        >
        <Input
            v-else
            :id="`${prefix}-${field.key}`"
            :type="inputType(field.type)"
            :value="String(current(field.key))"
            :required="field.required"
            :step="
                field.type === 'currency'
                    ? '0.01'
                    : field.type === 'number'
                      ? 'any'
                      : undefined
            "
            @input="changed(field, $event)"
        />
    </div>
</template>
