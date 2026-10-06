<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { FieldDraft, PartyField } from '@/lib/crm-parties';

defineProps<{
    fields: PartyField[];
    errors?: Record<string, string>;
}>();
const draft = defineModel<FieldDraft>({ required: true });
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

function inputType(type: string): string {
    return (
        {
            number: 'number',
            currency: 'number',
            user: 'number',
            date: 'date',
            datetime: 'datetime-local',
            email: 'email',
            url: 'url',
            phone: 'tel',
        }[type] ?? 'text'
    );
}
function set(key: string, value: string | boolean | string[]): void {
    draft.value = { ...draft.value, [key]: value };
}
function toggle(key: string, option: string): void {
    const current = Array.isArray(draft.value[key])
        ? (draft.value[key] as string[])
        : [];
    set(
        key,
        current.includes(option)
            ? current.filter((item) => item !== option)
            : [...current, option],
    );
}
</script>

<template>
    <div class="space-y-4">
        <template
            v-for="field in fields.filter((item) => item.editable)"
            :key="field.key"
        >
            <div v-if="field.type === 'checkbox'" class="space-y-1">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="draft[field.key] === true"
                        @change="
                            set(
                                field.key,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    {{ field.name }}
                </label>
                <InputError :message="errors?.[`custom_fields.${field.key}`]" />
            </div>
            <fieldset
                v-else-if="field.type === 'multi_select'"
                class="space-y-1 rounded-md border p-3"
            >
                <legend class="px-1 text-sm">{{ field.name }}</legend>
                <label
                    v-for="option in field.options ?? []"
                    :key="option"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="
                            Array.isArray(draft[field.key]) &&
                            (draft[field.key] as string[]).includes(option)
                        "
                        @change="toggle(field.key, option)"
                    />
                    {{ option }}
                </label>
                <InputError :message="errors?.[`custom_fields.${field.key}`]" />
            </fieldset>
            <div v-else class="space-y-1">
                <Label :for="`cf-${field.key}`"
                    >{{ field.name
                    }}<span v-if="field.required"> *</span></Label
                >
                <select
                    v-if="field.type === 'single_select'"
                    :id="`cf-${field.key}`"
                    :class="selectClass"
                    :value="String(draft[field.key] ?? '')"
                    @change="
                        set(
                            field.key,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">—</option>
                    <option
                        v-for="option in field.options ?? []"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>
                <textarea
                    v-else-if="field.type === 'long_text'"
                    :id="`cf-${field.key}`"
                    rows="3"
                    class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    :value="String(draft[field.key] ?? '')"
                    @input="
                        set(
                            field.key,
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <Input
                    v-else
                    :id="`cf-${field.key}`"
                    :type="inputType(field.type)"
                    :step="
                        ['number', 'currency'].includes(field.type)
                            ? 'any'
                            : undefined
                    "
                    :model-value="String(draft[field.key] ?? '')"
                    @update:model-value="set(field.key, String($event))"
                />
                <p v-if="field.tooltip" class="text-muted-foreground text-xs">
                    {{ field.tooltip }}
                </p>
                <InputError :message="errors?.[`custom_fields.${field.key}`]" />
            </div>
        </template>
        <p
            v-if="!fields.some((item) => item.editable)"
            class="text-muted-foreground text-sm"
        >
            {{ t('No additional fields are visible to you.') }}
        </p>
    </div>
</template>
