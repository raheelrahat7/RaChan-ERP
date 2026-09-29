<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { useLocale } from '@/composables/useLocale';

defineProps<{ reports: { label: string; href: string }[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Report Centre', href: '/reports' }] },
});

const { t } = useLocale();
</script>

<template>
    <Head :title="t('Report Centre')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Reports"
            title="Report Centre"
            description="Every report you have access to, in one place."
        />

        <div v-if="reports.length" class="grid gap-3 md:grid-cols-2">
            <Link
                v-for="report in reports"
                :key="report.href"
                :href="report.href"
                class="bg-card shadow-panel hover:border-accent flex items-center gap-3 rounded-lg border p-4 transition-colors"
            >
                <FileText class="text-accent-text size-5 shrink-0" />
                <span class="font-medium">{{ t(report.label) }}</span>
            </Link>
        </div>
        <p v-else class="text-muted-foreground text-sm">
            {{ t("You don't have access to any reports yet.") }}
        </p>
    </div>
</template>
