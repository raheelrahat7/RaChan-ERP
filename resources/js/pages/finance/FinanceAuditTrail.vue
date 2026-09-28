<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Log = {
    id: number;
    created_at: string;
    event: string;
    subject_type: string | null;
    subject_id: number | null;
    properties: Record<string, unknown> | null;
    actor: { name: string; email: string } | null;
};
const props = defineProps<{
    filters: { from: string; to: string; event: string; actor_id: string };
    events: string[];
    actors: { id: number; name: string; email: string }[];
    logs: {
        data: Log[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const filters = reactive({ ...props.filters });
const query = computed(() => {
    const params = new URLSearchParams();
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.event) params.set('event', filters.event);
    if (filters.actor_id) params.set('actor_id', filters.actor_id);
    return params.toString();
});
function apply(): void {
    router.get(
        '/accounting/audit-trail',
        Object.fromEntries(new URLSearchParams(query.value)),
    );
}
</script>

<template>
    <Head title="Finance audit trail" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Finance audit trail"
            description="Organization-scoped accounting and finance actions with actor, subject, timestamp, and recorded properties."
        />
        <Link href="/accounting" class="text-sm underline"
            >Back to accounting</Link
        >
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Filters') }}</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="apply"
                >
                    <Input
                        v-model="filters.from"
                        aria-label="From date"
                        type="date"
                        class="w-auto"
                    /><Input
                        v-model="filters.to"
                        aria-label="To date"
                        type="date"
                        class="w-auto"
                    /><select
                        v-model="filters.event"
                        aria-label="Audit event"
                        class="border-input bg-background h-9 rounded-md border px-3"
                    >
                        <option value="">All finance events</option>
                        <option
                            v-for="event in events"
                            :key="event"
                            :value="event"
                        >
                            {{ event }}
                        </option></select
                    ><select
                        v-model="filters.actor_id"
                        aria-label="Audit actor"
                        class="border-input bg-background h-9 rounded-md border px-3"
                    >
                        <option value="">All actors</option>
                        <option
                            v-for="actor in actors"
                            :key="actor.id"
                            :value="String(actor.id)"
                        >
                            {{ actor.name }} · {{ actor.email }}
                        </option></select
                    ><Button>{{ t('Apply filters') }}</Button
                    ><a
                        :href="`/accounting/audit-trail.csv${query ? `?${query}` : ''}`"
                        class="text-sm underline"
                        >Download CSV</a
                    >
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Recorded actions</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><div
                    v-for="log in logs.data"
                    :key="log.id"
                    class="border-b pb-3 last:border-0"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <span class="font-medium">{{ log.event }}</span
                        ><time class="text-muted-foreground text-sm">{{
                            new Date(log.created_at).toLocaleString()
                        }}</time>
                    </div>
                    <p class="text-sm">
                        {{ log.actor?.name ?? 'System'
                        }}<span
                            v-if="log.actor?.email"
                            class="text-muted-foreground"
                        >
                            · {{ log.actor.email }}</span
                        >
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ log.subject_type ?? 'No subject'
                        }}<span v-if="log.subject_id">
                            #{{ log.subject_id }}</span
                        ><span
                            v-if="
                                log.properties &&
                                Object.keys(log.properties).length
                            "
                        >
                            · {{ JSON.stringify(log.properties) }}</span
                        >
                    </p>
                </div>
                <p
                    v-if="!logs.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No matching finance audit events.
                </p>
                <div class="flex gap-3">
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
                        class="underline"
                        >{{ t('Previous') }}</Link
                    ><Link
                        v-if="logs.next_page_url"
                        :href="logs.next_page_url"
                        class="underline"
                        >{{ t('Next') }}</Link
                    >
                </div></CardContent
            ></Card
        >
    </div>
</template>
