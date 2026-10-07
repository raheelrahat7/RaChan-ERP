<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { money, totalsByCurrency } from '@/lib/crm-commercial';
import type { AccountingDocument } from '@/lib/crm-commercial';

const props = defineProps<{ leadId: number }>();
const { t } = useLocale();
const documents = ref<AccountingDocument[]>([]);
const canView = ref(true);
const loaded = ref(false);
const loadError = ref('');

const totals = () => Object.entries(totalsByCurrency(documents.value));

onMounted(async () => {
    try {
        const data = await apiJson<{
            documents: AccountingDocument[];
            permissions: { view: boolean };
        }>(`/crm/leads/${props.leadId}/accounting-links`);
        documents.value = data.documents;
        canView.value = data.permissions.view;
    } catch {
        loadError.value = t('Could not load accounting documents.');
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <Card>
        <CardHeader
            ><CardTitle class="text-eyebrow"
                >{{ t('Accounting link') }} ({{ documents.length }})</CardTitle
            ></CardHeader
        >
        <CardContent class="space-y-3">
            <p v-if="loadError" role="alert" class="text-destructive text-sm">
                {{ loadError }}
            </p>
            <p
                v-else-if="loaded && !canView"
                class="text-muted-foreground text-sm"
            >
                {{
                    t(
                        'You do not have access to finance documents for this lead.',
                    )
                }}
            </p>
            <p
                v-else-if="loaded && !documents.length"
                class="text-muted-foreground text-sm"
            >
                {{ t('No estimates or invoices are linked to this lead.') }}
            </p>
            <div
                v-for="document in documents"
                :key="`${document.kind}-${document.id}`"
                class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
            >
                <div class="min-w-0 space-y-1">
                    <p class="font-medium">
                        {{ document.reference }}
                        <Badge variant="secondary" class="ms-2">{{
                            t(
                                document.kind === 'invoice'
                                    ? 'Invoice'
                                    : 'Estimate',
                            )
                        }}</Badge>
                    </p>
                    <p v-if="document.title" class="text-xs">
                        {{ document.title }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ document.status.replaceAll('_', ' ') }}
                    </p>
                </div>
                <div class="text-end text-xs">
                    <p>
                        {{ t('Amount') }}:
                        {{ money(document.amount, document.currency) }}
                    </p>
                    <p>
                        {{ t('VAT') }}:
                        {{ money(document.vat, document.currency) }}
                    </p>
                    <p class="text-sm font-semibold">
                        {{ t('Total') }}:
                        {{ money(document.total, document.currency) }}
                    </p>
                </div>
            </div>
            <p
                v-if="
                    totals().length > 1 ||
                    (totals().length === 1 && documents.length > 1)
                "
                class="text-sm font-medium"
            >
                {{ t('Total') }}:
                {{
                    totals()
                        .map(([c, v]) => `${c} ${v}`)
                        .join(' · ')
                }}
            </p>
        </CardContent>
    </Card>
</template>
