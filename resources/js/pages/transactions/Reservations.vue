<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Unit = { id: number; number: string };
type Contact = { id: number; first_name: string; last_name: string };
type Listing = {
    id: number;
    unit_id: number;
    reference: string;
    purpose: string;
};
type Lead = {
    id: number;
    listing_id: number;
    first_name: string;
    last_name: string;
};
type Reservation = {
    id: number;
    reference: string;
    status: string;
    expires_at: string;
    unit: Unit | null;
    contact: Contact | null;
    listing: Pick<Listing, 'id' | 'reference' | 'purpose'> | null;
    lead: Pick<Lead, 'id' | 'first_name' | 'last_name'> | null;
};
const props = defineProps<{
    units: Unit[];
    contacts: Contact[];
    listings: Listing[];
    leads: Lead[];
    reservations: Reservation[];
    canManageTransactions: boolean;
    selectedListingId: number | null;
}>();
const initialListing = props.listings.find(
    (listing) => listing.id === props.selectedListingId,
);
const form = useForm({
    unit_id: initialListing ? String(initialListing.unit_id) : '',
    listing_id: initialListing ? String(initialListing.id) : '',
    lead_id: '',
    contact_id: '',
    expires_at: '',
    notes: '',
});
const availableLeads = computed(() =>
    props.leads.filter((lead) => String(lead.listing_id) === form.listing_id),
);
function selectListing(): void {
    const listing = props.listings.find(
        (item) => String(item.id) === form.listing_id,
    );
    if (listing) form.unit_id = String(listing.unit_id);
    form.lead_id = '';
}
function selectUnit(): void {
    if (
        !props.listings.some(
            (item) =>
                String(item.id) === form.listing_id &&
                String(item.unit_id) === form.unit_id,
        )
    ) {
        form.listing_id = '';
        form.lead_id = '';
    }
}
function createReservation(): void {
    form.post('/reservations', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head title="Reservations" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Reservations"
            description="Hold available inventory while a leasing or sales transaction is prepared."
        /><Card v-if="canManageTransactions"
            ><CardHeader><CardTitle>Create reservation</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="createReservation"
                >
                    <select
                        v-model="form.listing_id"
                        class="border-input h-9 rounded-md border px-3"
                        @change="selectListing"
                    >
                        <option value="">No linked listing</option>
                        <option
                            v-for="listing in props.listings"
                            :key="listing.id"
                            :value="String(listing.id)"
                        >
                            {{ listing.reference }} · {{ listing.purpose }}
                        </option>
                    </select>
                    <select
                        v-model="form.unit_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                        @change="selectUnit"
                    >
                        <option disabled value="">Available unit</option>
                        <option
                            v-for="unit in props.units"
                            :key="unit.id"
                            :value="String(unit.id)"
                        >
                            {{ unit.number }}
                        </option></select
                    ><select
                        v-if="form.listing_id"
                        v-model="form.lead_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No linked lead</option>
                        <option
                            v-for="lead in availableLeads"
                            :key="lead.id"
                            :value="String(lead.id)"
                        >
                            #{{ lead.id }} · {{ lead.first_name }}
                            {{ lead.last_name }}
                        </option></select
                    ><select
                        v-model="form.contact_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No contact</option>
                        <option
                            v-for="contact in props.contacts"
                            :key="contact.id"
                            :value="String(contact.id)"
                        >
                            {{ contact.first_name }} {{ contact.last_name }}
                        </option></select
                    ><Input
                        v-model="form.expires_at"
                        type="datetime-local"
                        required
                    /><Button :disabled="form.processing">Reserve</Button>
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Active reservations</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!reservations.length"
                    class="text-muted-foreground text-sm"
                >
                    No reservations yet.
                </p>
                <div
                    v-for="reservation in reservations"
                    :key="reservation.id"
                    class="flex justify-between border-b pb-3 last:border-0"
                >
                    <span
                        >{{ reservation.reference }} · Unit
                        {{ reservation.unit?.number }}
                        <span v-if="reservation.listing"
                            >· {{ reservation.listing.reference }} ({{
                                reservation.listing.purpose
                            }})</span
                        >
                        <span v-if="reservation.lead"
                            >· Lead #{{ reservation.lead.id }}</span
                        ></span
                    ><span class="text-muted-foreground">{{
                        reservation.status
                    }}</span>
                </div></CardContent
            ></Card
        >
    </div>
</template>
