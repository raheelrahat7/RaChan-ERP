<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { optionChoices } from '@/lib/crm-deal-commercial';
import type { DealOption } from '@/lib/crm-deal-commercial';

const props = defineProps<{
    dealId: number;
    version: number;
    status: string | null | undefined;
    options: DealOption[];
    canEdit: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();
const { t } = useLocale();
const error = ref('');
const busy = ref(false);
const shown = computed(() => optionChoices(props.options, props.status));

async function choose(code: string): Promise<void> {
    if (!props.canEdit || busy.value || code === props.status) {
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        await apiJson(`/crm/deals/${props.dealId}`, 'PUT', {
            expected_version: props.version,
            deal_status: code,
        });
        emit('changed');
    } catch (failure) {
        error.value =
            failure instanceof ApiError
                ? (Object.values(failure.fieldErrors())[0] ?? failure.message)
                : t('Something went wrong. Please try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <nav v-if="shown.length" :aria-label="t('Deal status')" class="space-y-1">
        <p class="text-eyebrow">{{ t('Deal status') }}</p>
        <ol class="flex flex-wrap gap-1.5">
            <li v-for="option in shown" :key="option.code">
                <button
                    type="button"
                    class="focus-visible:ring-ring rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:cursor-default"
                    :class="
                        option.code === status
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'bg-background text-muted-foreground hover:bg-muted'
                    "
                    :aria-current="option.code === status ? 'step' : undefined"
                    :disabled="!canEdit || busy"
                    @click="choose(option.code)"
                >
                    {{ option.name }}
                </button>
            </li>
        </ol>
        <p class="text-muted-foreground text-xs">
            {{
                t(
                    'A tracking label only. It does not post payments or change finance records.',
                )
            }}
        </p>
        <InputError :message="error" />
    </nav>
</template>
