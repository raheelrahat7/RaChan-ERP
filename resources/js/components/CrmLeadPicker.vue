<script setup lang="ts">
import { Search } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { splitName } from '@/lib/crm-lead-picker';
import type { QualifiedLead } from '@/lib/crm-lead-picker';

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    pick: [
        lead: QualifiedLead,
        prefill: { title: string; first_name: string; last_name: string },
    ];
}>();
const { t } = useLocale();

const query = ref('');
const leads = ref<QualifiedLead[]>([]);
const loading = ref(false);
const error = ref('');
let timer: number | null = null;
let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    try {
        const params = new URLSearchParams({ per_page: '20' });
        if (query.value.trim()) {
            params.set('q', query.value.trim());
        }
        const data = await apiJson<{ leads: { data: QualifiedLead[] } }>(
            `/crm/leads/qualified?${params}`,
        );
        if (request === latest) {
            leads.value = data.leads.data;
            error.value = '';
        }
    } catch {
        if (request === latest) {
            error.value = t('Could not load qualified leads.');
        }
    } finally {
        if (request === latest) {
            loading.value = false;
        }
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        void load();
    }
});
watch(query, () => {
    if (!open.value) {
        return;
    }
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void load(), 300);
});
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});

function choose(lead: QualifiedLead): void {
    emit('pick', lead, { title: lead.name, ...splitName(lead.name) });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ t('Create a deal from a lead') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Leads that reached a Won stage and have no deal yet. The lead stays in Leads, and the deal is linked to it.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <div class="relative">
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    :aria-label="t('Search qualified leads')"
                    :placeholder="t('Search qualified leads')"
                />
            </div>
            <p v-if="error" role="alert" class="text-destructive text-sm">
                {{ error }}
            </p>
            <p
                v-else-if="!loading && !leads.length"
                class="text-muted-foreground text-sm"
            >
                {{ t('No qualified leads without a deal.') }}
            </p>
            <ul class="max-h-80 space-y-1 overflow-y-auto">
                <li
                    v-for="lead in leads"
                    :key="lead.id"
                    class="flex items-center justify-between gap-3 rounded-md border p-3 text-sm"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ lead.name }}</p>
                        <p class="text-muted-foreground text-xs">
                            <Badge v-if="lead.stage" variant="secondary">{{
                                lead.stage.name
                            }}</Badge>
                            <span v-if="lead.assignee">
                                · {{ lead.assignee.name }}</span
                            >
                            <span v-if="lead.amount !== null">
                                · {{ lead.amount }}</span
                            >
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="!lead.permissions.edit"
                        @click="choose(lead)"
                        >{{ t('Create deal') }}</Button
                    >
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
