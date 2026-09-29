<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Column = { key: string; label: string; group: string };
const props = defineProps<{
    columns: Column[];
    canGrant: boolean;
    members: { id: number; name: string }[];
    grantUserIds: number[];
}>();
const selected = ref<string[]>(['id', 'first_name', 'last_name']);
const groups = computed(() => [
    ...new Set(props.columns.map((column) => column.group)),
]);
function toggle(key: string): void {
    selected.value = selected.value.includes(key)
        ? selected.value.filter((item) => item !== key)
        : [...selected.value, key];
}
function download(): void {
    if (!selected.value.length) return;
    const query = new URLSearchParams();
    for (const key of selected.value) query.append('columns[]', key);
    window.location.assign(`/crm/leads/export/download?${query.toString()}`);
}
function setGrant(userId: number, allowed: boolean): void {
    router.put(
        '/crm/leads/export/grants',
        { user_id: userId, allowed },
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head title="Export leads" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Export leads"
            description="Choose exactly which CRM data to include in your CSV."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <Card
            ><CardHeader><CardTitle>Columns to export</CardTitle></CardHeader
            ><CardContent class="space-y-6">
                <p class="text-muted-foreground text-sm">
                    Only selected columns are included. Phone numbers, email
                    addresses, lead notes, and lead activity comments are off by
                    default. To export the full activity and comment history,
                    use the separate download below.
                </p>
                <section v-for="group in groups" :key="group">
                    <h2 class="mb-3 font-medium">{{ group }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="column in columns.filter(
                                (item) => item.group === group,
                            )"
                            :key="column.key"
                            class="flex items-center gap-2 rounded-md border p-3 text-sm"
                            ><input
                                type="checkbox"
                                :checked="selected.includes(column.key)"
                                @change="toggle(column.key)"
                            />{{ column.label }}</label
                        >
                    </div>
                </section>
                <div class="flex flex-wrap gap-2">
                    <Button :disabled="!selected.length" @click="download"
                        >Download CSV</Button
                    ><Button
                        variant="outline"
                        @click="selected = columns.map((column) => column.key)"
                        >Select all</Button
                    ><Button variant="outline" @click="selected = []"
                        >Clear</Button
                    >
                    <a
                        href="/crm/leads/export/activities"
                        class="border-input inline-flex h-9 items-center rounded-md border px-4 text-sm"
                        >Download all lead activities and comments</a
                    >
                </div>
            </CardContent></Card
        >
        <Card v-if="canGrant"
            ><CardHeader
                ><CardTitle>Owner export permissions</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p class="text-muted-foreground text-sm">
                    Only the owner can grant this permission. Granted members
                    can download leads and activities that they are allowed to
                    view; other staff cannot download either.
                </p>
                <label
                    v-for="member in members"
                    :key="member.id"
                    class="flex items-center gap-2 text-sm"
                    ><input
                        type="checkbox"
                        :checked="grantUserIds.includes(member.id)"
                        @change="
                            setGrant(
                                member.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />{{ member.name }}</label
                ></CardContent
            ></Card
        >
    </div>
</template>
