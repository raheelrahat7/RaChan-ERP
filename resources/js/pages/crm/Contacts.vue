<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
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

type Contact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    account: { id: number; name: string } | null;
};
type Row = {
    id: number;
    name: string;
    company: string;
    email: string;
    phone: string;
};

const props = defineProps<{ contacts: Contact[]; canManageCrm: boolean }>();
const { t } = useLocale();
const open = ref(false);
const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
});
const rows = computed<Row[]>(() =>
    props.contacts.map((contact) => ({
        id: contact.id,
        name: `${contact.first_name} ${contact.last_name}`.trim(),
        company: contact.account?.name ?? '',
        email: contact.email ?? '',
        phone: contact.phone ?? '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'company', label: t('Company'), sortable: true },
    { key: 'email', label: t('Email') },
    { key: 'phone', label: t('Phone') },
]);

function createContact(): void {
    form.post('/crm/contacts', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('CRM contacts')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="CRM contacts"
            description="Manage the people and organizations in your pipeline."
        />
        <CrmSettingsTable
            :show-title="false"
            title="Contacts"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Add contact"
            :selectable="false"
            searchable
            :can-edit="canManageCrm"
            @add="open = true"
        />

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Add contact')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Manage the people and organizations in your pipeline.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="contact-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createContact"
                >
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="first_name">{{
                                t('First name')
                            }}</Label>
                            <Input
                                id="first_name"
                                v-model="form.first_name"
                                required
                            />
                            <InputError :message="form.errors.first_name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="last_name">{{ t('Last name') }}</Label>
                            <Input
                                id="last_name"
                                v-model="form.last_name"
                                required
                            />
                            <InputError :message="form.errors.last_name" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="email">{{ t('Email') }}</Label>
                        <Input id="email" v-model="form.email" type="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="space-y-1">
                        <Label for="phone">{{ t('Phone') }}</Label>
                        <Input id="phone" v-model="form.phone" />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="space-y-1">
                        <Label for="company">{{
                            t('Account / company')
                        }}</Label>
                        <Input id="company" v-model="form.company" />
                        <InputError :message="form.errors.company" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="contact-form"
                        :disabled="form.processing"
                        >{{ t('Create contact') }}</Button
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
