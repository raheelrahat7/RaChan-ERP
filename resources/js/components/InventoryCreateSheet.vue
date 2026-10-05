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

export type InventoryKind = 'property' | 'building' | 'unit';
type PropertyOption = {
    id: number;
    name: string;
    buildings?: { id: number; name: string }[];
};

const props = defineProps<{
    properties: PropertyOption[];
    initialKind: InventoryKind;
}>();
const open = defineModel<boolean>('open', { required: true });
const { t } = useLocale();

const kind = ref<InventoryKind>(props.initialKind);
const kinds = computed<
    { key: InventoryKind; label: string; disabled: boolean }[]
>(() => [
    { key: 'property', label: 'Property', disabled: false },
    { key: 'building', label: 'Building', disabled: !props.properties.length },
    { key: 'unit', label: 'Unit', disabled: !props.properties.length },
]);
const onlyProperty = computed(() =>
    props.properties.length === 1 ? String(props.properties[0].id) : '',
);
const propertyForm = useForm({
    name: '',
    type: 'residential',
    city: '',
    address_line_1: '',
});
const buildingForm = useForm({ property_id: '', name: '', floors: '' });
const unitForm = useForm({
    property_id: '',
    building_id: '',
    floor: '',
    number: '',
    type: 'apartment',
    status: 'available',
    area: '',
    asking_price: '',
});
const unitBuildings = computed(
    () =>
        props.properties.find(
            (item) => String(item.id) === unitForm.property_id,
        )?.buildings ?? [],
);
const form = computed(() =>
    kind.value === 'property'
        ? propertyForm
        : kind.value === 'building'
          ? buildingForm
          : unitForm,
);

function submit(): void {
    const path = {
        property: '/inventory/properties',
        building: '/inventory/buildings',
        unit: '/inventory/units',
    }[kind.value];
    form.value.post(path, {
        preserveScroll: true,
        onSuccess: () => {
            if (kind.value === 'property') {
                propertyForm.reset();
            } else if (kind.value === 'building') {
                buildingForm.reset();
            } else {
                unitForm.reset();
            }
            buildingForm.property_id = onlyProperty.value;
            unitForm.property_id = onlyProperty.value;
            open.value = false;
        },
    });
}

watch(open, (isOpen) => {
    if (isOpen) {
        kind.value = props.initialKind;
        buildingForm.property_id ||= onlyProperty.value;
        unitForm.property_id ||= onlyProperty.value;
    }
});
watch(
    () => unitForm.property_id,
    () => (unitForm.building_id = ''),
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('Create')
                }}</SheetTitle>
                <SheetDescription>{{
                    kind === 'unit'
                        ? t(
                              'Leave the status Available so the unit can be listed.',
                          )
                        : t('Add to your property inventory.')
                }}</SheetDescription>
            </SheetHeader>
            <div
                class="flex gap-2 border-b p-4"
                role="tablist"
                :aria-label="t('What to create')"
            >
                <Button
                    v-for="item in kinds"
                    :key="item.key"
                    type="button"
                    size="sm"
                    role="tab"
                    :aria-selected="kind === item.key"
                    :variant="kind === item.key ? 'default' : 'outline'"
                    :disabled="item.disabled"
                    @click="kind = item.key"
                    >{{ t(item.label) }}</Button
                >
            </div>
            <form
                id="inventory-create"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="submit"
            >
                <template v-if="kind === 'property'">
                    <div class="space-y-1">
                        <Label for="inv-name">{{ t('Property name') }} *</Label
                        ><Input
                            id="inv-name"
                            v-model="propertyForm.name"
                            required
                        /><InputError :message="propertyForm.errors.name" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="inv-ptype">{{ t('Type') }}</Label>
                            <select
                                id="inv-ptype"
                                v-model="propertyForm.type"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="residential">
                                    {{ t('Residential') }}
                                </option>
                                <option value="commercial">
                                    {{ t('Commercial') }}
                                </option>
                                <option value="mixed_use">
                                    {{ t('Mixed use') }}
                                </option>
                                <option value="land">{{ t('Land') }}</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-city">{{ t('City') }}</Label
                            ><Input id="inv-city" v-model="propertyForm.city" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="inv-address">{{ t('Address') }}</Label
                        ><Input
                            id="inv-address"
                            v-model="propertyForm.address_line_1"
                        />
                    </div>
                </template>
                <template v-else-if="kind === 'building'">
                    <div class="space-y-1">
                        <Label for="inv-bprop">{{ t('Property') }} *</Label>
                        <select
                            id="inv-bprop"
                            v-model="buildingForm.property_id"
                            required
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option disabled value="">
                                {{ t('Choose property') }}
                            </option>
                            <option
                                v-for="item in properties"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option></select
                        ><InputError
                            :message="buildingForm.errors.property_id"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="inv-bname"
                                >{{ t('Building name') }} *</Label
                            ><Input
                                id="inv-bname"
                                v-model="buildingForm.name"
                                required
                            /><InputError :message="buildingForm.errors.name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-floors">{{
                                t('Number of floors')
                            }}</Label
                            ><Input
                                id="inv-floors"
                                v-model="buildingForm.floors"
                                type="number"
                                min="1"
                            /><InputError
                                :message="buildingForm.errors.floors"
                            />
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="space-y-1">
                        <Label for="inv-uprop">{{ t('Property') }} *</Label>
                        <select
                            id="inv-uprop"
                            v-model="unitForm.property_id"
                            required
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option disabled value="">
                                {{ t('Choose property') }}
                            </option>
                            <option
                                v-for="item in properties"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option></select
                        ><InputError :message="unitForm.errors.property_id" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="inv-ubuilding">{{
                                t('Building')
                            }}</Label>
                            <select
                                id="inv-ubuilding"
                                v-model="unitForm.building_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">{{ t('No building') }}</option>
                                <option
                                    v-for="item in unitBuildings"
                                    :key="item.id"
                                    :value="String(item.id)"
                                >
                                    {{ item.name }}
                                </option></select
                            ><InputError
                                :message="unitForm.errors.building_id"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-ufloor">{{ t('Floor') }}</Label
                            ><Input
                                id="inv-ufloor"
                                v-model="unitForm.floor"
                                placeholder="G or 1"
                            /><InputError :message="unitForm.errors.floor" />
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-unumber"
                                >{{ t('Unit number') }} *</Label
                            ><Input
                                id="inv-unumber"
                                v-model="unitForm.number"
                                required
                            /><InputError :message="unitForm.errors.number" />
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-utype">{{ t('Type') }}</Label>
                            <select
                                id="inv-utype"
                                v-model="unitForm.type"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="type in [
                                        'apartment',
                                        'office',
                                        'retail',
                                        'warehouse',
                                        'plot',
                                        'other',
                                    ]"
                                    :key="type"
                                    :value="type"
                                    class="capitalize"
                                >
                                    {{ t(type) }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-ustatus">{{ t('Status') }}</Label>
                            <select
                                id="inv-ustatus"
                                v-model="unitForm.status"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
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
                                    {{ t(status) }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-uarea">{{ t('Area') }}</Label
                            ><Input
                                id="inv-uarea"
                                v-model="unitForm.area"
                                type="number"
                                min="0"
                                step="any"
                            /><InputError :message="unitForm.errors.area" />
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <Label for="inv-uprice">{{
                                t('Asking price (AED)')
                            }}</Label
                            ><Input
                                id="inv-uprice"
                                v-model="unitForm.asking_price"
                                type="number"
                                min="0"
                                step="any"
                            /><InputError
                                :message="unitForm.errors.asking_price"
                            />
                        </div>
                    </div>
                </template>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="inventory-create"
                    :disabled="form.processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
