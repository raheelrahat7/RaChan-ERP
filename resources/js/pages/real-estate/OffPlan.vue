<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
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
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type OffPlanProject = {
    id: number;
    developer_id: number;
    developer_name: string;
    cost_centre_id: number | null;
    code: string;
    name: string;
    emirate: string;
    location: string | null;
    completion_on: string | null;
    commission_rate: string;
    status: string;
};

defineProps<{
    projects: {
        data: OffPlanProject[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    developers: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Off-Plan Projects', href: '/real-estate/off-plan' },
        ],
    },
});

const { t } = useLocale();
const dialogOpen = ref(false);

const form = useForm({
    developer_id: null as number | null,
    cost_centre_id: '',
    code: '',
    name: '',
    emirate: '',
    location: '',
    completion_on: '',
    commission_rate: '',
});

function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        cost_centre_id:
            data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
    })).post('/real-estate/off-plan/projects', {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

const columns: DataTableColumn<OffPlanProject>[] = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Project' },
    { key: 'developer_name', label: 'Developer' },
    { key: 'emirate', label: 'Emirate' },
    { key: 'completion_on', label: 'Completion' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <Head :title="t('Off-Plan Projects')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Real Estate" title="Off-Plan Projects">
            <template #actions>
                <Button v-if="canManage" @click="openCreate">{{
                    t('New project')
                }}</Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="projects.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            empty-title="No off-plan projects yet."
        >
            <template #cell-code="{ row }">
                <Link
                    :href="`/real-estate/off-plan/${row.id}`"
                    class="text-accent-text font-medium hover:underline"
                    >{{ row.code }}</Link
                >
            </template>
            <template #cell-completion_on="{ row }">{{
                row.completion_on ?? '—'
            }}</template>
            <template #cell-status="{ row }"
                ><StatusDot :status="row.status"
            /></template>
        </DataTable>
        <Pagination :links="projects.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New project')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Developer') }}</Label>
                        <Select
                            :model-value="
                                form.developer_id
                                    ? String(form.developer_id)
                                    : ''
                            "
                            @update:model-value="
                                form.developer_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="developer in developers"
                                    :key="developer.id"
                                    :value="String(developer.id)"
                                    >{{ developer.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.developer_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="project-cost-centre"
                            >{{ t('Cost centre ID') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="project-cost-centre"
                            v-model="form.cost_centre_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.cost_centre_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-code">{{ t('Code') }}</Label>
                            <Input id="project-code" v-model="form.code" />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-name">{{ t('Name') }}</Label>
                            <Input id="project-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-emirate">{{
                                t('Emirate')
                            }}</Label>
                            <Input
                                id="project-emirate"
                                v-model="form.emirate"
                            />
                            <InputError :message="form.errors.emirate" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-location"
                                >{{ t('Location') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-location"
                                v-model="form.location"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-completion"
                                >{{ t('Completion date') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-completion"
                                v-model="form.completion_on"
                                type="date"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-commission"
                                >{{ t('Commission rate %') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-commission"
                                v-model="form.commission_rate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create project')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
