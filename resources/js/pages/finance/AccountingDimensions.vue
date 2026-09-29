<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Dimension = { id: number; code: string; name: string; active: boolean };
type Branch = Dimension & { company_id: number };
type CostCentre = Dimension & { branch_id: number };

const props = defineProps<{
    companies: Dimension[];
    branches: Branch[];
    costCentres: CostCentre[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dimensions', href: '/accounting/dimensions' }],
    },
});

const { t } = useLocale();

type Level = 'company' | 'branch' | 'cost_centre';
const dialogLevel = ref<Level | null>(null);
const form = useForm({
    code: '',
    name: '',
    company_id: null as number | null,
    branch_id: null as number | null,
});

function openCreate(level: Level): void {
    form.reset();
    dialogLevel.value = level;
}

function submit(): void {
    if (!dialogLevel.value) {
        return;
    }
    form.transform((data) => ({
        code: data.code,
        name: data.name,
        ...(dialogLevel.value === 'branch'
            ? { company_id: data.company_id }
            : {}),
        ...(dialogLevel.value === 'cost_centre'
            ? { branch_id: data.branch_id }
            : {}),
    })).post(`/accounting/dimensions/${dialogLevel.value}`, {
        preserveScroll: true,
        onSuccess: () => (dialogLevel.value = null),
    });
}

function companyName(id: number): string {
    return (
        props.companies.find((company) => company.id === id)?.name ?? String(id)
    );
}
function branchName(id: number): string {
    return (
        props.branches.find((branch) => branch.id === id)?.name ?? String(id)
    );
}

const dimensionColumns: DataTableColumn<Dimension>[] = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'active', label: 'Status' },
];
const branchColumns = computed<DataTableColumn<Branch>[]>(() => [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'company_id', label: 'Company' },
    { key: 'active', label: 'Status' },
]);
const costCentreColumns = computed<DataTableColumn<CostCentre>[]>(() => [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'branch_id', label: 'Branch' },
    { key: 'active', label: 'Status' },
]);

const activeCompanies = computed(() =>
    props.companies.filter((company) => company.active),
);
const activeBranches = computed(() =>
    props.branches.filter((branch) => branch.active),
);
</script>

<template>
    <Head :title="t('Dimensions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Accounting"
            title="Dimensions"
            description="The company, branch and cost-centre hierarchy other accounting records tag against."
        />

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Companies') }}
                </h2>
                <Button
                    v-if="canManage"
                    size="sm"
                    @click="openCreate('company')"
                    >{{ t('New company') }}</Button
                >
            </div>
            <DataTable
                :columns="dimensionColumns"
                :rows="companies"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No companies yet."
            >
                <template #cell-active="{ row }">
                    <Badge :variant="row.active ? 'outline' : 'secondary'">{{
                        t(row.active ? 'Active' : 'Inactive')
                    }}</Badge>
                </template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Branches') }}
                </h2>
                <Button
                    v-if="canManage"
                    size="sm"
                    @click="openCreate('branch')"
                    >{{ t('New branch') }}</Button
                >
            </div>
            <DataTable
                :columns="branchColumns"
                :rows="branches"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No branches yet."
            >
                <template #cell-company_id="{ row }">{{
                    companyName(row.company_id)
                }}</template>
                <template #cell-active="{ row }">
                    <Badge :variant="row.active ? 'outline' : 'secondary'">{{
                        t(row.active ? 'Active' : 'Inactive')
                    }}</Badge>
                </template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Cost centres') }}
                </h2>
                <Button
                    v-if="canManage"
                    size="sm"
                    @click="openCreate('cost_centre')"
                    >{{ t('New cost centre') }}</Button
                >
            </div>
            <DataTable
                :columns="costCentreColumns"
                :rows="costCentres"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No cost centres yet."
            >
                <template #cell-branch_id="{ row }">{{
                    branchName(row.branch_id)
                }}</template>
                <template #cell-active="{ row }">
                    <Badge :variant="row.active ? 'outline' : 'secondary'">{{
                        t(row.active ? 'Active' : 'Inactive')
                    }}</Badge>
                </template>
            </DataTable>
        </section>

        <Dialog
            :open="dialogLevel !== null"
            @update:open="(value) => !value && (dialogLevel = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t(
                            dialogLevel === 'company'
                                ? 'New company'
                                : dialogLevel === 'branch'
                                  ? 'New branch'
                                  : 'New cost centre',
                        )
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div
                        v-if="dialogLevel === 'branch'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>{{ t('Company') }}</Label>
                        <Select
                            :model-value="
                                form.company_id ? String(form.company_id) : ''
                            "
                            @update:model-value="
                                form.company_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="company in activeCompanies"
                                    :key="company.id"
                                    :value="String(company.id)"
                                    >{{ company.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.company_id" />
                    </div>
                    <div
                        v-if="dialogLevel === 'cost_centre'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>{{ t('Branch') }}</Label>
                        <Select
                            :model-value="
                                form.branch_id ? String(form.branch_id) : ''
                            "
                            @update:model-value="
                                form.branch_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="branch in activeBranches"
                                    :key="branch.id"
                                    :value="String(branch.id)"
                                    >{{ branch.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.branch_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="dim-code">{{ t('Code') }}</Label>
                        <Input id="dim-code" v-model="form.code" />
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="dim-name">{{ t('Name') }}</Label>
                        <Input id="dim-name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogLevel = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
