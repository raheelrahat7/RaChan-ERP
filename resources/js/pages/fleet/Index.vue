<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
defineProps<{
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
function create(): void {
    form.post('/operations/fleet');
}
</script>
<template>
    <Head title="Fleet" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Fleet vehicles"
            description="Track vehicle assignments, mileage and service work."
        /><Link href="/maintenance" class="text-sm underline">{{
            t('Maintenance')
        }}</Link
        ><Card
            ><CardHeader><CardTitle>Register vehicle</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="create"
                >
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="form.reference"
                            required
                            maxlength="100" /></label
                    ><label class="text-sm"
                        >{{ t('Plate')
                        }}<Input
                            v-model="form.plate"
                            required
                            maxlength="100" /></label
                    ><label class="text-sm"
                        >VIN<Input v-model="form.vin" maxlength="100" /></label
                    ><label class="text-sm"
                        >{{ t('Make')
                        }}<Input
                            v-model="form.make"
                            required
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >{{ t('Model')
                        }}<Input
                            v-model="form.model"
                            required
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >{{ t('Year')
                        }}<Input
                            v-model="form.year"
                            type="number"
                            min="1900"
                            max="2100" /></label
                    ><label class="text-sm"
                        >Odometer (km)<Input
                            v-model="form.odometer"
                            required
                            type="number"
                            min="0"
                            max="999999999" /></label
                    ><label class="text-sm"
                        >Base property<select
                            v-model="form.property_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Not allocated</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Existing fixed asset<select
                            v-model="form.fixed_asset_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">No asset link</option>
                            <option
                                v-for="asset in assets"
                                :key="asset.id"
                                :value="String(asset.id)"
                            >
                                {{ asset.reference }} · {{ asset.name }}
                            </option>
                        </select></label
                    >
                    <p
                        v-for="(message, field) in form.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="form.processing"
                        >Register vehicle</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader
                ><CardTitle>{{ t('Vehicles') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!vehicles.data.length">No vehicles registered.</p>
                <Link
                    v-for="vehicle in vehicles.data"
                    :key="vehicle.id"
                    :href="`/operations/fleet/${vehicle.id}`"
                    class="block rounded-md border p-3 text-sm underline"
                    >{{ vehicle.reference }} · {{ vehicle.plate }} ·
                    {{ vehicle.make }} {{ vehicle.model }} ·
                    {{ vehicle.odometer }} km · {{ vehicle.status }}</Link
                ><Pagination :links="vehicles.links" /></CardContent
        ></Card>
    </div>
</template>
