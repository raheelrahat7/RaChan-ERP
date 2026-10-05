<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
type Listing = {
    id: number;
    reference: string;
    purpose: string;
    status: string;
    price: string;
    public_url: string | null;
    market_segment: 'primary' | 'secondary' | null;
};
const props = defineProps<{
    listings: Listing[];
    units: { id: number; number: string }[];
    properties: { id: number; name: string; type: string; city: string | null }[];
    buildings: { id: number; property_id: number; name: string; floors: number | null }[];
    brokers: { id: number; name: string }[];
    canManage: boolean;
    canManageTransactions: boolean;
    canManageInventory: boolean;
    marketSegment: 'primary' | 'secondary' | null;
}>();
const secondaryPage = computed(() => props.marketSegment === 'secondary');
const selectedListingId = ref<number | null>(null);
const selectedListing = computed(() =>
    props.listings.find((listing) => listing.id === selectedListingId.value),
);
const form = useForm({
    inventory_mode: props.canManageInventory ? 'new_unit' : 'existing_unit',
    unit_id: '',
    property_id: props.properties.length === 1 ? String(props.properties[0].id) : '',
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
    purpose: secondaryPage.value ? 'sale' : 'rent',
    market_segment: secondaryPage.value ? 'secondary' : '',
    price: '',
});
const buildingMode = ref<'none' | 'existing' | 'new'>('none');
const availableBuildings = computed(() => props.buildings.filter((building) => String(building.property_id) === form.property_id));
watch(() => form.property_id, () => {
    form.building_id = '';
    form.building_name = '';
    form.floor = '';
    buildingMode.value = 'none';
});
watch(buildingMode, () => {
    form.building_id = '';
    form.building_name = '';
    form.floor = '';
});
watch(
    () => form.purpose,
    (purpose) => {
        form.market_segment =
            purpose === 'rent'
                ? 'secondary'
                : secondaryPage.value
                  ? 'secondary'
                  : 'primary';
    },
);
function create(): void {
    form.post('/real-estate/listings', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('unit_id', 'unit_number', 'building_name', 'floor', 'price');
        },
    });
}
function updateStatus(listing: Listing, status: string): void {
    router.put(
        `/real-estate/listings/${listing.id}/status`,
        { status },
        { preserveScroll: true },
    );
}
function updateMarketSegment(listing: Listing, marketSegment: string): void {
    router.put(
        `/real-estate/listings/${listing.id}/market-segment`,
        { market_segment: marketSegment },
        { preserveScroll: true },
    );
}
const inquiryForm = useForm({
    listing_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    notes: '',
});
function recordInquiry(): void {
    if (!selectedListing.value) return;
    inquiryForm.listing_id = String(selectedListing.value.id);
    inquiryForm.post('/real-estate/listings/inquiries', {
        preserveScroll: true,
        onSuccess: () => {
            inquiryForm.reset();
            selectedListingId.value = null;
        },
    });
}
</script>
<template>
    <Head :title="secondaryPage ? 'Secondary Market' : 'Listings'" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="secondaryPage ? 'Secondary Market' : 'Listings'"
            :description="
                secondaryPage
                    ? 'Resale and rental listings linked to the existing property inventory and CRM.'
                    : 'Market available units for rent or sale.'
            "
        />
        <nav aria-label="Listing views" class="flex flex-wrap gap-2">
            <Link
                href="/real-estate/listings"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                >All listings</Link
            >
            <Link
                href="/real-estate/secondary-market"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                >Secondary market</Link
            >
            <Link
                href="/real-estate/listings?market_segment=primary"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                >Primary sales</Link
            >
        </nav>
        <Card v-if="canManage && !canManageInventory && !units.length"
            ><CardHeader
                ><CardTitle>No available units to list</CardTitle></CardHeader
            ><CardContent class="space-y-3 text-sm"
                ><p>
                    Create a property and an available unit in Property
                    inventory first. Then return here to create a resale or
                    rental listing.
                </p>
                <p>Ask an inventory manager to add an available unit.</p>
            </CardContent></Card
        >
        <Card v-if="canManage && (canManageInventory || units.length)"
            ><CardHeader><CardTitle>Create listing</CardTitle></CardHeader
            ><CardContent
                ><form class="grid gap-4 sm:grid-cols-2" @submit.prevent="create">
                    <label class="space-y-1 text-sm sm:col-span-2">Inventory
                        <select v-model="form.inventory_mode" class="border-input h-9 w-full rounded-md border px-3">
                            <option v-if="units.length" value="existing_unit">Use an existing available unit</option>
                            <option v-if="canManageInventory" value="new_unit">Add a property/unit with this listing</option>
                        </select>
                    </label>
                    <template v-if="form.inventory_mode === 'new_unit'">
                        <label class="space-y-1 text-sm">Property
                            <select v-model="form.property_id" class="border-input h-9 w-full rounded-md border px-3">
                                <option value="">Add new property</option>
                                <option v-for="property in properties" :key="property.id" :value="String(property.id)">{{ property.name }}</option>
                            </select>
                        </label>
                        <template v-if="!form.property_id">
                            <label class="space-y-1 text-sm">Property name<Input v-model="form.property_name" required /></label>
                            <label class="space-y-1 text-sm">Property type
                                <select v-model="form.property_type" class="border-input h-9 w-full rounded-md border px-3">
                                    <option value="residential">Residential</option><option value="commercial">Commercial</option><option value="mixed_use">Mixed use</option><option value="land">Land</option>
                                </select>
                            </label>
                            <label class="space-y-1 text-sm">City<Input v-model="form.property_city" /></label>
                        </template>
                        <label class="space-y-1 text-sm">Building
                            <select v-model="buildingMode" class="border-input h-9 w-full rounded-md border px-3">
                                <option value="none">No building</option>
                                <option v-if="availableBuildings.length" value="existing">Use existing building</option>
                                <option value="new">Add new building</option>
                            </select>
                        </label>
                        <label v-if="buildingMode === 'existing'" class="space-y-1 text-sm">Existing building
                            <select v-model="form.building_id" class="border-input h-9 w-full rounded-md border px-3" required>
                                <option disabled value="">Choose building</option>
                                <option v-for="building in availableBuildings" :key="building.id" :value="String(building.id)">{{ building.name }}</option>
                            </select>
                        </label>
                        <template v-if="buildingMode === 'new'">
                            <label class="space-y-1 text-sm">Building name<Input v-model="form.building_name" required /></label>
                            <label class="space-y-1 text-sm">Number of floors (optional)<Input v-model="form.building_floors" type="number" min="1" max="999" /></label>
                        </template>
                        <label v-if="buildingMode !== 'none'" class="space-y-1 text-sm">Unit floor (optional)<Input v-model="form.floor" placeholder="G or 1" /></label>
                        <label class="space-y-1 text-sm">Unit number<Input v-model="form.unit_number" required /></label>
                        <label class="space-y-1 text-sm">Unit type
                            <select v-model="form.unit_type" class="border-input h-9 w-full rounded-md border px-3">
                                <option value="apartment">Apartment</option><option value="office">Office</option><option value="retail">Retail</option><option value="warehouse">Warehouse</option><option value="plot">Plot</option><option value="other">Other</option>
                            </select>
                        </label>
                    </template>
                    <label v-else class="space-y-1 text-sm">Available unit
                    <select
                        v-model="form.unit_id"
                        class="border-input h-9 w-full rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Unit') }}</option>
                        <option
                            v-for="unit in units"
                            :key="unit.id"
                            :value="String(unit.id)"
                        >
                            {{ unit.number }}
                        </option></select></label>
                    <label class="space-y-1 text-sm">Broker (optional)<select
                        v-model="form.broker_id"
                        class="border-input h-9 w-full rounded-md border px-3"
                    >
                        <option value="">No broker</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option></select></label>
                    <label class="space-y-1 text-sm">Listing purpose<select
                        v-model="form.purpose"
                        class="border-input h-9 w-full rounded-md border px-3"
                    >
                        <option value="rent">{{ t('Rent') }}</option>
                        <option value="sale">Sale</option></select></label>
                    <label v-if="form.purpose === 'sale'" class="space-y-1 text-sm">Sale market<select
                        v-if="form.purpose === 'sale'"
                        v-model="form.market_segment"
                        aria-label="Sale market segment"
                        class="border-input h-9 w-full rounded-md border px-3"
                        required
                    >
                        <option value="primary">Primary sale</option>
                        <option value="secondary">Resale</option></select></label>
                    <label class="space-y-1 text-sm">Asking price (AED)<Input
                        v-model="form.price"
                        type="number"
                        min="0"
                        placeholder="AED price"
                        required
                    /></label><div class="flex items-end"><Button :disabled="form.processing" type="submit">Create listing</Button></div>
                    <p class="text-muted-foreground w-full text-sm">
                        New listings start as drafts. Set the listing status to
                        Active before reserving it.
                    </p>
                    <p
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive w-full text-sm"
                    >
                        {{ Object.values(form.errors).join(' ') }}
                    </p>
                </form></CardContent
            ></Card
        ><Card v-if="canManage && selectedListing"
            ><CardHeader
                ><CardTitle
                    >Record inquiry for
                    {{ selectedListing.reference }}</CardTitle
                ></CardHeader
            ><CardContent>
                <form
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="recordInquiry"
                >
                    <label class="space-y-1 text-sm"
                        >First name<Input
                            v-model="inquiryForm.first_name"
                            required
                    /></label>
                    <label class="space-y-1 text-sm"
                        >Last name<Input
                            v-model="inquiryForm.last_name"
                            required
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('Email')
                        }}<Input v-model="inquiryForm.email" type="email"
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('Phone') }}<Input v-model="inquiryForm.phone"
                    /></label>
                    <label class="space-y-1 text-sm sm:col-span-2"
                        >{{ t('Notes') }}<Input v-model="inquiryForm.notes"
                    /></label>
                    <p
                        v-if="Object.keys(inquiryForm.errors).length"
                        class="text-destructive text-sm sm:col-span-2"
                        role="alert"
                    >
                        {{ Object.values(inquiryForm.errors).join(' ') }}
                    </p>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button :disabled="inquiryForm.processing"
                            >Save inquiry</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="selectedListingId = null"
                            >{{ t('Cancel') }}</Button
                        >
                    </div>
                </form>
            </CardContent></Card
        ><Card
            ><CardHeader
                ><CardTitle>{{ t('Listings') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!listings.length"
                    class="text-muted-foreground text-sm"
                >
                    No listings yet.
                </p>
                <div
                    v-for="listing in listings"
                    :key="listing.id"
                    class="grid gap-3 border-b pb-3 last:border-0 md:grid-cols-[1fr_auto_auto] md:items-center"
                >
                    <span
                        >{{ listing.reference }} · {{ listing.purpose }} ·
                        {{
                            listing.purpose === 'rent'
                                ? 'Rental'
                                : listing.market_segment === 'secondary'
                                  ? 'Resale'
                                  : listing.market_segment === 'primary'
                                    ? 'Primary sale'
                                    : 'Unclassified sale'
                        }}</span
                    ><span>AED {{ listing.price }} · {{ listing.status }}</span>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <select
                            v-if="listing.purpose === 'sale'"
                            :value="listing.market_segment ?? ''"
                            class="border-input h-8 rounded-md border px-2 text-sm"
                            aria-label="Sale market segment"
                            @change="
                                updateMarketSegment(
                                    listing,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option disabled value="">Classify sale</option>
                            <option value="primary">Primary</option>
                            <option value="secondary">Resale</option>
                        </select>
                        <select
                            :value="listing.status"
                            class="border-input h-8 rounded-md border px-2 text-sm"
                            aria-label="Listing status"
                            @change="
                                updateStatus(
                                    listing,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="draft">{{ t('Draft') }}</option>
                            <option value="active">{{ t('Active') }}</option>
                            <option value="paused">Paused</option>
                            <option value="closed">Closed</option>
                        </select>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="selectedListingId = listing.id"
                            >Record inquiry</Button
                        >
                        <Button
                            v-if="
                                canManageTransactions &&
                                listing.status === 'active' &&
                                (listing.purpose === 'rent' ||
                                    listing.market_segment === 'secondary')
                            "
                            as-child
                            size="sm"
                            variant="outline"
                            ><Link
                                :href="`/reservations?listing_id=${listing.id}`"
                                >Reserve</Link
                            ></Button
                        >
                        <Button
                            v-if="
                                listing.status === 'active' &&
                                listing.public_url
                            "
                            as-child
                            size="sm"
                            variant="outline"
                        >
                            <a
                                :href="listing.public_url"
                                target="_blank"
                                rel="noopener"
                                >Public page</a
                            >
                        </Button>
                    </div>
                </div></CardContent
            ></Card
        >
    </div>
</template>
