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
    plate: string;
    vehicle: string;
    odometer: string;
    status: string;
};

const props = defineProps<{
    vehicles: {
        data: {
            id: number;
            reference: string;
            plate: string;
            make: string;
            model: string;
            odometer: number;
            status: string;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    properties: { id: number; name: string }[];
    assets: { id: number; reference: string; name: string }[];
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const open = ref(false);
const form = useForm({
    reference: '',
    plate: '',
    vin: '',
    make: '',
    model: '',
    year: '',
    odometer: '0',
    property_id: '',
    fixed_asset_id: '',
});
const rows = computed<Row[]>(() =>
    props.vehicles.data.map((vehicle) => ({
        id: vehicle.id,
        reference: vehicle.reference,
        plate: vehicle.plate,
        vehicle: `${vehicle.make} ${vehicle.model}`,
        odometer: `${vehicle.odometer} km`,
        status: vehicle.status,
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'plate', label: t('Plate'), sortable: true },
    { key: 'vehicle', label: t('Vehicle'), sortable: true },
    { key: 'odometer', label: t('Odometer'), align: 'end' },
    { key: 'status', label: t('Status') },
]);

function create(): void {
    form.post('/operations/fleet', {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Fleet')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Fleet vehicles"
            description="Track vehicle assignments, mileage and service work."
        >
            <template #actions>
                <Link href="/maintenance" class="text-sm underline">{{
                    t('Maintenance')
                }}</Link>
            </template>
        </PageHeader>
        <CrmSettingsTable
            :show-title="false"
            title="Vehicles"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Register vehicle"
            :selectable="false"
            searchable
            can-edit
            @add="open = true"
        >
            <template #cell-reference="{ row }">
                <Link
                    :href="`/operations/fleet/${row.id}`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.reference }}</Link
                >
            </template>
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
        </CrmSettingsTable>
        <Pagination :links="vehicles.links" />

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Register vehicle')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Track vehicle assignments, mileage and service work.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="vehicle-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="fv-ref">{{ t('Reference') }}</Label
                            ><Input
                                id="fv-ref"
                                v-model="form.reference"
                                required
                                maxlength="100"
                            /><InputError :message="form.errors.reference" />
                        </div>
                        <div class="space-y-1">
                            <Label for="fv-plate">{{ t('Plate') }}</Label
                            ><Input
                                id="fv-plate"
                                v-model="form.plate"
                                required
                                maxlength="100"
                            /><InputError :message="form.errors.plate" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="fv-vin">VIN</Label
                        ><Input
                            id="fv-vin"
                            v-model="form.vin"
                            maxlength="100"
                        /><InputError :message="form.errors.vin" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="fv-make">{{ t('Make') }}</Label
                            ><Input
                                id="fv-make"
                                v-model="form.make"
                                required
                                maxlength="255"
                            /><InputError :message="form.errors.make" />
                        </div>
                        <div class="space-y-1">
                            <Label for="fv-model">{{ t('Model') }}</Label
                            ><Input
                                id="fv-model"
                                v-model="form.model"
                                required
                                maxlength="255"
                            /><InputError :message="form.errors.model" />
                        </div>
                        <div class="space-y-1">
                            <Label for="fv-year">{{ t('Year') }}</Label
                            ><Input
                                id="fv-year"
                                v-model="form.year"
                                type="number"
                                min="1900"
                                max="2100"
                            /><InputError :message="form.errors.year" />
                        </div>
                        <div class="space-y-1">
                            <Label for="fv-odo">{{ t('Odometer (km)') }}</Label
                            ><Input
                                id="fv-odo"
                                v-model="form.odometer"
                                required
                                type="number"
                                min="0"
                                max="999999999"
                            /><InputError :message="form.errors.odometer" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="fv-property">{{
                            t('Base property')
                        }}</Label>
                        <select
                            id="fv-property"
                            v-model="form.property_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('Not allocated') }}</option>
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
                    <div class="space-y-1">
                        <Label for="fv-asset">{{
                            t('Existing fixed asset')
                        }}</Label>
                        <select
                            id="fv-asset"
                            v-model="form.fixed_asset_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No asset link') }}</option>
                            <option
                                v-for="asset in assets"
                                :key="asset.id"
                                :value="String(asset.id)"
                            >
                                {{ asset.reference }} · {{ asset.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.fixed_asset_id" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="vehicle-form"
                        :disabled="form.processing"
                        >{{ t('Register vehicle') }}</Button
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
