<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Unit = { id: number; number: string };
type Contact = { id: number; first_name: string; last_name: string };
type Reservation = {
    id: number;
    reference: string;
    status: string;
    expires_at: string;
    unit: Unit | null;
    contact: Contact | null;
};
const props = defineProps<{
    units: Unit[];
    contacts: Contact[];
    reservations: Reservation[];
    canManageTransactions: boolean;
}>();
const form = useForm({
    unit_id: '',
    contact_id: '',
    expires_at: '',
    notes: '',
});
function createReservation(): void {
    form.post('/reservations', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head title="Reservations" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
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
                        v-model="form.unit_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
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
                        {{ reservation.unit?.number }}</span
                    ><span class="text-muted-foreground">{{
                        reservation.status
                    }}</span>
                </div></CardContent
            ></Card
        >
    </div>
</template>
