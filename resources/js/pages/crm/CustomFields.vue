<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Role = 'owner' | 'administrator' | 'manager' | 'member' | 'viewer';
type Field = {
    id: number;
    name: string;
    key: string;
    type: string;
    options: string[] | null;
    required: boolean;
    active: boolean;
    view_roles: Role[] | null;
    edit_roles: Role[] | null;
};
const props = defineProps<{ fields: Field[]; types: string[] }>();
const roles: Role[] = ['owner', 'administrator', 'manager', 'member', 'viewer'];
const selected = ref<Field | null>(null);
const find = ref('');
const visible = computed(() =>
    props.fields.filter((field) =>
        `${field.name} ${field.key}`
            .toLowerCase()
            .includes(find.value.toLowerCase()),
    ),
);
const form = useForm({
    name: '',
    key: '',
    type: 'text',
    options: '' as string,
    required: false,
    active: true,
    view_roles: [...roles] as Role[],
    edit_roles: [...roles] as Role[],
});
function edit(field: Field): void {
    selected.value = field;
    form.name = field.name;
    form.key = field.key;
    form.type = field.type;
    form.options = (field.options ?? []).join('\n');
    form.required = field.required;
    form.active = field.active;
    form.view_roles = field.view_roles ?? [...roles];
    form.edit_roles = field.edit_roles ?? [...roles];
}
function reset(): void {
    selected.value = null;
    form.reset();
    form.clearErrors();
}
function toggleRole(kind: 'view_roles' | 'edit_roles', role: Role): void {
    form[kind] = form[kind].includes(role)
        ? form[kind].filter((item) => item !== role)
        : [...form[kind], role];
    if (kind === 'view_roles' && !form.view_roles.includes(role))
        form.edit_roles = form.edit_roles.filter((item) => item !== role);
    if (
        kind === 'edit_roles' &&
        form.edit_roles.includes(role) &&
        !form.view_roles.includes(role)
    )
        form.view_roles = [...form.view_roles, role];
}
function save(): void {
    const payload = {
        name: form.name,
        key: form.key,
        type: form.type,
        options: form.options
            .split('\n')
            .map((item) => item.trim())
            .filter(Boolean),
        required: form.required,
        active: form.active,
        view_roles: form.view_roles,
        edit_roles: form.edit_roles,
    };
    const options = { preserveScroll: true, onSuccess: reset };
    if (selected.value)
        form.transform(() => payload).put(
            `/crm/custom-fields/${selected.value.id}`,
            options,
        );
    else form.transform(() => payload).post('/crm/custom-fields', options);
}
function archive(field: Field): void {
    if (confirm(`Archive ${field.name}? Saved lead values will be preserved.`))
        router.delete(`/crm/custom-fields/${field.id}`, {
            preserveScroll: true,
        });
}
</script>

<template>
    <Head title="CRM field settings" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM field settings"
            description="Define lead fields without changing the database schema. Archived fields keep their saved values."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]"
        >
            <Card>
                <CardHeader><CardTitle>Fields</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <Label for="find-field">Find field</Label>
                    <Input
                        id="find-field"
                        v-model="find"
                        type="search"
                        placeholder="Search name or key"
                    />
                    <div
                        v-for="field in visible"
                        :key="field.id"
                        class="flex items-center justify-between gap-3 rounded-md border p-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ field.name }}
                                <span
                                    v-if="!field.active"
                                    class="text-muted-foreground"
                                    >(archived)</span
                                >
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ field.key }} · {{ field.type }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="edit(field)"
                                >Edit</Button
                            ><Button
                                v-if="field.active"
                                variant="ghost"
                                size="sm"
                                @click="archive(field)"
                                >Archive</Button
                            >
                        </div>
                    </div>
                    <p
                        v-if="!visible.length"
                        class="text-muted-foreground text-sm"
                    >
                        No fields match.
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    ><CardTitle>{{
                        selected ? 'Edit field' : 'Create field'
                    }}</CardTitle></CardHeader
                >
                <CardContent>
                    <form class="space-y-4" @submit.prevent="save">
                        <div>
                            <Label for="field-name">Name</Label
                            ><Input
                                id="field-name"
                                v-model="form.name"
                                required
                                maxlength="120"
                            />
                        </div>
                        <div>
                            <Label for="field-key">Internal key</Label
                            ><Input
                                id="field-key"
                                v-model="form.key"
                                :disabled="!!selected"
                                required
                                maxlength="80"
                                pattern="[A-Za-z0-9_-]+"
                            />
                            <p class="text-muted-foreground text-xs">
                                Stable identifier; renaming the display name
                                keeps saved values.
                            </p>
                        </div>
                        <div>
                            <Label for="field-type">Type</Label
                            ><select
                                id="field-type"
                                v-model="form.type"
                                class="border-input bg-background h-9 w-full rounded-md border px-3"
                                :disabled="!!selected"
                            >
                                <option
                                    v-for="type in types"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ type.replace('_', ' ') }}
                                </option>
                            </select>
                        </div>
                        <div
                            v-if="
                                ['single_select', 'multi_select'].includes(
                                    form.type,
                                )
                            "
                        >
                            <Label for="field-options"
                                >Options, one per line</Label
                            ><textarea
                                id="field-options"
                                v-model="form.options"
                                class="border-input bg-background min-h-24 w-full rounded-md border p-2"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.required"
                                type="checkbox"
                            />Required for editors who can see it</label
                        >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.active"
                                type="checkbox"
                            />Active</label
                        >
                        <fieldset class="space-y-2">
                            <legend class="font-medium">Permissions</legend>
                            <div
                                v-for="role in roles"
                                :key="role"
                                class="grid grid-cols-[1fr_auto_auto] items-center gap-3 text-sm"
                            >
                                <span class="capitalize">{{ role }}</span
                                ><label class="flex items-center gap-1"
                                    ><input
                                        :checked="
                                            form.view_roles.includes(role)
                                        "
                                        type="checkbox"
                                        @change="toggleRole('view_roles', role)"
                                    />View</label
                                ><label class="flex items-center gap-1"
                                    ><input
                                        :checked="
                                            form.edit_roles.includes(role)
                                        "
                                        type="checkbox"
                                        @change="toggleRole('edit_roles', role)"
                                    />Edit</label
                                >
                            </div>
                        </fieldset>
                        <div aria-live="polite">
                            <InputError
                                v-for="(error, key) in form.errors"
                                :key="key"
                                :message="error"
                            />
                        </div>
                        <div class="flex gap-2">
                            <Button :disabled="form.processing">{{
                                selected ? 'Save changes' : 'Create field'
                            }}</Button
                            ><Button
                                v-if="selected"
                                type="button"
                                variant="outline"
                                @click="reset"
                                >Cancel</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
