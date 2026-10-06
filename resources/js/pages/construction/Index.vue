<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
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

type Row = {
    id: number;
    reference: string;
    title: string;
    budget: string;
    status: string;
};

const props = defineProps<{
    properties: { id: number; name: string }[];
    projects: {
        data: {
            id: number;
            reference: string;
            title: string;
            status: string;
            budget: string;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
}>();
const { t } = useLocale();
const open = ref(false);
const form = useForm({ property_id: '', reference: '', title: '', budget: '' });
const rows = computed<Row[]>(() =>
    props.projects.data.map((project) => ({
        id: project.id,
        reference: project.reference,
        title: project.title,
        budget: `AED ${project.budget}`,
        status: project.status,
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'title', label: t('Title'), sortable: true },
    { key: 'budget', label: t('Budget'), align: 'end' },
    { key: 'status', label: t('Status') },
]);

function create(): void {
    form.post('/operations/projects', {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Construction projects')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Construction projects"
            description="Track gross budgets, BOQ progress and owner-approved contractor claims in AED."
        >
            <template #actions>
                <Link href="/maintenance" class="text-sm underline">{{
                    t('Maintenance')
                }}</Link>
            </template>
        </PageHeader>
        <CrmSettingsTable
            :show-title="false"
            title="Projects"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Create project"
            :selectable="false"
            searchable
            can-edit
            @add="open = true"
        >
            <template #cell-reference="{ row }">
                <Link
                    :href="`/operations/projects/${row.id}`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.reference }}</Link
                >
            </template>
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
        </CrmSettingsTable>
        <Pagination :links="projects.links" />

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create project')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Track gross budgets, BOQ progress and owner-approved contractor claims in AED.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="project-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="cp-ref">{{ t('Reference') }}</Label
                        ><Input
                            id="cp-ref"
                            v-model="form.reference"
                            required
                            maxlength="100"
                        /><InputError :message="form.errors.reference" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cp-title">{{ t('Title') }}</Label
                        ><Input
                            id="cp-title"
                            v-model="form.title"
                            required
                            maxlength="255"
                        /><InputError :message="form.errors.title" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cp-budget">{{
                            t('Gross budget AED')
                        }}</Label
                        ><Input
                            id="cp-budget"
                            v-model="form.budget"
                            required
                            inputmode="decimal"
                        /><InputError :message="form.errors.budget" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cp-property">{{
                            t('Property (optional)')
                        }}</Label>
                        <select
                            id="cp-property"
                            v-model="form.property_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="">
                                {{ t('No property allocation') }}
                            </option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.property_id" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="project-form"
                        :disabled="form.processing"
                        >{{ t('Create project') }}</Button
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
