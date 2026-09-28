<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import {
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    useCommand,
} from '@/components/ui/command';
import { useLocale } from '@/composables/useLocale';
import { NAV_ICONS } from '@/lib/nav-icons';
import type { CommandEntry } from '@/lib/navigation';

defineProps<{ sections: [string, CommandEntry[]][] }>();
const emit = defineEmits<{ go: [href: string]; search: [query: string] }>();

const { t } = useLocale();
const { filterState } = useCommand();
const query = computed(() => filterState.search.trim());
const noPageMatches = computed(() => filterState.filtered.count === 0);
</script>

<template>
    <CommandInput
        :placeholder="t('Search or jump to…')"
        class="border-0 shadow-none focus-visible:shadow-none"
        @keydown.enter="noPageMatches && emit('search', query)"
    />
    <CommandList class="max-h-[60vh]">
        <CommandEmpty>{{ t('No pages match.') }}</CommandEmpty>
        <CommandGroup
            v-for="[section, entries] in sections"
            :key="section"
            :heading="t(section)"
        >
            <CommandItem
                v-for="entry in entries"
                :key="entry.href"
                :value="`${t(entry.label)} ${entry.label} ${entry.href}`"
                @select="emit('go', entry.href)"
            >
                <component :is="NAV_ICONS[entry.icon]" />
                {{ t(entry.label) }}
            </CommandItem>
        </CommandGroup>
    </CommandList>
    <button
        v-if="query.length >= 2"
        type="button"
        class="hover:bg-accent text-muted-foreground hover:text-foreground flex w-full items-center gap-2.5 border-t px-4 py-3 text-start text-sm"
        @click="emit('search', query)"
    >
        <Search class="size-4" />
        {{ t('Search all records for “:q”', { q: query }) }}
        <kbd
            v-if="noPageMatches"
            class="bg-card ms-auto rounded border px-1.5 text-[10.5px]"
            >↵</kbd
        >
    </button>
</template>
