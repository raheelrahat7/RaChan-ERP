<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { searchRecords } from '@/lib/record-search';
import type { RecordSearchResult, SearchableType } from '@/lib/record-search';

const props = defineProps<{
    type: SearchableType;
    modelValue: number | null;
    label: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const { t } = useLocale();
const query = ref('');
const results = ref<RecordSearchResult[]>([]);
const status = ref<'idle' | 'searching' | 'ok' | 'empty' | 'unavailable'>(
    'idle',
);
const picked = ref<RecordSearchResult | null>(null);
let requestId = 0;

watch(query, async (value) => {
    if (props.modelValue !== null) {
        return;
    }
    const term = value.trim();
    if (term.length < 2) {
        status.value = 'idle';
        results.value = [];

        return;
    }
    status.value = 'searching';
    const id = ++requestId;
    const outcome = await searchRecords(props.type, term);
    if (id !== requestId) {
        return;
    }
    if (outcome.status === 'unavailable') {
        status.value = 'unavailable';
        results.value = [];

        return;
    }
    results.value = outcome.results;
    status.value = outcome.results.length === 0 ? 'empty' : 'ok';
});

function pick(result: RecordSearchResult): void {
    picked.value = result;
    emit('update:modelValue', result.id);
    query.value = '';
    results.value = [];
    status.value = 'idle';
}

function clear(): void {
    picked.value = null;
    emit('update:modelValue', null);
}

const showList = computed(
    () =>
        props.modelValue === null &&
        ['searching', 'ok', 'empty', 'unavailable'].includes(status.value),
);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div
            v-if="modelValue !== null && picked"
            class="border-input bg-card flex items-center gap-2 rounded-sm border px-3 py-2 text-sm"
        >
            <span class="min-w-0 flex-1 truncate">
                {{ picked.label }}
                <span v-if="picked.sublabel" class="text-muted-foreground"
                    >· {{ picked.sublabel }}</span
                >
            </span>
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground"
                :aria-label="t('Clear selection')"
                @click="clear"
            >
                <X class="size-4" />
            </button>
        </div>
        <template v-else>
            <Input v-model="query" :placeholder="t(label)" />
            <div
                v-if="showList"
                class="border-input bg-card max-h-48 overflow-y-auto rounded-sm border text-sm"
            >
                <p
                    v-if="status === 'searching'"
                    class="text-muted-foreground p-2.5"
                >
                    {{ t('Searching…') }}
                </p>
                <p
                    v-else-if="status === 'unavailable'"
                    class="text-muted-foreground p-2.5"
                >
                    {{ t("Search isn't connected yet.") }}
                </p>
                <p
                    v-else-if="status === 'empty'"
                    class="text-muted-foreground p-2.5"
                >
                    {{ t('No matches.') }}
                </p>
                <button
                    v-for="result in results"
                    :key="result.id"
                    type="button"
                    class="hover:bg-accent flex w-full flex-col items-start px-2.5 py-1.5 text-start"
                    @click="pick(result)"
                >
                    <span>{{ result.label }}</span>
                    <span
                        v-if="result.sublabel"
                        class="text-muted-foreground text-xs"
                        >{{ result.sublabel }}</span
                    >
                </button>
            </div>
        </template>
    </div>
</template>
