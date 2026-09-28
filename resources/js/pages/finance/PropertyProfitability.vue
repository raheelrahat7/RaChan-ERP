<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Property = {
    id: number;
    name: string;
    city: string | null;
    owners: { id: number; name: string; ownership_share: string }[];
    active_rent: string;
    vendor_bill_commitments: string;
    maintenance_actual: string;
};
defineProps<{ properties: Property[] }>();
</script>

<template>
    <Head title="Property performance" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Property performance"
            description="AED operating view: active contracted rent, vendor-bill commitments, and actual maintenance costs."
        />
        <Card>
            <CardHeader
                ><CardTitle>Portfolio operating view</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <p
                    v-if="!properties.length"
                    class="text-muted-foreground text-sm"
                >
                    No properties yet.
                </p>
                <div
                    v-for="property in properties"
                    :key="property.id"
                    class="grid gap-3 border-b pb-4 last:border-0 md:grid-cols-4"
                >
                    <div>
                        <p class="font-medium">{{ property.name }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ property.city || 'No city set' }} ·
                            {{
                                property.owners
                                    .map(
                                        (owner) =>
                                            `${owner.name} (${owner.ownership_share}%)`,
                                    )
                                    .join(', ') || 'No owner assigned'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-sm">
                            Active contracted rent
                        </p>
                        <p>AED {{ property.active_rent }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-sm">
                            Vendor-bill commitments
                        </p>
                        <p>AED {{ property.vendor_bill_commitments }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-sm">
                            Actual maintenance
                        </p>
                        <p>AED {{ property.maintenance_actual }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
        <p class="text-muted-foreground text-sm">
            Maintenance actuals are displayed separately from vendor bills to
            avoid double counting when a bill settles a maintenance request.
        </p>
    </div>
</template>
