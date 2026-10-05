<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    changeField,
    fieldOf,
    needsValue,
    newCondition,
    operatorLabel,
} from '@/lib/crm-deal-filters';
import type { Condition, FilterField } from '@/lib/crm-deal-filters';

const props = defineProps<{ fields: FilterField[] }>();
const conditions = defineModel<Condition[]>({ required: true });
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 rounded-md border px-2 text-sm';

function add(): void {
    const next = newCondition(props.fields);
    if (next) {
        conditions.value = [...conditions.value, next];
    }
}
function update(index: number, next: Condition): void {
    conditions.value = conditions.value.map((item, position) =>
        position === index ? next : item,
    );
}
function remove(index: number): void {
    conditions.value = conditions.value.filter(
        (_, position) => position !== index,
    );
}
function pickField(index: number, key: string): void {
    update(index, changeField(props.fields, conditions.value[index], key));
}
function setValue(index: number, value: Condition['value']): void {
    update(index, { ...conditions.value[index], value });
}
function multiValue(event: Event): string[] {
    return Array.from((event.target as HTMLSelectElement).selectedOptions).map(
        (option) => option.value,
    );
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="!fields.length" class="text-muted-foreground text-sm">
            {{
                t(
                    'No fields are available for filtering. Turn on "show in filter" for a deal field first.',
                )
            }}
        </p>
        <div
            v-for="(condition, index) in conditions"
            :key="index"
            class="flex flex-wrap items-center gap-2"
        >
            <select
                :value="condition.field"
                :class="selectClass"
                :aria-label="t('Field')"
                @change="
                    pickField(index, ($event.target as HTMLSelectElement).value)
                "
            >
                <option
                    v-for="field in fields"
                    :key="field.key"
                    :value="field.key"
                >
                    {{ field.name }}
                </option>
            </select>
            <select
                :value="condition.operator"
                :class="selectClass"
                :aria-label="t('Condition')"
                @change="
                    update(index, {
                        field: condition.field,
                        operator: ($event.target as HTMLSelectElement).value,
                    })
                "
            >
                <option
                    v-for="operator in fieldOf(fields, condition.field)
                        ?.operators ?? []"
                    :key="operator"
                    :value="operator"
                >
                    {{ t(operatorLabel(operator)) }}
                </option>
            </select>
            <template v-if="needsValue(condition.operator)">
                <select
                    v-if="fieldOf(fields, condition.field)?.type === 'checkbox'"
                    :value="String(condition.value ?? '')"
                    :class="selectClass"
                    :aria-label="t('Value')"
                    @change="
                        setValue(
                            index,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">—</option>
                    <option value="true">{{ t('Yes') }}</option>
                    <option value="false">{{ t('No') }}</option>
                </select>
                <select
                    v-else-if="
                        fieldOf(fields, condition.field)?.type ===
                        'multi_select'
                    "
                    multiple
                    :class="selectClass"
                    :aria-label="t('Value')"
                    @change="setValue(index, multiValue($event))"
                >
                    <option
                        v-for="option in fieldOf(fields, condition.field)
                            ?.options ?? []"
                        :key="option"
                        :value="option"
                        :selected="
                            Array.isArray(condition.value) &&
                            condition.value.includes(option)
                        "
                    >
                        {{ option }}
                    </option>
                </select>
                <select
                    v-else-if="
                        fieldOf(fields, condition.field)?.type ===
                        'single_select'
                    "
                    :value="String(condition.value ?? '')"
                    :class="selectClass"
                    :aria-label="t('Value')"
                    @change="
                        setValue(
                            index,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">—</option>
                    <option
                        v-for="option in fieldOf(fields, condition.field)
                            ?.options ?? []"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>
                <Input
                    v-else
                    class="h-9 w-44"
                    :model-value="String(condition.value ?? '')"
                    :type="
                        ['date', 'datetime'].includes(
                            fieldOf(fields, condition.field)?.type ?? '',
                        )
                            ? 'date'
                            : ['number', 'currency', 'user'].includes(
                                    fieldOf(fields, condition.field)?.type ??
                                        '',
                                )
                              ? 'number'
                              : 'text'
                    "
                    :aria-label="t('Value')"
                    @update:model-value="setValue(index, String($event))"
                />
            </template>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="t('Remove condition')"
                @click="remove(index)"
                ><X class="size-4" aria-hidden="true"
            /></Button>
        </div>
        <Button
            v-if="fields.length"
            type="button"
            size="sm"
            variant="outline"
            @click="add"
            ><Plus class="size-4" aria-hidden="true" />{{
                t('Add condition')
            }}</Button
        >
    </div>
</template>
