<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Property = { id: number; name: string; type: string; city: string | null };
type Unit = {
    id: number;
    number: string;
    type: string;
    status: string;
    property: Property | null;
    building: { id: number; name: string } | null;
};
const props = defineProps<{
    properties: Property[];
    units: Unit[];
    canManageInventory: boolean;
}>();
const propertyForm = useForm({
    name: '',
    type: 'residential',
    city: '',
    address_line_1: '',
});
const unitForm = useForm({
    property_id: '',
    building_id: '',
    number: '',
    type: 'apartment',
    status: 'available',
    area: '',
    asking_price: '',
});
const buildingForm = useForm({ property_id: '', name: '', floors: '' });
function addProperty(): void {
    propertyForm.post('/inventory/properties', {
        preserveScroll: true,
        onSuccess: () => propertyForm.reset(),
    });
}
function addUnit(): void {
    unitForm.post('/inventory/units', {
        preserveScroll: true,
        onSuccess: () => unitForm.reset(),
    });
}
function addBuilding(): void {
    buildingForm.post('/inventory/buildings', {
        preserveScroll: true,
        onSuccess: () => buildingForm.reset(),
    });
}
function updateStatus(unit: Unit, status: string): void {
    router.put(
        `/inventory/units/${unit.id}/status`,
        { status },
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head title="Inventory" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Link
            v-if="canManageInventory"
            href="/inventory/imports"
            class="self-start text-sm underline"
            >Import inventory CSV</Link
        >
        <Heading
            title="Property inventory"
            description="Manage properties and units available for leasing or sale."
        /><Card v-if="canManageInventory"
            ><CardHeader><CardTitle>Add property</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="addProperty"
                >
                    <Input
                        v-model="propertyForm.name"
                        placeholder="Property name"
                        required
                    /><select
                        v-model="propertyForm.type"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="residential">Residential</option>
                        <option value="commercial">Commercial</option>
                        <option value="mixed_use">Mixed use</option>
                        <option value="land">Land</option></select
                    ><Input
                        v-model="propertyForm.city"
                        placeholder="City"
                    /><Button :disabled="propertyForm.processing"
                        >Add property</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-if="canManageInventory && props.properties.length"
            ><CardHeader><CardTitle>Add building</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="addBuilding"
                >
                    <select
                        v-model="buildingForm.property_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Property') }}</option>
                        <option
                            v-for="property in props.properties"
                            :key="property.id"
                            :value="String(property.id)"
                        >
                            {{ property.name }}
                        </option></select
                    ><Input
                        v-model="buildingForm.name"
                        placeholder="Building name"
                        required
                    /><Input
                        v-model="buildingForm.floors"
                        type="number"
                        min="1"
                        placeholder="Floors"
                    /><Button :disabled="buildingForm.processing"
                        >Add building</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-if="canManageInventory && props.properties.length"
            ><CardHeader><CardTitle>Add unit</CardTitle></CardHeader
            ><CardContent
                ><form class="flex flex-wrap gap-3" @submit.prevent="addUnit">
                    <select
                        v-model="unitForm.property_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Property') }}</option>
                        <option
                            v-for="property in props.properties"
                            :key="property.id"
                            :value="String(property.id)"
                        >
                            {{ property.name }}
                        </option></select
                    ><Input
                        v-model="unitForm.number"
                        placeholder="Unit number"
                        required
                    /><select
                        v-model="unitForm.type"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="apartment">Apartment</option>
                        <option value="office">Office</option>
                        <option value="retail">Retail</option>
                        <option value="warehouse">Warehouse</option>
                        <option value="plot">Plot</option>
                        <option value="other">Other</option></select
                    ><Button :disabled="unitForm.processing">Add unit</Button>
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Units</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!units.length" class="text-muted-foreground text-sm">
                    No units yet.
                </p>
                <div
                    v-for="unit in units"
                    :key="unit.id"
                    class="flex items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <span
                        ><Link
                            v-if="unit.property"
                            :href="`/inventory/properties/${unit.property.id}`"
                            class="underline"
                            >{{ unit.property.name }}</Link
                        >
                        ·
                        <Link
                            :href="`/inventory/units/${unit.id}`"
                            class="underline"
                            >{{ unit.number }}</Link
                        >
                        <span class="text-muted-foreground">{{
                            unit.type
                        }}</span></span
                    ><select
                        v-if="canManageInventory"
                        :value="unit.status"
                        class="border-input h-8 rounded-md border px-2 text-sm"
                        @change="
                            updateStatus(
                                unit,
                                String(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
                        <option
                            v-for="status in [
                                'available',
                                'reserved',
                                'leased',
                                'sold',
                                'unavailable',
                            ]"
                            :key="status"
                            :value="status"
                        >
                            {{ status }}
                        </option></select
                    ><span v-else class="text-muted-foreground">{{
                        unit.status
                    }}</span>
                </div></CardContent
            ></Card
        >
    </div>
</template>
