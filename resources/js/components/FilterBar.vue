<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        placeholder?: string;
        resultLabel?: string;
        clearable?: boolean;
    }>(),
    { placeholder: 'Search', resultLabel: undefined, clearable: false },
);

const search = defineModel<string>({ default: '' });
const emit = defineEmits<{ clear: [] }>();
const { t } = useLocale();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-5 py-3.5">
        <label class="relative w-full sm:w-72">
            <span class="sr-only">{{ t(placeholder) }}</span>
            <Search
                class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
            />
            <Input
                v-model="search"
                type="search"
                :placeholder="t(placeholder)"
                class="bg-surface-sunken border-border h-[34px] ps-9"
            />
        </label>
        <slot />
        <Button
            v-if="clearable"
            variant="ghost"
            size="sm"
            @click="emit('clear')"
        >
            <X />
            {{ t('Clear filters') }}
        </Button>
        <span v-if="resultLabel" class="text-muted-foreground ms-auto text-xs">
            {{ resultLabel }}
        </span>
    </div>
</template>
