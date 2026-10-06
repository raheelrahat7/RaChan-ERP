<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CrmPartyTabs from '@/components/CrmPartyTabs.vue';
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
import { ApiError, apiJson } from '@/lib/crm-api';
import type { DataTableColumn } from '@/lib/data-table';

type Company = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    contacts_count?: number;
    permissions?: { read: boolean; edit: boolean };
};
type Page = {
    data: Company[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = {
    id: number;
    name: string;
    email: string;
    phone: string;
    website: string;
    contacts: string;
};

defineProps<{ canManageCrm?: boolean }>();
const { t } = useLocale();
const page = ref<Page>({ data: [], current_page: 1, last_page: 1, total: 0 });
const query = ref('');
const loading = ref(true);
const error = ref('');
const open = ref(false);
const form = ref({ name: '', email: '', phone: '', website: '' });
const errors = ref<Record<string, string>>({});
const busy = ref(false);
let timer: number | null = null;
let latest = 0;

const canEdit = computed(
    () =>
        page.value.data.some((item) => item.permissions?.edit) ||
        page.value.total === 0,
);
const rows = computed<Row[]>(() =>
    page.value.data.map((company) => ({
        id: company.id,
        name: company.name,
        email: company.email ?? '',
        phone: company.phone ?? '',
        website: company.website ?? '',
        contacts: String(company.contacts_count ?? 0),
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'email', label: t('Email') },
    { key: 'phone', label: t('Phone') },
    { key: 'website', label: t('Website') },
    { key: 'contacts', label: t('Contacts'), align: 'end' },
]);

async function load(pageNumber = 1): Promise<void> {
    const request = ++latest;
    loading.value = true;
    try {
        const params = new URLSearchParams({
            per_page: '25',
            page: String(pageNumber),
        });
        if (query.value.trim()) {
            params.set('q', query.value.trim());
        }
        const data = await apiJson<{ companies: Page }>(
            `/crm/companies?${params}`,
        );
        if (request === latest) {
            page.value = data.companies;
            error.value = '';
        }
    } catch {
        if (request === latest) {
            error.value = t('Could not load companies.');
        }
    } finally {
        if (request === latest) {
            loading.value = false;
        }
    }
}

watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void load(1), 300);
});
onMounted(() => void load());
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});

function openForm(): void {
    form.value = { name: '', email: '', phone: '', website: '' };
    errors.value = {};
    open.value = true;
}

async function create(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const data = await apiJson<{ company: { id: number } }>(
            '/crm/companies',
            'POST',
            {
                name: form.value.name.trim(),
                email: form.value.email.trim() || null,
                phone: form.value.phone.trim() || null,
                website: form.value.website.trim() || null,
            },
        );
        router.visit(`/companies/${data.company.id}`);
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? found
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head :title="t('Companies')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Companies"
            description="Organizations your contacts, leads and deals belong to."
        />
        <CrmPartyTabs active="companies" />

        <div class="relative max-w-sm">
            <Search
                class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                aria-hidden="true"
            />
            <Input
                v-model="query"
                type="search"
                class="ps-9"
                :aria-label="t('Filter and search')"
                :placeholder="t('Filter and search')"
            />
        </div>
        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>

        <CrmSettingsTable
            :show-title="false"
            title="Companies"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Add company"
            :selectable="false"
            :can-edit="canEdit"
            @add="openForm"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="`/companies/${row.id}`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.name }}</Link
                >
            </template>
        </CrmSettingsTable>
        <div
            v-if="page.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="page.current_page <= 1 || loading"
                @click="load(page.current_page - 1)"
                >{{ t('Previous') }}</Button
            >
            <span
                >{{ t('Page') }} {{ page.current_page }} {{ t('of') }}
                {{ page.last_page }} · {{ page.total }}</span
            >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="page.current_page >= page.last_page || loading"
                @click="load(page.current_page + 1)"
                >{{ t('Next') }}</Button
            >
        </div>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Add company')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Organizations your contacts, leads and deals belong to.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="company-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <InputError :message="errors.form" />
                    <div class="space-y-1">
                        <Label for="co-name">{{ t('Name') }}</Label
                        ><Input
                            id="co-name"
                            v-model="form.name"
                            required
                        /><InputError :message="errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="co-email">{{ t('Email') }}</Label
                        ><Input
                            id="co-email"
                            v-model="form.email"
                            type="email"
                        /><InputError :message="errors.email" />
                    </div>
                    <div class="space-y-1">
                        <Label for="co-phone">{{ t('Phone') }}</Label
                        ><Input id="co-phone" v-model="form.phone" /><InputError
                            :message="errors.phone"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="co-web">{{ t('Website') }}</Label
                        ><Input
                            id="co-web"
                            v-model="form.website"
                            type="url"
                            placeholder="https://"
                        /><InputError :message="errors.website" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="company-form"
                        :disabled="busy"
                        >{{ t('Create company') }}</Button
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
