<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

type PaginationLink = {
    label: string;
    url: string | null;
    active: boolean;
};

defineProps<{ links: PaginationLink[] }>();
</script>

<template>
    <nav
        v-if="links.length > 1"
        class="flex flex-wrap gap-1"
        aria-label="Pagination"
    >
        <template v-for="link in links" :key="link.label">
            <Button
                v-if="link.url"
                :variant="link.active ? 'default' : 'outline'"
                size="sm"
                as-child
            >
                <Link :href="link.url" v-html="link.label" />
            </Button>
            <Button
                v-else
                variant="outline"
                size="sm"
                disabled
                v-html="link.label"
            />
        </template>
    </nav>
</template>
