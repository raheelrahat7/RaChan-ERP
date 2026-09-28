<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CommandPaletteBody from '@/components/CommandPaletteBody.vue';
import { CommandDialog } from '@/components/ui/command';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useLocale } from '@/composables/useLocale';
import { useNavigation } from '@/composables/useNavigation';
import { commandEntries } from '@/lib/navigation';
import type { CommandEntry } from '@/lib/navigation';

const { open, listen } = useCommandPalette();
listen();
const { groups } = useNavigation();
const { t } = useLocale();

const sections = computed<[string, CommandEntry[]][]>(() => {
    const map = new Map<string, CommandEntry[]>();
    for (const entry of commandEntries(groups.value)) {
        map.set(entry.section, [...(map.get(entry.section) ?? []), entry]);
    }

    return [...map.entries()];
});

function go(href: string): void {
    open.value = false;
    router.visit(href);
}

function searchAll(query: string): void {
    if (query.length < 2) {
        return;
    }
    open.value = false;
    router.get('/search', { q: query });
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        :title="t('Search or jump to…')"
        :description="t('Pages and records')"
    >
        <CommandPaletteBody :sections="sections" @go="go" @search="searchAll" />
    </CommandDialog>
</template>
