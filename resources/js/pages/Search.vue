<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { filterByType, highlight, typeCounts } from '@/lib/search-results';

const { t } = useLocale();
const props = defineProps<{
    q: string;
    results: { type: string; title: string; detail: string; href: string }[];
}>();
const term = ref(props.q);
const type = ref('');
const counts = computed(() => typeCounts(props.results));
const shown = computed(() => filterByType(props.results, type.value));
watch(
    () => props.q,
    (value) => {
        term.value = value;
        type.value = '';
    },
);
function submit(): void {
    router.get('/search', { q: term.value.trim() }, { preserveState: true });
}
</script>

<template>
    <Head :title="t('Search')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Search"
            description="Find records in your current organization."
        />
        <form class="flex flex-wrap gap-2" @submit.prevent="submit">
            <Input
                v-model="term"
                class="w-full sm:max-w-md"
                :aria-label="t('Search records')"
                :placeholder="t('Name, reference, city, or description')"
                minlength="2"
                maxlength="100"
                required
            /><Button>{{ t('Search') }}</Button>
        </form>
        <nav
            v-if="counts.length > 1"
            :aria-label="t('Result types')"
            class="flex flex-wrap gap-1.5"
        >
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    type === ''
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="type = ''"
            >
                {{ t('All') }} ({{ results.length }})
            </button>
            <button
                v-for="item in counts"
                :key="item.type"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    type === item.type
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="type = item.type"
            >
                {{ item.type }} ({{ item.total }})
            </button>
        </nav>
        <p v-if="!q" class="text-muted-foreground text-sm">
            {{ t('Enter at least two characters to search.') }}
        </p>
        <p v-else-if="!results.length" class="text-muted-foreground text-sm">
            {{ t('No matching records.') }}
        </p>
        <Card v-else>
            <CardContent class="p-0">
                <ul role="list" class="divide-y">
                    <li
                        v-for="(result, index) in shown"
                        :key="`${result.type}-${result.title}-${index}`"
                    >
                        <Link
                            :href="result.href"
                            class="hover:bg-muted/60 flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                        >
                            <span class="min-w-0">
                                <span class="font-medium"
                                    ><template
                                        v-for="(part, at) in highlight(
                                            result.title,
                                            q,
                                        )"
                                        :key="at"
                                        ><mark
                                            v-if="part.match"
                                            class="bg-primary/15 rounded-sm px-0.5"
                                            >{{ part.text }}</mark
                                        ><template v-else>{{
                                            part.text
                                        }}</template></template
                                    ></span
                                >
                                <span
                                    v-if="result.detail"
                                    class="text-muted-foreground block text-sm"
                                    >{{ result.detail }}</span
                                >
                            </span>
                            <Badge variant="secondary">{{ result.type }}</Badge>
                        </Link>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
