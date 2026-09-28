<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
type Person = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
};
const props = defineProps<{
    owners: Person[];
    tenants: Person[];
    brokers: Person[];
    canManage: boolean;
}>();
const form = useForm({ name: '', email: '', phone: '', reference: '' });
function create(type: 'owners' | 'tenants' | 'brokers'): void {
    form.post(`/real-estate/people/${type}`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head title="Real-estate people" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Real-estate people"
            description="Owners, tenants, and brokerage contacts."
        /><Card
            v-for="group in [
                { key: 'owners', title: 'Owners', items: owners },
                { key: 'tenants', title: 'Tenants', items: tenants },
                { key: 'brokers', title: 'Brokers', items: brokers },
            ]"
            :key="group.key"
            ><CardHeader
                ><CardTitle>{{ group.title }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><form
                    v-if="canManage"
                    class="flex flex-wrap gap-3"
                    @submit.prevent="
                        create(group.key as 'owners' | 'tenants' | 'brokers')
                    "
                >
                    <Input
                        v-model="form.name"
                        placeholder="Name"
                        required
                    /><Input
                        v-model="form.email"
                        placeholder="Email"
                        type="email"
                    /><Input v-model="form.phone" placeholder="Phone" /><Button
                        :disabled="form.processing"
                        >{{ t('Add') }}</Button
                    >
                </form>
                <p
                    v-if="!group.items.length"
                    class="text-muted-foreground text-sm"
                >
                    No records yet.
                </p>
                <div
                    v-for="person in group.items"
                    :key="person.id"
                    class="border-b pb-2 last:border-0"
                >
                    {{ person.name }}
                    <span class="text-muted-foreground text-sm">{{
                        person.email || person.phone
                    }}</span>
                </div></CardContent
            ></Card
        >
    </div>
</template>
