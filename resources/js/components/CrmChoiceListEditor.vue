<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { choiceCode, moveChoice } from '@/lib/crm-requirements';
import type { ChoiceDraft } from '@/lib/crm-requirements';

const props = defineProps<{
    canEdit: boolean;
    /** Codes already saved on the server; only brand-new ones may be removed. */
    savedValues: Set<string>;
    error?: string;
}>();
const list = defineModel<ChoiceDraft[]>({ required: true });
const { t } = useLocale();
const newLabel = ref('');
const addError = ref('');

function add(): void {
    const label = newLabel.value.trim();
    const value = choiceCode(label);
    if (!label || !value) {
        addError.value = t('Enter a name that starts with a letter.');

        return;
    }
    if (list.value.some((choice) => choice.value === value)) {
        addError.value = t('This option already exists.');

        return;
    }
    list.value = [...list.value, { value, label, active: true }];
    newLabel.value = '';
    addError.value = '';
}
function remove(index: number): void {
    list.value = list.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="space-y-3">
        <InputError :message="props.error" />
        <p v-if="!list.length" class="text-muted-foreground text-sm">
            {{
                t('No options yet. New selections are disabled for this field.')
            }}
        </p>
        <div
            v-for="(choice, index) in list"
            :key="choice.value"
            class="grid grid-cols-[1fr_auto] items-center gap-2 border-b pb-2 sm:grid-cols-[12rem_1fr_auto_auto]"
        >
            <code class="text-muted-foreground text-xs">{{
                choice.value
            }}</code>
            <Input
                v-model="choice.label"
                :disabled="!canEdit"
                maxlength="120"
                :aria-label="`${t('Name')} ${choice.value}`"
            />
            <label class="flex items-center gap-1 text-sm"
                ><input
                    v-model="choice.active"
                    type="checkbox"
                    :disabled="!canEdit"
                />{{ t('Active') }}</label
            >
            <div v-if="canEdit" class="flex gap-1">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="index === 0"
                    :aria-label="t('Move up')"
                    @click="list = moveChoice(list, index, -1)"
                    >↑</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="index === list.length - 1"
                    :aria-label="t('Move down')"
                    @click="list = moveChoice(list, index, 1)"
                    >↓</Button
                >
                <Button
                    v-if="!savedValues.has(choice.value)"
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="remove(index)"
                    >{{ t('Remove') }}</Button
                >
            </div>
        </div>
        <div v-if="canEdit" class="flex flex-wrap items-end gap-2 pt-1">
            <Input
                v-model="newLabel"
                class="max-w-xs"
                :placeholder="t('New option name')"
                :aria-label="t('New option name')"
                @keydown.enter.prevent="add"
            />
            <Button type="button" variant="outline" @click="add">{{
                t('Add option')
            }}</Button>
        </div>
        <InputError :message="addError" />
    </div>
</template>
