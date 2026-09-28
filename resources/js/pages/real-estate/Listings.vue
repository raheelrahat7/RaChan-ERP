<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
};
const props = defineProps<{
    listings: Listing[];
    units: { id: number; number: string }[];
    brokers: { id: number; name: string }[];
    canManage: boolean;
}>();
const selectedListingId = ref<number | null>(null);
const selectedListing = computed(() =>
    props.listings.find((listing) => listing.id === selectedListingId.value),
);
const form = useForm({
    unit_id: '',
    broker_id: '',
    purpose: 'rent',
    price: '',
});
function create(): void {
    form.post('/real-estate/listings', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function updateStatus(listing: Listing, status: string): void {
    router.put(
        `/real-estate/listings/${listing.id}/status`,
        { status },
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
    <Head title="Listings" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Listings"
            description="Market available units for rent or sale."
        /><Card v-if="canManage"
            ><CardHeader><CardTitle>Create listing</CardTitle></CardHeader
            ><CardContent
                ><form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.unit_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Unit') }}</option>
                        <option
                            v-for="unit in units"
                            :key="unit.id"
                            :value="String(unit.id)"
                        >
                            {{ unit.number }}
                        </option></select
                    ><select
                        v-model="form.broker_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No broker</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option></select
                    ><select
                        v-model="form.purpose"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="rent">{{ t('Rent') }}</option>
                        <option value="sale">Sale</option></select
                    ><Input
                        v-model="form.price"
                        type="number"
                        min="0"
                        placeholder="AED price"
                        required
                    /><Button :disabled="form.processing"
                        >Create listing</Button
                    >
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
                    <span>{{ listing.reference }} · {{ listing.purpose }}</span
                    ><span>AED {{ listing.price }} · {{ listing.status }}</span>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
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
