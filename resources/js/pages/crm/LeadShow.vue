<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Entry = {
    id: number;
    at: string | null;
    actor: string;
    event: string;
    detail: string;
};
type Field = {
    key: string;
    name: string;
    type: string;
    value: string | number | boolean | string[] | null;
};
type Activity = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    completed_at: string | null;
    created_at: string;
    creator: { name: string } | null;
};
const props = defineProps<{
    lead: {
        id: number;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        company: string | null;
        city: string | null;
        source: string | null;
        notes: string | null;
        status: string;
        stage: { name: string; color: string } | null;
        assignee: { name: string } | null;
        created_at: string;
        updated_at: string;
    };
    customFields: Field[];
    timeline: Entry[];
    activities: Activity[];
}>();
const tab = ref<'details' | 'activities' | 'history'>('details');
function display(value: Field['value']): string {
    return Array.isArray(value)
        ? value.join(', ')
        : value === null || value === ''
          ? '—'
          : String(value);
}
</script>
<template>
    <Head :title="`${lead.first_name} ${lead.last_name}`" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <Heading
            :title="`${lead.first_name} ${lead.last_name}`"
            description="Lead details, activities and history"
        />
        <div class="flex flex-wrap gap-2">
            <Badge
                v-if="lead.stage"
                variant="secondary"
                :style="{ borderColor: lead.stage.color }"
                >{{ lead.stage.name }}</Badge
            ><Badge variant="outline">{{ lead.status }}</Badge
            ><span class="text-muted-foreground self-center text-sm"
                >Responsible: {{ lead.assignee?.name ?? 'Unassigned' }}</span
            >
        </div>
        <div class="flex gap-2" role="tablist" aria-label="Lead sections">
            <Button
                v-for="section in ['details', 'activities', 'history'] as const"
                :key="section"
                type="button"
                role="tab"
                :aria-selected="tab === section"
                :variant="tab === section ? 'default' : 'outline'"
                class="capitalize"
                @click="tab = section"
                >{{ section }}</Button
            >
        </div>
        <div v-if="tab === 'details'" class="grid gap-6 lg:grid-cols-2">
            <Card
                ><CardHeader><CardTitle>General</CardTitle></CardHeader
                ><CardContent class="grid gap-3 sm:grid-cols-2"
                    ><div
                        v-for="item in [
                            { label: 'Email', value: lead.email },
                            { label: 'Phone', value: lead.phone },
                            { label: 'Company', value: lead.company },
                            { label: 'City', value: lead.city },
                            { label: 'Source', value: lead.source },
                            { label: 'Created', value: lead.created_at },
                            { label: 'Modified', value: lead.updated_at },
                            { label: 'Notes', value: lead.notes },
                        ]"
                        :key="item.label"
                    >
                        <p class="text-muted-foreground text-xs">
                            {{ item.label }}
                        </p>
                        <p class="text-sm break-words">
                            {{ item.value || '—' }}
                        </p>
                    </div></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Custom fields</CardTitle></CardHeader
                ><CardContent class="grid gap-3 sm:grid-cols-2"
                    ><p
                        v-if="!customFields.length"
                        class="text-muted-foreground text-sm"
                    >
                        No additional fields are visible to you.
                    </p>
                    <div v-for="field in customFields" :key="field.key">
                        <p class="text-muted-foreground text-xs">
                            {{ field.name }}
                        </p>
                        <p class="text-sm break-words">
                            {{ display(field.value) }}
                        </p>
                    </div></CardContent
                ></Card
            >
        </div>
        <Card v-if="tab === 'activities'"
            ><CardHeader><CardTitle>Activities</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!activities.length"
                    class="text-muted-foreground text-sm"
                >
                    No activities yet.
                </p>
                <div
                    v-for="activity in activities"
                    :key="activity.id"
                    class="border-b pb-3 last:border-0"
                >
                    <p class="font-medium capitalize">
                        {{ activity.type }}
                        <span
                            v-if="activity.completed_at"
                            class="text-muted-foreground text-xs"
                            >· completed</span
                        >
                    </p>
                    <p
                        v-if="activity.notes"
                        class="text-sm whitespace-pre-wrap"
                    >
                        {{ activity.notes }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ activity.creator?.name ?? 'System' }} ·
                        {{ activity.created_at
                        }}<span v-if="activity.due_at">
                            · due {{ activity.due_at }}</span
                        >
                    </p>
                </div></CardContent
            ></Card
        >
        <Card v-if="tab === 'history'"
            ><CardHeader><CardTitle>History</CardTitle></CardHeader
            ><CardContent class="space-y-0"
                ><p
                    v-if="!timeline.length"
                    class="text-muted-foreground text-sm"
                >
                    No history yet.
                </p>
                <div
                    v-for="entry in timeline"
                    :key="entry.id"
                    class="grid gap-2 border-b py-3 text-sm last:border-0 sm:grid-cols-[170px_140px_1fr]"
                >
                    <time class="text-muted-foreground">{{ entry.at }}</time
                    ><span>{{ entry.actor }}</span
                    ><span>{{ entry.detail }}</span>
                </div>
                <p
                    v-if="timeline.length === 100"
                    class="text-muted-foreground mt-3 text-xs"
                >
                    Showing the 100 most recent events.
                </p></CardContent
            ></Card
        >
    </div>
</template>
