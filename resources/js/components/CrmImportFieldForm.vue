<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{ header: string; fieldTypes: string[] }>();
const emit = defineEmits<{ created: [key: string]; cancel: [] }>();
const { t } = useLocale();

const ALL_ROLES = ['owner', 'administrator', 'manager', 'member', 'viewer'];
const form = useForm({
    name: '',
    key: '',
    type: 'text',
    options: '',
    required: false,
    view_roles: ['owner', 'administrator'] as string[],
    edit_roles: ['owner', 'administrator'] as string[],
});

watch(
    () => props.header,
    (header) => {
        form.name = header;
        form.key = header
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_|_$/g, '');
        form.type = 'text';
        form.options = '';
        form.required = false;
        form.clearErrors();
    },
    { immediate: true },
);

function setVisibleToAll(all: boolean): void {
    form.view_roles = all ? [...ALL_ROLES] : ['owner', 'administrator'];
    form.edit_roles = [...form.view_roles];
}
function create(): void {
    form.transform((data) => ({
        ...data,
        options: data.options
            .split('\n')
            .map((value) => value.trim())
            .filter(Boolean),
    })).post('/crm/custom-fields', {
        preserveScroll: true,
        onSuccess: () => emit('created', form.key),
    });
}
</script>

<template>
    <form
        class="bg-muted/40 grid gap-3 rounded-md border p-4 sm:grid-cols-2"
        @submit.prevent="create"
    >
        <h3 class="font-medium sm:col-span-2">
            {{ t('New lead field for') }} “{{ header }}”
        </h3>
        <div class="space-y-1">
            <Label for="new-field-name">{{ t('Field name') }}</Label>
            <Input id="new-field-name" v-model="form.name" required />
        </div>
        <div class="space-y-1">
            <Label for="new-field-key">{{ t('Internal key') }}</Label>
            <Input
                id="new-field-key"
                v-model="form.key"
                required
                pattern="[A-Za-z0-9_-]+"
            />
        </div>
        <div class="space-y-1">
            <Label for="new-field-type">{{ t('Type') }}</Label>
            <select
                id="new-field-type"
                v-model="form.type"
                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
            >
                <option v-for="type in fieldTypes" :key="type" :value="type">
                    {{ type.replaceAll('_', ' ') }}
                </option>
            </select>
        </div>
        <div
            v-if="['single_select', 'multi_select'].includes(form.type)"
            class="space-y-1"
        >
            <Label for="new-field-options">{{
                t('Options, one per line')
            }}</Label>
            <textarea
                id="new-field-options"
                v-model="form.options"
                class="border-input bg-background min-h-20 w-full rounded-md border p-2 text-sm"
                required
            />
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.required" type="checkbox" />{{
                t('Required on every new lead')
            }}
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                :checked="form.view_roles.length > 2"
                @change="
                    setVisibleToAll(($event.target as HTMLInputElement).checked)
                "
            />{{ t('Visible to all CRM roles') }}
        </label>
        <p class="text-muted-foreground text-xs sm:col-span-2">
            {{ t('New fields default to owner and administrator access.') }}
        </p>
        <InputError
            v-for="(error, key) in form.errors"
            :key="key"
            :message="error"
            class="sm:col-span-2"
        />
        <div class="flex gap-2 sm:col-span-2">
            <Button :disabled="form.processing">{{
                t('Create and map field')
            }}</Button>
            <Button type="button" variant="outline" @click="emit('cancel')">{{
                t('Cancel')
            }}</Button>
        </div>
    </form>
</template>
