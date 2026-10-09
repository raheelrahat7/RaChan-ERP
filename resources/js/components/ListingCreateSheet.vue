<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
import { ApiError, apiJson } from '@/lib/crm-api';
import { createExtras, detailForm, statusChoices } from '@/lib/listings';
import type { WorkflowStatus } from '@/lib/listings';

type Person = { id: number; name: string };

const props = defineProps<{
    secondaryPage: boolean;
    canManageInventory: boolean;
    units: { id: number; number: string }[];
    properties: {
        id: number;
        name: string;
        type: string;
        city: string | null;
    }[];
    buildings: {
        id: number;
        property_id: number;
        name: string;
        floors: number | null;
    }[];
    brokers: Person[];
    owners: Person[];
    costCentres: Person[];
    buyerContacts: { id: number; first_name: string; last_name: string }[];
    workflowStatuses: WorkflowStatus[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ created: [] }>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const base = () => ({
    inventory_mode: props.canManageInventory ? 'new_unit' : 'existing_unit',
    unit_id: '',
    property_id:
        props.properties.length === 1 ? String(props.properties[0].id) : '',
    property_name: '',
    property_type: 'residential',
    property_city: '',
    building_id: '',
    building_name: '',
    building_floors: '',
    floor: '',
    unit_number: '',
    unit_type: 'apartment',
    broker_id: '',
    purpose: props.secondaryPage ? 'sale' : 'rent',
    market_segment: props.secondaryPage ? 'secondary' : '',
    price: '',
});
const form = reactive(base());
const extras = reactive(detailForm());
const buildingMode = ref<'none' | 'existing' | 'new'>('none');
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);

const availableBuildings = computed(() =>
    props.buildings.filter(
        (building) => String(building.property_id) === form.property_id,
    ),
);
const statuses = computed(() => statusChoices(props.workflowStatuses, 'draft'));
const secondary = computed(
    () => props.secondaryPage || form.market_segment === 'secondary',
);

function reset(): void {
    Object.assign(form, base());
    Object.assign(extras, detailForm());
    buildingMode.value = 'none';
    errors.value = {};
    message.value = '';
}
watch(open, (isOpen) => isOpen && reset());
watch(
    () => form.property_id,
    () => {
        buildingMode.value = 'none';
    },
);
watch(buildingMode, () => {
    form.building_id = '';
    form.building_name = '';
    form.building_floors = '';
    form.floor = '';
});
watch(
    () => form.purpose,
    (purpose) => {
        form.market_segment =
            purpose === 'rent'
                ? 'secondary'
                : props.secondaryPage
                  ? 'secondary'
                  : 'primary';
    },
);

function payload(): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(form)) {
        const unit = form.inventory_mode === 'existing_unit';
        const inventoryKeys = [
            'property_id',
            'property_name',
            'property_type',
            'property_city',
            'building_id',
            'building_name',
            'building_floors',
            'floor',
            'unit_number',
            'unit_type',
        ];
        if (unit && inventoryKeys.includes(key)) {
            continue;
        }
        if (!unit && key === 'unit_id') {
            continue;
        }
        if (value !== '') {
            body[key] = value;
        }
    }
    if (form.broker_id) {
        body.broker_id = Number(form.broker_id);
    }

    return { ...createExtras(extras, secondary.value), ...body };
}

async function save(): Promise<void> {
    processing.value = true;
    errors.value = {};
    message.value = '';
    try {
        await apiJson('/real-estate/listings', 'POST', payload());
        emit('created');
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value = Object.keys(errors.value).length
                ? ''
                : error.message;
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('New listing')
                }}</SheetTitle>
                <SheetDescription>{{
                    t(
                        'New listings start as drafts. Set the listing status to Active before reserving it. Other details can be added with Edit.',
                    )
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="listing-create-form"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <div class="space-y-1">
                    <Label for="lc-mode">{{ t('Inventory') }}</Label>
                    <select
                        id="lc-mode"
                        v-model="form.inventory_mode"
                        :class="selectClass"
                    >
                        <option v-if="units.length" value="existing_unit">
                            {{ t('Use an existing available unit') }}
                        </option>
                        <option v-if="canManageInventory" value="new_unit">
                            {{ t('Add a property/unit with this listing') }}
                        </option>
                    </select>
                </div>
                <div
                    v-if="form.inventory_mode === 'new_unit'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="space-y-1">
                        <Label for="lc-property">{{ t('Property') }}</Label>
                        <select
                            id="lc-property"
                            v-model="form.property_id"
                            :class="selectClass"
                        >
                            <option value="">
                                {{ t('Add new property') }}
                            </option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                        <InputError :message="errors.property_id" />
                    </div>
                    <template v-if="!form.property_id">
                        <div class="space-y-1">
                            <Label for="lc-pname">{{
                                t('Property name')
                            }}</Label>
                            <Input
                                id="lc-pname"
                                v-model="form.property_name"
                                required
                            />
                            <InputError :message="errors.property_name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lc-ptype">{{
                                t('Property type')
                            }}</Label>
                            <select
                                id="lc-ptype"
                                v-model="form.property_type"
                                :class="selectClass"
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
                            <Label for="lc-city">{{ t('City') }}</Label>
                            <Input id="lc-city" v-model="form.property_city" />
                        </div>
                    </template>
                    <div class="space-y-1">
                        <Label for="lc-building">{{ t('Building') }}</Label>
                        <select
                            id="lc-building"
                            v-model="buildingMode"
                            :class="selectClass"
                        >
                            <option value="none">{{ t('No building') }}</option>
                            <option
                                v-if="availableBuildings.length"
                                value="existing"
                            >
                                {{ t('Use existing building') }}
                            </option>
                            <option value="new">
                                {{ t('Add new building') }}
                            </option>
                        </select>
                    </div>
                    <div v-if="buildingMode === 'existing'" class="space-y-1">
                        <Label for="lc-ebuilding">{{
                            t('Existing building')
                        }}</Label>
                        <select
                            id="lc-ebuilding"
                            v-model="form.building_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">
                                {{ t('Choose building') }}
                            </option>
                            <option
                                v-for="building in availableBuildings"
                                :key="building.id"
                                :value="String(building.id)"
                            >
                                {{ building.name }}
                            </option>
                        </select>
                        <InputError :message="errors.building_id" />
                    </div>
                    <template v-if="buildingMode === 'new'">
                        <div class="space-y-1">
                            <Label for="lc-bname">{{
                                t('Building name')
                            }}</Label>
                            <Input
                                id="lc-bname"
                                v-model="form.building_name"
                                required
                            />
                            <InputError :message="errors.building_name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lc-bfloors">{{
                                t('Number of floors (optional)')
                            }}</Label>
                            <Input
                                id="lc-bfloors"
                                v-model="form.building_floors"
                                type="number"
                                min="1"
                                max="999"
                            />
                        </div>
                    </template>
                    <div v-if="buildingMode !== 'none'" class="space-y-1">
                        <Label for="lc-floor">{{
                            t('Unit floor (optional)')
                        }}</Label>
                        <Input
                            id="lc-floor"
                            v-model="form.floor"
                            placeholder="G or 1"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-unit">{{ t('Unit number') }}</Label>
                        <Input
                            id="lc-unit"
                            v-model="form.unit_number"
                            required
                        />
                        <InputError :message="errors.unit_number" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-utype">{{ t('Unit type') }}</Label>
                        <select
                            id="lc-utype"
                            v-model="form.unit_type"
                            :class="selectClass"
                        >
                            <option value="apartment">
                                {{ t('Apartment') }}
                            </option>
                            <option value="office">{{ t('Office') }}</option>
                            <option value="retail">{{ t('Retail') }}</option>
                            <option value="warehouse">
                                {{ t('Warehouse') }}
                            </option>
                            <option value="plot">{{ t('Plot') }}</option>
                            <option value="other">{{ t('Other') }}</option>
                        </select>
                    </div>
                </div>
                <div v-else class="space-y-1">
                    <Label for="lc-existing">{{ t('Available unit') }}</Label>
                    <select
                        id="lc-existing"
                        v-model="form.unit_id"
                        :class="selectClass"
                        required
                    >
                        <option disabled value="">{{ t('Unit') }}</option>
                        <option
                            v-for="unit in units"
                            :key="unit.id"
                            :value="String(unit.id)"
                        >
                            {{ unit.number }}
                        </option>
                    </select>
                    <InputError :message="errors.unit_id" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="lc-purpose">{{
                            t('Listing purpose')
                        }}</Label>
                        <select
                            id="lc-purpose"
                            v-model="form.purpose"
                            :class="selectClass"
                        >
                            <option value="rent">{{ t('Rent') }}</option>
                            <option value="sale">{{ t('Sale') }}</option>
                        </select>
                        <InputError :message="errors.purpose" />
                    </div>
                    <div v-if="form.purpose === 'sale'" class="space-y-1">
                        <Label for="lc-segment">{{ t('Sale market') }}</Label>
                        <select
                            id="lc-segment"
                            v-model="form.market_segment"
                            :class="selectClass"
                            required
                        >
                            <option value="primary">
                                {{ t('Primary sale') }}
                            </option>
                            <option value="secondary">{{ t('Resale') }}</option>
                        </select>
                        <InputError :message="errors.market_segment" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-price">{{
                            t('Asking price (AED)')
                        }}</Label>
                        <Input
                            id="lc-price"
                            v-model="form.price"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                        />
                        <InputError :message="errors.price" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-broker">{{
                            t('Broker (optional)')
                        }}</Label>
                        <select
                            id="lc-broker"
                            v-model="form.broker_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No broker') }}</option>
                            <option
                                v-for="broker in brokers"
                                :key="broker.id"
                                :value="String(broker.id)"
                            >
                                {{ broker.name }}
                            </option>
                        </select>
                        <InputError :message="errors.broker_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-workflow">{{
                            t('Workflow status')
                        }}</Label>
                        <select
                            id="lc-workflow"
                            v-model="extras.workflow_status"
                            :class="selectClass"
                        >
                            <option
                                v-for="status in statuses"
                                :key="status.code"
                                :value="status.code"
                            >
                                {{ status.name }}
                            </option>
                        </select>
                        <InputError :message="errors.workflow_status" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-owner">{{ t('Owner') }}</Label>
                        <select
                            id="lc-owner"
                            v-model="extras.owner_id"
                            :class="selectClass"
                        >
                            <option value="">—</option>
                            <option
                                v-for="owner in owners"
                                :key="owner.id"
                                :value="String(owner.id)"
                            >
                                {{ owner.name }}
                            </option>
                        </select>
                        <InputError :message="errors.owner_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-centre">{{ t('Cost centre') }}</Label>
                        <select
                            id="lc-centre"
                            v-model="extras.cost_centre_id"
                            :class="selectClass"
                        >
                            <option value="">—</option>
                            <option
                                v-for="centre in costCentres"
                                :key="centre.id"
                                :value="String(centre.id)"
                            >
                                {{ centre.name }}
                            </option>
                        </select>
                        <InputError :message="errors.cost_centre_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-category">{{
                            t('Listing category')
                        }}</Label>
                        <Input
                            id="lc-category"
                            v-model="extras.listing_category"
                        />
                        <InputError :message="errors.listing_category" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-emirate">{{ t('Emirate') }}</Label>
                        <Input id="lc-emirate" v-model="extras.emirate" />
                        <InputError :message="errors.emirate" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-community">{{ t('Community') }}</Label>
                        <Input id="lc-community" v-model="extras.community" />
                        <InputError :message="errors.community" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lc-size">{{ t('Size (sq ft)') }}</Label>
                        <Input
                            id="lc-size"
                            v-model="extras.size_sqft"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <InputError :message="errors.size_sqft" />
                    </div>
                    <template v-if="secondary">
                        <div class="space-y-1">
                            <Label for="lc-valuation">{{
                                t('Valuation price')
                            }}</Label>
                            <Input
                                id="lc-valuation"
                                v-model="extras.valuation_price"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                            <InputError :message="errors.valuation_price" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lc-buyer">{{ t('Buyer') }}</Label>
                            <select
                                id="lc-buyer"
                                v-model="extras.buyer_contact_id"
                                :class="selectClass"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="contact in buyerContacts"
                                    :key="contact.id"
                                    :value="String(contact.id)"
                                >
                                    {{ contact.first_name }}
                                    {{ contact.last_name }}
                                </option>
                            </select>
                            <InputError :message="errors.buyer_contact_id" />
                        </div>
                    </template>
                </div>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="listing-create-form"
                    :disabled="processing"
                    >{{ t('Create listing') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
