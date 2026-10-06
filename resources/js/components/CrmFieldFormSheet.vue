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

export type FieldRole =
    | 'owner'
    | 'administrator'
    | 'manager'
    | 'member'
    | 'viewer';
export type FieldRecord = {
    id: number;
    name: string;
    key: string;
    type: string;
    options: string[] | null;
    required: boolean;
    active: boolean;
    sort_order?: number;
    view_roles: FieldRole[] | null;
    edit_roles: FieldRole[] | null;
};

const ROLES: FieldRole[] = [
    'owner',
    'administrator',
    'manager',
    'member',
    'viewer',
];
const props = withDefaults(
    defineProps<{
        field: FieldRecord | null;
        types: string[];
        entity?: string;
    }>(),
    { entity: 'lead' },
);
const emit = defineEmits<{ saved: [] }>();
const open = defineModel<boolean>('open', { required: true });

const { t } = useLocale();
const form = useForm({
    name: '',
    key: '',
    type: 'text',
    options: '',
    required: false,
    active: true,
    sort_order: 0,
    view_roles: [...ROLES] as FieldRole[],
    edit_roles: [...ROLES] as FieldRole[],
});
const editing = computed(() => props.field !== null);
const keyTouched = ref(false);
const hasOptions = computed(() =>
    ['single_select', 'multi_select'].includes(form.type),
);

function load(): void {
    form.clearErrors();
    keyTouched.value = false;
    const field = props.field;
    form.defaults({
        name: field?.name ?? '',
        key: field?.key ?? '',
        type: field?.type ?? 'text',
        options: (field?.options ?? []).join('\n'),
        required: field?.required ?? false,
        active: field?.active ?? true,
        sort_order: field?.sort_order ?? 0,
        view_roles: field?.view_roles ?? [...ROLES],
        edit_roles: field?.edit_roles ?? [...ROLES],
    }).reset();
}
function autoKey(): void {
    if (!editing.value && !keyTouched.value) {
        form.key = form.name
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_|_$/g, '');
    }
}
function toggleRole(kind: 'view_roles' | 'edit_roles', role: FieldRole): void {
    const has = form[kind].includes(role);
    form[kind] = has
        ? form[kind].filter((item) => item !== role)
        : [...form[kind], role];
    if (kind === 'view_roles' && has) {
        form.edit_roles = form.edit_roles.filter((item) => item !== role);
    }
    if (kind === 'edit_roles' && !has && !form.view_roles.includes(role)) {
        form.view_roles = [...form.view_roles, role];
    }
}
function save(): void {
    form.transform((data) => ({
        entity: props.entity,
        name: data.name,
        key: data.key,
        type: data.type,
        options: data.options
            .split('\n')
            .map((item) => item.trim())
            .filter(Boolean),
        required: data.required,
        active: data.active,
        sort_order: Number(data.sort_order) || 0,
        view_roles: data.view_roles,
        edit_roles: data.edit_roles,
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('saved');
        },
    };
    if (props.field) {
        form.put(`/crm/custom-fields/${props.field.id}`, options);
    } else {
        form.post('/crm/custom-fields', options);
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
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    editing ? t('Edit field') : t('New field')
                }}</SheetTitle>
                <SheetDescription>{{
                    t(
                        'General parameters. Archived fields keep their saved values.',
                    )
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="crm-field-form"
                class="flex-1 space-y-5 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <div class="space-y-1">
                    <Label for="field-name">{{ t('Name') }} *</Label>
                    <Input
                        id="field-name"
                        v-model="form.name"
                        required
                        maxlength="120"
                        @input="autoKey"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="field-key">{{ t('Internal key') }} *</Label>
                        <Input
                            id="field-key"
                            v-model="form.key"
                            required
                            pattern="[A-Za-z0-9_\-]+"
                            maxlength="80"
                            :disabled="editing"
                            @input="keyTouched = true"
                        />
                        <InputError :message="form.errors.key" />
                    </div>
                    <div class="space-y-1">
                        <Label for="field-sort">{{ t('Sorting') }}</Label>
                        <Input
                            id="field-sort"
                            v-model="form.sort_order"
                            type="number"
                            min="0"
                            max="10000"
                        />
                        <InputError :message="form.errors.sort_order" />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="field-type">{{ t('Type') }}</Label>
                    <select
                        id="field-type"
                        v-model="form.type"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option v-for="type in types" :key="type" :value="type">
                            {{ type.replaceAll('_', ' ') }}
                        </option>
                    </select>
                    <InputError :message="form.errors.type" />
                </div>
                <div v-if="hasOptions" class="space-y-1">
                    <Label for="field-options">{{
                        t('Options, one per line')
                    }}</Label>
                    <textarea
                        id="field-options"
                        v-model="form.options"
                        rows="5"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                        required
                    />
                    <InputError :message="form.errors.options" />
                </div>
                <div class="flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"
                        ><input v-model="form.required" type="checkbox" />{{
                            t('Required')
                        }}</label
                    >
                    <label class="flex items-center gap-2"
                        ><input v-model="form.active" type="checkbox" />{{
                            t('Active')
                        }}</label
                    >
                </div>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        {{ t('Who can see and edit this field') }}
                    </legend>
                    <div class="overflow-hidden rounded-md border">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-muted/40 text-xs">
                                    <th class="p-2 text-start font-medium">
                                        {{ t('Role') }}
                                    </th>
                                    <th class="p-2 font-medium">
                                        {{ t('View') }}
                                    </th>
                                    <th class="p-2 font-medium">
                                        {{ t('Edit') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="role in ROLES"
                                    :key="role"
                                    class="border-t"
                                >
                                    <th
                                        scope="row"
                                        class="p-2 text-start font-normal capitalize"
                                    >
                                        {{ role }}
                                    </th>
                                    <td class="p-2 text-center">
                                        <input
                                            type="checkbox"
                                            :checked="
                                                form.view_roles.includes(role)
                                            "
                                            :aria-label="`${role}: ${t('View')}`"
                                            @change="
                                                toggleRole('view_roles', role)
                                            "
                                        />
                                    </td>
                                    <td class="p-2 text-center">
                                        <input
                                            type="checkbox"
                                            :checked="
                                                form.edit_roles.includes(role)
                                            "
                                            :aria-label="`${role}: ${t('Edit')}`"
                                            @change="
                                                toggleRole('edit_roles', role)
                                            "
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <InputError
                        :message="
                            form.errors.view_roles || form.errors.edit_roles
                        "
                    />
                </fieldset>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="crm-field-form"
                    :disabled="form.processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
