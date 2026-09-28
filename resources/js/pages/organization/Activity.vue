<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    events: {
        data: {
            id: number;
            event: string;
            actor: string;
            subject_type: string | null;
            subject_id: number | null;
            created_at: string;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    members: { id: number; name: string }[];
    filters: { module?: string; actor_id?: number };
}>();
const form = useForm({
    module: props.filters.module ?? '',
    actor_id: props.filters.actor_id?.toString() ?? '',
});
function apply(): void {
    form.get('/organization/activity', { preserveScroll: true });
}
function label(event: string): string {
    return event.replaceAll('.', ' · ').replaceAll('_', ' ');
}
</script>
<template>
    <Head title="Organization activity" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Organization activity"
            description="Recorded changes across your organization."
        /><Link href="/organization" class="text-sm underline">{{
            t('Organization settings')
        }}</Link>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"
                >Module<select
                    v-model="form.module"
                    class="border-input block h-9 rounded-md border px-3"
                >
                    <option value="">All modules</option>
                    <option
                        v-for="module in [
                            'organization',
                            'identity',
                            'crm',
                            'inventory',
                            'leasing',
                            'transactions',
                            'finance',
                            'accounting',
                            'operations',
                            'portal',
                            'platform',
                            'construction',
                            'fleet',
                        ]"
                        :key="module"
                        :value="module"
                    >
                        {{ module }}
                    </option>
                </select></label
            ><label class="text-sm"
                >Actor<select
                    v-model="form.actor_id"
                    class="border-input block h-9 rounded-md border px-3"
                >
                    <option value="">All actors</option>
                    <option
                        v-for="member in members"
                        :key="member.id"
                        :value="String(member.id)"
                    >
                        {{ member.name }}
                    </option>
                </select></label
            ><Button :disabled="form.processing">Filter</Button>
        </form>
        <p v-if="!events.data.length">No matching recorded activity.</p>
        <ol class="space-y-3">
            <li
                v-for="event in events.data"
                :key="event.id"
                class="space-y-1 rounded-md border p-3 text-sm"
            >
                <p>{{ label(event.event) }}</p>
                <p>{{ event.actor }} · {{ event.created_at }}</p>
                <p v-if="event.subject_type">
                    {{ event.subject_type }} #{{ event.subject_id }}
                </p>
            </li>
        </ol>
        <Pagination :links="events.links" />
    </div>
</template>
