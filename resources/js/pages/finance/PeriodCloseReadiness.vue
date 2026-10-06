<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    period: {
        id: number;
        name: string;
        starts_on: string;
        ends_on: string;
        status: string;
    };
    journal_count: number;
    debits: string;
    credits: string;
    checks: {
        key: string;
        label: string;
        count: number;
        status: 'clear' | 'attention' | 'information';
        detail: string;
    }[];
    has_ledger_blocker: boolean;
}>();
</script>

<template>
    <Head :title="`${period.name} close readiness`" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="`${period.name} close readiness`"
            :description="`${period.starts_on}–${period.ends_on} · ${period.status}. Ledger warnings block closing; operational review items remain advisory.`"
        />
        <div class="flex gap-4 text-sm">
            <Link href="/accounting" class="underline">Back to accounting</Link>
            <a
                :href="`/accounting/periods/${period.id}/close-readiness.csv`"
                class="underline"
                >Download CSV</a
            >
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <Card
                ><CardHeader
                    ><CardTitle>Detailed journals</CardTitle></CardHeader
                ><CardContent class="text-2xl font-semibold">{{
                    journal_count
                }}</CardContent></Card
            ><Card
                ><CardHeader><CardTitle>Debits</CardTitle></CardHeader
                ><CardContent class="text-2xl font-semibold"
                    >AED {{ debits }}</CardContent
                ></Card
            ><Card
                ><CardHeader><CardTitle>Credits</CardTitle></CardHeader
                ><CardContent class="text-2xl font-semibold"
                    >AED {{ credits }}</CardContent
                ></Card
            >
        </div>
        <p
            v-if="has_ledger_blocker"
            role="alert"
            class="text-destructive font-medium"
        >
            Resolve ledger warnings before relying on period reports.
        </p>
        <Card
            ><CardHeader><CardTitle>Review checks</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><div
                    v-for="check in checks"
                    :key="check.key"
                    class="flex flex-col gap-1 border-b pb-3 last:border-0"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-medium">{{ check.label }}</span
                        ><span
                            :class="
                                check.status === 'attention'
                                    ? 'text-destructive'
                                    : check.status === 'clear'
                                      ? 'text-green-700 dark:text-green-400'
                                      : 'text-muted-foreground'
                            "
                            >{{
                                check.status === 'clear' ? 'Clear' : check.count
                            }}</span
                        >
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{ check.detail }}
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
