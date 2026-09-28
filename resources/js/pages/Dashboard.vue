<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

defineProps<{
    metrics: {
        openMaintenance: number;
        overdueMaintenance: number;
        availableUnits: number;
        reservedUnits: number;
        activeLeads: number;
        outstandingAed: number;
    };
    alerts: { title: string; count: number; href: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Operations dashboard"
            description="Tenant-scoped operational and receivables overview."
        />

        <div class="grid gap-4 md:grid-cols-3">
            <Card
                v-for="item in [
                    {
                        title: 'Open maintenance',
                        value: metrics.openMaintenance,
                    },
                    {
                        title: 'Overdue maintenance',
                        value: metrics.overdueMaintenance,
                    },
                    { title: 'Available units', value: metrics.availableUnits },
                    { title: 'Reserved units', value: metrics.reservedUnits },
                    { title: 'Active leads', value: metrics.activeLeads },
                    {
                        title: 'Outstanding AED',
                        value: metrics.outstandingAed.toFixed(2),
                    },
                ]"
                :key="item.title"
            >
                <CardHeader>
                    <CardTitle class="text-base">{{ item.title }}</CardTitle>
                </CardHeader>
                <CardContent class="text-muted-foreground text-sm">
                    {{ item.value }}
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader
                ><CardTitle>Alerts requiring attention</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p v-if="!alerts.length" class="text-muted-foreground text-sm">
                    No current alerts.
                </p>
                <Link
                    v-for="alert in alerts"
                    :key="alert.title"
                    :href="alert.href"
                    class="flex justify-between border-b pb-2 last:border-0 hover:underline"
                    ><span>{{ alert.title }}</span
                    ><span>{{ alert.count }}</span></Link
                >
            </CardContent>
        </Card>
    </div>
</template>
