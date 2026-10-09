<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PartiesPanel from '@/components/PartiesPanel.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import type { DataTableColumn } from '@/lib/data-table';

type Person = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
};
type Group = 'owners' | 'developers' | 'tenants' | 'brokers';
type Row = { id: number; name: string; email: string; phone: string };

const props = defineProps<{
    owners: Person[];
    tenants: Person[];
    brokers: Person[];
    canManage: boolean;
}>();
const { t } = useLocale();
const GROUPS: { key: Group; title: string; addLabel: string }[] = [
    { key: 'owners', title: 'Owners', addLabel: 'Add owner' },
    { key: 'developers', title: 'Developers', addLabel: 'Add developer' },
    { key: 'tenants', title: 'Tenants', addLabel: 'Add tenant' },
    { key: 'brokers', title: 'Brokers', addLabel: 'Add broker' },
];
const group = ref<Group>('owners');
const open = ref(false);
const form = useForm({ name: '', email: '', phone: '', reference: '' });

const current = computed(() =>
    GROUPS.find((item) => item.key === group.value)!,
);
const smallGroup = computed(
    () => group.value === 'tenants' || group.value === 'brokers',
);
const rows = computed<Row[]>(() =>
    (smallGroup.value ? props[group.value as 'tenants' | 'brokers'] : []).map(
        (person) => ({
            id: person.id,
            name: person.name,
            email: person.email ?? '',
            phone: person.phone ?? '',
        }),
    ),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'email', label: t('Email') },
    { key: 'phone', label: t('Phone') },
]);

function create(): void {
    form.post(`/real-estate/people/${group.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Real-estate people')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Real-estate people"
            description="Owners, tenants, and brokerage contacts."
        />
        <nav :aria-label="t('People types')" class="flex gap-2">
            <button
                v-for="item in GROUPS"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === group
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === group ? 'page' : undefined"
                @click="group = item.key"
            >
                {{ t(item.title) }}
                <span v-if="item.key !== 'developers'" class="opacity-70">
                    ({{ props[item.key].length }})</span
                >
            </button>
        </nav>
        <PartiesPanel
            v-if="group === 'owners' || group === 'developers'"
            :type="group"
            :can-create="canManage"
        />
        <CrmSettingsTable
            v-else
            :show-title="false"
            :title="current.title"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :add-label="current.addLabel"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="open = true"
        />

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t(current.addLabel)
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Owners, tenants, and brokerage contacts.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="person-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="pp-name">{{ t('Name') }}</Label
                        ><Input
                            id="pp-name"
                            v-model="form.name"
                            required
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pp-email">{{ t('Email') }}</Label
                        ><Input
                            id="pp-email"
                            v-model="form.email"
                            type="email"
                        /><InputError :message="form.errors.email" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pp-phone">{{ t('Phone') }}</Label
                        ><Input id="pp-phone" v-model="form.phone" /><InputError
                            :message="form.errors.phone"
                        />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="person-form"
                        :disabled="form.processing"
                        >{{ t('Add') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
</template>
