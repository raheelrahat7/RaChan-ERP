<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
type Row = {
    id: number;
    reference: string;
    unit: string;
    listing: string;
    lead: string;
    contact: string;
    expires_at: string;
    status: string;
};

const props = defineProps<{
    units: Unit[];
    contacts: Contact[];
    listings: Listing[];
    leads: Lead[];
    reservations: Reservation[];
    canManageTransactions: boolean;
    canManageInventory: boolean;
    canManageCrm: boolean;
    selectedListingId: number | null;
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const initialListing = props.listings.find(
    (listing) => listing.id === props.selectedListingId,
);
const open = ref(initialListing !== undefined);
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
const rows = computed<Row[]>(() =>
    props.reservations.map((reservation) => ({
        id: reservation.id,
        reference: reservation.reference,
        unit: reservation.unit?.number ?? '',
        listing: reservation.listing
            ? `${reservation.listing.reference} (${reservation.listing.purpose})`
            : '',
        lead: reservation.lead
            ? `#${reservation.lead.id} ${reservation.lead.first_name} ${reservation.lead.last_name}`
            : '',
        contact: reservation.contact
            ? `${reservation.contact.first_name} ${reservation.contact.last_name}`
            : '',
        expires_at: reservation.expires_at,
        status: reservation.status,
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'unit', label: t('Unit'), sortable: true },
    { key: 'listing', label: t('Listing') },
    { key: 'lead', label: t('Lead') },
    { key: 'contact', label: t('Contact') },
    { key: 'expires_at', label: t('Expires'), sortable: true },
    { key: 'status', label: t('Status') },
]);
const blocker = computed(() => {
    if (!props.canManageTransactions) {
        return null;
    }

    return props.units.length ? null : 'units';
});

function selectListing(): void {
    const listing = props.listings.find(
        (item) => String(item.id) === form.listing_id,
    );
    if (listing) {
        form.unit_id = String(listing.unit_id);
    }
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
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Reservations')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Reservations"
            description="Hold available inventory while a leasing or sales transaction is prepared."
        />

        <Card v-if="blocker === 'units'">
            <CardContent class="space-y-3 text-sm">
                <p class="font-medium">
                    {{ t('No available units to reserve') }}
                </p>
                <p>
                    {{
                        t(
                            'Add a property and an available unit in Property inventory before creating a reservation.',
                        )
                    }}
                </p>
                <Button v-if="canManageInventory" as-child variant="outline"
                    ><Link href="/inventory">{{
                        t('Create property and unit')
                    }}</Link></Button
                >
                <p v-else>
                    {{
                        t('Ask an inventory manager to add an available unit.')
                    }}
                </p>
            </CardContent>
        </Card>
        <p
            v-else-if="canManageTransactions && !listings.length"
            class="text-muted-foreground text-sm"
        >
            {{
                t(
                    'To link a reservation to a resale or rental listing, activate the listing first. You can still reserve an available unit without one.',
                )
            }}
            <Link
                v-if="canManageCrm"
                href="/real-estate/secondary-market"
                class="underline"
                >{{ t('Create or activate listing') }}</Link
            >
        </p>

        <CrmSettingsTable
            :show-title="false"
            title="Reservations"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Reserve"
            searchable
            :selectable="false"
            :can-edit="canManageTransactions && units.length > 0"
            @add="open = true"
        >
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ row.status }}</Badge>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create reservation')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Hold an available unit until the expiry time.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="reservation-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createReservation"
                >
                    <div class="space-y-1">
                        <Label for="res-listing">{{ t('Listing') }}</Label>
                        <select
                            id="res-listing"
                            v-model="form.listing_id"
                            :class="selectClass"
                            @change="selectListing"
                        >
                            <option value="">
                                {{ t('No linked listing') }}
                            </option>
                            <option
                                v-for="listing in listings"
                                :key="listing.id"
                                :value="String(listing.id)"
                            >
                                {{ listing.reference }} · {{ listing.purpose }}
                            </option>
                        </select>
                        <InputError :message="form.errors.listing_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="res-unit">{{ t('Available unit') }}</Label>
                        <select
                            id="res-unit"
                            v-model="form.unit_id"
                            :class="selectClass"
                            required
                            @change="selectUnit"
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="String(unit.id)"
                            >
                                {{ unit.number }}
                            </option>
                        </select>
                        <InputError :message="form.errors.unit_id" />
                    </div>
                    <div v-if="form.listing_id" class="space-y-1">
                        <Label for="res-lead">{{ t('Lead') }}</Label>
                        <select
                            id="res-lead"
                            v-model="form.lead_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No linked lead') }}</option>
                            <option
                                v-for="lead in availableLeads"
                                :key="lead.id"
                                :value="String(lead.id)"
                            >
                                #{{ lead.id }} · {{ lead.first_name }}
                                {{ lead.last_name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.lead_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="res-contact">{{ t('Contact') }}</Label>
                        <select
                            id="res-contact"
                            v-model="form.contact_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No contact') }}</option>
                            <option
                                v-for="contact in contacts"
                                :key="contact.id"
                                :value="String(contact.id)"
                            >
                                {{ contact.first_name }} {{ contact.last_name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.contact_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="res-expires">{{ t('Expires') }}</Label>
                        <Input
                            id="res-expires"
                            v-model="form.expires_at"
                            type="datetime-local"
                            required
                        />
                        <InputError :message="form.errors.expires_at" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="reservation-form"
                        :disabled="form.processing"
                        >{{ t('Reserve') }}</Button
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
