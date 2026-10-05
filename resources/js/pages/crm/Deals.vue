<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import CrmDealsView from '@/components/CrmDealsView.vue';
import { useLocale } from '@/composables/useLocale';
import type { Deal, DealPipeline, StageTotal } from '@/types/crm-deals';

const props = defineProps<{
    pipelines: DealPipeline[];
    deals: Deal[];
    stageTotals?: StageTotal[];
    canCreate?: boolean;
    canMove?: boolean;
}>();
const { t } = useLocale();
const pipelineId = ref(props.pipelines[0]?.id ?? 0);
</script>

<template>
    <Head :title="t('Deals')" />
    <div class="flex w-full flex-1 flex-col gap-4 p-4 md:p-6">
        <CrmDealsView
            v-model:pipeline-id="pipelineId"
            :pipelines="pipelines"
            :deals="deals"
            :totals="stageTotals"
            :can-create="canCreate"
            :can-move="canMove"
            linkable
        />
    </div>
</template>
