<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    q: string;
    results: { type: string; title: string; detail: string; href: string }[];
}>();
const term = ref(props.q);
watch(
    () => props.q,
    (value) => {
        term.value = value;
    },
);
function submit(): void {
    router.get('/search', { q: term.value.trim() }, { preserveState: true });
}
</script>

<template>
    <Head title="Search" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Search"
            description="Find records in your current organization."
        />
        <form class="flex flex-wrap gap-2" @submit.prevent="submit">
            <Input
                v-model="term"
                aria-label="Search records"
                placeholder="Name, reference, city, or description"
                minlength="2"
                maxlength="100"
                required
            /><Button>{{ t('Search') }}</Button>
        </form>
        <Card
            ><CardHeader><CardTitle>Results</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!q" class="text-muted-foreground text-sm">
                    Enter at least two characters to search.
                </p>
                <p
                    v-else-if="!results.length"
                    class="text-muted-foreground text-sm"
                >
                    No matching records.
                </p>
                <Link
                    v-for="(result, index) in results"
                    :key="`${result.type}-${result.title}-${index}`"
                    :href="result.href"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-3 last:border-0 hover:underline"
                    ><span
                        ><strong>{{ result.title }}</strong
                        ><span
                            v-if="result.detail"
                            class="text-muted-foreground"
                        >
                            · {{ result.detail }}</span
                        ></span
                    ><span class="text-muted-foreground text-sm">{{
                        result.type
                    }}</span></Link
                ></CardContent
            ></Card
        >
    </div>
</template>
