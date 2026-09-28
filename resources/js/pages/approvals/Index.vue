<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Money from '@/components/Money.vue';
import DateText from '@/components/DateText.vue';
import { useLocale } from '@/composables/useLocale';

type ApprovalItem = {
    key: string;
    module: string;
    transaction: string;
    amount: number | null;
    cost_centre: string | null;
    requested_by: string;
    status: 'submitted';
    submitted_at: string;
    href: string;
    approve_url: string;
    reject_url: string | null;
    reject_requires_reason: boolean;
};

defineProps<{ items: ApprovalItem[]; count: number }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Approvals', href: '/approvals' }] },
});

const { t } = useLocale();
const rejecting = ref<ApprovalItem | null>(null);

function approve(item: ApprovalItem): void {
    router.post(item.approve_url, {}, { preserveScroll: true });
}

function confirmReject(reason: string): void {
    if (!rejecting.value?.reject_url) {
        return;
    }
    router.post(
        rejecting.value.reject_url,
        { reason },
        { preserveScroll: true, onSuccess: () => (rejecting.value = null) },
    );
}
</script>

<template>
    <Head :title="t('Approvals')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Workflow"
            title="Approvals"
            description="Everything waiting for your decision, across every module."
        />
        <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
            <div
                v-if="items.length === 0"
                class="text-muted-foreground p-8 text-center text-sm"
            >
                {{ t('Nothing is waiting for your approval.') }}
            </div>
            <table v-else class="w-full text-[13px]">
                <thead>
                    <tr class="text-label border-b">
                        <th class="py-2.5 ps-5 pe-3 text-start font-medium">
                            {{ t('Module') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Transaction') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Requested by') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Submitted') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Amount') }}
                        </th>
                        <th class="ps-3 pe-5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.key"
                        class="border-b last:border-b-0"
                    >
                        <td class="py-3 ps-5 pe-3">
                            <Badge variant="outline">{{
                                t(item.module)
                            }}</Badge>
                        </td>
                        <td class="px-3">
                            <Link
                                :href="item.href"
                                class="text-accent-text font-medium hover:underline"
                                >{{ item.transaction }}</Link
                            >
                        </td>
                        <td class="px-3">{{ item.requested_by }}</td>
                        <td class="px-3">
                            <DateText :value="item.submitted_at" />
                        </td>
                        <td class="px-3 text-end tabular-nums">
                            <Money
                                v-if="item.amount !== null"
                                :value="item.amount"
                            />
                            <span v-else class="text-faint">—</span>
                        </td>
                        <td class="ps-3 pe-5">
                            <div class="flex justify-end gap-2">
                                <Button size="sm" @click="approve(item)">{{
                                    t('Approve')
                                }}</Button>
                                <Button
                                    v-if="item.reject_url"
                                    size="sm"
                                    variant="destructive-outline"
                                    @click="rejecting = item"
                                    >{{ t('Reject') }}</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ReasonDialog
            :open="rejecting !== null"
            title="Reject this item"
            description="This reason is recorded and, where the workflow supports it, shown to the person who submitted it."
            label="Reason"
            confirm-label="Reject"
            @update:open="(value) => !value && (rejecting = null)"
            @confirm="confirmReject"
        />
    </div>
</template>
