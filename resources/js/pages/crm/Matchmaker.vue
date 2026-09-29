<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Search, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{
    leads: { id: number; first_name: string; last_name: string }[];
    mode: 'local_rules';
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI Matchmaker', href: '/crm/matchmaker' }],
    },
});

const { t } = useLocale();
const query = ref('');
const filtered = computed(() => {
    const term = query.value.trim().toLowerCase();

    return term.length === 0
        ? []
        : props.leads
              .filter((lead) =>
                  `${lead.first_name} ${lead.last_name}`
                      .toLowerCase()
                      .includes(term),
              )
              .slice(0, 20);
});
</script>

<template>
    <Head :title="t('AI Matchmaker')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Sales & CRM"
            title="AI Matchmaker"
            description="Deterministic, explainable matching — not a black-box AI call."
        />
        <div class="bg-card shadow-panel rounded-lg border p-5">
            <label class="relative block">
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="query"
                    class="ps-9"
                    :placeholder="t('Search leads by name…')"
                />
            </label>
            <ul v-if="filtered.length" class="mt-3 flex flex-col gap-1">
                <li v-for="lead in filtered" :key="lead.id">
                    <Link
                        :href="`/crm/matchmaker/leads/${lead.id}`"
                        class="hover:bg-accent flex items-center gap-2 rounded-sm px-2.5 py-2 text-sm"
                    >
                        <Sparkles class="text-accent-text size-4" />{{
                            lead.first_name
                        }}
                        {{ lead.last_name }}
                    </Link>
                </li>
            </ul>
            <p
                v-else-if="query.trim().length > 0"
                class="text-muted-foreground mt-3 text-sm"
            >
                {{ t('No leads match that name.') }}
            </p>
            <p v-else class="text-muted-foreground mt-3 text-sm">
                {{ t('Start typing a lead name to find matches for them.') }}
            </p>
        </div>
    </div>
</template>
