<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Contact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    account: { id: number; name: string } | null;
};
defineProps<{ contacts: Contact[]; canManageCrm: boolean }>();
const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
});
function createContact(): void {
    form.post('/crm/contacts', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="CRM contacts" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM contacts"
            description="Manage the people and organizations in your pipeline."
        />
        <Card v-if="canManageCrm"
            ><CardHeader><CardTitle>Add contact</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-4 md:grid-cols-2"
                    @submit.prevent="createContact"
                >
                    <div class="space-y-2">
                        <Label for="first_name">First name</Label
                        ><Input
                            id="first_name"
                            v-model="form.first_name"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="last_name">Last name</Label
                        ><Input
                            id="last_name"
                            v-model="form.last_name"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="email">{{ t('Email') }}</Label
                        ><Input id="email" v-model="form.email" type="email" />
                    </div>
                    <div class="space-y-2">
                        <Label for="phone">{{ t('Phone') }}</Label
                        ><Input id="phone" v-model="form.phone" />
                    </div>
                    <div class="space-y-2">
                        <Label for="company">Account / company</Label
                        ><Input id="company" v-model="form.company" />
                    </div>
                    <Button class="w-fit" :disabled="form.processing"
                        >Create contact</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Contacts</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!contacts.length"
                    class="text-muted-foreground text-sm"
                >
                    No contacts yet.
                </p>
                <div
                    v-for="contact in contacts"
                    :key="contact.id"
                    class="border-b pb-3 last:border-0 last:pb-0"
                >
                    <p class="font-medium">
                        {{ contact.first_name }} {{ contact.last_name }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{
                            contact.account?.name ||
                            contact.email ||
                            contact.phone ||
                            'No contact details'
                        }}
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
